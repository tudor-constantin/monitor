<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\Client\Response;

final readonly class SafeHttpResult
{
    public function __construct(
        public Response $response,
        /** The URL that produced $response, after following every redirect. */
        public string $effectiveUrl,
        /** The validated public address $effectiveUrl was pinned to. */
        public string $resolvedAddress,
        public int $redirectCount,
    ) {}

    public function wasRedirected(): bool
    {
        return $this->redirectCount > 0;
    }
}
