<?php

declare(strict_types=1);

namespace App\Actions\StatusPages;

use App\Models\StatusPage;

class DeleteStatusPage
{
    public function handle(StatusPage $statusPage): void
    {
        $statusPage->delete();
    }
}
