<?php

namespace App\Services;

use App\Enums\LabFieldType;
use App\Models\LabField;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports results from the old lab software's database dump into HMS lab results.
 *
 * Only adds data and only when every match is certain:
 *  - case:  old patient receipt_no === HMS invoice number, names closely match, sex matches (when HMS has it);
 *  - test:  in-house HMS test with no HMS results yet ↔ exactly one old test with the same code
 *           on that patient, marked "result added";
 *  - field: HMS field ↔ old field with the same (normalised) name on that old test; a field that
 *           could match more than one way is skipped.
 * Imported tests are tagged with `results_imported_at` so the import can be rolled back exactly.
 *
 * @phpstan-type PlannedItem array{item_id: int, invoice_number: string, patient: string, test: string, completed_at: CarbonImmutable, values: array<int, string>, notes: list<string>}
 * @phpstan-type Plan array{items: list<PlannedItem>, skipped: array<string, list<string>>, translated: array<string, int>, kept_as_written: array<string, int>, dropped_values: array<string, int>}
 */
class OldLabResultsImporter
{
    public const MIN_NAME_SIMILARITY = 70;

    /**
     * HMS field name → old field name, both normalised, where the names differ.
     *
     * @var array<string, string>
     */
    private const FIELD_ALIASES = [
        'triglycerides' => 'triglyciride',
        'hdlcholesterol' => 'hdlcholestrol',
        'ldlcholesterol' => 'ldl',
        'vldlcholesterol' => 'vldl',
        'cholesterolhdlratio' => 'cholesterolhdl',
        'alkalinephosphatase' => 'alkalinephosphate',
        'urea' => 'ureaserum',
        'bloodsugarfasting' => 'bloodsugerfasting',
        'bloodsugarrandom' => 'bloodsugerrandom',
        'donorname' => 'donnername',
    ];

    /**
     * Old value → HMS option, per HMS field (normalised name). Old values are compared lower-cased.
     *
     * @var array<string, array<string, string>>
     */
    private const VALUE_TRANSLATIONS = [
        'hbsag' => ['negative' => 'Non-Reactive', 'positive' => 'Reactive'],
        'antihcv' => ['negative' => 'Non-Reactive', 'positive' => 'Reactive'],
        'hiv' => ['negative' => 'Non-Reactive', 'positive' => 'Reactive'],
        'vdrl' => ['negative' => 'Non-Reactive', 'positive' => 'Reactive'],
        'malariaparasite' => ['negative' => 'Not Seen', 'positive' => 'Seen'],
        'nitrite' => ['nil' => 'Negative'],
        'urobilinogen' => ['nil' => 'Normal'],
        'turbidity' => ['nil' => 'Clear', '+' => 'Slightly Turbid', '++' => 'Turbid'],
        'bacteria' => ['+' => 'Few', '++' => 'Moderate', '+++' => 'Many'],
        'color' => ['yrllow' => 'Yellow'],
        'crossmatchresult' => ['compatible' => 'Compatible', 'compatibal' => 'Compatible', 'incompatible' => 'Incompatible'],
    ];

    /**
     * Old values that mean "nothing entered".
     *
     * @var list<string>
     */
    private const EMPTY_VALUES = ['', '-', '--', '---', '.'];

    /**
     * The old tables the importer reads.
     *
     * @var list<string>
     */
    private const TABLES = ['patients', 'patient_test', 'tests', 'test_fields', 'test_test_field', 'test_results'];

    /**
     * Parse the old lab software's SQL dump into rows per table (values trimmed).
     *
     * @return array<string, list<array<string, ?string>>>
     */
    public function parseDump(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Cannot read dump file: {$path}");
        }

        $sql = file_get_contents($path);
        $tables = array_fill_keys(self::TABLES, []);

        preg_match_all('/INSERT INTO `([a-z_]+)` \(([^)]*)\) VALUES\s*/', $sql, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $i => $match) {
            $table = $matches[1][$i][0];

            if (! array_key_exists($table, $tables)) {
                continue;
            }

            $columns = array_map(fn (string $column) => trim($column, " `\t\n\r"), explode(',', $matches[2][$i][0]));
            $position = $match[1] + strlen($match[0]);

            foreach ($this->parseTuples($sql, $position) as $row) {
                if (count($row) === count($columns)) {
                    $tables[$table][] = array_combine($columns, $row);
                }
            }
        }

        return $tables;
    }

    /**
     * Work out exactly what would be imported, without writing anything.
     *
     * @param  array<string, list<array<string, ?string>>>  $old
     * @param  CarbonImmutable|null  $since  Only HMS lab cases created on or after this moment.
     * @return Plan
     */
    public function plan(array $old, ?CarbonImmutable $since = null): array
    {
        $plan = ['items' => [], 'skipped' => [], 'translated' => [], 'kept_as_written' => [], 'dropped_values' => []];

        $oldPatientsByReceipt = [];
        foreach ($old['patients'] as $patient) {
            $oldPatientsByReceipt[(string) $patient['receipt_no']][] = $patient;
        }

        $oldTestCodes = [];
        foreach ($old['tests'] as $test) {
            $oldTestCodes[$test['id']] = (string) $test['code'];
        }

        $oldPatientTests = [];
        foreach ($old['patient_test'] as $patientTest) {
            $oldPatientTests[$patientTest['patient_id']][] = $patientTest;
        }

        $oldResults = [];
        foreach ($old['test_results'] as $result) {
            $oldResults[$result['patient_test_id']][$result['test_field_id']] = (string) $result['result'];
        }

        $oldFieldNames = [];
        foreach ($old['test_fields'] as $field) {
            $oldFieldNames[$field['id']] = $this->normaliseName((string) $field['field_name']);
        }

        $oldFieldsByTest = [];
        foreach ($old['test_test_field'] as $link) {
            $oldFieldsByTest[$link['test_id']][] = $link['test_field_id'];
        }

        $invoices = LabInvoice::query()
            ->with(['patient', 'items.labTest.fields', 'items.results'])
            ->where('status', '!=', 'returned')
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
            ->orderBy('id')
            ->get();

        foreach ($invoices as $invoice) {
            $inHouseItems = $invoice->items->filter(fn (LabInvoiceItem $item) => $item->is_in_house);

            if ($inHouseItems->isEmpty()) {
                continue;
            }

            $label = "{$invoice->invoice_number} {$invoice->patient?->name}";
            $oldPatient = $this->matchPatient($invoice, $oldPatientsByReceipt[(string) $invoice->invoice_number] ?? [], $plan, $label);

            if ($oldPatient === null) {
                continue;
            }

            foreach ($inHouseItems as $item) {
                $itemLabel = $label.' / '.trim((string) $item->test_name);

                if ($item->results_completed_at !== null || $item->results->isNotEmpty()) {
                    $this->skip($plan, 'already has HMS results', $itemLabel);

                    continue;
                }

                if ($item->labTest === null || $item->labTest->fields->isEmpty()) {
                    $this->skip($plan, 'HMS test has no fields', $itemLabel);

                    continue;
                }

                $code = trim((string) $item->test_code);

                if ($code === '') {
                    $this->skip($plan, 'HMS test has no code', $itemLabel);

                    continue;
                }

                $candidates = array_values(array_filter(
                    $oldPatientTests[$oldPatient['id']] ?? [],
                    fn (array $patientTest) => ($oldTestCodes[$patientTest['test_id']] ?? null) === $code,
                ));

                if ($candidates === []) {
                    $this->skip($plan, 'test not found in old software', $itemLabel);

                    continue;
                }

                if (count($candidates) > 1) {
                    $this->skip($plan, 'old software has this test more than once for the patient', $itemLabel);

                    continue;
                }

                $patientTest = $candidates[0];

                if ((string) $patientTest['isResultAdded'] !== '1' || empty($oldResults[$patientTest['id']])) {
                    $this->skip($plan, 'no results entered in old software', $itemLabel);

                    continue;
                }

                $planned = $this->planItemValues(
                    $item,
                    $oldFieldsByTest[$patientTest['test_id']] ?? [],
                    $oldFieldNames,
                    $oldResults[$patientTest['id']],
                    $plan,
                );

                if ($planned['values'] === []) {
                    $this->skip($plan, 'no usable values after matching fields', $itemLabel);

                    continue;
                }

                $plan['items'][] = [
                    'item_id' => $item->id,
                    'invoice_number' => (string) $invoice->invoice_number,
                    'patient' => (string) $invoice->patient?->name,
                    'test' => trim((string) $item->test_name),
                    'completed_at' => $this->oldTimestamp($patientTest['updated_at'] ?? $patientTest['created_at']),
                    'values' => $planned['values'],
                    'notes' => $planned['notes'],
                ];
            }
        }

        return $plan;
    }

    /**
     * Write a plan's results. Re-checks each test inside the transaction so nothing
     * entered in the HMS meanwhile is ever overwritten.
     *
     * @param  Plan  $plan
     */
    public function apply(array $plan): int
    {
        return DB::transaction(function () use ($plan) {
            $imported = 0;
            $now = now();

            foreach ($plan['items'] as $planned) {
                $item = LabInvoiceItem::query()->lockForUpdate()->find($planned['item_id']);

                if ($item === null || $item->results_completed_at !== null || $item->results()->exists()) {
                    continue;
                }

                foreach ($planned['values'] as $fieldId => $value) {
                    $item->results()->create([
                        'lab_field_id' => $fieldId,
                        'value' => $value,
                        'entered_by' => null,
                    ]);
                }

                $item->update([
                    'results_completed_at' => $planned['completed_at'],
                    'results_completed_by' => null,
                    'results_imported_at' => $now,
                ]);

                $imported++;
            }

            return $imported;
        });
    }

    /**
     * Count the tests a rollback would touch: imported and not edited in the HMS since.
     */
    public function rollbackCount(): int
    {
        return LabInvoiceItem::query()->whereNotNull('results_imported_at')->count();
    }

    /**
     * Undo the import: remove imported results and completion from tests still marked as imported.
     */
    public function rollback(): int
    {
        return DB::transaction(function () {
            $items = LabInvoiceItem::query()->whereNotNull('results_imported_at')->lockForUpdate()->get();

            foreach ($items as $item) {
                $item->results()->delete();
                $item->update([
                    'results_completed_at' => null,
                    'results_completed_by' => null,
                    'results_imported_at' => null,
                ]);
            }

            return $items->count();
        });
    }

    /**
     * Find the one old patient for an HMS invoice, or record why not.
     *
     * @param  list<array<string, ?string>>  $candidates
     * @param  Plan  $plan
     * @return array<string, ?string>|null
     */
    private function matchPatient(LabInvoice $invoice, array $candidates, array &$plan, string $label): ?array
    {
        if ($candidates === []) {
            $this->skip($plan, 'receipt number not found in old software', $label);

            return null;
        }

        $hmsName = (string) $invoice->patient?->name;
        usort($candidates, fn (array $a, array $b) => $this->nameSimilarity((string) $b['name'], $hmsName) <=> $this->nameSimilarity((string) $a['name'], $hmsName));
        $best = $candidates[0];

        if ($this->nameSimilarity((string) $best['name'], $hmsName) < self::MIN_NAME_SIMILARITY) {
            $this->skip($plan, 'patient name does not match', "{$label} (old: {$best['name']})");

            return null;
        }

        if (count($candidates) > 1 && $this->nameSimilarity((string) $candidates[1]['name'], $hmsName) >= self::MIN_NAME_SIMILARITY) {
            $this->skip($plan, 'more than one old patient matches', $label);

            return null;
        }

        $hmsGender = $invoice->patient?->gender;

        if (in_array($hmsGender, ['male', 'female'], true) && $best['gender'] !== $hmsGender) {
            $this->skip($plan, 'patient sex does not match', "{$label} (HMS {$hmsGender}, old {$best['gender']})");

            return null;
        }

        return $best;
    }

    /**
     * Map one test's old results onto its HMS fields and convert the values.
     *
     * @param  list<string>  $oldFieldIds  Old fields linked to the old test.
     * @param  array<string, string>  $oldFieldNames
     * @param  array<string, string>  $oldValues  Old results keyed by old field id.
     * @param  Plan  $plan
     * @return array{values: array<int, string>, notes: list<string>}
     */
    private function planItemValues(LabInvoiceItem $item, array $oldFieldIds, array $oldFieldNames, array $oldValues, array &$plan): array
    {
        $fields = $item->labTest->fields;
        $matches = [];

        foreach ($fields as $field) {
            $name = $this->normaliseName($field->name);
            $wanted = [$name, self::FIELD_ALIASES[$name] ?? $name];

            $matches[$field->id] = array_values(array_filter(
                $oldFieldIds,
                fn (string $oldFieldId) => in_array($oldFieldNames[$oldFieldId] ?? null, $wanted, true),
            ));
        }

        // An old field claimed by more than one HMS field is ambiguous: skip all of them.
        $claims = array_count_values(array_merge(...array_values($matches ?: [[]])));
        $values = [];
        $notes = [];

        foreach ($fields as $field) {
            $fieldLabel = trim($field->name);
            $candidates = array_values(array_filter($matches[$field->id], fn (string $id) => ($claims[$id] ?? 0) === 1));

            if ($matches[$field->id] !== [] && $candidates === []) {
                $notes[] = "{$fieldLabel}: skipped (ambiguous old field)";

                continue;
            }

            $raw = array_values(array_unique(array_filter(
                array_map(fn (string $id) => $this->cleanValue($oldValues[$id] ?? ''), $candidates),
                fn (string $value) => ! in_array($value, self::EMPTY_VALUES, true),
            )));

            if ($raw === []) {
                continue;
            }

            if (count($raw) > 1) {
                $notes[] = "{$fieldLabel}: skipped (old software has conflicting values)";

                continue;
            }

            $converted = $this->convertValue($field, $raw[0], $plan);

            if ($converted !== null) {
                $values[$field->id] = $converted;
            }
        }

        return ['values' => $values, 'notes' => $notes];
    }

    /**
     * Convert an old value to what the HMS field expects, or null to leave it out.
     *
     * @param  Plan  $plan
     */
    private function convertValue(LabField $field, string $value, array &$plan): ?string
    {
        $fieldLabel = trim($field->name);

        if ($field->type === LabFieldType::Numeric) {
            if (app(LabReportBuilder::class)->isMeasurable($value)) {
                return $value;
            }

            $this->count($plan['dropped_values'], "{$fieldLabel}: '{$value}'");

            return null;
        }

        if ($field->type === LabFieldType::Choice) {
            foreach ($field->options ?? [] as $option) {
                if (strcasecmp($option, $value) === 0) {
                    return $option;
                }
            }

            $translated = self::VALUE_TRANSLATIONS[$this->normaliseName($field->name)][strtolower($value)] ?? null;

            if ($translated !== null && in_array($translated, $field->options ?? [], true)) {
                $this->count($plan['translated'], "{$fieldLabel}: '{$value}' → '{$translated}'");

                return $translated;
            }

            $this->count($plan['kept_as_written'], "{$fieldLabel}: '{$value}'");

            return $value;
        }

        return $value;
    }

    /**
     * Old timestamps are stored in UTC; convert to the app's timezone.
     */
    private function oldTimestamp(?string $value): CarbonImmutable
    {
        return filled($value)
            ? CarbonImmutable::parse($value, 'UTC')->setTimezone(config('app.timezone'))
            : CarbonImmutable::now();
    }

    /**
     * Collapse whitespace in an old value.
     */
    private function cleanValue(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Lower-case a field name and keep only letters and digits.
     */
    private function normaliseName(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower(trim($name)));
    }

    /**
     * Percentage similarity of two person names, ignoring case, spaces and punctuation.
     */
    private function nameSimilarity(string $a, string $b): int
    {
        $a = (string) preg_replace('/[^a-z]/', '', strtolower($a));
        $b = (string) preg_replace('/[^a-z]/', '', strtolower($b));

        if ($a === '' || $b === '') {
            return 0;
        }

        similar_text($a, $b, $percent);

        return (int) round($percent);
    }

    /**
     * Record a skipped case or test under its reason.
     *
     * @param  Plan  $plan
     */
    private function skip(array &$plan, string $reason, string $label): void
    {
        $plan['skipped'][$reason][] = $label;
    }

    /**
     * Increment a tally.
     *
     * @param  array<string, int>  $tally
     */
    private function count(array &$tally, string $key): void
    {
        $tally[$key] = ($tally[$key] ?? 0) + 1;
    }

    /**
     * Parse `(v, v, ...), (...);` tuples starting at a position in the SQL text.
     *
     * @return list<list<?string>>
     */
    private function parseTuples(string $sql, int $position): array
    {
        $length = strlen($sql);
        $rows = [];

        while ($position < $length) {
            while ($position < $length && ctype_space($sql[$position])) {
                $position++;
            }

            if ($position >= $length || $sql[$position] !== '(') {
                break;
            }

            $position++;
            $row = [];
            $value = '';
            $inString = false;
            $quoted = false;

            while ($position < $length) {
                $char = $sql[$position];

                if ($inString) {
                    if ($char === '\\') {
                        $next = $sql[$position + 1] ?? '';
                        $value .= match ($next) {
                            'n' => "\n", 'r' => "\r", 't' => "\t", '0' => "\0",
                            default => $next,
                        };
                        $position += 2;

                        continue;
                    }

                    if ($char === "'") {
                        if (($sql[$position + 1] ?? '') === "'") {
                            $value .= "'";
                            $position += 2;

                            continue;
                        }

                        $inString = false;
                        $position++;

                        continue;
                    }

                    $value .= $char;
                    $position++;

                    continue;
                }

                if ($char === "'") {
                    $inString = true;
                    $quoted = true;
                    $value = '';
                    $position++;

                    continue;
                }

                if ($char === ',' || $char === ')') {
                    $row[] = (! $quoted && strtoupper(trim($value)) === 'NULL') ? null : trim($value);
                    $value = '';
                    $quoted = false;
                    $position++;

                    if ($char === ')') {
                        break;
                    }

                    continue;
                }

                if (! $quoted) {
                    $value .= $char;
                }

                $position++;
            }

            $rows[] = $row;

            while ($position < $length && ctype_space($sql[$position])) {
                $position++;
            }

            if (($sql[$position] ?? '') === ',') {
                $position++;

                continue;
            }

            break;
        }

        return $rows;
    }
}
