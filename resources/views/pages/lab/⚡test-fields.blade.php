<?php

use App\Enums\LabFieldRangeCategory;
use App\Enums\LabFieldType;
use App\Enums\LabReportLayout;
use App\Models\LabField;
use App\Models\LabFieldRange;
use App\Models\LabTest;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Test Fields')] class extends Component
{
    public LabTest $labTest;

    public bool $showAttachModal = false;

    public bool $creatingNewField = false;

    /** @var array<int, int> */
    public array $fieldIdsToAttach = [];

    public string $newFieldName = '';

    public string $newFieldUnit = '';

    public string $newFieldType = 'numeric';

    public string $newFieldOptions = '';

    public bool $newFieldHasMultipleRanges = false;

    public ?string $newFieldMinValue = null;

    public ?string $newFieldMaxValue = null;

    /** @var array<int, array{category: string, value_low: ?string, value_high: ?string}> */
    public array $newFieldRanges = [];

    public bool $showReportSettingsModal = false;

    public string $reportLayout = '';

    public string $reportNote = '';

    public bool $reportShowRanges = true;

    public string $reportCustomTemplate = '';

    /** @var array<int, string> Section heading per attached field id. */
    public array $fieldSections = [];

    public bool $showEditModal = false;

    public ?int $editingFieldId = null;

    public string $editFieldName = '';

    public string $editFieldUnit = '';

    public string $editFieldType = 'numeric';

    public string $editFieldOptions = '';

    public bool $editFieldHasMultipleRanges = false;

    public ?string $editFieldMinValue = null;

    public ?string $editFieldMaxValue = null;

    /** @var array<int, array{category: string, value_low: ?string, value_high: ?string}> */
    public array $editFieldRanges = [];

    /**
     * Get the fields attached to this test, in display order, with their ranges.
     *
     * @return Collection<int, LabField>
     */
    #[Computed]
    public function fields(): Collection
    {
        return $this->labTest->fields()->with('ranges')->get();
    }

    /**
     * Get active fields not yet attached to this test, for the searchable picker.
     *
     * @return array<int, array{value: int, label: string}>
     */
    #[Computed]
    public function fieldOptions(): array
    {
        $attachedIds = $this->fields->pluck('id');

        return LabField::active()
            ->whereNotIn('id', $attachedIds)
            ->orderBy('name')
            ->get()
            ->map(fn (LabField $field) => [
                'value' => $field->id,
                'label' => $field->unit ? "{$field->name} ({$field->unit})" : $field->name,
            ])
            ->all();
    }

    /**
     * Get the currently selected (not yet attached) fields, for the removable pill list.
     *
     * @return array<int, array{value: int, label: string}>
     */
    #[Computed]
    public function selectedFieldsToAttach(): array
    {
        if ($this->fieldIdsToAttach === []) {
            return [];
        }

        return LabField::whereIn('id', $this->fieldIdsToAttach)
            ->orderBy('name')
            ->get()
            ->map(fn (LabField $field) => [
                'value' => $field->id,
                'label' => $field->unit ? "{$field->name} ({$field->unit})" : $field->name,
            ])
            ->all();
    }

    /**
     * Get the options for the field type select.
     *
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function typeOptions(): array
    {
        return array_map(
            fn (LabFieldType $type) => ['value' => $type->value, 'label' => $type->label()],
            LabFieldType::cases(),
        );
    }

    /**
     * Get the category options for a range row select.
     *
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        return array_map(
            fn (LabFieldRangeCategory $category) => ['value' => $category->value, 'label' => $category->label()],
            LabFieldRangeCategory::cases(),
        );
    }

    /**
     * Open the modal to attach an existing field.
     */
    public function openAttachModal(): void
    {
        $this->fieldIdsToAttach = [];
        $this->creatingNewField = false;
        $this->resetNewFieldForm();
        $this->resetValidation();
        $this->showAttachModal = true;
    }

    /**
     * Remove a field from the pending "attach existing" selection.
     */
    public function removeFieldIdToAttach(int $fieldId): void
    {
        $this->fieldIdsToAttach = array_values(array_diff($this->fieldIdsToAttach, [$fieldId]));
    }

    /**
     * Switch the attach modal to the "new field" form.
     */
    public function showNewFieldForm(): void
    {
        $this->creatingNewField = true;
        $this->resetValidation();
    }

    /**
     * Switch the attach modal back to the "existing field" picker.
     */
    public function showExistingFieldPicker(): void
    {
        $this->creatingNewField = false;
        $this->resetValidation();
    }

    /**
     * Seed a blank range row the first time "has different normal ranges" is checked.
     */
    public function updatedNewFieldHasMultipleRanges(bool $value): void
    {
        if ($value && $this->newFieldRanges === []) {
            $this->addRangeRow();
        }
    }

    /**
     * Add a blank range row to the new-field form.
     */
    public function addRangeRow(): void
    {
        $this->newFieldRanges[] = [
            'category' => LabFieldRangeCategory::Male->value,
            'value_low' => null,
            'value_high' => null,
        ];
    }

    /**
     * Remove a range row from the new-field form.
     */
    public function removeRangeRow(int $index): void
    {
        unset($this->newFieldRanges[$index]);
        $this->newFieldRanges = array_values($this->newFieldRanges);
    }

    /**
     * Attach an existing field to the test.
     */
    public function attachExistingField(): void
    {
        $validated = $this->validate([
            'fieldIdsToAttach' => ['required', 'array', 'min:1'],
            'fieldIdsToAttach.*' => ['integer', 'exists:lab_fields,id'],
        ]);

        $nextOrder = (int) $this->labTest->fields()->max('display_order');

        foreach ($validated['fieldIdsToAttach'] as $fieldId) {
            $nextOrder++;
            $this->labTest->fields()->attach($fieldId, ['display_order' => $nextOrder]);
        }

        unset($this->fields);
        $this->showAttachModal = false;

        Flux::toast(variant: 'success', text: __('Fields attached to test.'));
    }

    /**
     * Create a new reusable field with its ranges and attach it to the test.
     */
    public function createAndAttachField(): void
    {
        $validated = $this->validate([
            'newFieldName' => ['required', 'string', 'max:255', 'unique:lab_fields,name'],
            'newFieldUnit' => ['nullable', 'string', 'max:50'],
            'newFieldType' => ['required', Rule::enum(LabFieldType::class)],
            'newFieldOptions' => ['required_if:newFieldType,choice', 'nullable', 'string', 'max:1000'],
            'newFieldHasMultipleRanges' => ['boolean'],
            'newFieldMinValue' => ['nullable', 'string', 'max:50'],
            'newFieldMaxValue' => ['nullable', 'string', 'max:50'],
            'newFieldRanges' => ['required_if:newFieldHasMultipleRanges,true', 'array'],
            'newFieldRanges.*.category' => ['required_if:newFieldHasMultipleRanges,true', Rule::in(LabFieldRangeCategory::values())],
            'newFieldRanges.*.value_low' => ['nullable', 'string', 'max:50'],
            'newFieldRanges.*.value_high' => ['nullable', 'string', 'max:50'],
        ]);

        $type = LabFieldType::from($validated['newFieldType']);
        $options = $this->parseOptions($validated['newFieldOptions'] ?? '');

        if ($type === LabFieldType::Choice && $options === []) {
            $this->addError('newFieldOptions', __('Enter at least one option.'));

            return;
        }

        DB::transaction(function () use ($validated, $type, $options) {
            $field = LabField::create([
                'name' => $validated['newFieldName'],
                'unit' => $validated['newFieldUnit'] ?: null,
                'type' => $type,
                'options' => $type === LabFieldType::Choice ? $options : null,
            ]);

            if ($type->hasRanges()) {
                $this->saveRanges($field, $validated['newFieldHasMultipleRanges'], $validated['newFieldRanges'] ?? [], $validated['newFieldMinValue'], $validated['newFieldMaxValue']);
            }

            $nextOrder = ((int) $this->labTest->fields()->max('display_order')) + 1;

            $this->labTest->fields()->attach($field->id, ['display_order' => $nextOrder]);
        });

        unset($this->fields);
        $this->showAttachModal = false;

        Flux::toast(variant: 'success', text: __('Field created and attached to test.'));
    }

    /**
     * Split a comma-separated options string into a clean, de-duplicated list.
     *
     * @return list<string>
     */
    private function parseOptions(string $input): array
    {
        return collect(explode(',', $input))
            ->map(fn (string $option) => trim($option))
            ->filter(fn (string $option) => $option !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Persist a field's ranges: either the per-category rows, or a single
     * general range built from the min/max fields.
     *
     * @param  array<int, array{category: string, value_low: ?string, value_high: ?string}>  $ranges
     */
    private function saveRanges(LabField $field, bool $hasMultipleRanges, array $ranges, ?string $minValue, ?string $maxValue): void
    {
        if ($hasMultipleRanges) {
            foreach ($ranges as $range) {
                $field->ranges()->create($range);
            }

            return;
        }

        if (filled($minValue) || filled($maxValue)) {
            $field->ranges()->create([
                'category' => LabFieldRangeCategory::General->value,
                'value_low' => filled($minValue) ? $minValue : null,
                'value_high' => filled($maxValue) ? $maxValue : null,
            ]);
        }
    }

    /**
     * Detach a field from this test (the field itself is not deleted).
     */
    public function detachField(int $fieldId): void
    {
        $this->labTest->fields()->detach($fieldId);

        unset($this->fields);

        Flux::toast(variant: 'success', text: __('Field removed from test.'));
    }

    /**
     * Move a field one position up in the display order.
     */
    public function moveFieldUp(int $fieldId): void
    {
        $this->swapDisplayOrder($fieldId, -1);
    }

    /**
     * Move a field one position down in the display order.
     */
    public function moveFieldDown(int $fieldId): void
    {
        $this->swapDisplayOrder($fieldId, 1);
    }

    /**
     * Swap a field's display order with its neighbour in the given direction.
     */
    private function swapDisplayOrder(int $fieldId, int $direction): void
    {
        $ordered = $this->fields;
        $index = $ordered->search(fn (LabField $field) => $field->id === $fieldId);
        $neighbourIndex = $index + $direction;

        if ($index === false || $neighbourIndex < 0 || $neighbourIndex >= $ordered->count()) {
            return;
        }

        $current = $ordered->get($index);
        $neighbour = $ordered->get($neighbourIndex);

        DB::transaction(function () use ($current, $neighbour) {
            $table = DB::table('lab_test_field')->where('lab_test_id', $this->labTest->id);

            $currentOrder = $current->pivot->display_order;
            $neighbourOrder = $neighbour->pivot->display_order;

            $table->clone()->where('lab_field_id', $current->id)->update(['display_order' => $neighbourOrder]);
            $table->clone()->where('lab_field_id', $neighbour->id)->update(['display_order' => $currentOrder]);
        });

        unset($this->fields);
    }

    /**
     * Open the modal to edit a field's master data and ranges.
     */
    public function openEditFieldModal(int $fieldId): void
    {
        $field = LabField::with('ranges')->findOrFail($fieldId);
        $ranges = $field->ranges;
        $isSimple = $ranges->count() <= 1 && $ranges->every(fn (LabFieldRange $range) => $range->category === LabFieldRangeCategory::General);

        $this->editingFieldId = $field->id;
        $this->editFieldName = $field->name;
        $this->editFieldUnit = $field->unit ?? '';
        $this->editFieldType = $field->type->value;
        $this->editFieldOptions = implode(', ', $field->options ?? []);
        $this->editFieldHasMultipleRanges = ! $isSimple;

        if ($isSimple) {
            $this->editFieldMinValue = $ranges->first()?->value_low;
            $this->editFieldMaxValue = $ranges->first()?->value_high;
            $this->editFieldRanges = [];
        } else {
            $this->editFieldMinValue = null;
            $this->editFieldMaxValue = null;
            $this->editFieldRanges = $ranges->map(fn (LabFieldRange $range) => [
                'category' => $range->category->value,
                'value_low' => $range->value_low,
                'value_high' => $range->value_high,
            ])->all();
        }

        $this->resetValidation();
        $this->showEditModal = true;
    }

    /**
     * Seed a blank range row the first time "has different normal ranges" is checked.
     */
    public function updatedEditFieldHasMultipleRanges(bool $value): void
    {
        if ($value && $this->editFieldRanges === []) {
            $this->addEditRangeRow();
        }
    }

    /**
     * Add a blank range row to the edit-field form.
     */
    public function addEditRangeRow(): void
    {
        $this->editFieldRanges[] = [
            'category' => LabFieldRangeCategory::Male->value,
            'value_low' => null,
            'value_high' => null,
        ];
    }

    /**
     * Remove a range row from the edit-field form.
     */
    public function removeEditRangeRow(int $index): void
    {
        unset($this->editFieldRanges[$index]);
        $this->editFieldRanges = array_values($this->editFieldRanges);
    }

    /**
     * Save changes to the field's master data and ranges. This affects every
     * test that uses this field, not just the one currently being viewed.
     */
    public function updateField(): void
    {
        $validated = $this->validate([
            'editFieldName' => ['required', 'string', 'max:255', Rule::unique('lab_fields', 'name')->ignore($this->editingFieldId)],
            'editFieldUnit' => ['nullable', 'string', 'max:50'],
            'editFieldType' => ['required', Rule::enum(LabFieldType::class)],
            'editFieldOptions' => ['required_if:editFieldType,choice', 'nullable', 'string', 'max:1000'],
            'editFieldHasMultipleRanges' => ['boolean'],
            'editFieldMinValue' => ['nullable', 'string', 'max:50'],
            'editFieldMaxValue' => ['nullable', 'string', 'max:50'],
            'editFieldRanges' => ['required_if:editFieldHasMultipleRanges,true', 'array'],
            'editFieldRanges.*.category' => ['required_if:editFieldHasMultipleRanges,true', Rule::in(LabFieldRangeCategory::values())],
            'editFieldRanges.*.value_low' => ['nullable', 'string', 'max:50'],
            'editFieldRanges.*.value_high' => ['nullable', 'string', 'max:50'],
        ]);

        $type = LabFieldType::from($validated['editFieldType']);
        $options = $this->parseOptions($validated['editFieldOptions'] ?? '');

        if ($type === LabFieldType::Choice && $options === []) {
            $this->addError('editFieldOptions', __('Enter at least one option.'));

            return;
        }

        DB::transaction(function () use ($validated, $type, $options) {
            $field = LabField::findOrFail($this->editingFieldId);

            $field->update([
                'name' => $validated['editFieldName'],
                'unit' => $validated['editFieldUnit'] ?: null,
                'type' => $type,
                'options' => $type === LabFieldType::Choice ? $options : null,
            ]);

            $field->ranges()->delete();

            if ($type->hasRanges()) {
                $this->saveRanges($field, $validated['editFieldHasMultipleRanges'], $validated['editFieldRanges'] ?? [], $validated['editFieldMinValue'], $validated['editFieldMaxValue']);
            }
        });

        unset($this->fields);
        $this->showEditModal = false;

        Flux::toast(variant: 'success', text: __('Field updated everywhere it is used.'));
    }

    /**
     * Reset the "create new field" form.
     */
    private function resetNewFieldForm(): void
    {
        $this->newFieldName = '';
        $this->newFieldUnit = '';
        $this->newFieldType = LabFieldType::Numeric->value;
        $this->newFieldOptions = '';
        $this->newFieldHasMultipleRanges = false;
        $this->newFieldMinValue = null;
        $this->newFieldMaxValue = null;
        $this->newFieldRanges = [];
    }

    /**
     * Get the options for the report layout select, with "Automatic" first.
     *
     * @return array<int, array{value: string, label: string, description: string}>
     */
    #[Computed]
    public function reportLayoutOptions(): array
    {
        return [
            ['value' => '', 'label' => __('Automatic'), 'description' => __('Compact for single-field tests, Table otherwise.')],
            ...array_map(
                fn (LabReportLayout $layout) => ['value' => $layout->value, 'label' => $layout->label(), 'description' => $layout->description()],
                LabReportLayout::cases(),
            ),
        ];
    }

    /**
     * Open the report settings modal, loaded from the test and its field sections.
     */
    public function openReportSettingsModal(): void
    {
        $this->reportLayout = $this->labTest->report_layout?->value ?? '';
        $this->reportNote = $this->labTest->report_note ?? '';
        $this->reportShowRanges = $this->labTest->report_show_ranges;
        $this->reportCustomTemplate = $this->labTest->report_custom_template ?? '';
        $this->fieldSections = $this->fields
            ->mapWithKeys(fn (LabField $field) => [$field->id => $field->pivot->section ?? ''])
            ->all();

        $this->resetValidation();
        $this->showReportSettingsModal = true;
    }

    /**
     * Save how this test is laid out on the printed report.
     */
    public function saveReportSettings(): void
    {
        $validated = $this->validate([
            'reportLayout' => ['nullable', Rule::enum(LabReportLayout::class)],
            'reportNote' => ['nullable', 'string', 'max:2000'],
            'reportShowRanges' => ['boolean'],
            'reportCustomTemplate' => ['required_if:reportLayout,custom', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'fieldSections' => ['array'],
            'fieldSections.*' => ['nullable', 'string', 'max:100'],
        ]);

        $isCustom = $validated['reportLayout'] === LabReportLayout::Custom->value;

        if ($isCustom && ! view()->exists('lab.reports.custom.'.$validated['reportCustomTemplate'])) {
            $this->addError('reportCustomTemplate', __('No template found at resources/views/lab/reports/custom/:name.blade.php', ['name' => $validated['reportCustomTemplate']]));

            return;
        }

        DB::transaction(function () use ($validated, $isCustom) {
            $this->labTest->update([
                'report_layout' => $validated['reportLayout'] ?: null,
                'report_note' => filled($validated['reportNote']) ? trim($validated['reportNote']) : null,
                'report_show_ranges' => $validated['reportShowRanges'],
                'report_custom_template' => $isCustom ? $validated['reportCustomTemplate'] : null,
            ]);

            $attachedIds = $this->fields->pluck('id')->all();

            foreach ($validated['fieldSections'] ?? [] as $fieldId => $section) {
                if (! in_array((int) $fieldId, $attachedIds, true)) {
                    continue;
                }

                $this->labTest->fields()->updateExistingPivot($fieldId, [
                    'section' => filled($section) ? trim($section) : null,
                ]);
            }
        });

        unset($this->fields);
        $this->showReportSettingsModal = false;

        Flux::toast(variant: 'success', text: __('Report settings saved.'));
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('lab.tests')" wire:navigate class="self-start">
            {{ __('Lab Fields') }}
        </flux:button>

        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="1">{{ $labTest->test_name }}</flux:heading>
                <flux:text class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                    {{ __('Code :code · sample :sample · :count fields', [
                        'code' => $labTest->test_code ?: '—',
                        'sample' => $labTest->sample ?: '—',
                        'count' => $this->fields->count(),
                    ]) }}
                    · {{ __('report layout: :layout', ['layout' => $labTest->report_layout ? $labTest->report_layout->label() : __('Automatic')]) }}
                </flux:text>
            </div>

            <div class="flex flex-wrap gap-2">
                <flux:button icon="document-text" wire:click="openReportSettingsModal">
                    {{ __('Report Settings') }}
                </flux:button>
                <flux:button icon="eye" :href="route('lab.tests.report-preview', $labTest)" target="_blank">
                    {{ __('Preview') }}
                </flux:button>
                <flux:button variant="primary" icon="plus" wire:click="openAttachModal">
                    {{ __('Add Field') }}
                </flux:button>
            </div>
        </div>

        @if ($this->fields->isEmpty())
            <flux:card>
                <div class="py-8 text-center text-zinc-500">
                    {{ __('No fields attached to this test yet.') }}
                </div>
            </flux:card>
        @else
            <div class="flex flex-col gap-2">
                @foreach ($this->fields as $index => $field)
                    <flux:card wire:key="field-{{ $field->id }}" class="flex flex-row items-center gap-3 p-3">
                        <div class="flex flex-col gap-0.5">
                            <flux:button
                                size="xs"
                                variant="ghost"
                                icon="chevron-up"
                                wire:click="moveFieldUp({{ $field->id }})"
                                :disabled="$index === 0"
                            />
                            <flux:button
                                size="xs"
                                variant="ghost"
                                icon="chevron-down"
                                wire:click="moveFieldDown({{ $field->id }})"
                                :disabled="$index === $this->fields->count() - 1"
                            />
                        </div>

                        <div class="min-w-0 flex-1">
                            <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">
                                {{ $field->name }}
                                @if ($field->unit)
                                    <span class="font-normal text-zinc-500">({{ $field->unit }})</span>
                                @endif
                            </flux:text>

                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                @if ($field->type === LabFieldType::Numeric)
                                    @forelse ($field->ranges as $range)
                                        <flux:badge size="sm">{{ $range->formatted() }}</flux:badge>
                                    @empty
                                        <flux:badge size="sm" color="zinc">{{ __('No ranges set') }}</flux:badge>
                                    @endforelse
                                @elseif ($field->type === LabFieldType::Choice)
                                    <flux:badge size="sm" color="blue">{{ $field->type->label() }}</flux:badge>
                                    @foreach ($field->options ?? [] as $option)
                                        <flux:badge size="sm" color="zinc">{{ $option }}</flux:badge>
                                    @endforeach
                                @else
                                    <flux:badge size="sm" color="purple">{{ $field->type->label() }}</flux:badge>
                                @endif
                            </div>
                        </div>

                        <div class="flex shrink-0 gap-1">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="openEditFieldModal({{ $field->id }})" />
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="x-mark"
                                wire:click="detachField({{ $field->id }})"
                                wire:confirm="{{ __('Remove this field from the test?') }}"
                            />
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endif
    </div>

    <flux:modal wire:model="showAttachModal" class="w-full max-w-3xl">
        <flux:heading level="2">{{ __('Add Field') }}</flux:heading>

        <div class="mt-4 flex gap-2">
            <flux:button size="sm" :variant="$creatingNewField ? 'ghost' : 'primary'" wire:click="showExistingFieldPicker" class="flex-1">
                {{ __('Existing Field') }}
            </flux:button>
            <flux:button size="sm" :variant="$creatingNewField ? 'primary' : 'ghost'" wire:click="showNewFieldForm" class="flex-1">
                {{ __('New Field') }}
            </flux:button>
        </div>

        @if (! $creatingNewField)
            <form wire:submit="attachExistingField" class="mt-6 space-y-4">
                <flux:field>
                    <flux:label>{{ __('Fields') }}</flux:label>
                    <x-searchable-select
                        wire:model.live="fieldIdsToAttach"
                        :multiple="true"
                        :options="$this->fieldOptions"
                        placeholder="{{ __('Search fields...') }}"
                    />
                    <flux:error name="fieldIdsToAttach" />
                </flux:field>

                @if (count($fieldIdsToAttach))
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($this->selectedFieldsToAttach as $selected)
                            <span class="inline-flex items-center gap-1 rounded-full border border-zinc-200 bg-zinc-50 py-1 ps-2.5 pe-1 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                {{ $selected['label'] }}
                                <button
                                    type="button"
                                    wire:click="removeFieldIdToAttach({{ $selected['value'] }})"
                                    class="rounded-full p-0.5 hover:bg-zinc-200 dark:hover:bg-zinc-600"
                                    aria-label="{{ __('Remove') }}"
                                >
                                    <flux:icon name="x-mark" class="size-3" />
                                </button>
                            </span>
                        @endforeach
                    </div>
                @endif

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="$set('showAttachModal', false)">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button type="submit" variant="primary">
                        {{ __('Attach') }}
                    </flux:button>
                </div>
            </form>
        @else
            <form wire:submit="createAndAttachField" class="mt-6 space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Field Name') }}</flux:label>
                        <flux:input wire:model="newFieldName" placeholder="{{ __('e.g. Hemoglobin') }}" />
                        <flux:error name="newFieldName" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Unit') }}</flux:label>
                        <flux:input wire:model="newFieldUnit" placeholder="{{ __('e.g. g/dL') }}" />
                        <flux:error name="newFieldUnit" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Result Type') }}</flux:label>
                    <flux:select wire:model.live="newFieldType">
                        @foreach ($this->typeOptions as $option)
                            <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="newFieldType" />
                </flux:field>

                @if ($newFieldType === 'choice')
                    <flux:field>
                        <flux:label>{{ __('Options') }}</flux:label>
                        <flux:input wire:model="newFieldOptions" placeholder="{{ __('e.g. Positive, Negative') }}" />
                        <flux:description>{{ __('Separate options with commas.') }}</flux:description>
                        <flux:error name="newFieldOptions" />
                    </flux:field>
                @endif

                @if ($newFieldType === 'numeric' && ! $newFieldHasMultipleRanges)
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Min') }}</flux:label>
                            <flux:input wire:model="newFieldMinValue" placeholder="{{ __('e.g. 12 or 02:00') }}" />
                            <flux:error name="newFieldMinValue" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Max') }}</flux:label>
                            <flux:input wire:model="newFieldMaxValue" placeholder="{{ __('e.g. 16 or 07:00') }}" />
                            <flux:error name="newFieldMaxValue" />
                        </flux:field>
                    </div>
                @endif

                @if ($newFieldType === 'numeric')
                    <flux:checkbox wire:model.live="newFieldHasMultipleRanges" label="{{ __('Has different normal ranges (e.g. by gender)') }}" />
                @endif

                @if ($newFieldType === 'numeric' && $newFieldHasMultipleRanges)
                    <div>
                        <flux:label>{{ __('Normal Ranges') }}</flux:label>
                        <div class="mt-2 flex flex-col gap-2">
                            @foreach ($newFieldRanges as $index => $range)
                                <div wire:key="new-range-{{ $index }}" class="grid grid-cols-12 items-end gap-2">
                                    <div class="col-span-5">
                                        <flux:select wire:model="newFieldRanges.{{ $index }}.category" size="sm">
                                            @foreach ($this->categoryOptions as $option)
                                                <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    </div>
                                    <div class="col-span-3">
                                        <flux:input wire:model="newFieldRanges.{{ $index }}.value_low" size="sm" placeholder="{{ __('Low') }}" />
                                    </div>
                                    <div class="col-span-3">
                                        <flux:input wire:model="newFieldRanges.{{ $index }}.value_high" size="sm" placeholder="{{ __('High') }}" />
                                    </div>
                                    <div class="col-span-1">
                                        <flux:button size="sm" variant="ghost" icon="x-mark" type="button" wire:click="removeRangeRow({{ $index }})" />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <flux:error name="newFieldRanges" />

                        <flux:button size="sm" variant="ghost" icon="plus" type="button" wire:click="addRangeRow" class="mt-2">
                            {{ __('Add Range') }}
                        </flux:button>
                    </div>
                @endif

                <div class="flex justify-end gap-3">
                    <flux:button type="button" variant="ghost" wire:click="$set('showAttachModal', false)">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button type="submit" variant="primary">
                        {{ __('Save Field') }}
                    </flux:button>
                </div>
            </form>
        @endif
    </flux:modal>

    <flux:modal wire:model="showEditModal" class="w-full max-w-3xl">
        <flux:heading level="2">{{ __('Edit Field') }}</flux:heading>
        <flux:text class="mt-1 text-sm text-amber-600 dark:text-amber-400">
            {{ __('Editing this field updates it on every test that uses it.') }}
        </flux:text>

        <form wire:submit="updateField" class="mt-6 space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Field Name') }}</flux:label>
                    <flux:input wire:model="editFieldName" />
                    <flux:error name="editFieldName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Unit') }}</flux:label>
                    <flux:input wire:model="editFieldUnit" />
                    <flux:error name="editFieldUnit" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Result Type') }}</flux:label>
                <flux:select wire:model.live="editFieldType">
                    @foreach ($this->typeOptions as $option)
                        <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="editFieldType" />
            </flux:field>

            @if ($editFieldType === 'choice')
                <flux:field>
                    <flux:label>{{ __('Options') }}</flux:label>
                    <flux:input wire:model="editFieldOptions" placeholder="{{ __('e.g. Positive, Negative') }}" />
                    <flux:description>{{ __('Separate options with commas.') }}</flux:description>
                    <flux:error name="editFieldOptions" />
                </flux:field>
            @endif

            @if ($editFieldType === 'numeric' && ! $editFieldHasMultipleRanges)
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Min') }}</flux:label>
                        <flux:input wire:model="editFieldMinValue" />
                        <flux:error name="editFieldMinValue" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Max') }}</flux:label>
                        <flux:input wire:model="editFieldMaxValue" />
                        <flux:error name="editFieldMaxValue" />
                    </flux:field>
                </div>
            @endif

            @if ($editFieldType === 'numeric')
                <flux:checkbox wire:model.live="editFieldHasMultipleRanges" label="{{ __('Has different normal ranges (e.g. by gender)') }}" />
            @endif

            @if ($editFieldType === 'numeric' && $editFieldHasMultipleRanges)
                <div>
                    <flux:label>{{ __('Normal Ranges') }}</flux:label>
                    <div class="mt-2 flex flex-col gap-2">
                        @foreach ($editFieldRanges as $index => $range)
                            <div wire:key="edit-range-{{ $index }}" class="grid grid-cols-12 items-end gap-2">
                                <div class="col-span-5">
                                    <flux:select wire:model="editFieldRanges.{{ $index }}.category" size="sm">
                                        @foreach ($this->categoryOptions as $option)
                                            <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>
                                <div class="col-span-3">
                                    <flux:input wire:model="editFieldRanges.{{ $index }}.value_low" size="sm" placeholder="{{ __('Low') }}" />
                                </div>
                                <div class="col-span-3">
                                    <flux:input wire:model="editFieldRanges.{{ $index }}.value_high" size="sm" placeholder="{{ __('High') }}" />
                                </div>
                                <div class="col-span-1">
                                    <flux:button size="sm" variant="ghost" icon="x-mark" type="button" wire:click="removeEditRangeRow({{ $index }})" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <flux:error name="editFieldRanges" />

                    <flux:button size="sm" variant="ghost" icon="plus" type="button" wire:click="addEditRangeRow" class="mt-2">
                        {{ __('Add Range') }}
                    </flux:button>
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showEditModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showReportSettingsModal" class="w-full max-w-3xl">
        <flux:heading level="2">{{ __('Report Settings') }}</flux:heading>
        <flux:text class="mt-1 text-sm">
            {{ __('How this test appears on the printed report. Empty results never print.') }}
        </flux:text>

        <form wire:submit="saveReportSettings" class="mt-6 space-y-5">
            <flux:field>
                <flux:label>{{ __('Layout') }}</flux:label>
                <flux:select wire:model.live="reportLayout">
                    @foreach ($this->reportLayoutOptions as $option)
                        <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:description>
                    {{ collect($this->reportLayoutOptions)->firstWhere('value', $reportLayout)['description'] ?? '' }}
                </flux:description>
                <flux:error name="reportLayout" />
            </flux:field>

            @if ($reportLayout === 'custom')
                <flux:field>
                    <flux:label>{{ __('Custom Template Name') }}</flux:label>
                    <flux:input wire:model="reportCustomTemplate" placeholder="{{ __('e.g. semen-analysis') }}" />
                    <flux:description>{{ __('File in resources/views/lab/reports/custom/, without .blade.php.') }}</flux:description>
                    <flux:error name="reportCustomTemplate" />
                </flux:field>
            @endif

            <flux:field>
                <flux:label>{{ __('Default Note') }}</flux:label>
                <flux:textarea wire:model="reportNote" rows="4" placeholder="{{ __('Printed under this test every time, e.g. method or interpretation.') }}" />
                <flux:error name="reportNote" />
            </flux:field>

            <div class="flex flex-col gap-3 sm:flex-row sm:gap-8">
                <flux:checkbox wire:model="reportShowRanges" label="{{ __('Show normal ranges') }}" />
            </div>

            @if ($this->fields->isNotEmpty())
                <div>
                    <flux:label>{{ __('Section Headings') }}</flux:label>
                    <flux:text class="mt-1 text-sm">
                        {{ __('Optional heading printed above a field, e.g. Physical, Chemical, Microscopic. Consecutive fields with the same heading are grouped.') }}
                    </flux:text>
                    <div class="mt-3 flex flex-col gap-2">
                        @foreach ($this->fields as $field)
                            <div wire:key="section-{{ $field->id }}" class="grid grid-cols-2 items-center gap-3">
                                <flux:text class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ $field->name }}</flux:text>
                                <flux:input wire:model="fieldSections.{{ $field->id }}" size="sm" placeholder="{{ __('No heading') }}" />
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showReportSettingsModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
