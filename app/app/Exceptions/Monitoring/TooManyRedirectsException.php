<?php

declare(strict_types=1);

namespace App\Exceptions\Monitoring;

use RuntimeException;

final class TooManyRedirectsException extends RuntimeException
{
    public function __construct(public readonly int $maximumRedirects)
    {
        parent::__construct("The request exceeded {$maximumRedirects} redirects.");
    }
}
