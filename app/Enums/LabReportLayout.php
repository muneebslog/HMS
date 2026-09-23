<?php

namespace App\Enums;

enum LabReportLayout: string
{
    case Table = 'table';
    case TwoColumns = 'two_columns';
    case Compact = 'compact';
    case Highlight = 'highlight';
    case Grid = 'grid';
    case Narrative = 'narrative';
    case Custom = 'custom';

    /**
     * Get the translated label for the layout.
     */
    public function label(): string
    {
        return match ($this) {
            self::Table => __('Table'),
            self::TwoColumns => __('Two Columns'),
            self::Compact => __('Compact'),
            self::Highlight => __('Highlight'),
            self::Grid => __('Grid (titres)'),
            self::Narrative => __('Narrative'),
            self::Custom => __('Custom Template'),
        };
    }

    /**
     * Get a short hint describing when to use the layout.
     */
    public function description(): string
    {
        return match ($this) {
            self::Table => __('Result, unit and normal range per row. Best for CBC, LFT, RFT, lipid profile.'),
            self::TwoColumns => __('Field and result pairs in two columns. Best for urine C/E, screening.'),
            self::Compact => __('One dotted line per result. Best for single-result tests.'),
            self::Highlight => __('All results joined on one large line, e.g. "B Positive".'),
            self::Grid => __('Choice fields as rows, options as titre columns. The first option is treated as negative.'),
            self::Narrative => __('Results followed by a prominent comment block.'),
            self::Custom => __('A developer-provided Blade template.'),
        };
    }

    /**
     * Get the Blade view that renders a report section in this layout.
     */
    public function view(): string
    {
        return 'lab.reports.layouts.'.str_replace('_', '-', $this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $layout) => $layout->value, self::cases());
    }
}
