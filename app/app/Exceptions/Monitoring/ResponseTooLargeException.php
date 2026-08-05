<?php

declare(strict_types=1);

namespace App\Exceptions\Monitoring;

use RuntimeException;

final class ResponseTooLargeException extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Response exceeded the maximum size.', previous: $previous);
    }
}
