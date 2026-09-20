<?php

use App\Enums\LabFieldRangeCategory;
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

    public ?int $fieldIdToAttach = null;

    public string $newFieldName = '';

    public string $newFieldUnit = '';

    /** @var array<int, array{category: string, value_low: ?string, value_high: ?string, age_low: ?string, age_high: ?string}> */
    public array $newFieldRanges = [];

    public bool $showEditModal = false;

    public ?int $editingFieldId = null;

    public string $editFieldName = '';

    public string $editFieldUnit = '';

    /** @var array<int, array{category: string, value_low: ?string, value_high: ?string, age_low: ?string, age_high: ?string}> */
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
        $this->fieldIdToAttach = null;
        $this->creatingNewField = false;
        $this->resetNewFieldForm();
        $this->resetValidation();
        $this->showAttachModal = true;
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
     * Add a blank range row to the new-field form.
     */
    public function addRangeRow(): void
    {
        $this->newFieldRanges[] = [
            'category' => LabFieldRangeCategory::Male->value,
            'value_low' => null,
            'value_high' => null,
            'age_low' => null,
            'age_high' => null,
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
            'fieldIdToAttach' => ['required', 'integer', 'exists:lab_fields,id'],
        ]);

        $nextOrder = ((int) $this->labTest->fields()->max('display_order')) + 1;

        $this->labTest->fields()->attach($validated['fieldIdToAttach'], ['display_order' => $nextOrder]);

        unset($this->fields);
        $this->showAttachModal = false;

        Flux::toast(variant: 'success', text: __('Field attached to test.'));
    }

    /**
     * Create a new reusable field with its ranges and attach it to the test.
     */
    public function createAndAttachField(): void
    {
        $validated = $this->validate([
            'newFieldName' => ['required', 'string', 'max:255', 'unique:lab_fields,name'],
            'newFieldUnit' => ['nullable', 'string', 'max:50'],
            'newFieldRanges' => ['required', 'array', 'min:1'],
            'newFieldRanges.*.category' => ['required', Rule::in(LabFieldRangeCategory::values())],
            'newFieldRanges.*.value_low' => ['nullable', 'numeric'],
            'newFieldRanges.*.value_high' => ['nullable', 'numeric'],
            'newFieldRanges.*.age_low' => ['nullable', 'integer', 'min:0'],
            'newFieldRanges.*.age_high' => ['nullable', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($validated) {
            $field = LabField::create([
                'name' => $validated['newFieldName'],
                'unit' => $validated['newFieldUnit'] ?: null,
            ]);

            foreach ($validated['newFieldRanges'] as $range) {
                $field->ranges()->create($range);
            }

            $nextOrder = ((int) $this->labTest->fields()->max('display_order')) + 1;

            $this->labTest->fields()->attach($field->id, ['display_order' => $nextOrder]);
        });

        unset($this->fields);
        $this->showAttachModal = false;

        Flux::toast(variant: 'success', text: __('Field created and attached to test.'));
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

        $this->editingFieldId = $field->id;
        $this->editFieldName = $field->name;
        $this->editFieldUnit = $field->unit ?? '';
        $this->editFieldRanges = $field->ranges->map(fn (LabFieldRange $range) => [
            'category' => $range->category->value,
            'value_low' => $range->value_low,
            'value_high' => $range->value_high,
            'age_low' => $range->age_low,
            'age_high' => $range->age_high,
        ])->all();

        $this->resetValidation();
        $this->showEditModal = true;
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
            'age_low' => null,
            'age_high' => null,
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
            'editFieldRanges' => ['required', 'array', 'min:1'],
            'editFieldRanges.*.category' => ['required', Rule::in(LabFieldRangeCategory::values())],
            'editFieldRanges.*.value_low' => ['nullable', 'numeric'],
            'editFieldRanges.*.value_high' => ['nullable', 'numeric'],
            'editFieldRanges.*.age_low' => ['nullable', 'integer', 'min:0'],
            'editFieldRanges.*.age_high' => ['nullable', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($validated) {
            $field = LabField::findOrFail($this->editingFieldId);

            $field->update([
                'name' => $validated['editFieldName'],
                'unit' => $validated['editFieldUnit'] ?: null,
            ]);

            $field->ranges()->delete();

            foreach ($validated['editFieldRanges'] as $range) {
                $field->ranges()->create($range);
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
        $this->newFieldRanges = [
            [
                'category' => LabFieldRangeCategory::Male->value,
                'value_low' => null,
                'value_high' => null,
                'age_low' => null,
                'age_high' => null,
            ],
        ];
    }

    /**
     * Format a field's range row for display as a badge.
     */
    public function formatRange(LabFieldRange $range): string
    {
        $bounds = match (true) {
            $range->value_low !== null && $range->value_high !== null => "{$range->value_low}–{$range->value_high}",
            $range->value_low !== null => __('≥ :value', ['value' => $range->value_low]),
            $range->value_high !== null => __('≤ :value', ['value' => $range->value_high]),
            default => __('no range set'),
        };

        $age = match (true) {
            $range->age_low !== null && $range->age_high !== null => __(', age :low–:high', ['low' => $range->age_low, 'high' => $range->age_high]),
            $range->age_low !== null => __(', age :low+', ['low' => $range->age_low]),
            $range->age_high !== null => __(', up to age :high', ['high' => $range->age_high]),
            default => '',
        };

        return $range->category->label().' '.$bounds.$age;
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
                </flux:text>
            </div>

            <flux:button variant="primary" icon="plus" wire:click="openAttachModal">
                {{ __('Add Field') }}
            </flux:button>
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
                                @forelse ($field->ranges as $range)
                                    <flux:badge size="sm">{{ $this->formatRange($range) }}</flux:badge>
                                @empty
                                    <flux:badge size="sm" color="zinc">{{ __('No ranges set') }}</flux:badge>
                                @endforelse
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

    <flux:modal wire:model="showAttachModal" class="w-full max-w-2xl">
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
                    <flux:label>{{ __('Field') }}</flux:label>
                    <x-searchable-select
                        wire:model="fieldIdToAttach"
                        :options="$this->fieldOptions"
                        placeholder="{{ __('Search fields...') }}"
                    />
                    <flux:error name="fieldIdToAttach" />
                </flux:field>

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

                <div>
                    <flux:label>{{ __('Normal Ranges') }}</flux:label>
                    <div class="mt-2 flex flex-col gap-2">
                        @foreach ($newFieldRanges as $index => $range)
                            <div wire:key="new-range-{{ $index }}" class="grid grid-cols-12 items-end gap-2">
                                <div class="col-span-3">
                                    <flux:select wire:model="newFieldRanges.{{ $index }}.category" size="sm">
                                        @foreach ($this->categoryOptions as $option)
                                            <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>
                                <div class="col-span-2">
                                    <flux:input wire:model="newFieldRanges.{{ $index }}.value_low" size="sm" placeholder="{{ __('Low') }}" />
                                </div>
                                <div class="col-span-2">
                                    <flux:input wire:model="newFieldRanges.{{ $index }}.value_high" size="sm" placeholder="{{ __('High') }}" />
                                </div>
                                <div class="col-span-2">
                                    <flux:input wire:model="newFieldRanges.{{ $index }}.age_low" size="sm" placeholder="{{ __('Age low') }}" />
                                </div>
                                <div class="col-span-2">
                                    <flux:input wire:model="newFieldRanges.{{ $index }}.age_high" size="sm" placeholder="{{ __('Age high') }}" />
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

    <flux:modal wire:model="showEditModal" class="w-full max-w-2xl">
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

            <div>
                <flux:label>{{ __('Normal Ranges') }}</flux:label>
                <div class="mt-2 flex flex-col gap-2">
                    @foreach ($editFieldRanges as $index => $range)
                        <div wire:key="edit-range-{{ $index }}" class="grid grid-cols-12 items-end gap-2">
                            <div class="col-span-3">
                                <flux:select wire:model="editFieldRanges.{{ $index }}.category" size="sm">
                                    @foreach ($this->categoryOptions as $option)
                                        <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <div class="col-span-2">
                                <flux:input wire:model="editFieldRanges.{{ $index }}.value_low" size="sm" placeholder="{{ __('Low') }}" />
                            </div>
                            <div class="col-span-2">
                                <flux:input wire:model="editFieldRanges.{{ $index }}.value_high" size="sm" placeholder="{{ __('High') }}" />
                            </div>
                            <div class="col-span-2">
                                <flux:input wire:model="editFieldRanges.{{ $index }}.age_low" size="sm" placeholder="{{ __('Age low') }}" />
                            </div>
                            <div class="col-span-2">
                                <flux:input wire:model="editFieldRanges.{{ $index }}.age_high" size="sm" placeholder="{{ __('Age high') }}" />
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
</div>
