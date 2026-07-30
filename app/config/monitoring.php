<?php

return [
    'check_retention_days' => (int) env('MONITOR_CHECK_RETENTION_DAYS', 90),
    'monitor_creation_limit_per_minute' => (int) env('MONITOR_CREATION_LIMIT_PER_MINUTE', 10),
    'public_subscription_limit_per_hour' => (int) env('PUBLIC_SUBSCRIPTION_LIMIT_PER_HOUR', 5),
];
