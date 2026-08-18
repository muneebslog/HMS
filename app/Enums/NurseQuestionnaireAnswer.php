<?php

namespace App\Enums;

enum NurseQuestionnaireAnswer: string
{
    case Yes = 'yes';
    case No = 'no';

    /**
     * Get the translated label for the answer.
     */
    public function label(): string
    {
        return match ($this) {
            self::Yes => __('Yes'),
            self::No => __('No'),
        };
    }
}
