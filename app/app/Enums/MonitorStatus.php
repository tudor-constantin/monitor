<?php

declare(strict_types=1);

namespace App\Enums;

enum MonitorStatus: string
{
    case Pending = 'pending';
    case Up = 'up';
    case Degraded = 'degraded';
    case Down = 'down';
    case Paused = 'paused';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Up => 'Up',
            self::Degraded => 'Degraded',
            self::Down => 'Down',
            self::Paused => 'Paused',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'zinc',
            self::Up => 'green',
            self::Degraded => 'amber',
            self::Down => 'red',
            self::Paused => 'blue',
        };
    }

    /**
     * The relative priority used to order monitors by urgency, lowest first
     * (e.g. on the dashboard: down and degraded monitors surface before
     * healthy ones).
     */
    public function sortWeight(): int
    {
        return match ($this) {
            self::Down => 0,
            self::Degraded => 1,
            self::Pending => 2,
            self::Up => 3,
            self::Paused => 4,
        };
    }
}
