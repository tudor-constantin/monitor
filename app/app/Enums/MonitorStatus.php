<?php

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
}
