<?php

namespace App\Services\Hermes;

use App\Models\Patient;

/**
 * How patients are named in data sent to an AI assistant: MR number only, never name, phone or CNIC.
 */
final class PatientReference
{
    public static function of(?Patient $patient): ?string
    {
        if ($patient === null) {
            return null;
        }

        return filled($patient->mrn) ? (string) $patient->mrn : 'patient#'.$patient->id;
    }
}
