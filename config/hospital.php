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

    'address' => env('HOSPITAL_ADDRESS', 'E-433/12-A, Street No.1, Peer Colony, Walton Road, Lahore Cantt.'),

    'phone' => env('HOSPITAL_PHONE', '042-36662345'),

    'email' => env('HOSPITAL_EMAIL', 'mmcwalton@gmail.com'),

];
