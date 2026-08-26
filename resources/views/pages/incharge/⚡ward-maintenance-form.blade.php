<?php

use App\Enums\WardMaintenanceFaultPriority;
use App\Enums\WardMaintenanceShift;
use App\Enums\WardMaintenanceStatus;
use App\Livewire\Concerns\HasChecklistWizard;
use App\Models\WardMaintenanceEntry;
use App\Services\WardMaintenanceChecklistDefinition;
use App\Services\WardMaintenanceService;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Fill Ward Maintenance')] class extends Component
{
    use HasChecklistWizard;
    #[Locked]
    public string $shiftValue;

    public string $checklistDate = '';

    public string $activeSection = 'header';

    public string $supervisorName = '';

    public string $supervisorTime = '';

    /**
     * @var array<string, string>
     */
    public array $statuses = [];

    /**
     * @var array<string, array{available: bool|null, functional: bool|null, remarks: string}>
     */
    public array $equipment = [];

    /**
     * @var list<array{fault_time: string, bed_room: string, description: string, priority: string, reported_to: string, action_taken: string, resolved: bool|null}>
     */
    public array $faultRows = [];

    public string $patientSafetyFault = '';

    public string $patientSafetyReported = '';

    public string $roomUnavailable = '';

    public string $bedsOutOfService = '';

    public string $reasonRemarks = '';

    public string $supervisorRemarks = '';

    public function mount(string $shift, ?string $date = null): void
    {
        $shiftEnum = WardMaintenanceShift::tryFrom($shift);
        abort_unless($shiftEnum !== null, 404);

        $this->shiftValue = $shiftEnum->value;
        $this->checklistDate = $date ?: now()->format('Y-m-d');

        $service = app(WardMaintenanceService::class);

        if ($this->existingEntry !== null) {
            return;
        }

        $this->statuses = $service->emptyStatusMap();
        $this->equipment = $service->emptyEquipmentMap();
        $this->faultRows = [
            $this->emptyFaultRow(),
            $this->emptyFaultRow(),
            $this->emptyFaultRow(),
        ];
    }

    /**
     * @return array{fault_time: string, bed_room: string, description: string, priority: string, reported_to: string, action_taken: string, resolved: bool|null}
     */
    private function emptyFaultRow(): array
    {
        return [
            'fault_time' => '',
            'bed_room' => '',
            'description' => '',
            'priority' => '',
            'reported_to' => '',
            'action_taken' => '',
            'resolved' => null,
        ];
    }

    #[Computed]
    public function shift(): WardMaintenanceShift
    {
        return WardMaintenanceShift::from($this->shiftValue);
    }

    #[Computed]
    public function definition(): WardMaintenanceChecklistDefinition
    {
        return app(WardMaintenanceChecklistDefinition::class);
    }

    #[Computed]
    public function existingEntry(): ?WardMaintenanceEntry
    {
        return app(WardMaintenanceService::class)->findEntry(
            Carbon::parse($this->checklistDate),
            $this->shift
        )?->load(['answers', 'faults', 'user', 'healthAide']);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function sections(): array
    {
        return [
            ['key' => 'header', 'label' => __('Header')],
            ['key' => 'A', 'label' => __('A. Beds')],
            ['key' => 'B', 'label' => __('B. Infrastructure')],
            ['key' => 'C', 'label' => __('C. Electrical')],
            ['key' => 'D', 'label' => __('D. Bathroom')],
            ['key' => 'E', 'label' => __('E. Equipment')],
            ['key' => 'F', 'label' => __('F. Common Area')],
            ['key' => 'G', 'label' => __('G. Safety')],
            ['key' => 'H', 'label' => __('H. Fault Report')],
            ['key' => 'I', 'label' => __('I. Sign-off')],
        ];
    }

    public function markSectionOk(string $section): void
    {
        $service = app(WardMaintenanceService::class);

        foreach ($this->definition->statusCells() as $cell) {
            $matches = match ($section) {
                'A', 'B', 'D', 'F', 'G' => $cell['section'] === $section,
                'C' => in_array($cell['section'], ['C_gyne', 'C_private'], true),
                default => false,
            };

            if (! $matches) {
                continue;
            }

            $key = $service->statusKey($cell['section'], $cell['item_key'], $cell['location_key']);
            $this->statuses[$key] = WardMaintenanceStatus::Ok->value;
        }

        if ($section === 'E') {
            foreach (array_keys($this->equipment) as $itemKey) {
                $this->equipment[$itemKey]['available'] = true;
                $this->equipment[$itemKey]['functional'] = true;
            }
        }

        Flux::toast(variant: 'success', text: __('Section marked OK.'));
    }

    public function addFaultRow(): void
    {
        $this->faultRows[] = $this->emptyFaultRow();
    }

    public function removeFaultRow(int $index): void
    {
        unset($this->faultRows[$index]);
        $this->faultRows = array_values($this->faultRows);

        if ($this->faultRows === []) {
            $this->faultRows = [$this->emptyFaultRow()];
        }
    }

    public function submit(): void
    {
        if ($this->existingEntry !== null) {
            Flux::toast(variant: 'danger', text: __('This shift checklist has already been submitted.'));

            return;
        }

        $service = app(WardMaintenanceService::class);
        $rules = [
            'healthAideCode' => ['required', 'digits_between:4,6'],
            'supervisorName' => ['nullable', 'string', 'max:255'],
            'supervisorTime' => ['nullable', 'string', 'max:20'],
            'patientSafetyFault' => ['required', Rule::in(['yes', 'no'])],
            'patientSafetyReported' => ['required', Rule::in(['yes', 'no', 'na'])],
            'roomUnavailable' => ['required', Rule::in(['yes', 'no'])],
            'bedsOutOfService' => ['nullable', 'string', 'max:2000'],
            'reasonRemarks' => ['nullable', 'string', 'max:2000'],
            'supervisorRemarks' => ['nullable', 'string', 'max:2000'],
        ];
        $messages = [
            'healthAideCode.required' => __('Enter the health aide code.'),
            'healthAideCode.digits_between' => __('The health aide code must be 4 to 6 digits.'),
        ];

        foreach ($this->definition->statusCells() as $cell) {
            $key = $service->statusKey($cell['section'], $cell['item_key'], $cell['location_key']);
            $rules["statuses.{$key}"] = ['required', Rule::enum(WardMaintenanceStatus::class)];
            $messages["statuses.{$key}.required"] = __('Please answer: :item', ['item' => $cell['label']]);
        }

        foreach (array_keys($this->definition->sectionEItems()) as $itemKey) {
            $rules["equipment.{$itemKey}.available"] = ['required', 'boolean'];
            $rules["equipment.{$itemKey}.functional"] = ['required', 'boolean'];
            $rules["equipment.{$itemKey}.remarks"] = ['nullable', 'string', 'max:2000'];
        }

        foreach ($this->faultRows as $index => $row) {
            if ($service->faultRowIsEmpty($row)) {
                continue;
            }

            $rules["faultRows.{$index}.description"] = ['required', 'string', 'max:2000'];
            $rules["faultRows.{$index}.priority"] = ['required', Rule::enum(WardMaintenanceFaultPriority::class)];
            $rules["faultRows.{$index}.fault_time"] = ['nullable', 'string', 'max:20'];
            $rules["faultRows.{$index}.bed_room"] = ['nullable', 'string', 'max:255'];
            $rules["faultRows.{$index}.reported_to"] = ['nullable', 'string', 'max:255'];
            $rules["faultRows.{$index}.action_taken"] = ['nullable', 'string', 'max:2000'];
            $rules["faultRows.{$index}.resolved"] = ['nullable', 'boolean'];
        }

        $validated = $this->validate($rules, $messages);

        if (! $this->hasVerifiedHealthAide() && ! $this->verifyHealthAideCode()) {
            $this->activeSection = 'header';

            return;
        }

        $service->submit(
            auth()->user(),
            Carbon::parse($this->checklistDate),
            $this->shift,
            [
                'health_aide_id' => $this->verifiedHealthAideId,
                'checked_by_name' => $this->verifiedHealthAideName,
                'supervisor_name' => $validated['supervisorName'] ?? '',
                'checked_by_time' => now()->format('H:i'),
                'supervisor_time' => $validated['supervisorTime'] ?? '',
                'patient_safety_fault' => $validated['patientSafetyFault'],
                'patient_safety_reported' => $validated['patientSafetyReported'],
                'room_unavailable' => $validated['roomUnavailable'],
                'beds_out_of_service' => $validated['bedsOutOfService'] ?? '',
                'reason_remarks' => $validated['reasonRemarks'] ?? '',
                'supervisor_remarks' => $validated['supervisorRemarks'] ?? '',
            ],
            $validated['statuses'],
            $validated['equipment'],
            $validated['faultRows'] ?? $this->faultRows,
        );

        Flux::toast(variant: 'success', text: __('Ward maintenance checklist submitted.'));
        unset($this->existingEntry);
        $this->redirect(route('incharge.ward-maintenance', ['date' => $this->checklistDate]), navigate: true);
    }

    /**
     * Determine whether a status cell has been answered.
     */
    public function statusIsFilled(string $section, string $itemKey, string $locationKey = ''): bool
    {
        $key = $this->statusKey($section, $itemKey, $locationKey);

        return filled($this->statuses[$key] ?? null);
    }

    /**
     * Get the completion counts for a section.
     *
     * @return array{answered: int, total: int}
     */
    public function sectionCompletion(string $section): array
    {
        $service = app(WardMaintenanceService::class);
        $answered = 0;
        $total = 0;

        $countCell = function (string $cellSection, string $itemKey, string $locationKey = '') use (&$answered, &$total, $service): void {
            $key = $service->statusKey($cellSection, $itemKey, $locationKey);
            $total++;
            if (filled($this->statuses[$key] ?? null)) {
                $answered++;
            }
        };

        $addCells = function (string $cellSection, array $items, array $locations = ['']) use ($countCell): void {
            foreach ($items as $itemKey => $label) {
                foreach ($locations as $locationKey => $locationLabel) {
                    $countCell($cellSection, $itemKey, is_string($locationKey) ? $locationKey : $locationLabel);
                }
            }
        };

        $sectionHandler = match ($section) {
            'header' => function () use (&$answered, &$total): void {
                $total = 1;
                $answered = $this->hasVerifiedHealthAide() ? 1 : 0;
            },
            'A' => function () use ($addCells): void {
                $addCells('A', $this->definition->sectionAItems(), $this->definition->beds());
            },
            'B' => function () use ($addCells): void {
                $addCells('B', $this->definition->sectionBItems(), $this->definition->areas());
            },
            'C' => function () use ($countCell): void {
                foreach ($this->definition->sectionCGyneItems() as $itemKey => $label) {
                    $countCell('C_gyne', $itemKey, 'gyne_ward');
                }
                foreach ($this->definition->sectionCPrivateItems() as $itemKey => $label) {
                    foreach ($this->definition->privateAreas() as $locationKey => $locationLabel) {
                        $countCell('C_private', $itemKey, $locationKey);
                    }
                }
            },
            'D' => function () use ($countCell): void {
                foreach ($this->definition->sectionDGyneItems() as $itemKey => $label) {
                    $countCell('D', $itemKey, 'gyne_ward');
                }
                foreach ($this->definition->sectionDPrivateItems() as $itemKey => $label) {
                    foreach (['private_1', 'private_2', 'shared_private'] as $locationKey) {
                        $countCell('D', $itemKey, $locationKey);
                    }
                }
            },
            'E' => function () use (&$answered, &$total): void {
                foreach (array_keys($this->definition->sectionEItems()) as $itemKey) {
                    $total += 2;
                    if (($this->equipment[$itemKey]['available'] ?? null) !== null) {
                        $answered++;
                    }
                    if (($this->equipment[$itemKey]['functional'] ?? null) !== null) {
                        $answered++;
                    }
                }
            },
            'F' => function () use ($countCell): void {
                foreach ($this->definition->sectionFItems() as $itemKey => $label) {
                    $countCell('F', $itemKey, '');
                }
            },
            'G' => function () use ($countCell): void {
                foreach ($this->definition->sectionGItems() as $itemKey => $label) {
                    $countCell('G', $itemKey, '');
                }
            },
            'I' => function () use (&$answered, &$total): void {
                $total = 3;
                $answered = filled($this->patientSafetyFault) ? $answered + 1 : $answered;
                $answered = filled($this->patientSafetyReported) ? $answered + 1 : $answered;
                $answered = filled($this->roomUnavailable) ? $answered + 1 : $answered;
            },
            default => fn () => null,
        };

        $sectionHandler();

        return ['answered' => $answered, 'total' => $total];
    }

    /**
     * Determine whether a section is fully completed.
     */
    public function isSectionComplete(string $section): bool
    {
        $completion = $this->sectionCompletion($section);

        return $completion['total'] > 0 && $completion['answered'] === $completion['total'];
    }

    /**
     * Get a color class for a status value indicator.
     */
    public function statusColorClass(string $statusValue): string
    {
        return match ($statusValue) {
            'ok' => 'bg-emerald-500',
            'fault' => 'bg-rose-500',
            'na' => 'bg-zinc-400',
            default => 'bg-transparent',
        };
    }

    /**
     * Get the overall completion across all scored sections.
     *
     * @return array{answered: int, total: int}
     */
    public function overallProgress(): array
    {
        $scoredSections = ['header', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'I'];
        $answered = 0;
        $total = 0;

        foreach ($scoredSections as $section) {
            $completion = $this->sectionCompletion($section);
            $answered += $completion['answered'];
            $total += $completion['total'];
        }

        return ['answered' => $answered, 'total' => $total];
    }

    public function statusKey(string $section, string $itemKey, string $locationKey = ''): string
    {
        return app(WardMaintenanceService::class)->statusKey($section, $itemKey, $locationKey);
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="1">{{ __('Ward Maintenance Checklist') }}</flux:heading>
                <flux:text class="text-zinc-500">
                    {{ __(':shift · :date', [
                        'shift' => $this->shift->label(),
                        'date' => \Illuminate\Support\Carbon::parse($checklistDate)->format('M j, Y'),
                    ]) }}
                </flux:text>
            </div>
            <flux:button variant="ghost" :href="route('incharge.ward-maintenance', ['date' => $checklistDate])" wire:navigate icon="arrow-left">
                {{ __('Back') }}
            </flux:button>
        </div>

        @if ($this->existingEntry)
            @include('pages.incharge.partials.ward-maintenance-view', ['entry' => $this->existingEntry])
        @else
            @php
                $progress = $this->overallProgress();
                $completedKeys = collect($this->sections())
                    ->filter(fn (array $section): bool => $section['key'] !== 'H' && $this->isSectionComplete($section['key']))
                    ->pluck('key')
                    ->all();
            @endphp

            <x-checklist-wizard-steps
                :steps="$this->sections()"
                :active="$activeSection"
                :completed="$completedKeys"
            />

            <form wire:submit="submit" class="flex flex-col gap-6">
                @if ($activeSection === 'header')
                    <flux:card>
                        <flux:heading level="2" class="mb-4">{{ __('Checklist Header') }}</flux:heading>
                        <flux:callout icon="information-circle" class="mb-4">
                            <flux:callout.text>
                                {{ __('Legend: OK = acceptable, Fault = issue found, N/A = not applicable. Areas: Gyne Ward (B1–B5), Private 1 (B6), Private 2 (B7), Shared Private (B8–B9).') }}
                            </flux:callout.text>
                        </flux:callout>
                        <x-checklist-health-aide-code>
                            <flux:field>
                                <flux:label>{{ __('Supervisor') }}</flux:label>
                                <flux:input wire:model="supervisorName" />
                                <flux:error name="supervisorName" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Supervisor Time') }}</flux:label>
                                <flux:input wire:model="supervisorTime" />
                                <flux:error name="supervisorTime" />
                            </flux:field>
                        </x-checklist-health-aide-code>
                    </flux:card>
                @endif

                @if ($activeSection === 'A')
                    <flux:card>
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <flux:heading level="2">{{ __('A. Bed & Bedside Equipment') }}</flux:heading>
                            <flux:button type="button" size="sm" variant="outline" wire:click="markSectionOk('A')">{{ __('Mark all OK') }}</flux:button>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[900px] border-collapse text-sm">
                                <thead>
                                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                        <th class="py-2 pe-3 text-start">{{ __('Item') }}</th>
                                        @foreach ($this->definition->beds() as $bed)
                                            <th class="px-1 py-2 text-center">{{ $bed }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->definition->sectionAItems() as $itemKey => $label)
                                        <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="a-{{ $itemKey }}">
                                            <td class="py-3 pe-3 align-top">{{ $label }}</td>
                                            @foreach ($this->definition->beds() as $bed)
                                                @php $key = $this->statusKey('A', $itemKey, $bed); @endphp
                                                <td class="px-1 py-2 align-top">
                                                    <flux:select wire:model="statuses.{{ $key }}" size="sm">
                                                        <option value="">—</option>
                                                        <option value="ok">{{ __('OK') }}</option>
                                                        <option value="fault">{{ __('Fault') }}</option>
                                                        <option value="na">{{ __('N/A') }}</option>
                                                    </flux:select>
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </flux:card>
                @endif

                @if ($activeSection === 'B')
                    <flux:card>
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <flux:heading level="2">{{ __('B. Room / Area Infrastructure') }}</flux:heading>
                            <flux:button type="button" size="sm" variant="outline" wire:click="markSectionOk('B')">{{ __('Mark all OK') }}</flux:button>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[700px] border-collapse text-sm">
                                <thead>
                                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                        <th class="py-2 pe-3 text-start">{{ __('Check Item') }}</th>
                                        @foreach ($this->definition->areas() as $locationKey => $locationLabel)
                                            <th class="px-2 py-2 text-center">{{ $locationLabel }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->definition->sectionBItems() as $itemKey => $label)
                                        <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="b-{{ $itemKey }}">
                                            <td class="py-3 pe-3 align-top">{{ $label }}</td>
                                            @foreach ($this->definition->areas() as $locationKey => $locationLabel)
                                                @php $key = $this->statusKey('B', $itemKey, $locationKey); @endphp
                                                <td class="px-2 py-2 align-top">
                                                    <flux:select wire:model="statuses.{{ $key }}" size="sm">
                                                        <option value="">—</option>
                                                        <option value="ok">{{ __('OK') }}</option>
                                                        <option value="fault">{{ __('Fault') }}</option>
                                                        <option value="na">{{ __('N/A') }}</option>
                                                    </flux:select>
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </flux:card>
                @endif

                @if ($activeSection === 'C')
                    <div class="grid gap-6 xl:grid-cols-2">
                        <flux:card>
                            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                                <flux:heading level="2">{{ __('C. Gyne Ward (Non-AC)') }}</flux:heading>
                                <flux:button type="button" size="sm" variant="outline" wire:click="markSectionOk('C')">{{ __('Mark all OK') }}</flux:button>
                            </div>
                            <div class="space-y-3">
                                @foreach ($this->definition->sectionCGyneItems() as $itemKey => $label)
                                    @php
                                        $key = $this->statusKey('C_gyne', $itemKey, 'gyne_ward');
                                        $statusValue = $this->statuses[$key] ?? '';
                                    @endphp
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between rounded-lg border border-zinc-100 p-3 dark:border-zinc-800" wire:key="c-gyne-{{ $itemKey }}">
                                        <flux:text>{{ $label }}</flux:text>
                                        <div class="flex items-center gap-2">
                                            <span class="size-2.5 rounded-full {{ $this->statusColorClass($statusValue) }}" aria-hidden="true"></span>
                                            <flux:select wire:model="statuses.{{ $key }}" class="sm:w-36">
                                                <option value="">—</option>
                                                <option value="ok">{{ __('OK') }}</option>
                                                <option value="fault">{{ __('Fault') }}</option>
                                                <option value="na">{{ __('N/A') }}</option>
                                            </flux:select>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </flux:card>

                        <flux:card>
                            <flux:heading level="2" class="mb-4">{{ __('C. Private Rooms (AC)') }}</flux:heading>
                            <div class="overflow-x-auto">
                                <table class="w-full min-w-[520px] border-collapse text-sm">
                                    <thead>
                                        <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                            <th class="py-2 pe-3 text-start">{{ __('Parameter') }}</th>
                                            @foreach ($this->definition->privateAreas() as $locationKey => $locationLabel)
                                                <th class="px-2 py-2 text-center">{{ $locationLabel }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($this->definition->sectionCPrivateItems() as $itemKey => $label)
                                            <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="c-priv-{{ $itemKey }}">
                                                <td class="py-3 pe-3 align-top">{{ $label }}</td>
                                                @foreach ($this->definition->privateAreas() as $locationKey => $locationLabel)
                                                    @php $key = $this->statusKey('C_private', $itemKey, $locationKey); @endphp
                                                    <td class="px-2 py-2 align-top">
                                                        <flux:select wire:model="statuses.{{ $key }}" size="sm">
                                                            <option value="">—</option>
                                                            <option value="ok">{{ __('OK') }}</option>
                                                            <option value="fault">{{ __('Fault') }}</option>
                                                            <option value="na">{{ __('N/A') }}</option>
                                                        </flux:select>
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </flux:card>
                    </div>
                @endif

                @if ($activeSection === 'D')
                    <flux:card>
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <flux:heading level="2">{{ __('D. Bathroom & Water Supply') }}</flux:heading>
                            <flux:button type="button" size="sm" variant="outline" wire:click="markSectionOk('D')">{{ __('Mark all OK') }}</flux:button>
                        </div>
                        <div class="grid gap-6 lg:grid-cols-2">
                            <div>
                                <flux:heading level="3" class="mb-3">{{ __('Gyne Ward Bathroom') }}</flux:heading>
                                <div class="space-y-3">
                                    @foreach ($this->definition->sectionDGyneItems() as $itemKey => $label)
                                        @php
                                            $key = $this->statusKey('D', $itemKey, 'gyne_ward');
                                            $statusValue = $this->statuses[$key] ?? '';
                                        @endphp
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between rounded-lg border border-zinc-100 p-3 dark:border-zinc-800" wire:key="d-gyne-{{ $itemKey }}">
                                            <flux:text>{{ $label }}</flux:text>
                                            <div class="flex items-center gap-2">
                                                <span class="size-2.5 rounded-full {{ $this->statusColorClass($statusValue) }}" aria-hidden="true"></span>
                                                <flux:select wire:model="statuses.{{ $key }}" class="sm:w-36">
                                                    <option value="">—</option>
                                                    <option value="ok">{{ __('OK') }}</option>
                                                    <option value="fault">{{ __('Fault') }}</option>
                                                    <option value="na">{{ __('N/A') }}</option>
                                                </flux:select>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            @foreach (['private_1', 'private_2', 'shared_private'] as $locationKey)
                                <div wire:key="d-loc-{{ $locationKey }}">
                                    <flux:heading level="3" class="mb-3">{{ $this->definition->bathrooms()[$locationKey] }}</flux:heading>
                                    <div class="space-y-3">
                                        @foreach ($this->definition->sectionDPrivateItems() as $itemKey => $label)
                                            @php
                                                $key = $this->statusKey('D', $itemKey, $locationKey);
                                                $statusValue = $this->statuses[$key] ?? '';
                                            @endphp
                                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between rounded-lg border border-zinc-100 p-3 dark:border-zinc-800" wire:key="d-{{ $locationKey }}-{{ $itemKey }}">
                                                <flux:text>{{ $label }}</flux:text>
                                                <div class="flex items-center gap-2">
                                                    <span class="size-2.5 rounded-full {{ $this->statusColorClass($statusValue) }}" aria-hidden="true"></span>
                                                    <flux:select wire:model="statuses.{{ $key }}" class="sm:w-36">
                                                        <option value="">—</option>
                                                        <option value="ok">{{ __('OK') }}</option>
                                                        <option value="fault">{{ __('Fault') }}</option>
                                                        <option value="na">{{ __('N/A') }}</option>
                                                    </flux:select>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </flux:card>
                @endif

                @if ($activeSection === 'E')
                    <flux:card>
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <flux:heading level="2">{{ __('E. Medical / Patient-Care Equipment') }}</flux:heading>
                            <flux:button type="button" size="sm" variant="outline" wire:click="markSectionOk('E')">{{ __('Mark all available & functional') }}</flux:button>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[700px] border-collapse text-sm">
                                <thead>
                                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                        <th class="py-2 pe-3 text-start">{{ __('Equipment') }}</th>
                                        <th class="px-2 py-2 text-center">{{ __('Available') }}</th>
                                        <th class="px-2 py-2 text-center">{{ __('Functional') }}</th>
                                        <th class="py-2 text-start">{{ __('Remarks') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->definition->sectionEItems() as $itemKey => $label)
                                        <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="e-{{ $itemKey }}">
                                            <td class="py-3 pe-3 align-top">{{ $label }}</td>
                                            <td class="px-2 py-2 align-top">
                                                <flux:select wire:model="equipment.{{ $itemKey }}.available" size="sm">
                                                    <option value="">—</option>
                                                    <option value="1">{{ __('Yes') }}</option>
                                                    <option value="0">{{ __('No') }}</option>
                                                </flux:select>
                                            </td>
                                            <td class="px-2 py-2 align-top">
                                                <flux:select wire:model="equipment.{{ $itemKey }}.functional" size="sm">
                                                    <option value="">—</option>
                                                    <option value="1">{{ __('Yes') }}</option>
                                                    <option value="0">{{ __('No') }}</option>
                                                </flux:select>
                                            </td>
                                            <td class="py-2 align-top">
                                                <flux:input wire:model="equipment.{{ $itemKey }}.remarks" />
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </flux:card>
                @endif

                @if ($activeSection === 'F')
                    <flux:card>
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <flux:heading level="2">{{ __('F. Common Area Check') }}</flux:heading>
                            <flux:button type="button" size="sm" variant="outline" wire:click="markSectionOk('F')">{{ __('Mark all OK') }}</flux:button>
                        </div>
                        <div class="space-y-3">
                            @foreach ($this->definition->sectionFItems() as $itemKey => $label)
                                @php
                                    $key = $this->statusKey('F', $itemKey, '');
                                    $statusValue = $this->statuses[$key] ?? '';
                                @endphp
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between rounded-lg border border-zinc-100 p-3 dark:border-zinc-800" wire:key="f-{{ $itemKey }}">
                                    <flux:text>{{ $label }}</flux:text>
                                    <div class="flex items-center gap-2">
                                        <span class="size-2.5 rounded-full {{ $this->statusColorClass($statusValue) }}" aria-hidden="true"></span>
                                        <flux:select wire:model="statuses.{{ $key }}" class="sm:w-36">
                                            <option value="">—</option>
                                            <option value="ok">{{ __('OK') }}</option>
                                            <option value="fault">{{ __('Fault') }}</option>
                                            <option value="na">{{ __('N/A') }}</option>
                                        </flux:select>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </flux:card>
                @endif

                @if ($activeSection === 'G')
                    <flux:card>
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <flux:heading level="2">{{ __('G. Safety Check') }}</flux:heading>
                            <flux:button type="button" size="sm" variant="outline" wire:click="markSectionOk('G')">{{ __('Mark all OK') }}</flux:button>
                        </div>
                        <div class="space-y-3">
                            @foreach ($this->definition->sectionGItems() as $itemKey => $label)
                                @php
                                    $key = $this->statusKey('G', $itemKey, '');
                                    $statusValue = $this->statuses[$key] ?? '';
                                @endphp
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between rounded-lg border border-zinc-100 p-3 dark:border-zinc-800" wire:key="g-{{ $itemKey }}">
                                    <flux:text>{{ $label }}</flux:text>
                                    <div class="flex items-center gap-2">
                                        <span class="size-2.5 rounded-full {{ $this->statusColorClass($statusValue) }}" aria-hidden="true"></span>
                                        <flux:select wire:model="statuses.{{ $key }}" class="sm:w-36">
                                            <option value="">—</option>
                                            <option value="ok">{{ __('OK') }}</option>
                                            <option value="fault">{{ __('Fault') }}</option>
                                            <option value="na">{{ __('N/A') }}</option>
                                        </flux:select>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </flux:card>
                @endif

                @if ($activeSection === 'H')
                    <flux:card>
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <flux:heading level="2">{{ __('H. Maintenance Fault Report') }}</flux:heading>
                            <flux:button type="button" size="sm" variant="ghost" wire:click="addFaultRow">{{ __('Add row') }}</flux:button>
                        </div>
                        <div class="space-y-4">
                            @foreach ($faultRows as $index => $row)
                                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" wire:key="fault-{{ $index }}">
                                    <div class="mb-3 flex items-center justify-between">
                                        <flux:heading level="3">{{ __('Fault #:number', ['number' => $index + 1]) }}</flux:heading>
                                        <flux:button type="button" size="sm" variant="ghost" wire:click="removeFaultRow({{ $index }})">{{ __('Remove') }}</flux:button>
                                    </div>
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <flux:field>
                                            <flux:label>{{ __('Time') }}</flux:label>
                                            <flux:input wire:model="faultRows.{{ $index }}.fault_time" />
                                        </flux:field>
                                        <flux:field>
                                            <flux:label>{{ __('Bed / Room') }}</flux:label>
                                            <flux:input wire:model="faultRows.{{ $index }}.bed_room" />
                                        </flux:field>
                                        <flux:field class="sm:col-span-2">
                                            <flux:label>{{ __('Fault / Problem Description') }}</flux:label>
                                            <flux:textarea wire:model="faultRows.{{ $index }}.description" rows="2" />
                                            <flux:error name="faultRows.{{ $index }}.description" />
                                        </flux:field>
                                        <flux:field>
                                            <flux:label>{{ __('Priority') }}</flux:label>
                                            <flux:select wire:model="faultRows.{{ $index }}.priority">
                                                <option value="">{{ __('Select') }}</option>
                                                <option value="urgent">{{ __('Urgent') }}</option>
                                                <option value="routine">{{ __('Routine') }}</option>
                                            </flux:select>
                                            <flux:error name="faultRows.{{ $index }}.priority" />
                                        </flux:field>
                                        <flux:field>
                                            <flux:label>{{ __('Reported To') }}</flux:label>
                                            <flux:input wire:model="faultRows.{{ $index }}.reported_to" />
                                        </flux:field>
                                        <flux:field>
                                            <flux:label>{{ __('Action Taken') }}</flux:label>
                                            <flux:input wire:model="faultRows.{{ $index }}.action_taken" />
                                        </flux:field>
                                        <flux:field>
                                            <flux:label>{{ __('Resolved') }}</flux:label>
                                            <flux:select wire:model="faultRows.{{ $index }}.resolved">
                                                <option value="">—</option>
                                                <option value="1">{{ __('Yes') }}</option>
                                                <option value="0">{{ __('No') }}</option>
                                            </flux:select>
                                        </flux:field>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </flux:card>
                @endif

                @if ($activeSection === 'I')
                    <flux:card>
                        <flux:heading level="2" class="mb-4">{{ __('I. Final Daily Verification & Sign-off') }}</flux:heading>
                        <div class="space-y-6">
                            <flux:field>
                                <flux:label>{{ __('Any fault affecting patient safety/care?') }}</flux:label>
                                <flux:radio.group wire:model.live="patientSafetyFault" class="flex flex-wrap gap-6">
                                    <flux:radio value="no" :label="__('No')" />
                                    <flux:radio value="yes" :label="__('Yes')" />
                                </flux:radio.group>
                                <flux:error name="patientSafetyFault" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('If YES, has it been reported immediately to bio-med/maintenance?') }}</flux:label>
                                <flux:radio.group wire:model="patientSafetyReported" class="flex flex-wrap gap-6">
                                    <flux:radio value="yes" :label="__('Yes')" />
                                    <flux:radio value="no" :label="__('No')" />
                                    <flux:radio value="na" :label="__('N/A')" />
                                </flux:radio.group>
                                <flux:error name="patientSafetyReported" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Any room/bed temporarily unavailable due to maintenance?') }}</flux:label>
                                <flux:radio.group wire:model.live="roomUnavailable" class="flex flex-wrap gap-6">
                                    <flux:radio value="no" :label="__('No')" />
                                    <flux:radio value="yes" :label="__('Yes')" />
                                </flux:radio.group>
                                <flux:error name="roomUnavailable" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Bed/Room Out of Service') }}</flux:label>
                                <flux:input wire:model="bedsOutOfService" />
                                <flux:error name="bedsOutOfService" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Reason / Remarks') }}</flux:label>
                                <flux:textarea wire:model="reasonRemarks" rows="2" />
                                <flux:error name="reasonRemarks" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Supervisor Remarks') }}</flux:label>
                                <flux:textarea wire:model="supervisorRemarks" rows="2" />
                                <flux:error name="supervisorRemarks" />
                            </flux:field>
                        </div>
                    </flux:card>
                @endif

                <x-checklist-wizard-footer
                    :is-first="$this->isFirstSection()"
                    :is-last="$this->isLastSection()"
                    :answered="$progress['answered']"
                    :total="$progress['total']"
                    :verified-name="$this->hasVerifiedHealthAide() ? $this->verifiedHealthAideName : null"
                    :save-label="__('Save')"
                />
            </form>
        @endif
    </div>
</div>
