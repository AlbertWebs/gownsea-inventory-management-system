<?php

return [
    'slow_query_threshold_ms' => (int) env('GOWNSEA_SLOW_QUERY_THRESHOLD_MS', 1000),
    'fitting_reminder_minutes' => (int) env('GOWNSEA_FITTING_REMINDER_MINUTES', 180),
    /* Global fallback until location, product and contract level rules are configured. */
    'penalties' => [
        'per_day_minor' => (int) env('GOWNSEA_PENALTY_PER_DAY_MINOR', 0),
        'grace_days' => (int) env('GOWNSEA_PENALTY_GRACE_DAYS', 0),
        'cap_minor' => (int) env('GOWNSEA_PENALTY_CAP_MINOR', 0),
        'count_return_day' => (bool) env('GOWNSEA_PENALTY_COUNT_RETURN_DAY', false),
    ],
];
