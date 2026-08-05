<?php

declare(strict_types=1);

namespace App\Actions\StatusPages;

use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateStatusPage
{
    public function __construct(
        private readonly SyncStatusPageMonitors $syncStatusPageMonitors,
    ) {}

    /**
     * @param  array{name: string, description: string|null, is_public: bool}  $attributes
     * @param  list<int>  $monitorIds
     */
    public function handle(User $user, array $attributes, array $monitorIds): StatusPage
    {
        return DB::transaction(function () use ($user, $attributes, $monitorIds): StatusPage {
            $statusPage = $user->statusPages()->create([
                ...$attributes,
                'slug' => $this->uniqueSlug($attributes['name']),
            ]);

            $this->syncStatusPageMonitors->handle($statusPage, $monitorIds);

            return $statusPage->refresh();
        });
    }

    private function uniqueSlug(string $name): string
    {
        $baseSlug = Str::substr(Str::slug($name), 0, 130);
        $baseSlug = $baseSlug === '' ? 'status' : $baseSlug;
        $slug = $baseSlug;
        $suffix = 2;

        while (StatusPage::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
