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

    'address' => env('HOSPITAL_ADDRESS', 'Peer Colony, St. # 1, Walton Road, Lahore.'),

    'phone' => env('HOSPITAL_PHONE', '0320-8489685 , 042-3662345'),

    'email' => env('HOSPITAL_EMAIL', 'mmcwalton@gmail.com'),

];
