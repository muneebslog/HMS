<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Display / Kiosk Session Lifetime
    |--------------------------------------------------------------------------
    |
    | Public display boards (ER station, stock, drips, tokens) are meant to
    | stay open for long periods. Use a much longer idle timeout than the
    | default web session so Livewire polling keeps the board usable.
    |
    */

    'session_lifetime_minutes' => (int) env('DISPLAY_SESSION_LIFETIME', 10080),

];
