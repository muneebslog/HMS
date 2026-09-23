<?php

namespace App\Services;

use App\Enums\LabFieldRangeCategory;
use App\Enums\LabFieldType;
use App\Enums\LabReportLayout;
use App\Models\LabField;
use App\Models\LabFieldRange;
use App\Models\LabTest;

/**
 * Turns a lab test and its entered results into a printable report section.
 *
 * Empty results are dropped, section headings with no filled results are
 * dropped, and a test with no filled results produces no section at all.
 *
 * @phpstan-type ReportRow array{field: string, unit: ?string, value: string, range: ?string, flag: ?string, options: list<string>}
 * @phpstan-type ReportGroup array{heading: ?string, rows: list<ReportRow>}
 * @phpstan-type ReportSection array{title: string, layout: LabReportLayout, note: ?string, comment: ?string, show_ranges: bool, custom_template: ?string, groups: list<ReportGroup>, rows: list<ReportRow>}
 */
class LabReportBuilder
{
    /**
     * Patients younger than this many years use "child" ranges when a field has them.
     */
    public const CHILD_MAX_AGE = 12;

    public const FLAG_HIGH = 'high';

    public const FLAG_LOW = 'low';

    /**
     * Build a report section for a test.
     *
     * @param  array<int, string|null>  $values  Result values keyed by lab field id.
     * @return ReportSection|null
     */
    public function buildSection(LabTest $labTest, array $values, ?string $gender = null, ?int $age = null, ?string $comment = null): ?array
    {
        $labTest->loadMissing('fields.ranges');

        $groups = [];
        $rows = [];

        foreach ($labTest->fields as $field) {
            $value = isset($values[$field->id]) ? trim((string) $values[$field->id]) : '';

            if ($value === '') {
                continue;
            }

            $range = $field->type->hasRanges() ? $this->selectRange($field, $gender, $age) : null;

            $row = [
                'field' => trim($field->name),
                'unit' => $field->unit,
                'value' => $value,
                'range' => $range ? $this->formatRangeBounds($range) : null,
                'flag' => $range ? $this->flag($value, $range) : null,
                'options' => $field->type === LabFieldType::Choice ? ($field->options ?? []) : [],
            ];

            $heading = filled($field->pivot->section) ? trim($field->pivot->section) : null;
            $lastIndex = array_key_last($groups);

            if ($lastIndex === null || $groups[$lastIndex]['heading'] !== $heading) {
                $groups[] = ['heading' => $heading, 'rows' => []];
                $lastIndex = array_key_last($groups);
            }

            $groups[$lastIndex]['rows'][] = $row;
            $rows[] = $row;
        }

        if ($rows === []) {
            return null;
        }

        return [
            'title' => $labTest->reportTitle(),
            'layout' => $labTest->resolvedReportLayout($labTest->fields->count()),
            'note' => filled($labTest->report_note) ? $labTest->report_note : null,
            'comment' => filled($comment) ? trim($comment) : null,
            'show_ranges' => $labTest->report_show_ranges,
            'custom_template' => $labTest->report_custom_template,
            'groups' => $groups,
            'rows' => $rows,
        ];
    }

    /**
     * Pick the range that applies to a patient: child (under the child age),
     * then the patient's gender, then general, then the first range defined.
     */
    public function selectRange(LabField $field, ?string $gender, ?int $age): ?LabFieldRange
    {
        $ranges = $field->ranges;

        if ($ranges->isEmpty()) {
            return null;
        }

        $preferred = [];

        if ($age !== null && $age < self::CHILD_MAX_AGE) {
            $preferred[] = LabFieldRangeCategory::Child;
        }

        $preferred[] = LabFieldRangeCategory::tryFrom((string) $gender);
        $preferred[] = LabFieldRangeCategory::General;

        foreach (array_filter($preferred) as $category) {
            $match = $ranges->first(fn (LabFieldRange $range) => $range->category === $category);

            if ($match) {
                return $match;
            }
        }

        return $ranges->first();
    }

    /**
     * Flag a value as high or low against a range. Values or bounds that
     * cannot be compared (free text) are never flagged.
     */
    public function flag(string $value, LabFieldRange $range): ?string
    {
        $number = $this->toComparable($value);

        if ($number === null) {
            return null;
        }

        $low = $this->toComparable($range->value_low);
        $high = $this->toComparable($range->value_high);

        if ($low !== null && $number < $low) {
            return self::FLAG_LOW;
        }

        if ($high !== null && $number > $high) {
            return self::FLAG_HIGH;
        }

        return null;
    }

    /**
     * Fill a test with sample values so its report layout can be previewed
     * before real results exist. The first numeric field is pushed above its
     * range so the high flag is visible in the preview.
     *
     * @return array<int, string>
     */
    public function sampleValues(LabTest $labTest): array
    {
        $labTest->loadMissing('fields.ranges');

        $layout = $labTest->resolvedReportLayout($labTest->fields->count());
        $values = [];
        $flaggedOne = false;

        foreach ($labTest->fields as $field) {
            $values[$field->id] = match ($field->type) {
                LabFieldType::Choice => $this->sampleChoice($field, $layout),
                LabFieldType::Text => __('Sample'),
                LabFieldType::Numeric => $this->sampleNumber($field, ! $flaggedOne),
            };

            if ($field->type === LabFieldType::Numeric && $this->hasNumericHigh($field)) {
                $flaggedOne = true;
            }
        }

        return $values;
    }

    /**
     * Work out how many titre columns a grid row fills for its chosen option.
     * The first option is the negative baseline and is not a column.
     *
     * @param  ReportRow  $row
     * @return array{columns: list<string>, filled: int}
     */
    public function gridCells(array $row): array
    {
        $columns = array_values(array_slice($row['options'], 1));
        $position = array_search($row['value'], $row['options'], true);

        return [
            'columns' => $columns,
            'filled' => $position === false ? 0 : (int) $position,
        ];
    }

    /**
     * Whether a value can be compared against a range: a plain number or an "m:ss" time.
     */
    public function isMeasurable(string $value): bool
    {
        return $this->toComparable($value) !== null;
    }

    /**
     * Format a range's bounds without its category, e.g. "12 – 16" or "≤ 200".
     */
    public function formatRangeBounds(LabFieldRange $range): ?string
    {
        $low = filled($range->value_low) ? $this->trimZeros($range->value_low) : null;
        $high = filled($range->value_high) ? $this->trimZeros($range->value_high) : null;

        return match (true) {
            $low !== null && $high !== null => "{$low} – {$high}",
            $low !== null => "≥ {$low}",
            $high !== null => "≤ {$high}",
            default => null,
        };
    }

    /**
     * Remove insignificant trailing zeros from a decimal string ("12.00" → "12", "0.80" → "0.8").
     */
    private function trimZeros(string $value): string
    {
        if (! preg_match('/^-?\d+\.\d+$/', $value)) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }

    /**
     * Convert a value to a comparable number: plain numbers as-is, "m:ss" as seconds.
     */
    private function toComparable(?string $value): ?float
    {
        $value = trim((string) $value);

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (preg_match('/^(\d+):([0-5]?\d)$/', $value, $matches)) {
            return (float) ($matches[1] * 60 + $matches[2]);
        }

        return null;
    }

    /**
     * Pick a representative option for a choice field in the preview.
     */
    private function sampleChoice(LabField $field, LabReportLayout $layout): string
    {
        $options = $field->options ?? [];

        if ($options === []) {
            return __('Sample');
        }

        if ($layout === LabReportLayout::Grid) {
            return $options[min(2, count($options) - 1)];
        }

        return $options[0];
    }

    /**
     * Pick a sample number for a numeric field: the middle of its range, or
     * just above the top of it when a high flag should be shown.
     */
    private function sampleNumber(LabField $field, bool $showHigh): string
    {
        $range = $field->ranges->firstWhere('category', LabFieldRangeCategory::General) ?? $field->ranges->first();

        if (! $range) {
            return '1';
        }

        $low = $this->toComparable($range->value_low);
        $high = $this->toComparable($range->value_high);
        $isTime = str_contains((string) ($range->value_low ?? $range->value_high), ':');

        $number = match (true) {
            $showHigh && $high !== null => $high * 1.15,
            $low !== null && $high !== null => ($low + $high) / 2,
            $high !== null => $high * 0.8,
            $low !== null => $low * 1.2,
            default => 1,
        };

        if ($isTime) {
            $seconds = (int) round($number);

            return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
        }

        return $this->trimZeros(number_format($number, 2, '.', ''));
    }

    /**
     * Whether a numeric field has an upper bound that a sample can exceed.
     */
    private function hasNumericHigh(LabField $field): bool
    {
        return $field->ranges->contains(fn (LabFieldRange $range) => $this->toComparable($range->value_high) !== null);
    }
}
