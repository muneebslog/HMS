<?php

namespace App\Enums;

enum GyneProblem: string
{
    case HighBloodPressure = 'high_blood_pressure';
    case Diabetes = 'diabetes';
    case Thyroid = 'thyroid';
    case Anemia = 'anemia';
    case Bleeding = 'bleeding';
    case LowerAbdominalPain = 'lower_abdominal_pain';
    case WhiteDischarge = 'white_discharge';
    case IrregularPeriods = 'irregular_periods';
    case Infertility = 'infertility';
    case UrinaryProblem = 'urinary_problem';

    /**
     * Get the translated label for the problem.
     */
    public function label(): string
    {
        return match ($this) {
            self::HighBloodPressure => __('High BP'),
            self::Diabetes => __('Diabetes'),
            self::Thyroid => __('Thyroid'),
            self::Anemia => __('Anemia'),
            self::Bleeding => __('Bleeding'),
            self::LowerAbdominalPain => __('Lower abdominal pain'),
            self::WhiteDischarge => __('White discharge'),
            self::IrregularPeriods => __('Irregular periods'),
            self::Infertility => __('Infertility'),
            self::UrinaryProblem => __('Urinary problem'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $problem) => $problem->value, self::cases());
    }
}
