<?php

use App\Enums\LabFieldType;
use App\Enums\OutgoingSampleStatus;
use App\Models\LabField;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Services\LabReportBuilder;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Lab Case')] class extends Component
{
    public LabInvoice $labInvoice;

    public bool $showResultsModal = false;

    public ?int $editingItemId = null;

    /** @var array<int, string> Entered values keyed by lab field id. */
    public array $resultValues = [];

    public string $resultComment = '';

    /**
     * Load everything the case page shows up front.
     */
    public function mount(): void
    {
        $this->labInvoice->load(['patient.family', 'referredByDoctor']);
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
            ->with(['labTest.fields', 'results', 'resultsCompletedByUser'])
            ->get()
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
        if ($item->is_in_house) {
            if ($item->isDone()) {
                return ['label' => __('Results complete'), 'color' => 'green'];
            }

            return $item->results->isNotEmpty()
                ? ['label' => __('Results pending'), 'color' => 'sky']
                : ['label' => __('Awaiting results'), 'color' => 'amber'];
        }

        if (filled($item->report_path)) {
            return ['label' => __('Report uploaded'), 'color' => 'green'];
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
     * Whether results can be entered for a test: the user may manage results,
     * and the test is in-house with fields set up.
     */
    public function canEnterResults(LabInvoiceItem $item): bool
    {
        return $this->canManageResults && $item->is_in_house && ($item->labTest?->fields->isNotEmpty() ?? false);
    }

    /**
     * Open the results modal for one of this case's in-house tests.
     */
    public function openResults(int $itemId): void
    {
        abort_unless($this->canManageResults, 403);

        $this->editingItemId = $itemId;
        unset($this->editingItem);

        $item = $this->editingItem;

        if (! $item || ! $item->is_in_house || $item->labTest === null || $item->labTest->fields->isEmpty()) {
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
    public function updatedResultValues(mixed $value, string $fieldId): void
    {
        $field = $this->editingItem?->labTest?->fields->firstWhere('id', (int) $fieldId);

        if ($field && is_string($value)) {
            $this->resultValues[$field->id] = $this->normalizeResultValue($field, $value);
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
            ]);
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

        if (! $item || ! $item->is_in_house) {
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
                @if ($items->contains(fn ($item) => $item->is_in_house && $item->isDone()))
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
            <flux:heading level="2" class="mb-4">{{ __('Tests') }}</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Test') }}</flux:table.column>
                    <flux:table.column>{{ __('Sample') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Results') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($items as $item)
                        @php($status = $this->itemStatus($item))
                        <flux:table.row wire:key="case-item-{{ $item->id }}">
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
                            </flux:table.cell>
                            <flux:table.cell>{{ $item->sample ?: '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$status['color']">{{ $status['label'] }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="text-right">
                                @php($showReport = $item->is_in_house && $item->isDone())
                                <div class="flex items-center justify-end gap-2">
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

                <flux:textarea wire:model="resultComment" :label="__('Comment')" rows="2" placeholder="{{ __('Optional, printed under this test.') }}" />

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
</div>
