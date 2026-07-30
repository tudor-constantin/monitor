<?php

namespace App\Models;

use App\Enums\MonitorCheckStatus;
use Database\Factories\MonitorCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $monitor_id
 * @property MonitorCheckStatus $status
 * @property int|null $status_code
 * @property int|null $response_time_ms
 * @property int|null $response_size_bytes
 * @property string|null $resolved_ip
 * @property string|null $error_type
 * @property string|null $error_message
 * @property Carbon $checked_at
 * @property Carbon|null $created_at
 */
#[Fillable([
    'monitor_id',
    'status',
    'status_code',
    'response_time_ms',
    'response_size_bytes',
    'resolved_ip',
    'error_type',
    'error_message',
    'checked_at',
])]
class MonitorCheck extends Model
{
    /** @use HasFactory<MonitorCheckFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

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
            'status' => MonitorCheckStatus::class,
            'status_code' => 'integer',
            'response_time_ms' => 'integer',
            'response_size_bytes' => 'integer',
            'checked_at' => 'datetime',
        ];
    }
}
