<?php

namespace App\Actions\StatusPages;

use App\Models\StatusPage;

class DeleteStatusPage
{
    public function handle(StatusPage $statusPage): void
    {
        $statusPage->delete();
    }
}
