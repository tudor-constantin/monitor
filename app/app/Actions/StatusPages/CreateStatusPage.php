<?php

declare(strict_types=1);

namespace App\Actions\StatusPages;

use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CreateStatusPage
{
    public function __construct(
        private readonly SyncStatusPageMonitors $syncStatusPageMonitors,
    ) {}

    /**
     * @param  array{name: string, description: string|null, is_public: bool}  $attributes
     * @param  list<int>  $monitorIds
     */
    private const SLUG_ATTEMPTS = 5;

    /**
     * @param  array{name: string, description: string|null, is_public: bool}  $attributes
     * @param  list<int>  $monitorIds
     */
    public function handle(User $user, array $attributes, array $monitorIds): StatusPage
    {
        $baseSlug = $this->baseSlug($attributes['name']);

        // status_pages.slug is unique, and picking a free slug then inserting it
        // is a check-then-act race. Rather than pretend the SELECT is
        // authoritative, let the database decide and retry on the violation.
        for ($attempt = 1; $attempt <= self::SLUG_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($user, $attributes, $monitorIds, $baseSlug, $attempt): StatusPage {
                    $statusPage = $user->statusPages()->create([
                        ...$attributes,
                        'slug' => $this->candidateSlug($baseSlug, $attempt),
                    ]);

                    $this->syncStatusPageMonitors->handle($statusPage, $monitorIds);

                    return $statusPage->refresh();
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === self::SLUG_ATTEMPTS) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('A unique status page slug could not be generated.');
    }

    private function baseSlug(string $name): string
    {
        $baseSlug = Str::substr(Str::slug($name), 0, 130);

        return $baseSlug === '' ? 'status' : $baseSlug;
    }

    /**
     * The first attempt uses the lowest free suffix so slugs stay readable; a
     * retry means we lost a race, so fall back to a random suffix instead of
     * marching up the same sequence every concurrent writer is also walking.
     */
    private function candidateSlug(string $baseSlug, int $attempt): string
    {
        if ($attempt > 1) {
            return "{$baseSlug}-".Str::lower(Str::random(6));
        }

        $slug = $baseSlug;
        $suffix = 2;

        while (StatusPage::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
