<?php

namespace App\Rules;

use App\Services\Monitoring\IpAddressSafety;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

class SafePublicUrl implements ValidationRule
{
    public function __construct(
        private readonly IpAddressSafety $ipAddressSafety = new IpAddressSafety,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        $parts = parse_url($value);

        if (! is_array($parts)) {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        $scheme = Str::lower($parts['scheme'] ?? '');
        $host = Str::of($parts['host'] ?? '')
            ->trim('[]')
            ->lower()
            ->toString();

        if (! in_array($scheme, ['http', 'https'], true)) {
            $fail('The :attribute must use HTTP or HTTPS.');

            return;
        }

        if ($host === '') {
            $fail('The :attribute must include a hostname.');

            return;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            $fail('The :attribute cannot contain credentials.');

            return;
        }

        if (isset($parts['port']) && ! in_array($parts['port'], [80, 443], true)) {
            $fail('The :attribute must use port 80 or 443.');

            return;
        }

        if ($this->isInternalHostname($host) || $this->isUnsafeIpAddress($host)) {
            $fail('The :attribute must point to a public address.');
        }
    }

    private function isInternalHostname(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        if (! str_contains($host, '.')) {
            return true;
        }

        return collect([
            'localhost',
            '.localhost',
            '.local',
            '.internal',
            '.home',
            '.lan',
        ])->contains(fn (string $suffix): bool => $host === $suffix || Str::endsWith($host, $suffix));
    }

    private function isUnsafeIpAddress(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return ! $this->ipAddressSafety->isPublic($host);
    }
}
