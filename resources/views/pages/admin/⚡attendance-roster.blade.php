<?php

use App\Enums\DutyAssignmentStatus;
use App\Enums\DutyAssignmentType;
use App\Models\DutyAssignment;
use App\Models\DutyShiftTemplate;
use App\Models\HealthAide;
use App\Services\NotificationService;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Duty Roster')] class extends Component
{
    public string $weekStart;

    public bool $showModal = false;

    public bool $showBulkModal = false;

    public bool $showEditModal = false;

    public ?int $editingAssignmentId = null;

    public ?int $healthAideId = null;

    /** @var list<int|string> */
    public array $selectedHealthAideIds = [];

    public ?int $templateId = null;

    public string $date = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $assignmentType = 'regular';

    public ?string $customStart = null;

    public ?string $customEnd = null;

    public string $station = '';

    public string $notes = '';

    public function mount(): void
    {
        if (! auth()->user()?->isAdmin() && ! auth()->user()?->isManagement()) {
            abort(403);
        }

        $this->weekStart = now()->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    #[Computed]
    public function assignments()
    {
        $start = Carbon::parse($this->weekStart);
        $end = $start->copy()->addDays(6);

        return DutyAssignment::query()
            ->scheduled()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with(['healthAide', 'shiftTemplate'])
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn (DutyAssignment $assignment) => $assignment->date->toDateString());
    }

    #[Computed]
    public function healthAides()
    {
        return HealthAide::query()->active()->orderBy('name')->get();
    }

    #[Computed]
    public function templates()
    {
        return DutyShiftTemplate::query()->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function weekDays()
    {
        $start = Carbon::parse($this->weekStart);

        return collect(range(0, 6))->map(fn (int $offset) => $start->copy()->addDays($offset));
    }

    public function previousWeek(): void
    {
        $this->weekStart = Carbon::parse($this->weekStart)->subWeek()->toDateString();
        unset($this->assignments);
    }

    public function nextWeek(): void
    {
        $this->weekStart = Carbon::parse($this->weekStart)->addWeek()->toDateString();
        unset($this->assignments);
    }

    public function openCreateModal(?string $date = null): void
    {
        $this->resetForm();
        $this->date = $date ?? today()->toDateString();
        $this->showModal = true;
    }

    public function openBulkModal(): void
    {
        $this->resetForm();
        $this->dateFrom = Carbon::parse($this->weekStart)->toDateString();
        $this->dateTo = Carbon::parse($this->weekStart)->addDays(6)->toDateString();
        $this->selectedHealthAideIds = [];
        $this->showBulkModal = true;
    }

    public function openEditModal(int $id): void
    {
        $assignment = DutyAssignment::query()->findOrFail($id);

        $this->editingAssignmentId = $assignment->id;
        $this->healthAideId = $assignment->health_aide_id;
        $this->templateId = $assignment->duty_shift_template_id;
        $this->date = $assignment->date->toDateString();
        $this->assignmentType = $assignment->assignment_type->value;
        $this->customStart = $assignment->starts_at->format('H:i');
        $this->customEnd = $assignment->ends_at->format('H:i');
        $this->station = $assignment->station ?? '';
        $this->notes = $assignment->notes ?? '';
        $this->showEditModal = true;
    }

    public function saveAssignment(NotificationService $notifications): void
    {
        $validated = $this->validate([
            'healthAideId' => ['required', 'exists:health_aides,id'],
            'templateId' => ['nullable', 'exists:duty_shift_templates,id'],
            'date' => ['required', 'date'],
            'assignmentType' => ['required', Rule::in(array_column(DutyAssignmentType::cases(), 'value'))],
            'customStart' => ['nullable', 'date_format:H:i'],
            'customEnd' => ['nullable', 'date_format:H:i'],
            'station' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $window = $this->resolveWindow(
            Carbon::parse($validated['date']),
            $validated['templateId'] ? (int) $validated['templateId'] : null,
            $validated['customStart'] ?? null,
            $validated['customEnd'] ?? null,
        );

        $assignment = DutyAssignment::query()->create([
            'health_aide_id' => $validated['healthAideId'],
            'duty_shift_template_id' => $validated['templateId'] ?: null,
            'date' => Carbon::parse($validated['date'])->toDateString(),
            'starts_at' => $window['starts_at'],
            'ends_at' => $window['ends_at'],
            'assignment_type' => DutyAssignmentType::from($validated['assignmentType']),
            'station' => $validated['station'] ?: null,
            'notes' => $validated['notes'] ?: null,
            'status' => DutyAssignmentStatus::Scheduled,
            'created_by' => auth()->id(),
        ]);

        if ($assignment->assignment_type === DutyAssignmentType::Emergency) {
            $notifications->notifyEmergencyShiftAssigned($assignment, auth()->user());
        }

        $this->showModal = false;
        $this->resetForm();
        unset($this->assignments);
        Flux::toast(variant: 'success', text: __('Duty assignment created.'));
    }

    public function saveBulkAssignments(NotificationService $notifications): void
    {
        $validated = $this->validate([
            'selectedHealthAideIds' => ['required', 'array', 'min:1'],
            'selectedHealthAideIds.*' => ['integer', 'exists:health_aides,id'],
            'templateId' => ['nullable', 'exists:duty_shift_templates,id'],
            'dateFrom' => ['required', 'date'],
            'dateTo' => ['required', 'date', 'after_or_equal:dateFrom'],
            'assignmentType' => ['required', Rule::in(array_column(DutyAssignmentType::cases(), 'value'))],
            'customStart' => ['nullable', 'date_format:H:i'],
            'customEnd' => ['nullable', 'date_format:H:i'],
            'station' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $from = Carbon::parse($validated['dateFrom'])->startOfDay();
        $to = Carbon::parse($validated['dateTo'])->startOfDay();
        $created = 0;

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $window = $this->resolveWindow(
                $date->copy(),
                $validated['templateId'] ? (int) $validated['templateId'] : null,
                $validated['customStart'] ?? null,
                $validated['customEnd'] ?? null,
            );

            foreach ($validated['selectedHealthAideIds'] as $aideId) {
                $assignment = DutyAssignment::query()->create([
                    'health_aide_id' => $aideId,
                    'duty_shift_template_id' => $validated['templateId'] ?: null,
                    'date' => $date->toDateString(),
                    'starts_at' => $window['starts_at'],
                    'ends_at' => $window['ends_at'],
                    'assignment_type' => DutyAssignmentType::from($validated['assignmentType']),
                    'station' => $validated['station'] ?: null,
                    'notes' => $validated['notes'] ?: null,
                    'status' => DutyAssignmentStatus::Scheduled,
                    'created_by' => auth()->id(),
                ]);

                if ($assignment->assignment_type === DutyAssignmentType::Emergency) {
                    $notifications->notifyEmergencyShiftAssigned($assignment, auth()->user());
                }

                $created++;
            }
        }

        $this->showBulkModal = false;
        $this->resetForm();
        unset($this->assignments);
        Flux::toast(variant: 'success', text: __('Created :count duty assignment(s).', ['count' => $created]));
    }

    public function updateAssignment(): void
    {
        $validated = $this->validate([
            'editingAssignmentId' => ['required', 'exists:duty_assignments,id'],
            'templateId' => ['nullable', 'exists:duty_shift_templates,id'],
            'date' => ['required', 'date'],
            'assignmentType' => ['required', Rule::in(array_column(DutyAssignmentType::cases(), 'value'))],
            'customStart' => ['required', 'date_format:H:i'],
            'customEnd' => ['required', 'date_format:H:i'],
            'station' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $assignment = DutyAssignment::query()->findOrFail($validated['editingAssignmentId']);

        $window = $this->resolveWindow(
            Carbon::parse($validated['date']),
            $validated['templateId'] ? (int) $validated['templateId'] : null,
            $validated['customStart'],
            $validated['customEnd'],
        );

        $assignment->update([
            'duty_shift_template_id' => $validated['templateId'] ?: null,
            'date' => Carbon::parse($validated['date'])->toDateString(),
            'starts_at' => $window['starts_at'],
            'ends_at' => $window['ends_at'],
            'assignment_type' => DutyAssignmentType::from($validated['assignmentType']),
            'station' => $validated['station'] ?: null,
            'notes' => $validated['notes'] ?: null,
        ]);

        $this->showEditModal = false;
        $this->editingAssignmentId = null;
        $this->resetForm();
        unset($this->assignments);
        Flux::toast(variant: 'success', text: __('Duty assignment updated.'));
    }

    public function cancelAssignment(int $id): void
    {
        DutyAssignment::query()->findOrFail($id)->update([
            'status' => DutyAssignmentStatus::Cancelled,
        ]);

        unset($this->assignments);
        Flux::toast(variant: 'success', text: __('Duty assignment cancelled.'));
    }

    public function toggleAideSelection(int $aideId): void
    {
        $ids = array_map('intval', $this->selectedHealthAideIds);

        if (in_array($aideId, $ids, true)) {
            $this->selectedHealthAideIds = array_values(array_filter($ids, fn (int $id) => $id !== $aideId));
        } else {
            $this->selectedHealthAideIds = [...$ids, $aideId];
        }
    }

    public function selectAllAides(): void
    {
        $this->selectedHealthAideIds = $this->healthAides->pluck('id')->all();
    }

    public function clearAideSelection(): void
    {
        $this->selectedHealthAideIds = [];
    }

    /**
     * @return array{starts_at: Carbon, ends_at: Carbon}
     */
    private function resolveWindow(Carbon $date, ?int $templateId, ?string $customStart, ?string $customEnd): array
    {
        $template = $templateId
            ? DutyShiftTemplate::query()->find($templateId)
            : null;

        if ($template !== null) {
            return $template->windowForDate($date);
        }

        $startsAt = $date->copy()->setTimeFromTimeString($customStart ?? '07:00');
        $endsAt = $date->copy()->setTimeFromTimeString($customEnd ?? '15:00');

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt->addDay();
        }

        return [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];
    }

    public function resetForm(): void
    {
        $this->healthAideId = null;
        $this->selectedHealthAideIds = [];
        $this->templateId = null;
        $this->date = today()->toDateString();
        $this->dateFrom = today()->toDateString();
        $this->dateTo = today()->toDateString();
        $this->assignmentType = DutyAssignmentType::Regular->value;
        $this->customStart = null;
        $this->customEnd = null;
        $this->station = '';
        $this->notes = '';
        $this->editingAssignmentId = null;
        $this->resetValidation();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <flux:heading level="1">{{ __('Duty Roster') }}</flux:heading>
        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="previousWeek" icon="chevron-left">{{ __('Previous') }}</flux:button>
            <flux:button wire:click="nextWeek" icon="chevron-right">{{ __('Next') }}</flux:button>
            <flux:button wire:click="openBulkModal" icon="users">{{ __('Bulk Assign') }}</flux:button>
            <flux:button variant="primary" wire:click="openCreateModal" icon="plus">{{ __('Assign Duty') }}</flux:button>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-7">
        @foreach ($this->weekDays as $day)
            <flux:card wire:key="day-{{ $day->toDateString() }}">
                <div class="mb-3 flex items-center justify-between">
                    <flux:heading size="sm">{{ $day->format('D, M j') }}</flux:heading>
                    <flux:button size="sm" variant="ghost" wire:click="openCreateModal('{{ $day->toDateString() }}')" icon="plus" />
                </div>
                <div class="space-y-2">
                    @foreach ($this->assignments->get($day->toDateString(), collect()) as $assignment)
                        <div wire:key="assignment-{{ $assignment->id }}" class="rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">
                            <div class="font-medium">{{ $assignment->healthAide->name }}</div>
                            <div class="text-xs text-zinc-500">{{ $assignment->starts_at->format('H:i') }} - {{ $assignment->ends_at->format('H:i') }}</div>
                            <flux:badge size="sm">{{ $assignment->assignment_type->label() }}</flux:badge>
                            <div class="mt-1 flex gap-1">
                                <flux:button size="xs" variant="ghost" wire:click="openEditModal({{ $assignment->id }})">{{ __('Edit') }}</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="cancelAssignment({{ $assignment->id }})">{{ __('Cancel') }}</flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @endforeach
    </div>

    <flux:modal wire:model="showModal" class="md:w-lg">
        <flux:heading size="lg">{{ __('Assign Duty') }}</flux:heading>
        <form wire:submit="saveAssignment" class="mt-4 space-y-4">
            <flux:select wire:model="healthAideId" label="{{ __('Health Aide') }}" required>
                <option value="">{{ __('Select aide') }}</option>
                @foreach ($this->healthAides as $aide)
                    <option value="{{ $aide->id }}">{{ $aide->name }}</option>
                @endforeach
            </flux:select>
            <flux:input wire:model="date" type="date" label="{{ __('Date') }}" required />
            <flux:select wire:model="templateId" label="{{ __('Shift Template') }}">
                <option value="">{{ __('Custom times') }}</option>
                @foreach ($this->templates as $template)
                    <option value="{{ $template->id }}">{{ $template->name }} ({{ $template->start_time->format('H:i') }}-{{ $template->end_time->format('H:i') }})</option>
                @endforeach
            </flux:select>
            <flux:select wire:model="assignmentType" label="{{ __('Type') }}">
                @foreach (\App\Enums\DutyAssignmentType::cases() as $type)
                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                @endforeach
            </flux:select>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="customStart" type="time" label="{{ __('Custom Start') }}" />
                <flux:input wire:model="customEnd" type="time" label="{{ __('Custom End') }}" />
            </div>
            <flux:input wire:model="station" label="{{ __('Station') }}" />
            <flux:textarea wire:model="notes" label="{{ __('Notes') }}" />
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" type="button" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showBulkModal" class="md:w-2xl">
        <flux:heading size="lg">{{ __('Bulk Assign Duties') }}</flux:heading>
        <form wire:submit="saveBulkAssignments" class="mt-4 space-y-4">
            <div>
                <div class="mb-2 flex items-center justify-between">
                    <flux:label>{{ __('Health Aides') }}</flux:label>
                    <div class="flex gap-2">
                        <flux:button size="xs" type="button" variant="ghost" wire:click="selectAllAides">{{ __('Select all') }}</flux:button>
                        <flux:button size="xs" type="button" variant="ghost" wire:click="clearAideSelection">{{ __('Clear') }}</flux:button>
                    </div>
                </div>
                <div class="max-h-48 space-y-2 overflow-y-auto rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    @foreach ($this->healthAides as $aide)
                        <label wire:key="bulk-aide-{{ $aide->id }}" class="flex cursor-pointer items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                value="{{ $aide->id }}"
                                wire:model="selectedHealthAideIds"
                                class="size-4 rounded border-zinc-400"
                            >
                            <span>{{ $aide->name }}</span>
                        </label>
                    @endforeach
                </div>
                <flux:error name="selectedHealthAideIds" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="dateFrom" type="date" label="{{ __('From') }}" required />
                <flux:input wire:model="dateTo" type="date" label="{{ __('To') }}" required />
            </div>
            <flux:select wire:model="templateId" label="{{ __('Shift Template') }}">
                <option value="">{{ __('Custom times') }}</option>
                @foreach ($this->templates as $template)
                    <option value="{{ $template->id }}">{{ $template->name }} ({{ $template->start_time->format('H:i') }}-{{ $template->end_time->format('H:i') }})</option>
                @endforeach
            </flux:select>
            <flux:select wire:model="assignmentType" label="{{ __('Type') }}">
                @foreach (\App\Enums\DutyAssignmentType::cases() as $type)
                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                @endforeach
            </flux:select>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="customStart" type="time" label="{{ __('Custom Start') }}" />
                <flux:input wire:model="customEnd" type="time" label="{{ __('Custom End') }}" />
            </div>
            <flux:input wire:model="station" label="{{ __('Station') }}" />
            <flux:textarea wire:model="notes" label="{{ __('Notes') }}" />
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" type="button" wire:click="$set('showBulkModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Create Assignments') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showEditModal" class="md:w-lg">
        <flux:heading size="lg">{{ __('Edit Duty (override day)') }}</flux:heading>
        <form wire:submit="updateAssignment" class="mt-4 space-y-4">
            <flux:text>{{ $this->healthAides->firstWhere('id', $this->healthAideId)?->name }}</flux:text>
            <flux:input wire:model="date" type="date" label="{{ __('Date') }}" required />
            <flux:select wire:model="templateId" label="{{ __('Shift Template') }}">
                <option value="">{{ __('Custom times') }}</option>
                @foreach ($this->templates as $template)
                    <option value="{{ $template->id }}">{{ $template->name }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model="assignmentType" label="{{ __('Type') }}">
                @foreach (\App\Enums\DutyAssignmentType::cases() as $type)
                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                @endforeach
            </flux:select>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="customStart" type="time" label="{{ __('Start') }}" required />
                <flux:input wire:model="customEnd" type="time" label="{{ __('End') }}" required />
            </div>
            <flux:input wire:model="station" label="{{ __('Station') }}" />
            <flux:textarea wire:model="notes" label="{{ __('Notes') }}" />
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" type="button" wire:click="$set('showEditModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Update') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
