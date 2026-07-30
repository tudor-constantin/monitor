<?php

namespace App\Enums;

enum MonitorCheckStatus: string
{
    case Successful = 'successful';
    case Failed = 'failed';
    case Timeout = 'timeout';
    case ConnectionError = 'connection_error';
    case InvalidResponse = 'invalid_response';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Successful => 'Successful',
            self::Failed => 'Failed',
            self::Timeout => 'Timed out',
            self::ConnectionError => 'Connection error',
            self::InvalidResponse => 'Invalid response',
            self::Blocked => 'Blocked',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Successful => 'green',
            self::Timeout, self::Failed, self::ConnectionError, self::InvalidResponse => 'red',
            self::Blocked => 'amber',
        };
    }
}
