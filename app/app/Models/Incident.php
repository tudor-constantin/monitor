<?php

namespace App\Models;

use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $monitor_id
 * @property Carbon $started_at
 * @property Carbon|null $resolved_at
 * @property int|null $initial_check_id
 * @property int|null $recovery_check_id
 * @property string|null $cause
 * @property int|null $duration_seconds
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'monitor_id',
    'started_at',
    'resolved_at',
    'initial_check_id',
    'recovery_check_id',
    'cause',
    'duration_seconds',
])]
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /**
     * @return BelongsTo<MonitorCheck, $this>
     */
    public function initialCheck(): BelongsTo
    {
        return $this->belongsTo(MonitorCheck::class, 'initial_check_id');
    }

    /**
     * @return BelongsTo<MonitorCheck, $this>
     */
    public function recoveryCheck(): BelongsTo
    {
        return $this->belongsTo(MonitorCheck::class, 'recovery_check_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }
}
