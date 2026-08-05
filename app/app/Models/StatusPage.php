<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StatusPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_public
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'name',
    'slug',
    'description',
    'is_public',
])]
class StatusPage extends Model
{
    /** @use HasFactory<StatusPageFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_public' => false,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Monitor, $this>
     */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class, 'status_page_monitor')
            ->withPivot(['display_name', 'position'])
            ->orderByPivot('position');
    }

    /**
     * @return HasMany<StatusPageSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(StatusPageSubscription::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }
}
