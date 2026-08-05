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

    /*
     * How long a status page's daily uptime history may be served from cache.
     * Set to 0 to always recompute (only sensible for very small installs).
     */
    'status_page_history_cache_seconds' => (int) env('STATUS_PAGE_HISTORY_CACHE_SECONDS', 300),

    /*
     * A monitor whose last check is older than its interval times this factor is
     * reported as stale, which surfaces checks that were dropped rather than run.
     */
    'stale_check_interval_multiplier' => (int) env('MONITOR_STALE_INTERVAL_MULTIPLIER', 3),
];
