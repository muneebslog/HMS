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

    /*
    |--------------------------------------------------------------------------
    | Laboratory Report Branding
    |--------------------------------------------------------------------------
    |
    | Letterhead, footer and signatories printed on lab reports.
    |
    */

    'lab' => [
        'brand' => env('LAB_BRAND', 'Mohsin'),

        'brand_subtitle' => env('LAB_BRAND_SUBTITLE', 'Clinical Laboratory'),

        'registration' => env('LAB_REGISTRATION', 'PHC REG # R 13048'),

        'color' => env('LAB_COLOR', '#233a8b'),

        'footer_color' => env('LAB_FOOTER_COLOR', '#312e81'),

        'address' => env('LAB_ADDRESS', '433/12-A, Peer Colony, St # 1, Walton Road Lahore'),

        'phone' => env('LAB_PHONE', 'Cell: 0320-8489685 | Ph: 042 36662345'),

        'website' => env('LAB_WEBSITE', 'mohsinmedicalcomplex.com'),

        /*
         * The day lab work moved into the HMS (samples, rider, results). Tests billed
         * earlier with no HMS activity were handled in the old lab software: they count
         * as done and are left out of lab pending lists, badges and stats. Empty = no cutoff.
         */
        'tracking_started_at' => env('LAB_TRACKING_STARTED_AT', '2026-09-24'),

        'disclaimer' => env('LAB_DISCLAIMER', 'Electronically verified report. No signature(s) required. Not valid for Court.'),

        /*
         * Printed left to right along the bottom of every page.
         *
         * @var list<array{name: string, qualification: ?string, title: ?string}>
         */
        'signatories' => [
            ['name' => 'Dr. Tariq Saeed', 'qualification' => 'M.B.B.S, M.C.P.S, F.C.P.S', 'title' => 'Asst. Prof. Surgery Shalimar'],
            ['name' => 'Dr. Muhammad Sohail', 'qualification' => 'M.B.B.S, MS (Urology)', 'title' => 'Consultant Urologist'],
            ['name' => 'Muhammad Asghar', 'qualification' => null, 'title' => 'Sr. Lab Technician'],
            ['name' => 'Muhammad Zia Ul Haq', 'qualification' => null, 'title' => 'Biochemist & Molecular Biologist'],
        ],
    ],

    'finance' => [
        /*
         * The first business day whose shifts are reviewed and settled on the Finance page.
         * A business day starts with the night shift opened the evening before. Shifts from
         * earlier days are shown as "before tracking" and cannot be approved or settled there.
         * Empty = no cutoff.
         */
        'tracking_started_at' => env('FINANCE_TRACKING_STARTED_AT', '2026-09-22'),
    ],

];
