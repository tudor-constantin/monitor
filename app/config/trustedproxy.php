<?php

$trustedProxies = array_values(array_filter(array_map(
    static fn (string $proxy): string => trim($proxy),
    explode(',', (string) env('TRUSTED_PROXIES', '')),
)));

if (in_array('*', $trustedProxies, true)) {
    throw new InvalidArgumentException('TRUSTED_PROXIES cannot contain wildcard entries.');
}

return [
    'proxies' => $trustedProxies,
];
