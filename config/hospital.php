<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hospital / Facility Branding
    |--------------------------------------------------------------------------
    |
    | Used on printable bills and reports. Override via .env as needed.
    |
    */

    'name' => env('HOSPITAL_NAME', 'MOHSIN MEDICAL COMPLEX'),

    'tagline' => env('HOSPITAL_TAGLINE', 'Maternity, Gynaecology & Surgical Care'),

    'address' => env('HOSPITAL_ADDRESS', ''),

    'phone' => env('HOSPITAL_PHONE', ''),

    'email' => env('HOSPITAL_EMAIL', ''),

];
