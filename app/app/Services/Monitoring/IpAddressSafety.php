<?php

namespace App\Services\Monitoring;

use Symfony\Component\HttpFoundation\IpUtils;

class IpAddressSafety
{
    private const IPV6_GLOBAL_UNICAST_RANGE = '2000::/3';

    private const NON_UNICAST_RANGES = [
        '192.88.99.0/24',
        '224.0.0.0/4',
        '64:ff9b:1::/48',
        '100:0:0:1::/64',
        '3fff::/20',
        '5f00::/16',
        'ff00::/8',
    ];

    public function isPublic(string $ipAddress): bool
    {
        $isGlobal = filter_var(
            $ipAddress,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_GLOBAL_RANGE,
        ) !== false;

        $isIpv6 = filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        $isExpectedIpv6GlobalUnicast = ! $isIpv6
            || IpUtils::checkIp($ipAddress, self::IPV6_GLOBAL_UNICAST_RANGE);

        return $isGlobal
            && $isExpectedIpv6GlobalUnicast
            && ! IpUtils::checkIp($ipAddress, self::NON_UNICAST_RANGES);
    }
}
