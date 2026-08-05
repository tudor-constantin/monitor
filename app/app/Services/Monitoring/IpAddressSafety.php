<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use Symfony\Component\HttpFoundation\IpUtils;

class IpAddressSafety
{
    private const IPV6_GLOBAL_UNICAST_RANGE = '2000::/3';

    private const IPV4_NON_UNICAST_RANGES = [
        '192.88.99.0/24', // deprecated 6to4 relay anycast
        '224.0.0.0/4',    // multicast
    ];

    private const IPV6_NON_GLOBAL_UNICAST_RANGES = [
        '3fff::/20', // IETF reserved for future use, carved out of the 2000::/3 global unicast block
    ];

    public function isPublic(string $ipAddress): bool
    {
        $isGlobal = filter_var(
            $ipAddress,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_GLOBAL_RANGE,
        ) !== false;

        if (! $isGlobal) {
            return false;
        }

        $isIpv6 = filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if ($isIpv6) {
            return IpUtils::checkIp($ipAddress, self::IPV6_GLOBAL_UNICAST_RANGE)
                && ! IpUtils::checkIp($ipAddress, self::IPV6_NON_GLOBAL_UNICAST_RANGES);
        }

        return ! IpUtils::checkIp($ipAddress, self::IPV4_NON_UNICAST_RANGES);
    }
}
