<?php

namespace App\Actions\StatusPages;

use App\Models\StatusPage;
use Illuminate\Support\Facades\DB;

class UpdateStatusPage
{
    public function __construct(
        private readonly SyncStatusPageMonitors $syncStatusPageMonitors,
    ) {}

    /**
     * @param  array{name: string, description: string|null, is_public: bool}  $attributes
     * @param  list<int>  $monitorIds
     */
    public function handle(StatusPage $statusPage, array $attributes, array $monitorIds): StatusPage
    {
        return DB::transaction(function () use ($statusPage, $attributes, $monitorIds): StatusPage {
            $statusPage->update($attributes);
            $this->syncStatusPageMonitors->handle($statusPage, $monitorIds);

            return $statusPage->refresh();
        });
    }
}
