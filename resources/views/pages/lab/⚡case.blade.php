<?php

use App\Actions\CancelLabTest;
use App\Actions\RequestSampleRetake;
use App\Actions\StorePartnerLabReport;
use App\Enums\LabFieldType;
use App\Enums\OutgoingSampleStatus;
use App\Models\LabField;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\LabSampleRetake;
use App\Models\LabTest;
use App\Services\LabReportBuilder;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Lab Case')] class extends Component
{
    use WithFileUploads;

    public LabInvoice $labInvoice;

    public bool $showUploadModal = false;

    public ?int $uploadItemId = null;

    public ?TemporaryUploadedFile $reportUpload = null;

    public bool $showResultsModal = false;

    public ?int $editingItemId = null;

    /** @var array<int, string> Entered values keyed by lab field id. */
    public array $resultValues = [];

    public string $resultComment = '';

    public bool $showRetakeModal = false;

    public ?int $retakeItemId = null;

    public string $retakeReason = '';

    public string $retakeOtherReason = '';

    public bool $showCancelModal = false;

    public ?int $cancelItemId = null;

    public string $cancelReason = '';

    public string $cancelOtherReason = '';

    public bool $showAddTestModal = false;

    public ?int $addLabTestId = null;

    /**
     * Load everything the case page shows up front.
     */
    public function mount(): void
    {
        $this->labInvoice->load(['patient.family', 'referredByDoctor']);

        $resultsItemId = request()->integer('results');

        if ($resultsItemId > 0 && $this->canManageResults) {
            $this->openResults($resultsItemId);
        }
    }

    /**
     * Get the case's tests: in-house first, then send-out, each in the order they were billed.
     *
     * @return \Illuminate\Support\Collection<int, LabInvoiceItem>
     */
    #[Computed]
    public function items()
    {
        return $this->labInvoice->items()
            ->with(['labTest.fields', 'results', 'resultsCompletedByUser', 'reportUploadedByUser', 'cancelledByUser', 'latestRetake'])
            ->get()
            ->each(fn (LabInvoiceItem $item) => $item->setRelation('labInvoice', $this->labInvoice))
            ->sortBy([['is_in_house', 'desc'], ['id', 'asc']])
            ->values();
    }

    /**
     * Get the test currently open in the results modal, with its fields and ranges.
     */
    #[Computed]
    public function editingItem(): ?LabInvoiceItem
    {
        if ($this->editingItemId === null) {
            return null;
        }

        return $this->labInvoice->items()
            ->with(['labTest.fields.ranges', 'results'])
            ->whereKey($this->editingItemId)
            ->first();
    }

    /**
     * Describe where a test stands, for the status badge.
     *
     * @return array{label: string, color: string}
     */
    public function itemStatus(LabInvoiceItem $item): array
    {
        if ($item->isCancelled()) {
            return ['label' => __('Cancelled: :reason', ['reason' => $item->cancel_reason]), 'color' => 'zinc'];
        }

        if ($item->isLegacy() && $item->results_completed_at === null) {
            return ['label' => __('Done in old lab software'), 'color' => 'zinc'];
        }

        if ($item->is_in_house) {
            if ($item->isDone()) {
                return ['label' => __('Results complete'), 'color' => 'green'];
            }

            if ($item->hasOpenRetake()) {
                return ['label' => __('Retake requested: :reason', ['reason' => $item->latestRetake->reason]), 'color' => 'red'];
            }

            if ($item->sample_received_at === null && $item->results->isEmpty()) {
                return $item->sample_collected_at !== null
                    ? ['label' => __('Collected at ER, not in lab yet'), 'color' => 'sky']
                    : ['label' => __('Sample not received'), 'color' => 'zinc'];
            }

            return $item->results->isNotEmpty()
                ? ['label' => __('Results pending'), 'color' => 'sky']
                : ['label' => __('Awaiting results'), 'color' => 'amber'];
        }

        if ($item->results_completed_at !== null) {
            return ['label' => __('Results complete'), 'color' => 'green'];
        }

        if (filled($item->report_path)) {
            return ['label' => __('Report uploaded'), 'color' => 'green'];
        }

        if ($item->results->isNotEmpty()) {
            return ['label' => __('Results pending'), 'color' => 'sky'];
        }

        $status = $item->outgoing_status ?? OutgoingSampleStatus::Pending;

        return [
            'label' => __('Outsourced: :status', ['status' => $status->label()]),
            'color' => $status === OutgoingSampleStatus::Received ? 'green' : 'purple',
        ];
    }

    /**
     * Whether the current user may add, edit or discard results (the "Lab Results Entry" permission).
     * Without it the page is view, search and print only.
     */
    #[Computed]
    public function canManageResults(): bool
    {
        return auth()->user()?->canAccessRoute('lab.results.entry') ?? false;
    }

    /**
     * Whether the current user may ask for a sample retake (the Sample Receiving permission).
     */
    #[Computed]
    public function canRequestRetake(): bool
    {
        return auth()->user()?->canAccessRoute('lab.samples') ?? false;
    }

    /**
     * Whether a retake can be asked for this test: in-house, not finished, no retake already waiting.
     */
    public function retakeAllowedFor(LabInvoiceItem $item): bool
    {
        return $this->canRequestRetake
            && $item->is_in_house
            && $item->results_completed_at === null
            && ! $item->hasOpenRetake()
            && ! $this->labInvoice->isReturned();
    }

    /**
     * Open the retake form for one of this case's tests.
     */
    public function openRetake(int $itemId): void
    {
        abort_unless($this->canRequestRetake, 403);

        $this->retakeItemId = $itemId;
        $this->retakeReason = $this->items->firstWhere('id', $itemId)?->sample_received_at === null ? 'Sample not received' : '';
        $this->retakeOtherReason = '';
        $this->resetValidation();
        $this->showRetakeModal = true;
    }

    /**
     * Ask reception to call the patient back for a new sample of this test.
     */
    public function requestRetake(): void
    {
        abort_unless($this->canRequestRetake, 403);

        $this->validate([
            'retakeReason' => ['required', 'string', Rule::in([...LabSampleRetake::REASONS, 'other'])],
            'retakeOtherReason' => ['required_if:retakeReason,other', 'nullable', 'string', 'max:255'],
        ], [], [
            'retakeReason' => __('reason'),
            'retakeOtherReason' => __('reason'),
        ]);

        $belongsToCase = $this->labInvoice->items()->whereKey($this->retakeItemId)->exists();

        try {
            if (! $belongsToCase) {
                throw new \InvalidArgumentException(__('A retake cannot be requested for this test.'));
            }

            app(RequestSampleRetake::class)->handle(
                auth()->user(),
                (int) $this->retakeItemId,
                $this->retakeReason === 'other' ? $this->retakeOtherReason : $this->retakeReason,
            );
        } catch (\InvalidArgumentException $exception) {
            $this->showRetakeModal = false;
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        $this->showRetakeModal = false;
        $this->retakeItemId = null;
        unset($this->items);

        Flux::toast(variant: 'success', text: __('Retake requested. Reception will call the patient.'));
    }

    /**
     * Whether a test can be cancelled: the user works in the lab (results entry or sample receiving)
     * and the test is not finished, not cancelled, and its case is not returned.
     */
    public function cancelAllowedFor(LabInvoiceItem $item): bool
    {
        return ($this->canManageResults || $this->canRequestRetake) && $item->canBeCancelled();
    }

    /**
     * Open the cancel form for one of this case's tests.
     */
    public function openCancel(int $itemId): void
    {
        abort_unless($this->canManageResults || $this->canRequestRetake, 403);

        $this->cancelItemId = $itemId;
        $this->cancelReason = $this->items->firstWhere('id', $itemId)?->hasOpenRetake() ? 'Patient declined the retake' : '';
        $this->cancelOtherReason = '';
        $this->resetValidation();
        $this->showCancelModal = true;
    }

    /**
     * Cancel a test the patient no longer wants.
     */
    public function cancelTest(): void
    {
        abort_unless($this->canManageResults || $this->canRequestRetake, 403);

        $this->validate([
            'cancelReason' => ['required', 'string', Rule::in([...LabInvoiceItem::CANCEL_REASONS, 'other'])],
            'cancelOtherReason' => ['required_if:cancelReason,other', 'nullable', 'string', 'max:255'],
        ], [], [
            'cancelReason' => __('reason'),
            'cancelOtherReason' => __('reason'),
        ]);

        $item = $this->items->firstWhere('id', $this->cancelItemId);

        try {
            if ($item === null) {
                throw new \InvalidArgumentException(__('This test cannot be cancelled.'));
            }

            app(CancelLabTest::class)->handle(
                auth()->user(),
                $item,
                $this->cancelReason === 'other' ? $this->cancelOtherReason : $this->cancelReason,
            );
        } catch (\InvalidArgumentException $exception) {
            $this->showCancelModal = false;
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        $this->showCancelModal = false;
        $this->cancelItemId = null;
        unset($this->items);

        Flux::toast(variant: 'success', text: __(':test cancelled.', ['test' => trim($item->test_name)]));
    }

    /**
     * Whether another test can be added to this case (free of charge).
     */
    #[Computed]
    public function canAddTest(): bool
    {
        return $this->canManageResults && ! $this->labInvoice->isReturned();
    }

    /**
     * Active lab tests not already on this case, for the add-test picker.
     *
     * @return list<array{value: int, label: string, keywords: string}>
     */
    #[Computed]
    public function addableLabTestOptions(): array
    {
        $existingTestIds = $this->items->pluck('lab_test_id')->filter()->all();

        return LabTest::query()
            ->active()
            ->whereNotIn('id', $existingTestIds)
            ->orderBy('test_name')
            ->get()
            ->map(fn (LabTest $labTest): array => [
                'value' => $labTest->id,
                'label' => filled($labTest->test_code)
                    ? $labTest->test_name.' ('.$labTest->test_code.')'
                    : $labTest->test_name,
                'keywords' => trim($labTest->test_name.' '.($labTest->test_code ?? '')),
            ])
            ->values()
            ->all();
    }

    /**
     * Open the form for adding another test to this case.
     */
    public function openAddTest(): void
    {
        abort_unless($this->canAddTest, 403);

        $this->addLabTestId = null;
        $this->resetValidation();
        $this->showAddTestModal = true;
    }

    /**
     * Add a test to this case at no charge; the bill and receipt stay as they are.
     */
    public function addTest(): void
    {
        abort_unless($this->canAddTest, 403);

        $this->validate([
            'addLabTestId' => ['required', 'integer', Rule::in(array_column($this->addableLabTestOptions, 'value'))],
        ], [], ['addLabTestId' => __('test')]);

        $labTest = LabTest::findOrFail($this->addLabTestId);

        $this->labInvoice->items()->create([
            'lab_test_id' => $labTest->id,
            'test_name' => $labTest->test_name,
            'test_code' => $labTest->test_code,
            'sample' => $labTest->sample,
            'time_required' => $labTest->time_required,
            'is_in_house' => $labTest->is_in_house,
            'outgoing_status' => $labTest->is_in_house ? null : OutgoingSampleStatus::Pending,
            'price' => 0,
        ]);

        $this->showAddTestModal = false;
        $this->addLabTestId = null;
        unset($this->items, $this->addableLabTestOptions);

        Flux::toast(variant: 'success', text: __(':test added to this case (no charge).', ['test' => trim($labTest->test_name)]));
    }

    /**
     * Whether results can be entered for a test: the user may manage results,
     * and the test has fields set up. Outsourced tests can be typed in from the partner lab's report.
     */
    public function canEnterResults(LabInvoiceItem $item): bool
    {
        return $this->canManageResults && ! $item->isCancelled() && ($item->labTest?->fields->isNotEmpty() ?? false);
    }

    /**
     * Whether the partner lab's report can be uploaded (or replaced) for a test.
     */
    public function canUploadReport(LabInvoiceItem $item): bool
    {
        return $this->canManageResults && ! $item->is_in_house && ! $item->isCancelled() && ! $this->labInvoice->isReturned();
    }

    /**
     * Open the upload form for one of this case's outsourced tests.
     */
    public function openUpload(int $itemId): void
    {
        abort_unless($this->canManageResults, 403);

        $item = $this->labInvoice->items()->find($itemId);

        if (! $item || ! $this->canUploadReport($item)) {
            Flux::toast(variant: 'danger', text: __('A report cannot be uploaded for this test.'));

            return;
        }

        $this->uploadItemId = $item->id;
        $this->reset('reportUpload');
        $this->resetValidation();
        $this->showUploadModal = true;
    }

    /**
     * Store the partner lab's report for the open test and mark its result received.
     * A new upload replaces the previous file.
     */
    public function uploadReport(): void
    {
        abort_unless($this->canManageResults, 403);

        $item = $this->uploadItemId ? $this->labInvoice->items()->find($this->uploadItemId) : null;

        if (! $item || ! $this->canUploadReport($item)) {
            Flux::toast(variant: 'danger', text: __('A report cannot be uploaded for this test.'));

            return;
        }

        $this->validate(['reportUpload' => StorePartnerLabReport::RULES], [], ['reportUpload' => __('report')]);

        app(StorePartnerLabReport::class)->handle(auth()->user(), $item, $this->reportUpload);

        $this->showUploadModal = false;
        $this->uploadItemId = null;
        $this->reset('reportUpload');
        unset($this->items);

        Flux::toast(variant: 'success', text: __('Report uploaded.'));
    }

    /**
     * Delete the uploaded partner lab report for a test.
     */
    public function removeReport(int $itemId): void
    {
        abort_unless($this->canManageResults, 403);

        $item = $this->labInvoice->items()->find($itemId);

        if (! $item || $item->is_in_house || blank($item->report_path)) {
            return;
        }

        app(StorePartnerLabReport::class)->remove($item);
        unset($this->items);

        Flux::toast(variant: 'success', text: __('Report removed.'));
    }

    /**
     * Open the results modal for one of this case's tests.
     */
    public function openResults(int $itemId): void
    {
        abort_unless($this->canManageResults, 403);

        $this->editingItemId = $itemId;
        unset($this->editingItem);

        $item = $this->editingItem;

        if (! $item || $item->labTest === null || $item->labTest->fields->isEmpty()) {
            $this->editingItemId = null;
            Flux::toast(variant: 'danger', text: __('Results cannot be entered for this test.'));

            return;
        }

        $saved = $item->results->pluck('value', 'lab_field_id');

        $this->resultValues = $item->labTest->fields
            ->mapWithKeys(fn (LabField $field) => [$field->id => (string) ($saved[$field->id] ?? '')])
            ->all();
        $this->resultComment = $item->result_comment ?? '';

        $this->resetValidation();
        $this->showResultsModal = true;
    }

    /**
     * Get the patient's normal range for a field, formatted for display.
     */
    public function rangeFor(LabField $field): ?string
    {
        if (! $field->type->hasRanges()) {
            return null;
        }

        $builder = app(LabReportBuilder::class);
        $range = $builder->selectRange($field, $this->labInvoice->patient?->gender, $this->labInvoice->patient?->age);

        return $range ? $builder->formatRangeBounds($range) : null;
    }

    /**
     * Get the high/low flag for the value currently typed into a field.
     */
    public function flagFor(LabField $field): ?string
    {
        $value = trim((string) ($this->resultValues[$field->id] ?? ''));

        if ($value === '' || ! $field->type->hasRanges()) {
            return null;
        }

        $builder = app(LabReportBuilder::class);
        $range = $builder->selectRange($field, $this->labInvoice->patient?->gender, $this->labInvoice->patient?->age);

        return $range ? $builder->flag($value, $range) : null;
    }

    /**
     * Expand typing shortcuts: a lone "n" in a text field means "Nil".
     */
    public function normalizeResultValue(LabField $field, string $value): string
    {
        $value = trim($value);

        if ($field->type === LabFieldType::Text && strtolower($value) === 'n') {
            return 'Nil';
        }

        return $value;
    }

    /**
     * Apply typing shortcuts as soon as a field is left, so the lab sees "Nil" straight away.
     */
    public function updatedResultValues(mixed $value, ?string $fieldId = null): void
    {
        $fields = $this->editingItem?->labTest?->fields;

        if ($fields === null) {
            return;
        }

        // Livewire may send the whole set of values at once instead of one field.
        $changed = $fieldId === null ? (is_array($value) ? $value : []) : [$fieldId => $value];

        foreach ($changed as $id => $fieldValue) {
            $field = $fields->firstWhere('id', (int) $id);

            if ($field && is_string($fieldValue)) {
                $this->resultValues[$field->id] = $this->normalizeResultValue($field, $fieldValue);
            }
        }
    }

    /**
     * Save the entered results. Completing marks the test done in the HMS;
     * saving as pending keeps the values but leaves it awaiting results.
     */
    public function saveResults(bool $complete): void
    {
        abort_unless($this->canManageResults, 403);

        $item = $this->editingItem;

        if (! $item || ! $this->canEnterResults($item)) {
            Flux::toast(variant: 'danger', text: __('Results cannot be entered for this test.'));

            return;
        }

        $fields = $item->labTest->fields;
        $builder = app(LabReportBuilder::class);
        $rules = ['resultComment' => ['nullable', 'string', 'max:2000']];
        $attributes = [];

        foreach ($fields as $field) {
            $key = "resultValues.{$field->id}";
            $attributes[$key] = trim($field->name);
            $rules[$key] = match ($field->type) {
                LabFieldType::Choice => ['nullable', 'string', Rule::in($field->options ?? [])],
                LabFieldType::Numeric => ['nullable', 'string', 'max:50', function (string $attribute, mixed $value, \Closure $fail) use ($builder) {
                    if (filled($value) && ! $builder->isMeasurable(trim((string) $value))) {
                        $fail(__('Enter a number (or a time like 4:30).'));
                    }
                }],
                LabFieldType::Text => ['nullable', 'string', 'max:255'],
            };
        }

        $this->validate($rules, [], $attributes);

        $values = $fields->mapWithKeys(fn (LabField $field) => [
            $field->id => $this->normalizeResultValue($field, (string) ($this->resultValues[$field->id] ?? '')),
        ]);

        if ($complete && $values->filter()->isEmpty()) {
            $this->addError('resultValues', __('Enter at least one result before completing.'));

            return;
        }

        DB::transaction(function () use ($item, $values, $complete) {
            foreach ($values as $fieldId => $value) {
                if ($value === '') {
                    $item->results()->where('lab_field_id', $fieldId)->delete();

                    continue;
                }

                $item->results()->updateOrCreate(
                    ['lab_field_id' => $fieldId],
                    ['value' => $value, 'entered_by' => auth()->id()],
                );
            }

            $item->update([
                'result_comment' => filled($this->resultComment) ? trim($this->resultComment) : null,
                'results_completed_at' => $complete ? now() : null,
                'results_completed_by' => $complete ? auth()->id() : null,
                'results_imported_at' => null,
                ...($item->is_in_house ? [
                    'sample_received_at' => $item->sample_received_at ?? now(),
                    'sample_received_by' => $item->sample_received_at ? $item->sample_received_by : auth()->id(),
                ] : []),
            ]);

            if ($complete) {
                $item->markOutgoingReceived(auth()->id());
            } else {
                $item->reopenOutgoing();
            }
        });

        $this->showResultsModal = false;
        $this->editingItemId = null;
        unset($this->items, $this->editingItem);

        Flux::toast(variant: 'success', text: $complete ? __('Results saved and completed.') : __('Results saved as pending.'));
    }

    /**
     * Throw away everything entered for the open test: its values, comment and
     * completion, putting it back to awaiting results.
     */
    public function discardResults(): void
    {
        abort_unless($this->canManageResults, 403);

        $item = $this->editingItem;

        if (! $item) {
            Flux::toast(variant: 'danger', text: __('Results cannot be discarded for this test.'));

            return;
        }

        DB::transaction(function () use ($item) {
            $item->results()->delete();

            $item->update([
                'result_comment' => null,
                'results_completed_at' => null,
                'results_completed_by' => null,
                'results_imported_at' => null,
            ]);

            $item->reopenOutgoing();
        });

        $this->showResultsModal = false;
        $this->editingItemId = null;
        $this->resultValues = [];
        $this->resultComment = '';
        unset($this->items, $this->editingItem);

        Flux::toast(variant: 'success', text: __('Results discarded.'));
    }
}; ?>

<div>
    @php
        $patient = $labInvoice->patient;
        $items = $this->items;
        $done = $items->filter->isDone()->count();
        $total = $items->count();
    @endphp

    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('lab.cases')" wire:navigate class="self-start">
            {{ __('Lab Cases') }}
        </flux:button>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="1" class="uppercase">{{ $patient?->name ?? __('Unknown patient') }}</flux:heading>
                <flux:text class="mt-1 text-sm">
                    {{ __('Receipt :number · registered :date', ['number' => $labInvoice->invoice_number, 'date' => $labInvoice->created_at->format('d M Y, g:i A')]) }}
                </flux:text>
            </div>

            <div class="flex items-center gap-2">
                <span class="text-sm tabular-nums text-zinc-500">{{ __(':done of :total tests done', ['done' => $done, 'total' => $total]) }}</span>
                @if ($total > 0 && $done === $total)
                    <flux:badge color="green" icon="check-circle">{{ __('Complete') }}</flux:badge>
                @else
                    <flux:badge color="amber" icon="clock">{{ __('Awaiting results') }}</flux:badge>
                @endif
                @if ($items->contains(fn ($item) => $item->results_completed_at !== null))
                    <flux:button size="sm" icon="document-text" :href="route('lab.cases.report', $labInvoice)" target="_blank">
                        {{ __('Show report') }}
                    </flux:button>
                @endif
            </div>
        </div>

        <flux:card>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3 lg:grid-cols-6">
                <div>
                    <dt class="text-zinc-500">{{ __('MR No') }}</dt>
                    <dd class="font-medium">{{ $patient?->mrn ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Age') }}</dt>
                    <dd class="font-medium">{{ $patient?->age !== null ? __(':age years', ['age' => $patient->age]) : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Sex') }}</dt>
                    <dd class="font-medium">{{ $patient?->gender ? ucfirst($patient->gender) : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Phone') }}</dt>
                    <dd class="font-medium">{{ $patient?->contactPhone() ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Referred By') }}</dt>
                    <dd class="font-medium">{{ $labInvoice->referredByDoctor?->name ?? __('Self') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Receipt No') }}</dt>
                    <dd class="font-mono font-medium">{{ $labInvoice->invoice_number }}</dd>
                </div>
            </dl>
        </flux:card>

        <flux:card>
            <div class="mb-4 flex items-center justify-between gap-3">
                <flux:heading level="2">{{ __('Tests') }}</flux:heading>
                @if ($this->canAddTest)
                        <flux:button size="sm" icon="plus" wire:click="openAddTest">{{ __('Add test') }}</flux:button>
                    @endif
            </div>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Test') }}</flux:table.column>
                    <flux:table.column>{{ __('Sample') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Results') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($items as $item)
                        @php
                            $status = $this->itemStatus($item);
                            $rowAction = match (true) {
                                $this->canEnterResults($item) => '$wire.openResults('.$item->id.')',
                                $item->results_completed_at !== null => "window.open('".route('lab.cases.report', ['labInvoice' => $labInvoice, 'item' => $item->id])."', '_blank')",
                                default => null,
                            };
                        @endphp
                        <flux:table.row
                            wire:key="case-item-{{ $item->id }}"
                            :class="$rowAction ? 'cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50' : ''"
                            x-data
                            x-on:click="{{ $rowAction ? 'if (! $event.target.closest(\'a, button\')) '.$rowAction : '' }}"
                        >
                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ trim($item->test_name) }}</span>
                                    <flux:badge size="sm" :color="$item->is_in_house ? 'teal' : 'purple'">
                                        {{ $item->is_in_house ? __('In-house') : __('Outsourced') }}
                                    </flux:badge>
                                </div>
                                @if (filled($item->labTest?->display_name))
                                    <div class="text-xs text-zinc-500">{{ $item->labTest->display_name }}</div>
                                @endif
                                @if ($item->results_completed_at)
                                    <div class="text-xs text-zinc-500">
                                        @if ($item->results_imported_at)
                                            {{ __('Completed :date · imported from old lab software', ['date' => $item->results_completed_at->format('d M, g:i A')]) }}
                                        @else
                                            {{ __('Completed :date by :name', ['date' => $item->results_completed_at->format('d M, g:i A'), 'name' => $item->resultsCompletedByUser?->name ?? __('unknown')]) }}
                                        @endif
                                    </div>
                                @endif
                                @if ($item->cancelled_at)
                                    <div class="text-xs text-zinc-500">
                                        {{ __('Cancelled :date by :name', ['date' => $item->cancelled_at->format('d M, g:i A'), 'name' => $item->cancelledByUser?->name ?? __('unknown')]) }}
                                    </div>
                                @endif
                                @if ($item->report_uploaded_at)
                                    <div class="text-xs text-zinc-500">
                                        {{ __('Partner report uploaded :date by :name', ['date' => $item->report_uploaded_at->format('d M, g:i A'), 'name' => $item->reportUploadedByUser?->name ?? __('unknown')]) }}
                                    </div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $item->sample ?: '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$status['color']">{{ $status['label'] }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="text-right">
                                @php($showReport = $item->results_completed_at !== null)
                                @php($hasFile = ! $item->is_in_house && filled($item->report_path))
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    @if ($hasFile)
                                        <flux:button.group>
                                            <flux:button
                                                size="sm"
                                                :variant="$showReport ? 'filled' : 'primary'"
                                                icon="paper-clip"
                                                :href="route('lab.cases.report-file', ['labInvoice' => $labInvoice, 'item' => $item])"
                                                target="_blank"
                                            >
                                                {{ __('Partner report') }}
                                            </flux:button>
                                            @if ($this->canManageResults)
                                                <flux:button
                                                    size="sm"
                                                    icon="trash"
                                                    :tooltip="__('Remove uploaded report')"
                                                    wire:click="removeReport({{ $item->id }})"
                                                    wire:confirm="{{ __('Remove the uploaded report for this test?') }}"
                                                />
                                            @endif
                                        </flux:button.group>
                                    @endif

                                    @if ($showReport)
                                        <flux:button
                                            size="sm"
                                            variant="primary"
                                            icon="document-text"
                                            :href="route('lab.cases.report', ['labInvoice' => $labInvoice, 'item' => $item->id])"
                                            target="_blank"
                                        >
                                            {{ __('Show report') }}
                                        </flux:button>
                                    @endif

                                    @if ($this->retakeAllowedFor($item))
                                        <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="openRetake({{ $item->id }})">
                                            {{ __('Retake') }}
                                        </flux:button>
                                    @endif

                                    @if ($this->cancelAllowedFor($item))
                                        <flux:button size="sm" variant="ghost" icon="x-circle" wire:click="openCancel({{ $item->id }})">
                                            {{ __('Cancel test') }}
                                        </flux:button>
                                    @endif

                                    @if ($this->canUploadReport($item))
                                        <flux:button
                                            size="sm"
                                            :variant="$hasFile || $showReport ? 'ghost' : 'filled'"
                                            icon="arrow-up-tray"
                                            wire:click="openUpload({{ $item->id }})"
                                        >
                                            {{ $hasFile ? __('Replace report') : __('Upload report') }}
                                        </flux:button>
                                    @endif

                                    @if ($this->canEnterResults($item))
                                        <flux:button
                                            size="sm"
                                            :variant="$item->results->isEmpty() && ! $item->results_completed_at ? 'primary' : 'filled'"
                                            :icon="$item->results->isEmpty() && ! $item->results_completed_at ? 'plus' : 'pencil-square'"
                                            wire:click="openResults({{ $item->id }})"
                                        >
                                            {{ $item->results->isEmpty() && ! $item->results_completed_at ? __('Add results') : __('Edit results') }}
                                        </flux:button>
                                    @elseif ($this->canManageResults && $item->is_in_house)
                                        <span class="text-xs text-zinc-500">{{ __('No fields set up for this test') }}</span>
                                    @elseif (! $showReport)
                                        <span class="text-xs text-zinc-400">—</span>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>

    <flux:modal wire:model="showResultsModal" class="w-full max-w-3xl">
        @if ($item = $this->editingItem)
            <flux:heading level="2">{{ $item->labTest->reportTitle() }}</flux:heading>
            <flux:text class="mt-1 text-sm">
                {{ __('Normal ranges shown for: :sex, :age', [
                    'sex' => $patient?->gender ? ucfirst($patient->gender) : __('sex not set'),
                    'age' => $patient?->age !== null ? __(':age years', ['age' => $patient->age]) : __('age not set'),
                ]) }}
                · {{ __('Empty fields are not printed.') }}
            </flux:text>

            <form
                wire:submit="saveResults(true)"
                class="mt-6 space-y-5"
                x-data="{
                    focusNextResultField(event) {
                        if (! ['INPUT', 'SELECT'].includes(event.target.tagName)) {
                            return;
                        }

                        event.preventDefault();

                        const fields = [...this.$el.querySelectorAll('[data-result-field] input, [data-result-field] select, textarea')];
                        const next = fields[fields.indexOf(event.target) + 1];

                        if (next) {
                            next.focus();
                            next.select?.();
                        }
                    },
                }"
                x-on:keydown.enter="focusNextResultField($event)"
            >
                <div class="flex flex-col gap-2">
                    @php($currentSection = false)
                    @foreach ($item->labTest->fields as $field)
                        @php($section = filled($field->pivot->section) ? trim($field->pivot->section) : null)
                        @if ($section !== $currentSection)
                            @php($currentSection = $section)
                            @if ($section)
                                <div class="mt-3 border-b border-zinc-200 pb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-700">{{ $section }}</div>
                            @endif
                        @endif

                        @php($flag = $this->flagFor($field))
                        <div wire:key="result-field-{{ $field->id }}" class="grid grid-cols-1 items-start gap-2 sm:grid-cols-12 sm:items-center">
                            <div class="sm:col-span-4">
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ trim($field->name) }}</div>
                                @if ($field->unit)
                                    <div class="text-xs text-zinc-500">{{ $field->unit }}</div>
                                @endif
                            </div>

                            <div class="sm:col-span-5" data-result-field>
                                @if ($field->type === LabFieldType::Choice)
                                    <flux:select wire:model="resultValues.{{ $field->id }}" size="sm">
                                        <flux:select.option value="">—</flux:select.option>
                                        @foreach ($field->options ?? [] as $option)
                                            <flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                @elseif ($field->type === LabFieldType::Numeric)
                                    <flux:input wire:model.live.debounce.400ms="resultValues.{{ $field->id }}" size="sm" inputmode="decimal" />
                                @else
                                    <flux:input wire:model.blur="resultValues.{{ $field->id }}" size="sm" placeholder="{{ __('n = Nil') }}" />
                                @endif
                                <flux:error name="resultValues.{{ $field->id }}" />
                            </div>

                            <div class="flex items-center gap-2 text-xs text-zinc-500 sm:col-span-3">
                                @if ($range = $this->rangeFor($field))
                                    <span>{{ $range }}</span>
                                @endif
                                @if ($flag === LabReportBuilder::FLAG_HIGH)
                                    <flux:badge size="sm" color="red">{{ __('High') }}</flux:badge>
                                @elseif ($flag === LabReportBuilder::FLAG_LOW)
                                    <flux:badge size="sm" color="blue">{{ __('Low') }}</flux:badge>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <flux:error name="resultValues" />

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    @if ($item->results->isNotEmpty() || $item->results_completed_at || filled($item->result_comment))
                        <flux:button
                            type="button"
                            variant="danger"
                            icon="trash"
                            wire:click="discardResults"
                            wire:confirm="{{ __('Discard all results for this test? This cannot be undone.') }}"
                            class="sm:me-auto"
                        >
                            {{ __('Discard results') }}
                        </flux:button>
                    @endif
                    <flux:button type="button" variant="ghost" wire:click="$set('showResultsModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button type="button" wire:click="saveResults(false)">{{ __('Save as pending') }}</flux:button>
                    <flux:button type="submit" variant="primary" icon="check">{{ __('Save & complete') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>

    <flux:modal wire:model="showUploadModal" class="w-full max-w-md">
        <flux:heading level="2">{{ __('Upload partner lab report') }}</flux:heading>
        <flux:text class="mt-1 text-sm">{{ __('Attach the PDF or a photo of the report. The test is marked as result received.') }}</flux:text>

        <form wire:submit="uploadReport" class="mt-6 space-y-4">
            <flux:field>
                <flux:input type="file" wire:model="reportUpload" accept="application/pdf,.pdf,image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" />
                <flux:error name="reportUpload" />
                <div wire:loading wire:target="reportUpload" class="mt-1 text-sm text-zinc-500">{{ __('Uploading...') }}</div>
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showUploadModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="uploadReport,reportUpload">{{ __('Upload') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    @if ($this->canAddTest)
        <flux:modal wire:model="showAddTestModal" class="w-full max-w-md">
            <flux:heading level="2">{{ __('Add a test to this case') }}</flux:heading>
            <flux:text class="mt-1 text-sm">{{ __('The test is added free of charge. The bill and receipt are not changed.') }}</flux:text>

            <form wire:submit="addTest" class="mt-6 space-y-4">
                <flux:field>
                    <flux:label>{{ __('Test') }}</flux:label>
                    <x-searchable-select
                        wire:model="addLabTestId"
                        :options="$this->addableLabTestOptions"
                        :placeholder="__('Search by name or code')"
                    />
                    <flux:error name="addLabTestId" />
                </flux:field>

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="$set('showAddTestModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary" icon="plus">{{ __('Add test') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    <flux:modal wire:model="showRetakeModal" class="w-full max-w-md">
        <flux:heading level="2">{{ __('Ask for a new sample') }}</flux:heading>
        <flux:text class="mt-1 text-sm">{{ __('Reception will call the patient back and print a no-charge slip when they come.') }}</flux:text>

        <form wire:submit="requestRetake" class="mt-6 space-y-4">
            <flux:radio.group wire:model.live="retakeReason" :label="__('Why?')">
                @foreach (LabSampleRetake::REASONS as $reason)
                    <flux:radio :value="$reason" :label="__($reason)" />
                @endforeach
                <flux:radio value="other" :label="__('Other')" />
            </flux:radio.group>

            @if ($retakeReason === 'other')
                <flux:input wire:model="retakeOtherReason" :label="__('Reason')" />
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showRetakeModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="danger" icon="arrow-path">{{ __('Ask for retake') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showCancelModal" class="w-full max-w-md">
        <flux:heading level="2">{{ __('Cancel this test') }}</flux:heading>
        <flux:text class="mt-1 text-sm">{{ __('The test leaves the lab and reception queues, including any retake waiting on the patient. The bill is not changed; return the receipt at reception if money is refunded.') }}</flux:text>

        <form wire:submit="cancelTest" class="mt-6 space-y-4">
            <flux:radio.group wire:model.live="cancelReason" :label="__('Why?')">
                @foreach (LabInvoiceItem::CANCEL_REASONS as $reason)
                    <flux:radio :value="$reason" :label="__($reason)" />
                @endforeach
                <flux:radio value="other" :label="__('Other')" />
            </flux:radio.group>

            @if ($cancelReason === 'other')
                <flux:input wire:model="cancelOtherReason" :label="__('Reason')" />
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showCancelModal', false)">{{ __('Keep test') }}</flux:button>
                <flux:button type="submit" variant="danger" icon="x-circle">{{ __('Cancel test') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
