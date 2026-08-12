<?php

declare(strict_types=1);

namespace App\Actions\Monitors;

use App\Data\MonitorCheckResult;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Services\Monitoring\MonitorStatusManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class PersistMonitorCheck
{
    public function __construct(
        private readonly MonitorStatusManager $monitorStatusManager,
    ) {}

    /**
     * Whether this scheduling slot already has its result on record.
     *
     * The authoritative guard is the CAS inside handle(); this is the cheap
     * read that runs before the outbound request, so a copy whose slot is
     * already spent is turned away without making one. Without it the duplicate
     * still costs a real HTTP request to the monitored site even though its
     * result is thrown away.
     */
    public function alreadyRecorded(Monitor $monitor, CarbonInterface $checkEpoch): bool
    {
        $lastEpoch = Monitor::query()
            ->whereKey($monitor->getKey())
            ->value('last_check_epoch');

        return $lastEpoch !== null
            && $checkEpoch->getTimestamp() <= CarbonImmutable::parse($lastEpoch)->getTimestamp();
    }

    /**
     * @param  string|null  $claimToken  The claim this result was produced under.
     *                                   Null skips the check, for callers that
     *                                   are not a queued run of CheckMonitor.
     * @param  CarbonInterface|null  $checkEpoch  The scheduling slot this result
     *                                            belongs to. Null skips the check.
     * @return MonitorCheck|null Null when the run was superseded and wrote nothing.
     */
    public function handle(Monitor $monitor, MonitorCheckResult $result, ?string $claimToken = null, ?CarbonInterface $checkEpoch = null): ?MonitorCheck
    {
        return DB::transaction(function () use ($monitor, $result, $claimToken, $checkEpoch): ?MonitorCheck {
            $lockedMonitor = Monitor::query()
                ->lockForUpdate()
                ->findOrFail($monitor->id);

            // Read under the row lock, so this is the committed truth and not a
            // snapshot from before a competing run claimed the monitor. A run
            // that lost its claim is a straggler: something newer owns this
            // monitor, and writing now would double-count the check, move the
            // status twice, and open an incident off stale evidence.
            if ($claimToken !== null && $lockedMonitor->check_claim_token !== $claimToken) {
                return null;
            }

            // The claim only serialises runs: it is handed back the moment one
            // finishes, so a second copy of the same dispatch takes a fresh
            // claim and would otherwise record a second result for a slot that
            // already has one -- two rows a second apart, consecutive_failures
            // jumping by two, an incident opened on one interval's evidence.
            // The slot is what those copies share, so the slot is what has to
            // be spent. Monotonic, so a copy that drains out of order behind a
            // newer one is refused rather than overwriting it.
            if ($checkEpoch !== null) {
                $lastEpoch = $lockedMonitor->last_check_epoch;

                if ($lastEpoch !== null && $checkEpoch->getTimestamp() <= $lastEpoch->getTimestamp()) {
                    return null;
                }

                $lockedMonitor->forceFill(['last_check_epoch' => $checkEpoch])->save();
            }

            $check = $lockedMonitor->checks()->create([
                'status' => $result->status,
                'status_code' => $result->statusCode,
                'response_time_ms' => $result->responseTimeMs,
                'response_size_bytes' => $result->responseSizeBytes,
                'resolved_ip' => $result->resolvedIp,
                'error_type' => $result->errorType,
                'error_message' => $result->errorMessage,
                'checked_at' => $result->checkedAt,
            ]);

            $this->monitorStatusManager->handle($lockedMonitor, $check);

            return $check;
        }, attempts: 3);
    }
}
