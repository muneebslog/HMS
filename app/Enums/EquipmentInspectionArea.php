<?php

namespace App\Enums;

enum EquipmentInspectionArea: string
{
    case ConsultationRoom = 'consultation_room';
    case LabourRoom = 'labour_room';
    case OperationTheatre = 'operation_theatre';
    case Decontamination = 'decontamination';
    case MaintenanceRegister = 'maintenance_register';

    /**
     * Get the translated label for the area.
     */
    public function label(): string
    {
        return match ($this) {
            self::ConsultationRoom => __('Surgeon / Consultation Room'),
            self::LabourRoom => __('Labour Room'),
            self::OperationTheatre => __('Operation Theatre'),
            self::Decontamination => __('OT Washing & Decontamination'),
            self::MaintenanceRegister => __('Master Equipment Maintenance Register'),
        };
    }

    /**
     * Short description shown on the hub cards.
     */
    public function description(): string
    {
        return match ($this) {
            self::ConsultationRoom => __('Daily equipment, cleaning, and monthly maintenance checks.'),
            self::LabourRoom => __('Critical equipment, turnover cleaning, and emergency readiness.'),
            self::OperationTheatre => __('OT equipment status, pre-opening, turnover, and terminal clean.'),
            self::Decontamination => __('Washing area equipment, daily hygiene, and weekly maintenance.'),
            self::MaintenanceRegister => __('Log equipment defects with corrective action and technician sign-off.'),
        };
    }

    /**
     * Whether this area uses the maintenance register row table instead of equipment checklists.
     */
    public function isRegister(): bool
    {
        return $this === self::MaintenanceRegister;
    }
}
