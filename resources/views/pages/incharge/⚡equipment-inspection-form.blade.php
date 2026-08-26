<?php

use App\Enums\EquipmentInspectionArea;
use App\Enums\EquipmentInspectionShift;
use App\Models\EquipmentInspectionEntry;
use App\Models\HealthAide;
use App\Services\EquipmentInspectionChecklistDefinition;
use App\Services\EquipmentInspectionService;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Fill Equipment Inspection')] class extends Component
{
    #[Locked]
    public string $areaValue;

    #[Locked]
    public string $shiftValue;

    public string $checklistDate = '';

    public string $activeSection = 'header';

    public string $healthAideCode = '';

    public string $supervisorName = '';

    /**
     * @var array<string, array{present: bool|null, functional: bool|null, clean: bool|null, maint_req: bool|null, remarks: string}>
     */
    public array $equipment = [];

    /**
     * @var array<string, bool|null>
     */
    public array $checklist = [];

    /**
     * @var array<string, string>
     */
    public array $signOff = [];

    /**
     * @var list<array{item_date: string, department: string, equipment: string, problem: string, action_taken: string, technician: string, completed_date: string, signed: string}>
     */
    public array $registerRows = [];

    public function mount(string $area, string $shift, ?string $date = null): void
    {
        $areaEnum = EquipmentInspectionArea::tryFrom($area);
        $shiftEnum = EquipmentInspectionShift::tryFrom($shift);
        abort_unless($areaEnum !== null && $shiftEnum !== null, 404);

        $this->areaValue = $areaEnum->value;
        $this->shiftValue = $shiftEnum->value;
        $this->checklistDate = $date ?: now()->format('Y-m-d');

        $service = app(EquipmentInspectionService::class);

        if ($this->existingEntry !== null) {
            return;
        }

        $this->equipment = $service->emptyEquipmentMap($areaEnum);
        $this->checklist = $service->emptyChecklistMap($areaEnum);
        $this->signOff = $service->emptySignOffMap($areaEnum);
        $this->registerRows = [
            $this->emptyRegisterRow(),
            $this->emptyRegisterRow(),
            $this->emptyRegisterRow(),
        ];
    }

    /**
     * @return array{item_date: string, department: string, equipment: string, problem: string, action_taken: string, technician: string, completed_date: string, signed: string}
     */
    private function emptyRegisterRow(): array
    {
        return [
            'item_date' => '',
            'department' => '',
            'equipment' => '',
            'problem' => '',
            'action_taken' => '',
            'technician' => '',
            'completed_date' => '',
            'signed' => '',
        ];
    }

    #[Computed]
    public function area(): EquipmentInspectionArea
    {
        return EquipmentInspectionArea::from($this->areaValue);
    }

    #[Computed]
    public function shift(): EquipmentInspectionShift
    {
        return EquipmentInspectionShift::from($this->shiftValue);
    }

    #[Computed]
    public function definition(): EquipmentInspectionChecklistDefinition
    {
        return app(EquipmentInspectionChecklistDefinition::class);
    }

    #[Computed]
    public function existingEntry(): ?EquipmentInspectionEntry
    {
        return app(EquipmentInspectionService::class)->findEntry(
            $this->area,
            Carbon::parse($this->checklistDate),
            $this->shift
        )?->load(['answers', 'registerRows', 'user', 'healthAide']);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function sections(): array
    {
        return $this->definition->sections($this->area);
    }

    public function setSection(string $section): void
    {
        $this->activeSection = $section;
    }

    public function markEquipmentOk(): void
    {
        foreach (array_keys($this->equipment) as $itemKey) {
            $this->equipment[$itemKey]['present'] = true;
            $this->equipment[$itemKey]['functional'] = true;
            $this->equipment[$itemKey]['clean'] = true;
            $this->equipment[$itemKey]['maint_req'] = false;
        }

        Flux::toast(variant: 'success', text: __('Equipment section marked OK.'));
    }

    public function markChecklistDone(string $section): void
    {
        $service = app(EquipmentInspectionService::class);

        foreach ($this->definition->checklistItems($this->area, $section) as $itemKey => $label) {
            $this->checklist[$service->checklistKey($section, $itemKey)] = true;
        }

        Flux::toast(variant: 'success', text: __('Section marked done.'));
    }

    public function addRegisterRow(): void
    {
        $this->registerRows[] = $this->emptyRegisterRow();
    }

    public function removeRegisterRow(int $index): void
    {
        unset($this->registerRows[$index]);
        $this->registerRows = array_values($this->registerRows);

        if ($this->registerRows === []) {
            $this->registerRows = [$this->emptyRegisterRow()];
        }
    }

    public function submit(): void
    {
        if ($this->existingEntry !== null) {
            Flux::toast(variant: 'danger', text: __('This shift checklist has already been submitted.'));

            return;
        }

        $service = app(EquipmentInspectionService::class);
        $rules = [
            'healthAideCode' => ['required', 'digits_between:4,6'],
            'supervisorName' => ['nullable', 'string', 'max:255'],
        ];
        $messages = [
            'healthAideCode.required' => __('Enter the health aide code.'),
            'healthAideCode.digits_between' => __('The health aide code must be 4 to 6 digits.'),
        ];

        foreach ($this->definition->equipmentItems($this->area) as $itemKey => $item) {
            $rules["equipment.{$itemKey}.present"] = ['required', 'boolean'];
            $rules["equipment.{$itemKey}.functional"] = ['required', 'boolean'];
            $rules["equipment.{$itemKey}.clean"] = ['required', 'boolean'];
            $rules["equipment.{$itemKey}.maint_req"] = ['required', 'boolean'];
            $rules["equipment.{$itemKey}.remarks"] = ['nullable', 'string', 'max:2000'];
            $messages["equipment.{$itemKey}.present.required"] = __('Please answer Present for: :item', ['item' => $item['label']]);
            $messages["equipment.{$itemKey}.functional.required"] = __('Please answer Functional for: :item', ['item' => $item['label']]);
            $messages["equipment.{$itemKey}.clean.required"] = __('Please answer Clean for: :item', ['item' => $item['label']]);
            $messages["equipment.{$itemKey}.maint_req.required"] = __('Please answer Maint. Req. for: :item', ['item' => $item['label']]);
        }

        foreach ($this->definition->checklistSections($this->area) as $section) {
            foreach ($this->definition->checklistItems($this->area, $section) as $itemKey => $label) {
                $key = $service->checklistKey($section, $itemKey);
                $rules["checklist.{$key}"] = ['required', 'boolean'];
                $messages["checklist.{$key}.required"] = __('Please answer: :item', ['item' => $label]);
            }
        }

        foreach ($this->definition->signOffFields($this->area) as $fieldKey => $field) {
            if ($field['type'] === 'yes_no') {
                $rules["signOff.{$fieldKey}"] = $field['required']
                    ? ['required', Rule::in(['yes', 'no'])]
                    : ['nullable', Rule::in(['yes', 'no', ''])];
            } elseif ($field['type'] === 'choice') {
                $rules["signOff.{$fieldKey}"] = $field['required']
                    ? ['required', Rule::in($field['choices'])]
                    : ['nullable', Rule::in([...$field['choices'], ''])];
            } else {
                $rules["signOff.{$fieldKey}"] = $field['required']
                    ? ['required', 'string', 'max:255']
                    : ['nullable', 'string', 'max:255'];
            }
        }

        if ($this->area->isRegister()) {
            foreach ($this->registerRows as $index => $row) {
                if ($service->registerRowIsEmpty($row)) {
                    continue;
                }

                $rules["registerRows.{$index}.equipment"] = ['required', 'string', 'max:255'];
                $rules["registerRows.{$index}.problem"] = ['required', 'string', 'max:2000'];
                $rules["registerRows.{$index}.item_date"] = ['nullable', 'date'];
                $rules["registerRows.{$index}.department"] = ['nullable', 'string', 'max:255'];
                $rules["registerRows.{$index}.action_taken"] = ['nullable', 'string', 'max:2000'];
                $rules["registerRows.{$index}.technician"] = ['nullable', 'string', 'max:255'];
                $rules["registerRows.{$index}.completed_date"] = ['nullable', 'date'];
                $rules["registerRows.{$index}.signed"] = ['nullable', 'string', 'max:255'];
            }
        }

        $validated = $this->validate($rules, $messages);

        $aide = HealthAide::findByPin($validated['healthAideCode']);

        if ($aide === null) {
            $this->addError('healthAideCode', __('Invalid health aide code.'));

            return;
        }

        $service->submit(
            auth()->user(),
            $this->area,
            Carbon::parse($this->checklistDate),
            $this->shift,
            [
                'health_aide_id' => $aide->id,
                'checked_by_name' => $aide->name,
                'supervisor_name' => $validated['supervisorName'] ?? '',
            ],
            $validated['equipment'] ?? [],
            $validated['checklist'] ?? [],
            $validated['signOff'] ?? [],
            $validated['registerRows'] ?? $this->registerRows,
        );

        Flux::toast(variant: 'success', text: __('Equipment inspection submitted.'));
        unset($this->existingEntry);
        $this->redirect(route('incharge.equipment-inspections.area', [
            'area' => $this->areaValue,
            'selectedDate' => $this->checklistDate,
        ]), navigate: true);
    }

    /**
     * @return array{answered: int, total: int}
     */
    public function sectionCompletion(string $section): array
    {
        $answered = 0;
        $total = 0;
        $service = app(EquipmentInspectionService::class);

        if ($section === 'header') {
            return [
                'answered' => filled($this->healthAideCode) ? 1 : 0,
                'total' => 1,
            ];
        }

        if ($section === 'A') {
            foreach (array_keys($this->definition->equipmentItems($this->area)) as $itemKey) {
                $total += 4;
                foreach (['present', 'functional', 'clean', 'maint_req'] as $field) {
                    if (($this->equipment[$itemKey][$field] ?? null) !== null) {
                        $answered++;
                    }
                }
            }

            return compact('answered', 'total');
        }

        if (in_array($section, $this->definition->checklistSections($this->area), true)) {
            foreach ($this->definition->checklistItems($this->area, $section) as $itemKey => $label) {
                $key = $service->checklistKey($section, $itemKey);
                $total++;
                if (($this->checklist[$key] ?? null) !== null) {
                    $answered++;
                }
            }

            return compact('answered', 'total');
        }

        if ($section === 'signoff') {
            foreach ($this->definition->signOffFields($this->area) as $fieldKey => $field) {
                if (! $field['required']) {
                    continue;
                }
                $total++;
                if (filled($this->signOff[$fieldKey] ?? null)) {
                    $answered++;
                }
            }

            return compact('answered', 'total');
        }

        if ($section === 'register') {
            $filled = collect($this->registerRows)->contains(
                fn (array $row) => ! $service->registerRowIsEmpty($row)
            );

            return ['answered' => $filled ? 1 : 0, 'total' => 1];
        }

        return ['answered' => 0, 'total' => 0];
    }

    public function isSectionComplete(string $section): bool
    {
        $completion = $this->sectionCompletion($section);

        return $completion['total'] > 0 && $completion['answered'] === $completion['total'];
    }

    /**
     * @return array{answered: int, total: int}
     */
    public function overallProgress(): array
    {
        $answered = 0;
        $total = 0;

        foreach ($this->sections() as $section) {
            $completion = $this->sectionCompletion($section['key']);
            $answered += $completion['answered'];
            $total += $completion['total'];
        }

        return compact('answered', 'total');
    }

    public function checklistKey(string $section, string $itemKey): string
    {
        return app(EquipmentInspectionService::class)->checklistKey($section, $itemKey);
    }

    public function choiceLabel(string $value): string
    {
        return match ($value) {
            'yes' => __('Yes'),
            'no' => __('No'),
            'complete' => __('Complete'),
            'incomplete' => __('Incomplete'),
            'approved' => __('Approved'),
            'not_approved' => __('Not Approved'),
            default => $value,
        };
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="1">{{ $this->area->label() }}</flux:heading>
                <flux:text class="text-zinc-500">
                    {{ __(':shift · :date', [
                        'shift' => $this->shift->label(),
                        'date' => \Illuminate\Support\Carbon::parse($checklistDate)->format('M j, Y'),
                    ]) }}
                </flux:text>
            </div>
            <flux:button
                variant="ghost"
                :href="route('incharge.equipment-inspections.area', ['area' => $areaValue, 'selectedDate' => $checklistDate])"
                wire:navigate
                icon="arrow-left"
            >
                {{ __('Back') }}
            </flux:button>
        </div>

        @if ($this->existingEntry)
            @include('pages.incharge.partials.equipment-inspection-view', ['entry' => $this->existingEntry])
        @else
            @php
                $progress = $this->overallProgress();
            @endphp
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-2 flex items-center justify-between text-sm">
                    <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('Overall progress') }}</span>
                    <span class="font-medium text-zinc-600 dark:text-zinc-300">
                        {{ __(':answered of :total completed', ['answered' => $progress['answered'], 'total' => $progress['total']]) }}
                    </span>
                </div>
                <div class="h-2.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <div
                        class="h-full rounded-full bg-primary transition-all duration-300"
                        style="width: {{ $progress['total'] > 0 ? ($progress['answered'] / $progress['total']) * 100 : 0 }}%"
                        aria-hidden="true"
                    ></div>
                </div>
            </div>

            <div class="sticky top-0 z-10 flex flex-wrap gap-2 rounded-xl border border-zinc-200 bg-zinc-50/80 p-2 backdrop-blur-sm dark:border-zinc-700 dark:bg-zinc-900/80">
                @foreach ($this->sections() as $section)
                    @php
                        $isComplete = $this->isSectionComplete($section['key']);
                    @endphp
                    <flux:button
                        size="sm"
                        :variant="$activeSection === $section['key'] ? 'primary' : ($isComplete ? 'outline' : 'ghost')"
                        wire:click="setSection('{{ $section['key'] }}')"
                        class="{{ $isComplete ? 'border-emerald-300 text-emerald-700 dark:border-emerald-700 dark:text-emerald-300' : '' }}"
                    >
                        <span class="inline-flex items-center gap-1.5">
                            @if ($isComplete)
                                <flux:icon name="check-circle" class="size-3.5 text-emerald-600 dark:text-emerald-400" />
                            @elseif ($this->sectionCompletion($section['key'])['answered'] > 0)
                                <span class="size-2 rounded-full bg-amber-500"></span>
                            @endif
                            {{ $section['label'] }}
                        </span>
                    </flux:button>
                @endforeach
            </div>

            <form wire:submit="submit" class="flex flex-col gap-6">
                @if ($activeSection === 'header')
                    <flux:card>
                        <flux:heading level="2" class="mb-4">{{ __('Checklist Header') }}</flux:heading>
                        <flux:callout icon="information-circle" class="mb-4">
                            <flux:callout.text>
                                {{ __('Columns: Present, Functional, Clean, Maint. Req. Tick Maint. Req. only when corrective maintenance is needed.') }}
                            </flux:callout.text>
                        </flux:callout>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>{{ __('Health Aide Code') }}</flux:label>
                                <flux:input
                                    wire:model="healthAideCode"
                                    type="password"
                                    inputmode="numeric"
                                    autocomplete="one-time-code"
                                    maxlength="6"
                                    required
                                />
                                <flux:description>{{ __('Time is recorded automatically on submit.') }}</flux:description>
                                <flux:error name="healthAideCode" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Supervisor') }}</flux:label>
                                <flux:input wire:model="supervisorName" />
                                <flux:error name="supervisorName" />
                            </flux:field>
                        </div>
                    </flux:card>
                @endif

                @if ($activeSection === 'A' && ! $this->area->isRegister())
                    <flux:card>
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <flux:heading level="2">{{ __('Equipment Audit') }}</flux:heading>
                            <flux:button type="button" size="sm" variant="outline" wire:click="markEquipmentOk">
                                {{ __('Mark all OK') }}
                            </flux:button>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[900px] border-collapse text-sm">
                                <thead>
                                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                        <th class="py-2 pe-3 text-start">{{ __('Equipment / Item') }}</th>
                                        <th class="px-2 py-2 text-center">{{ __('Present') }}</th>
                                        <th class="px-2 py-2 text-center">{{ __('Functional') }}</th>
                                        <th class="px-2 py-2 text-center">{{ __('Clean') }}</th>
                                        <th class="px-2 py-2 text-center">{{ __('Maint. Req.') }}</th>
                                        <th class="py-2 text-start">{{ __('Remarks') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->definition->equipmentItems($this->area) as $itemKey => $item)
                                        <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="equip-{{ $itemKey }}">
                                            <td class="py-3 pe-3 align-top">
                                                <div>{{ $item['label'] }}</div>
                                                @if ($item['hint'])
                                                    <div class="text-xs text-zinc-500">{{ $item['hint'] }}</div>
                                                @endif
                                            </td>
                                            @foreach (['present', 'functional', 'clean', 'maint_req'] as $field)
                                                <td class="px-2 py-2 align-top">
                                                    <flux:select wire:model="equipment.{{ $itemKey }}.{{ $field }}" size="sm">
                                                        <option value="">—</option>
                                                        <option value="1">{{ __('Yes') }}</option>
                                                        <option value="0">{{ __('No') }}</option>
                                                    </flux:select>
                                                </td>
                                            @endforeach
                                            <td class="py-2 align-top">
                                                <flux:input wire:model="equipment.{{ $itemKey }}.remarks" placeholder="{{ $item['hint'] }}" />
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </flux:card>
                @endif

                @foreach ($this->definition->checklistSections($this->area) as $checklistSection)
                    @if ($activeSection === $checklistSection)
                        <flux:card>
                            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                                <flux:heading level="2">
                                    {{ collect($this->sections())->firstWhere('key', $checklistSection)['label'] ?? $checklistSection }}
                                </flux:heading>
                                <flux:button type="button" size="sm" variant="outline" wire:click="markChecklistDone('{{ $checklistSection }}')">
                                    {{ __('Mark all done') }}
                                </flux:button>
                            </div>
                            <div class="space-y-3">
                                @foreach ($this->definition->checklistItems($this->area, $checklistSection) as $itemKey => $label)
                                    @php $key = $this->checklistKey($checklistSection, $itemKey); @endphp
                                    <div class="flex flex-col gap-2 rounded-lg border border-zinc-100 p-3 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800" wire:key="check-{{ $key }}">
                                        <flux:text>{{ $label }}</flux:text>
                                        <flux:select wire:model="checklist.{{ $key }}" class="sm:w-36">
                                            <option value="">—</option>
                                            <option value="1">{{ __('Done') }}</option>
                                            <option value="0">{{ __('Not done') }}</option>
                                        </flux:select>
                                    </div>
                                @endforeach
                            </div>
                        </flux:card>
                    @endif
                @endforeach

                @if ($activeSection === 'register' && $this->area->isRegister())
                    <flux:card>
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <flux:heading level="2">{{ __('Master Equipment Maintenance Register') }}</flux:heading>
                                <flux:text class="text-sm text-zinc-500">
                                    {{ __('Any equipment defect must be formally logged with corrective action and technician sign-off.') }}
                                </flux:text>
                            </div>
                            <flux:button type="button" size="sm" variant="outline" wire:click="addRegisterRow">
                                {{ __('Add row') }}
                            </flux:button>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[1100px] border-collapse text-sm">
                                <thead>
                                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                        <th class="py-2 pe-2 text-start">{{ __('Date') }}</th>
                                        <th class="py-2 pe-2 text-start">{{ __('Department') }}</th>
                                        <th class="py-2 pe-2 text-start">{{ __('Equipment') }}</th>
                                        <th class="py-2 pe-2 text-start">{{ __('Problem') }}</th>
                                        <th class="py-2 pe-2 text-start">{{ __('Action Taken') }}</th>
                                        <th class="py-2 pe-2 text-start">{{ __('Technician') }}</th>
                                        <th class="py-2 pe-2 text-start">{{ __('Date Comp.') }}</th>
                                        <th class="py-2 pe-2 text-start">{{ __('Sign') }}</th>
                                        <th class="py-2"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($registerRows as $index => $row)
                                        <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="register-{{ $index }}">
                                            <td class="py-2 pe-2 align-top"><flux:input type="date" wire:model="registerRows.{{ $index }}.item_date" size="sm" /></td>
                                            <td class="py-2 pe-2 align-top"><flux:input wire:model="registerRows.{{ $index }}.department" size="sm" /></td>
                                            <td class="py-2 pe-2 align-top"><flux:input wire:model="registerRows.{{ $index }}.equipment" size="sm" /></td>
                                            <td class="py-2 pe-2 align-top"><flux:input wire:model="registerRows.{{ $index }}.problem" size="sm" /></td>
                                            <td class="py-2 pe-2 align-top"><flux:input wire:model="registerRows.{{ $index }}.action_taken" size="sm" /></td>
                                            <td class="py-2 pe-2 align-top"><flux:input wire:model="registerRows.{{ $index }}.technician" size="sm" /></td>
                                            <td class="py-2 pe-2 align-top"><flux:input type="date" wire:model="registerRows.{{ $index }}.completed_date" size="sm" /></td>
                                            <td class="py-2 pe-2 align-top"><flux:input wire:model="registerRows.{{ $index }}.signed" size="sm" /></td>
                                            <td class="py-2 align-top">
                                                <flux:button type="button" size="sm" variant="ghost" wire:click="removeRegisterRow({{ $index }})" icon="trash" />
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </flux:card>
                @endif

                @if ($activeSection === 'signoff')
                    <flux:card>
                        <flux:heading level="2" class="mb-4">{{ __('Supervisor Sign-off') }}</flux:heading>
                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach ($this->definition->signOffFields($this->area) as $fieldKey => $field)
                                <flux:field wire:key="signoff-{{ $fieldKey }}">
                                    <flux:label>{{ $field['label'] }}</flux:label>
                                    @if ($field['type'] === 'yes_no')
                                        <flux:select wire:model="signOff.{{ $fieldKey }}">
                                            <option value="">—</option>
                                            <option value="yes">{{ __('Yes') }}</option>
                                            <option value="no">{{ __('No') }}</option>
                                        </flux:select>
                                    @elseif ($field['type'] === 'choice')
                                        <flux:select wire:model="signOff.{{ $fieldKey }}">
                                            <option value="">—</option>
                                            @foreach ($field['choices'] as $choice)
                                                <option value="{{ $choice }}">{{ $this->choiceLabel($choice) }}</option>
                                            @endforeach
                                        </flux:select>
                                    @else
                                        <flux:input wire:model="signOff.{{ $fieldKey }}" />
                                    @endif
                                    <flux:error name="signOff.{{ $fieldKey }}" />
                                </flux:field>
                            @endforeach
                        </div>
                    </flux:card>
                @endif

                <div class="flex justify-end gap-3">
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        {{ __('Submit Checklist') }}
                    </flux:button>
                </div>
            </form>
        @endif
    </div>
</div>
