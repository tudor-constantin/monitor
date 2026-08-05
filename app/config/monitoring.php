<?php

return [
    'check_retention_days' => (int) env('MONITOR_CHECK_RETENTION_DAYS', 90),
    /*
     * Highest per-check HTTP timeout a user may configure. This is the ceiling
     * the monitor form validates against and the value CheckMonitor falls back
     * to when it cannot read a monitor's own timeout, so the queue timeout is
     * never shorter than the request it has to supervise.
     */
    'max_timeout_seconds' => (int) env('MONITOR_MAX_TIMEOUT_SECONDS', 60),
    'monitor_creation_limit_per_minute' => (int) env('MONITOR_CREATION_LIMIT_PER_MINUTE', 10),
    'public_subscription_limit_per_hour' => (int) env('PUBLIC_SUBSCRIPTION_LIMIT_PER_HOUR', 5),
];
