<?php

use App\Enums\EmergencyDepartmentEquipmentStatus;
use App\Enums\EmergencyDepartmentShift;
use App\Models\EmergencyDepartmentLogEntry;
use App\Services\EmergencyDepartmentLogDefinition;
use App\Services\EmergencyDepartmentLogService;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Fill ER Operational Log')] class extends Component
{
    #[Locked]
    public string $shiftValue;

    public string $checklistDate = '';

    public string $activeSection = 'header';

    public string $completedByName = '';

    public string $supervisorName = '';

    public string $equipmentIssuesLog = '';

    /**
     * @var array<string, array{count: string, remarks: string}>
     */
    public array $handover = [];

    /**
     * @var array<string, array{status: string, remarks: string}>
     */
    public array $equipment = [];

    /**
     * @var array<string, array{adequate: bool|null, remarks: string}>
     */
    public array $crashCart = [];

    /**
     * @var array<string, bool|null>
     */
    public array $cleaning = [];

    public function mount(string $shift, ?string $date = null): void
    {
        $shiftEnum = EmergencyDepartmentShift::tryFrom($shift);
        abort_unless($shiftEnum !== null, 404);

        $this->shiftValue = $shiftEnum->value;
        $this->checklistDate = $date ?: now()->format('Y-m-d');
        $this->completedByName = auth()->user()->name;

        $service = app(EmergencyDepartmentLogService::class);

        if ($this->existingEntry !== null) {
            return;
        }

        $this->handover = $service->emptyHandoverMap();
        $this->equipment = $service->emptyEquipmentMap();
        $this->crashCart = $service->emptyCrashCartMap();
        $this->cleaning = $service->emptyCleaningMap();
    }

    #[Computed]
    public function shift(): EmergencyDepartmentShift
    {
        return EmergencyDepartmentShift::from($this->shiftValue);
    }

    #[Computed]
    public function definition(): EmergencyDepartmentLogDefinition
    {
        return app(EmergencyDepartmentLogDefinition::class);
    }

    #[Computed]
    public function existingEntry(): ?EmergencyDepartmentLogEntry
    {
        return app(EmergencyDepartmentLogService::class)->findEntry(
            Carbon::parse($this->checklistDate),
            $this->shift
        )?->load(['answers', 'user']);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function sections(): array
    {
        return [
            ['key' => 'header', 'label' => __('Header')],
            ['key' => 'A', 'label' => __('A. Handover')],
            ['key' => 'B', 'label' => __('B. Equipment')],
            ['key' => 'C', 'label' => __('C. Crash Cart')],
            ['key' => 'D', 'label' => __('D. Cleaning')],
        ];
    }

    public function setSection(string $section): void
    {
        $this->activeSection = $section;
    }

    public function markEquipmentOk(): void
    {
        foreach (array_keys($this->equipment) as $itemKey) {
            $this->equipment[$itemKey]['status'] = EmergencyDepartmentEquipmentStatus::Ok->value;
        }

        Flux::toast(variant: 'success', text: __('Equipment marked OK.'));
    }

    public function markCrashCartAdequate(): void
    {
        foreach (array_keys($this->crashCart) as $itemKey) {
            $this->crashCart[$itemKey]['adequate'] = true;
        }

        Flux::toast(variant: 'success', text: __('Crash cart marked adequate.'));
    }

    public function markCleaningDone(): void
    {
        foreach (array_keys($this->cleaning) as $key) {
            $this->cleaning[$key] = true;
        }

        Flux::toast(variant: 'success', text: __('Cleaning section marked done.'));
    }

    public function submit(): void
    {
        if ($this->existingEntry !== null) {
            Flux::toast(variant: 'danger', text: __('This shift log has already been submitted.'));

            return;
        }

        $service = app(EmergencyDepartmentLogService::class);
        $rules = [
            'completedByName' => ['required', 'string', 'max:255'],
            'supervisorName' => ['nullable', 'string', 'max:255'],
            'equipmentIssuesLog' => ['nullable', 'string', 'max:5000'],
        ];
        $messages = [];

        foreach ($this->definition->handoverMetrics() as $itemKey => $label) {
            $rules["handover.{$itemKey}.count"] = ['required', 'integer', 'min:0'];
            $rules["handover.{$itemKey}.remarks"] = ['nullable', 'string', 'max:2000'];
            $messages["handover.{$itemKey}.count.required"] = __('Please enter a count for: :item', ['item' => $label]);
        }

        foreach ($this->definition->equipmentItems() as $itemKey => $item) {
            $rules["equipment.{$itemKey}.status"] = ['required', Rule::enum(EmergencyDepartmentEquipmentStatus::class)];
            $rules["equipment.{$itemKey}.remarks"] = ['nullable', 'string', 'max:2000'];
            $messages["equipment.{$itemKey}.status.required"] = __('Please answer: :item', ['item' => $item['label']]);
        }

        foreach ($this->definition->crashCartItems() as $item) {
            $rules["crashCart.{$item['item_key']}.adequate"] = ['required', 'boolean'];
            $rules["crashCart.{$item['item_key']}.remarks"] = ['nullable', 'string', 'max:2000'];
            $messages["crashCart.{$item['item_key']}.adequate.required"] = __('Please answer: :item', ['item' => $item['label']]);
        }

        foreach ($this->definition->cleaningItems() as $item) {
            $key = $service->cleaningKey($item['section'], $item['item_key']);
            $rules["cleaning.{$key}"] = ['required', 'boolean'];
            $messages["cleaning.{$key}.required"] = __('Please answer: :item', ['item' => $item['label']]);
        }

        $validated = $this->validate($rules, $messages);

        $service->submit(
            auth()->user(),
            Carbon::parse($this->checklistDate),
            $this->shift,
            [
                'completed_by_name' => $validated['completedByName'],
                'supervisor_name' => $validated['supervisorName'] ?? '',
                'equipment_issues_log' => $validated['equipmentIssuesLog'] ?? '',
            ],
            $validated['handover'],
            $validated['equipment'],
            $validated['crashCart'],
            $validated['cleaning'],
        );

        Flux::toast(variant: 'success', text: __('Emergency department operational log submitted.'));
        unset($this->existingEntry);
        $this->redirect(route('incharge.emergency-department-log', ['selectedDate' => $this->checklistDate]), navigate: true);
    }

    /**
     * @return array{answered: int, total: int}
     */
    public function sectionCompletion(string $section): array
    {
        $answered = 0;
        $total = 0;
        $service = app(EmergencyDepartmentLogService::class);

        if ($section === 'header') {
            return [
                'answered' => filled($this->completedByName) ? 1 : 0,
                'total' => 1,
            ];
        }

        if ($section === 'A') {
            foreach (array_keys($this->definition->handoverMetrics()) as $itemKey) {
                $total++;
                if (($this->handover[$itemKey]['count'] ?? '') !== '' && $this->handover[$itemKey]['count'] !== null) {
                    $answered++;
                }
            }

            return compact('answered', 'total');
        }

        if ($section === 'B') {
            foreach (array_keys($this->definition->equipmentItems()) as $itemKey) {
                $total++;
                if (filled($this->equipment[$itemKey]['status'] ?? null)) {
                    $answered++;
                }
            }

            return compact('answered', 'total');
        }

        if ($section === 'C') {
            foreach ($this->definition->crashCartItems() as $item) {
                $total++;
                if (($this->crashCart[$item['item_key']]['adequate'] ?? null) !== null) {
                    $answered++;
                }
            }

            return compact('answered', 'total');
        }

        if ($section === 'D') {
            foreach ($this->definition->cleaningItems() as $item) {
                $key = $service->cleaningKey($item['section'], $item['item_key']);
                $total++;
                if (($this->cleaning[$key] ?? null) !== null) {
                    $answered++;
                }
            }

            return compact('answered', 'total');
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

    public function cleaningKey(string $section, string $itemKey): string
    {
        return app(EmergencyDepartmentLogService::class)->cleaningKey($section, $itemKey);
    }

    public function statusColorClass(string $statusValue): string
    {
        return match ($statusValue) {
            'ok' => 'bg-emerald-500',
            'issue' => 'bg-rose-500',
            default => 'bg-transparent',
        };
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="1">{{ __('Emergency Department Operational Log') }}</flux:heading>
                <flux:text class="text-zinc-500">
                    {{ __(':shift · :date', [
                        'shift' => $this->shift->label(),
                        'date' => \Illuminate\Support\Carbon::parse($checklistDate)->format('M j, Y'),
                    ]) }}
                </flux:text>
            </div>
            <flux:button variant="ghost" :href="route('incharge.emergency-department-log', ['selectedDate' => $checklistDate])" wire:navigate icon="arrow-left">
                {{ __('Back') }}
            </flux:button>
        </div>

        @if ($this->existingEntry)
            @include('pages.incharge.partials.emergency-department-log-view', ['entry' => $this->existingEntry])
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
                        <flux:heading level="2" class="mb-4">{{ __('Log Header') }}</flux:heading>
                        <flux:callout icon="information-circle" class="mb-4">
                            <flux:callout.text>
                                {{ __('Mohsin Medical Complex — Standard Operating Checklist & Departmental Audit. Complete handover counts, equipment status, crash-cart stock, and cleaning checks for this shift.') }}
                            </flux:callout.text>
                        </flux:callout>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>{{ __('Completed By') }}</flux:label>
                                <flux:input wire:model="completedByName" required />
                                <flux:error name="completedByName" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Supervisor') }}</flux:label>
                                <flux:input wire:model="supervisorName" />
                                <flux:error name="supervisorName" />
                            </flux:field>
                        </div>
                    </flux:card>
                @endif

                @if ($activeSection === 'A')
                    <flux:card>
                        <flux:heading level="2" class="mb-4">{{ __('A. Department Summary & Handover') }}</flux:heading>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[640px] border-collapse text-sm">
                                <thead>
                                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                        <th class="py-2 pe-3 text-start">{{ __('Metric') }}</th>
                                        <th class="w-28 px-2 py-2 text-start">{{ __('Count') }}</th>
                                        <th class="py-2 text-start">{{ __('Notes / Remarks') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->definition->handoverMetrics() as $itemKey => $label)
                                        <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="handover-{{ $itemKey }}">
                                            <td class="py-3 pe-3 align-top">{{ $label }}</td>
                                            <td class="px-2 py-2 align-top">
                                                <flux:input type="number" min="0" wire:model="handover.{{ $itemKey }}.count" />
                                                <flux:error name="handover.{{ $itemKey }}.count" />
                                            </td>
                                            <td class="py-2 align-top">
                                                <flux:input wire:model="handover.{{ $itemKey }}.remarks" />
                                            </td>
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
                            <flux:heading level="2">{{ __('B. Emergency Equipment Status') }}</flux:heading>
                            <flux:button type="button" size="sm" variant="outline" wire:click="markEquipmentOk">
                                {{ __('Mark all OK') }}
                            </flux:button>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[720px] border-collapse text-sm">
                                <thead>
                                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                        <th class="py-2 pe-3 text-start">{{ __('Equipment Item') }}</th>
                                        <th class="w-40 px-2 py-2 text-center">{{ __('Status') }}</th>
                                        <th class="py-2 text-start">{{ __('Issue / Action Required') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->definition->equipmentItems() as $itemKey => $item)
                                        @php $statusValue = $this->equipment[$itemKey]['status'] ?? ''; @endphp
                                        <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="equip-{{ $itemKey }}">
                                            <td class="py-3 pe-3 align-top">
                                                <div>{{ $item['label'] }}</div>
                                                <div class="text-xs text-zinc-500">{{ __('Expected: :status', ['status' => $item['expected']]) }}</div>
                                            </td>
                                            <td class="px-2 py-2 align-top">
                                                <div class="flex items-center gap-2">
                                                    <span class="size-2.5 rounded-full {{ $this->statusColorClass($statusValue) }}" aria-hidden="true"></span>
                                                    <flux:select wire:model="equipment.{{ $itemKey }}.status" size="sm">
                                                        <option value="">—</option>
                                                        <option value="ok">{{ __('OK') }}</option>
                                                        <option value="issue">{{ __('Issue') }}</option>
                                                    </flux:select>
                                                </div>
                                                <flux:error name="equipment.{{ $itemKey }}.status" />
                                            </td>
                                            <td class="py-2 align-top">
                                                <flux:input wire:model="equipment.{{ $itemKey }}.remarks" />
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-4">
                            <flux:field>
                                <flux:label>{{ __('Equipment Issues / Maintenance Log') }}</flux:label>
                                <flux:textarea wire:model="equipmentIssuesLog" rows="3" />
                                <flux:error name="equipmentIssuesLog" />
                            </flux:field>
                        </div>
                    </flux:card>
                @endif

                @if ($activeSection === 'C')
                    <flux:card>
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <flux:heading level="2">{{ __('C. Crash Cart / ER Trolley Stock') }}</flux:heading>
                            <flux:button type="button" size="sm" variant="outline" wire:click="markCrashCartAdequate">
                                {{ __('Mark all adequate') }}
                            </flux:button>
                        </div>
                        <div class="flex flex-col gap-8">
                            @foreach ($this->definition->crashCartDrawers() as $drawerKey => $drawer)
                                <div wire:key="drawer-{{ $drawerKey }}">
                                    <flux:heading level="3" class="mb-3">{{ $drawer['label'] }}</flux:heading>
                                    <div class="grid gap-3 md:grid-cols-2">
                                        @foreach ($drawer['items'] as $itemKey => $item)
                                            <div class="flex flex-col gap-2 rounded-lg border border-zinc-100 p-3 dark:border-zinc-800" wire:key="stock-{{ $itemKey }}">
                                                <flux:text>{{ $this->definition->crashCartItemLabel($item) }}</flux:text>
                                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                                    <flux:select wire:model="crashCart.{{ $itemKey }}.adequate" class="sm:w-40">
                                                        <option value="">—</option>
                                                        <option value="1">{{ __('Adequate') }}</option>
                                                        <option value="0">{{ __('Short / Missing') }}</option>
                                                    </flux:select>
                                                    <flux:input wire:model="crashCart.{{ $itemKey }}.remarks" :placeholder="__('Notes')" />
                                                </div>
                                                <flux:error name="crashCart.{{ $itemKey }}.adequate" />
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </flux:card>
                @endif

                @if ($activeSection === 'D')
                    <flux:card>
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <flux:heading level="2">{{ __('D. Cleaning & Facility Maintenance') }}</flux:heading>
                            <flux:button type="button" size="sm" variant="outline" wire:click="markCleaningDone">
                                {{ __('Mark all done') }}
                            </flux:button>
                        </div>
                        <div class="flex flex-col gap-8">
                            @foreach ($this->definition->cleaningGroups() as $groupKey => $group)
                                <div wire:key="clean-group-{{ $groupKey }}">
                                    <flux:heading level="3" class="mb-3">{{ $group['label'] }}</flux:heading>
                                    <div class="space-y-3">
                                        @foreach ($group['items'] as $itemKey => $label)
                                            @php $key = $this->cleaningKey($groupKey, $itemKey); @endphp
                                            <div class="flex flex-col gap-2 rounded-lg border border-zinc-100 p-3 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800" wire:key="clean-{{ $key }}">
                                                <flux:text>{{ $label }}</flux:text>
                                                <flux:select wire:model="cleaning.{{ $key }}" class="sm:w-36">
                                                    <option value="">—</option>
                                                    <option value="1">{{ __('Done') }}</option>
                                                    <option value="0">{{ __('Not done') }}</option>
                                                </flux:select>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </flux:card>
                @endif

                <div class="sticky bottom-0 z-10 rounded-xl border border-zinc-200 bg-white/95 p-4 shadow-sm backdrop-blur-sm dark:border-zinc-700 dark:bg-zinc-900/95">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex flex-col gap-1">
                            <div class="flex items-center gap-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                <flux:icon name="chart-pie" class="size-4 text-primary" />
                                {{ __(':answered of :total completed', ['answered' => $progress['answered'], 'total' => $progress['total']]) }}
                            </div>
                            <flux:text class="text-xs text-zinc-500">
                                {{ __('Use section tabs to complete the form, then submit from any section.') }}
                            </flux:text>
                        </div>
                        <flux:button type="submit" variant="primary" icon="check">
                            {{ __('Submit Log') }}
                        </flux:button>
                    </div>
                </div>
            </form>
        @endif
    </div>
</div>
