<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A monitor's check counts for one calendar day.
 *
 * Raw checks are pruned after the retention period, so this is what keeps the
 * status page history meaningful beyond that window — and it turns a
 * multi-million-row aggregate into one row per monitor per day.
 *
 * @property int $id
 * @property int $monitor_id
 * @property Carbon $date
 * @property int $total_checks
 * @property int $successful_checks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'monitor_id',
    'date',
    'total_checks',
    'successful_checks',
])]
class MonitorCheckDailyStat extends Model
{
    /**
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_checks' => 'integer',
            'successful_checks' => 'integer',
        ];
    }
}
