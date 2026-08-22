<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default ZKTeco Device
    |--------------------------------------------------------------------------
    */

    'device' => [
        'name' => env('ATTENDANCE_DEVICE_NAME', 'Main Entrance K60'),
        'ip_address' => env('ATTENDANCE_DEVICE_IP', '192.168.100.201'),
        'port' => (int) env('ATTENDANCE_DEVICE_PORT', 4370),
        'comm_key' => (int) env('ATTENDANCE_DEVICE_COMM_KEY', 0),
        'timeout' => (float) env('ATTENDANCE_DEVICE_TIMEOUT', 5.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Punch Pairing Windows (minutes)
    |--------------------------------------------------------------------------
    */

    'pre_window_minutes' => (int) env('ATTENDANCE_PRE_WINDOW', 30),
    'post_window_minutes' => (int) env('ATTENDANCE_POST_WINDOW', 60),
    'duplicate_punch_minutes' => (int) env('ATTENDANCE_DUPLICATE_MINUTES', 2),
    'missing_punch_alert_minutes' => (int) env('ATTENDANCE_MISSING_ALERT', 15),

    /*
    |--------------------------------------------------------------------------
    | Payroll Defaults
    |--------------------------------------------------------------------------
    */

    'overtime_after_minutes' => (int) env('ATTENDANCE_OVERTIME_AFTER', 0),
    'round_payable_to_minutes' => (int) env('ATTENDANCE_ROUND_MINUTES', 15),
    'extra_shifts_count_as_overtime' => (bool) env('ATTENDANCE_EXTRA_AS_OT', true),

];
