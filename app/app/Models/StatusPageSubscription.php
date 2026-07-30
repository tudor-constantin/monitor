<?php

namespace App\Models;

use Database\Factories\StatusPageSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $status_page_id
 * @property string $email
 * @property bool $subscribed_to_all
 * @property bool $pending_subscribed_to_all
 * @property list<int>|null $pending_monitor_ids
 * @property string|null $confirmation_token_hash
 * @property Carbon|null $confirmation_requested_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'status_page_id',
    'email',
    'subscribed_to_all',
    'pending_subscribed_to_all',
    'pending_monitor_ids',
    'confirmation_token_hash',
    'confirmation_requested_at',
    'verified_at',
])]
class StatusPageSubscription extends Model
{
    /** @use HasFactory<StatusPageSubscriptionFactory> */
    use HasFactory;

    use HasUlids;
    use MassPrunable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'subscribed_to_all' => true,
        'pending_subscribed_to_all' => true,
    ];

    /**
     * @return BelongsTo<StatusPage, $this>
     */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    /**
     * @return BelongsToMany<Monitor, $this>
     */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(
            Monitor::class,
            'status_page_subscription_monitor',
        );
    }

    /**
     * @return Builder<StatusPageSubscription>
     */
    public function prunable(): Builder
    {
        return self::query()
            ->whereNull('verified_at')
            ->where('confirmation_requested_at', '<', now()->subDay());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subscribed_to_all' => 'boolean',
            'pending_subscribed_to_all' => 'boolean',
            'pending_monitor_ids' => 'array',
            'confirmation_requested_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }
}
