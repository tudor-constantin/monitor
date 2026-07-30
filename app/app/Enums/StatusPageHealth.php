<?php

namespace App\Enums;

enum StatusPageHealth: string
{
    case Operational = 'operational';
    case Degraded = 'degraded';
    case Outage = 'outage';
    case Monitoring = 'monitoring';

    public function label(): string
    {
        return match ($this) {
            self::Operational => 'All systems operational',
            self::Degraded => 'Some systems are degraded',
            self::Outage => 'Service disruption detected',
            self::Monitoring => 'Monitoring is starting',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Operational => 'All monitored services are responding normally.',
            self::Degraded => 'One or more services may be experiencing reduced availability.',
            self::Outage => 'One or more monitored services are currently unavailable.',
            self::Monitoring => 'Current status will appear as monitoring results become available.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Operational => 'green',
            self::Degraded => 'amber',
            self::Outage => 'red',
            self::Monitoring => 'zinc',
        };
    }
}
