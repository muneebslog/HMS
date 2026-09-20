<?php

use App\Enums\UserRole;

return [

    /*
    |--------------------------------------------------------------------------
    | Always Accessible Routes
    |--------------------------------------------------------------------------
    |
    | These routes bypass the page access permission check for assigned users.
    |
    */

    'always_accessible' => [
        'dashboard',
        'pending-role',
        'profile.edit',
        'security.edit',
        'appearance.edit',
        'indoor.procedures.birth-certificate',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sidebar Groups (display order)
    |--------------------------------------------------------------------------
    */

    'groups' => [
        'Platform',
        'Reception',
        'Management',
        'Administration',
        'Dev Side',
        'System',
    ],

    /*
    |--------------------------------------------------------------------------
    | Page Registry
    |--------------------------------------------------------------------------
    |
    | Each manageable route. Sub-routes use "parent" to inherit access from a
    | parent page. Routes with "admin_only" cannot be granted to other roles.
    |
    */

    'pages' => [
        // Doctor (Platform)
        'doctor.portal' => ['label' => 'Doctor Portal', 'group' => 'Platform'],
        'doctor.medication' => ['label' => 'Medication', 'group' => 'Platform'],
        'doctor.procedures' => ['label' => 'My Procedures', 'group' => 'Platform'],

        // Indoor (Platform)
        'indoor.attachments.show' => ['label' => 'Indoor Attachment', 'group' => 'Platform'],
        'indoor.procedures.discharge-certificate' => ['label' => 'Discharge Certificate', 'group' => 'Platform'],
        'indoor.procedures.birth-certificate' => ['label' => 'Birth Certificate', 'group' => 'Platform'],
        'indoor.procedures.print' => ['label' => 'Indoor Bill Print', 'group' => 'Platform'],

        // Reception
        'reception.walkin' => ['label' => 'Walk-in', 'group' => 'Reception'],
        'reception.reservation' => ['label' => 'Reservations', 'group' => 'Reception'],
        'reception.lab-entry' => ['label' => 'Lab Entry', 'group' => 'Reception'],
        'reception.vitals' => ['label' => 'Vitals', 'group' => 'Reception'],
        'reception.procedures' => ['label' => 'Procedures', 'group' => 'Reception'],
        'reception.procedures.file' => ['label' => 'Procedure File', 'group' => 'Reception', 'parent' => 'reception.procedures'],
        'reception.procedures.print' => ['label' => 'Procedure Print', 'group' => 'Reception', 'parent' => 'reception.procedures'],
        'reception.procedures.apparent-invoice' => ['label' => 'Apparent Invoice', 'group' => 'Reception', 'parent' => 'reception.procedures'],
        'reception.token-flow' => ['label' => 'Token Flow', 'group' => 'Reception'],
        'payout.daily' => ['label' => 'Daily Payout', 'group' => 'Reception'],
        'supervisor.checklist' => ['label' => 'Checklist', 'group' => 'Reception'],
        'reception.shift' => ['label' => 'Shift', 'group' => 'Reception'],
        'reception.print-jobs' => ['label' => 'Print Jobs', 'group' => 'Reception'],

        // Management
        'lab-entries' => ['label' => 'Lab Entries Listings', 'group' => 'Management'],
        'reception.mr-lookup' => ['label' => 'MR Lookup', 'group' => 'Management'],
        'reception.invoices' => ['label' => 'Invoices', 'group' => 'Management'],
        'invoices.print' => ['label' => 'Invoice Print', 'group' => 'Management', 'parent' => 'reception.invoices'],
        'reception.queue' => ['label' => 'Queue', 'group' => 'Management'],
        'reception.queue.tv' => ['label' => 'Queue TV', 'group' => 'Management', 'parent' => 'reception.queue'],
        'payout.doctor' => ['label' => 'Doctor Payout', 'group' => 'Management'],
        'management.shift-history' => ['label' => 'Shift History', 'group' => 'Management'],
        'management.approvals' => ['label' => 'Approvals', 'group' => 'Management'],
        'admin.drive' => ['label' => 'HMS Drive', 'group' => 'Management'],
        'admin.drive.download' => ['label' => 'Drive Download', 'group' => 'Management', 'parent' => 'admin.drive'],
        'admin.drive.view' => ['label' => 'Drive View', 'group' => 'Management', 'parent' => 'admin.drive'],
        'admin.pdf-print' => ['label' => 'PDF Print', 'group' => 'Management'],
        'admin.notifications' => ['label' => 'Notifications', 'group' => 'Management'],

        // Administration
        'management.crud' => ['label' => 'Management CRUD', 'group' => 'Administration'],
        'management.procedure-type-documents.preview' => ['label' => 'Procedure Type Document Preview', 'group' => 'Administration', 'parent' => 'management.crud'],
        'admin.users' => ['label' => 'Users', 'group' => 'Administration', 'admin_only' => true],
        'admin.act-as-role' => ['label' => 'Act as Role', 'group' => 'Administration', 'admin_only' => true],
        'admin.employees' => ['label' => 'Staff Profiles', 'group' => 'Administration'],
        'admin.employees.profile' => ['label' => 'Staff Profile', 'group' => 'Administration', 'parent' => 'admin.employees'],
        'employee-documents.download' => ['label' => 'Employee Document Download', 'group' => 'Administration', 'parent' => 'admin.employees'],
        'employee-photos.show' => ['label' => 'Employee Photo', 'group' => 'Administration', 'parent' => 'admin.employees'],
        'employee-qualifications.download' => ['label' => 'Employee Qualification Download', 'group' => 'Administration', 'parent' => 'admin.employees'],
        'admin.health-aides' => ['label' => 'Health Aides', 'group' => 'Administration'],
        'admin.policy-journal' => ['label' => 'Policy Journal', 'group' => 'Administration'],
        'admin.policy-journals.download' => ['label' => 'Policy Journal Download', 'group' => 'Administration', 'parent' => 'admin.policy-journal'],
        'admin.reports' => ['label' => 'Reports to Admin', 'group' => 'Administration'],
        'admin.monthly-report' => ['label' => 'Monthly Report', 'group' => 'Administration'],
        'admin.procedure-finances' => ['label' => 'Procedure Finances', 'group' => 'Administration'],
        'admin.service-stats' => ['label' => 'Service Statistics', 'group' => 'Administration'],
        'admin.medication-deliveries' => ['label' => 'Medication Deliveries', 'group' => 'Administration'],
        'admin.rechecks' => ['label' => 'Recheck Timers', 'group' => 'Administration'],
        'admin.patient-flow' => ['label' => 'Patient Flow', 'group' => 'Administration'],

        // Dev Side
        'admin.sms-logs' => ['label' => 'SMS Logs', 'group' => 'Dev Side'],
        'admin.merge-duplicates' => ['label' => 'Merge Duplicates', 'group' => 'Dev Side', 'admin_only' => true],
        'admin.sql-runner' => ['label' => 'SQL Runner', 'group' => 'Dev Side', 'admin_only' => true],
        'admin.kanban' => ['label' => 'Kanban', 'group' => 'Dev Side'],

        // System (sidebar links; display routes are public but visibility is role-controlled)
        'display.tokens' => ['label' => 'Token Display', 'group' => 'System'],
        'display.er' => ['label' => 'ER Station', 'group' => 'System'],
        'display.drips' => ['label' => 'Drip Delivery', 'group' => 'System'],
        'display.stock' => ['label' => 'Stock Station', 'group' => 'System'],
        'display.er_drips' => ['label' => 'ER + Drips', 'group' => 'System'],
        'display.shift_orders' => ['label' => 'Shift Orders', 'group' => 'System'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Role Access
    |--------------------------------------------------------------------------
    |
    | Maps each role to route names granted on fresh seed. Mirrors the previous
    | hardcoded route middleware assignments in routes/web.php.
    |
    */

    'defaults' => [
        UserRole::Receptionist->value => [
            'indoor.attachments.show', 'indoor.procedures.discharge-certificate', 'indoor.procedures.birth-certificate', 'indoor.procedures.print',
            'reception.mr-lookup',
            'reception.walkin', 'reception.reservation', 'reception.lab-entry',
            'reception.vitals', 'reception.procedures', 'reception.procedures.file', 'reception.procedures.print', 'reception.procedures.apparent-invoice',
            'reception.token-flow', 'payout.daily', 'supervisor.checklist',
            'lab-entries', 'reception.shift', 'reception.print-jobs',
            'display.tokens', 'display.er', 'display.drips', 'display.stock', 'display.er_drips', 'display.shift_orders',
        ],

        UserRole::Management->value => [
            'reception.mr-lookup',
            'reception.invoices', 'invoices.print', 'reception.queue', 'reception.queue.tv',
            'payout.doctor', 'management.shift-history', 'management.approvals',
            'admin.drive', 'admin.drive.download', 'admin.drive.view', 'admin.pdf-print', 'admin.notifications',
            'lab-entries', 'reception.shift', 'reception.print-jobs',
            'display.tokens', 'display.er', 'display.drips', 'display.stock', 'display.er_drips', 'display.shift_orders',
            'indoor.procedures.birth-certificate',
        ],

        UserRole::Doctor->value => [
            'doctor.portal', 'doctor.medication', 'doctor.procedures',
            'indoor.attachments.show', 'indoor.procedures.discharge-certificate', 'indoor.procedures.birth-certificate', 'indoor.procedures.print',
            'reception.mr-lookup',
        ],

        UserRole::Indoor->value => [
            'indoor.attachments.show', 'indoor.procedures.discharge-certificate', 'indoor.procedures.birth-certificate', 'indoor.procedures.print',
            'display.tokens', 'display.er', 'display.drips', 'display.stock', 'display.er_drips', 'display.shift_orders',
        ],

        UserRole::InchargeNurse->value => [
            'indoor.procedures.birth-certificate',
        ],

        UserRole::LabTechnician->value => [
            'lab-entries',
            'reception.mr-lookup',
            'indoor.procedures.birth-certificate',
        ],

        UserRole::Admin->value => 'all',
    ],

];
