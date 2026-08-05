<?php

declare(strict_types=1);

namespace App\Actions\Monitors;

use App\Models\Monitor;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Finds active monitors that have gone quiet.
 *
 * A monitor stops producing checks for reasons that all look identical from the
 * outside: a dispatch silently dropped by the unique lock, a worker killed
 * mid-job, an exhausted retry budget, Redis being unavailable. In every case the
 * scheduler keeps advancing next_check_at and the UI keeps showing the last
 * known status, so nothing raises its hand. This is the one check that does.
 */
class FindStaleMonitors
{
    /**
     * @return Collection<int, Monitor>
     */
    public function handle(int $limit = 25): Collection
    {
        return $this->query()
            ->select([
                'id', 'user_id', 'name', 'url', 'interval_seconds',
                'last_checked_at', 'next_check_at', 'created_at',
            ])
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * How many monitors are stale in total.
     *
     * Reported alongside the sample because the failures this detects are
     * rarely isolated — Redis going away makes every monitor stale at once —
     * and "3 of 1,240" is a very different page than "3".
     */
    public function count(): int
    {
        return $this->query()->count();
    }

    /**
     * @return Builder<Monitor>
     */
    private function query(): Builder
    {
        $multiplier = max(2, (int) config('monitoring.stale_check_interval_multiplier', 3));

        return Monitor::query()
            ->where('is_active', true)
            ->where(function (Builder|BuilderContract $query) use ($multiplier): void {
                $query
                    // Checked before, but not recently enough.
                    ->where(fn ($query) => $query
                        ->whereNotNull('last_checked_at')
                        ->whereRaw(
                            'last_checked_at < DATE_SUB(?, INTERVAL (interval_seconds * ?) SECOND)',
                            [now(), $multiplier],
                        ))
                    // Never checked at all, and long past due. An active monitor
                    // that never produced a single check is the worst version of
                    // this failure, so it must not fall through the gap left by
                    // only looking at monitors that have a last_checked_at.
                    ->orWhere(fn ($query) => $query
                        ->whereNull('last_checked_at')
                        ->whereRaw(
                            'created_at < DATE_SUB(?, INTERVAL (interval_seconds * ?) SECOND)',
                            [now(), $multiplier],
                        ));
            });
    }
}
