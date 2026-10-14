<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Warn-only for insufficient balance
    |--------------------------------------------------------------------------
    | When true, a request with insufficient leave balance is allowed through
    | with a warning rather than being blocked outright.
    */
    'warn_only_insufficient_balance' => false,

    /*
    |--------------------------------------------------------------------------
    | Warn when casual leave is used before regular leave is exhausted
    |--------------------------------------------------------------------------
    */
    'casual_before_regular_warning' => true,

    /*
    |--------------------------------------------------------------------------
    | Maximum days to carry over from one year to the next
    |--------------------------------------------------------------------------
    | null = carry all remaining days forward (no cap).
    */
    'carry_over_max_days' => null,

    /*
    |--------------------------------------------------------------------------
    | Off days in a working week (Carbon dayOfWeek integers)
    |--------------------------------------------------------------------------
    | 5 = Friday, 6 = Saturday (Egyptian weekend)
    */
    'working_week_off_days' => [5, 6],

    /*
    |--------------------------------------------------------------------------
    | Email notifications
    |--------------------------------------------------------------------------
    */
    'email_notifications' => false,
];
