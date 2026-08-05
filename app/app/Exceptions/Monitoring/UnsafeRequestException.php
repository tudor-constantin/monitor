<?php

declare(strict_types=1);

namespace App\Exceptions\Monitoring;

use RuntimeException;
use Throwable;

/**
 * A URL in a request chain is not safe to connect to.
 *
 * Raised for a rejected scheme, port, or embedded credentials, for a hostname
 * that cannot be resolved, and for a hostname that resolves to a non-public
 * address. Callers map {@see errorType()} onto their own failure vocabulary.
 */
final class UnsafeRequestException extends RuntimeException
{
    private function __construct(
        private readonly string $errorType,
        string $message,
        private readonly ?string $address = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function unsupportedTarget(string $url): self
    {
        return new self(
            'unsafe_request_target',
            "The URL [{$url}] must use HTTP or HTTPS on port 80 or 443 without credentials.",
        );
    }

    public static function unresolvableHost(string $host): self
    {
        return new self(
            'dns_resolution_failed',
            "The hostname [{$host}] could not be resolved.",
        );
    }

    public static function nonPublicAddress(string $host, string $address): self
    {
        return new self(
            'unsafe_ip_address',
            "The hostname [{$host}] resolves to the non-public address [{$address}].",
            $address,
        );
    }

    public function errorType(): string
    {
        return $this->errorType;
    }

    /**
     * The offending address, when the failure was caused by one.
     */
    public function address(): ?string
    {
        return $this->address;
    }
}
