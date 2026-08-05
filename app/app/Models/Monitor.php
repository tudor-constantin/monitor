<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MonitorStatus;
use Database\Factories\MonitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $url
 * @property string|null $favicon_path
 * @property Carbon|null $favicon_fetched_at
 * @property string $method
 * @property int $expected_status_code
 * @property int $interval_seconds
 * @property int $timeout_seconds
 * @property MonitorStatus $status
 * @property bool $is_active
 * @property int $consecutive_failures
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $next_check_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'name',
    'url',
    'method',
    'expected_status_code',
    'interval_seconds',
    'timeout_seconds',
    'status',
    'is_active',
    'consecutive_failures',
    'last_checked_at',
    'next_check_at',
])]
class Monitor extends Model
{
    /** @use HasFactory<MonitorFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'method' => 'GET',
        'expected_status_code' => 200,
        'interval_seconds' => 300,
        'timeout_seconds' => 10,
        'status' => 'pending',
        'is_active' => true,
        'consecutive_failures' => 0,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<MonitorCheck, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class);
    }

    /**
     * @return HasMany<Incident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /**
     * @return BelongsToMany<StatusPage, $this>
     */
    public function statusPages(): BelongsToMany
    {
        return $this->belongsToMany(StatusPage::class, 'status_page_monitor')
            ->withPivot(['display_name', 'position']);
    }

    public function faviconUrl(): ?string
    {
        return $this->favicon_path === null
            ? null
            : Storage::disk('public')->url($this->favicon_path);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MonitorStatus::class,
            'is_active' => 'boolean',
            'favicon_fetched_at' => 'datetime',
            'expected_status_code' => 'integer',
            'interval_seconds' => 'integer',
            'timeout_seconds' => 'integer',
            'consecutive_failures' => 'integer',
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
        ];
    }
}
