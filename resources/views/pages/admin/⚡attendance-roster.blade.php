<?php

use App\Enums\DutyAssignmentStatus;
use App\Enums\DutyAssignmentType;
use App\Models\DutyAssignment;
use App\Models\DutyLocation;
use App\Models\DutyShiftTemplate;
use App\Models\HealthAide;
use App\Services\NotificationService;
use App\Services\RosterSchedulingService;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Duty Roster')] class extends Component
{
    public string $weekStart;

    public ?int $filterHealthAideId = null;

    public bool $showRecurringModal = false;

    public bool $showOverrideModal = false;

    public ?int $editingAssignmentId = null;

    public ?int $healthAideId = null;

    /** @var list<int|string> */
    public array $selectedHealthAideIds = [];

    /** @var list<int|string> */
    public array $selectedWeekdays = [1, 2, 3, 4, 5, 6, 7];

    public ?int $templateId = null;

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $dutyStartAt = '';

    public string $dutyEndAt = '';

    public string $assignmentType = 'regular';

    public ?int $dutyLocationId = null;

    public string $notes = '';

    public function mount(): void
    {
        if (! auth()->user()?->isAdmin() && ! auth()->user()?->isManagement()) {
            abort(403);
        }

        $this->weekStart = now()->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    #[Computed]
    public function weekDays()
    {
        $start = Carbon::parse($this->weekStart);

        return collect(range(0, 6))->map(fn (int $offset) => $start->copy()->addDays($offset));
    }

    #[Computed]
    public function assignments()
    {
        return app(RosterSchedulingService::class)->assignmentsOverlappingWeek(
            Carbon::parse($this->weekStart),
            $this->filterHealthAideId,
        );
    }

    #[Computed]
    public function calendarSegments(): array
    {
        return app(RosterSchedulingService::class)->calendarSegmentsForWeek(
            $this->assignments,
            Carbon::parse($this->weekStart),
        );
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
    public function dutyLocations()
    {
        return DutyLocation::query()->active()->orderBy('sort_order')->orderBy('name')->get();
    }

    #[Computed]
    public function dutyEndHint(): ?string
    {
        if ($this->dutyStartAt === '' || $this->dutyEndAt === '') {
            return null;
        }

        $start = Carbon::parse($this->dutyStartAt);
        $end = Carbon::parse($this->dutyEndAt);

        if ($end->greaterThan($start) && $end->toDateString() !== $start->toDateString()) {
            return __('Ends :day at :time (next day)', [
                'day' => $end->format('D'),
                'time' => $end->format('H:i'),
            ]);
        }

        return null;
    }

    public function previousWeek(): void
    {
        $this->weekStart = Carbon::parse($this->weekStart)->subWeek()->toDateString();
        $this->refreshCalendar();
    }

    public function nextWeek(): void
    {
        $this->weekStart = Carbon::parse($this->weekStart)->addWeek()->toDateString();
        $this->refreshCalendar();
    }

    public function goToToday(): void
    {
        $this->weekStart = now()->startOfWeek(Carbon::MONDAY)->toDateString();
        $this->refreshCalendar();
    }

    public function openRecurringModal(): void
    {
        $this->resetForm();
        $this->dateFrom = Carbon::parse($this->weekStart)->toDateString();
        $this->dateTo = Carbon::parse($this->weekStart)->addDays(6)->toDateString();
        $this->selectedWeekdays = [1, 2, 3, 4, 5, 6, 7];
        $this->dutyStartAt = Carbon::parse($this->weekStart)->setTime(7, 0)->format('Y-m-d\TH:i');
        $this->dutyEndAt = Carbon::parse($this->weekStart)->setTime(15, 0)->format('Y-m-d\TH:i');
        $this->showRecurringModal = true;
    }

    public function openOverrideModal(?string $date = null, ?int $hour = null): void
    {
        $this->resetForm();
        $this->editingAssignmentId = null;

        $start = $date !== null
            ? Carbon::parse($date)->setTime($hour ?? 7, 0)
            : now()->setMinute(0)->setSecond(0);

        $this->dutyStartAt = $start->format('Y-m-d\TH:i');
        $this->dutyEndAt = $start->copy()->addHours(8)->format('Y-m-d\TH:i');
        $this->showOverrideModal = true;
    }

    public function openEditOverride(int $id): void
    {
        $assignment = DutyAssignment::query()->with('healthAide')->findOrFail($id);

        $this->editingAssignmentId = $assignment->id;
        $this->healthAideId = $assignment->health_aide_id;
        $this->templateId = $assignment->duty_shift_template_id;
        $this->dutyStartAt = $assignment->starts_at->format('Y-m-d\TH:i');
        $this->dutyEndAt = $assignment->ends_at->format('Y-m-d\TH:i');
        $this->assignmentType = $assignment->assignment_type->value;
        $this->dutyLocationId = $assignment->duty_location_id;
        $this->notes = $assignment->notes ?? '';
        $this->showOverrideModal = true;
    }

    public function saveRecurringSchedule(NotificationService $notifications, RosterSchedulingService $scheduler): void
    {
        $validated = $this->validate([
            'selectedHealthAideIds' => ['required', 'array', 'min:1'],
            'selectedHealthAideIds.*' => ['integer', 'exists:health_aides,id'],
            'selectedWeekdays' => ['required', 'array', 'min:1'],
            'selectedWeekdays.*' => ['integer', 'between:1,7'],
            'templateId' => ['nullable', 'exists:duty_shift_templates,id'],
            'dateFrom' => ['required', 'date'],
            'dateTo' => ['required', 'date', 'after_or_equal:dateFrom'],
            'dutyStartAt' => ['required', 'date'],
            'dutyEndAt' => ['required_without:templateId', 'nullable', 'date', 'after:dutyStartAt'],
            'assignmentType' => ['required', Rule::in(array_column(DutyAssignmentType::cases(), 'value'))],
            'dutyLocationId' => ['required', 'exists:duty_locations,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $template = $validated['templateId']
            ? DutyShiftTemplate::query()->find((int) $validated['templateId'])
            : null;

        $dutyStartAt = Carbon::parse($validated['dutyStartAt']);
        $dutyEndAt = isset($validated['dutyEndAt']) ? Carbon::parse($validated['dutyEndAt']) : null;

        $created = $scheduler->createRecurringAssignments(
            healthAideIds: array_map('intval', $validated['selectedHealthAideIds']),
            dateFrom: Carbon::parse($validated['dateFrom'])->startOfDay(),
            dateTo: Carbon::parse($validated['dateTo'])->startOfDay(),
            weekdays: array_map('intval', $validated['selectedWeekdays']),
            dutyStartAt: $dutyStartAt,
            dutyEndAt: $dutyEndAt,
            template: $template,
            dutyLocationId: (int) $validated['dutyLocationId'],
            assignmentType: DutyAssignmentType::from($validated['assignmentType']),
            notes: $validated['notes'] ?: null,
            createdBy: auth()->user(),
        );

        foreach ($created as $assignment) {
            if ($assignment->assignment_type === DutyAssignmentType::Emergency) {
                $notifications->notifyEmergencyShiftAssigned($assignment, auth()->user());
            }
        }

        $this->showRecurringModal = false;
        $this->resetForm();
        $this->refreshCalendar();
        Flux::toast(variant: 'success', text: __('Created :count duty assignment(s).', ['count' => count($created)]));
    }

    public function saveOverride(NotificationService $notifications, RosterSchedulingService $scheduler): void
    {
        $validated = $this->validate([
            'healthAideId' => ['required', 'exists:health_aides,id'],
            'editingAssignmentId' => ['nullable', 'exists:duty_assignments,id'],
            'templateId' => ['nullable', 'exists:duty_shift_templates,id'],
            'dutyStartAt' => ['required', 'date'],
            'dutyEndAt' => ['required_without:templateId', 'nullable', 'date', 'after:dutyStartAt'],
            'assignmentType' => ['required', Rule::in(array_column(DutyAssignmentType::cases(), 'value'))],
            'dutyLocationId' => ['required', 'exists:duty_locations,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $template = $validated['templateId']
            ? DutyShiftTemplate::query()->find((int) $validated['templateId'])
            : null;

        $assignment = $scheduler->createOrUpdateOverride(
            healthAideId: (int) $validated['healthAideId'],
            dutyStartAt: Carbon::parse($validated['dutyStartAt']),
            dutyEndAt: isset($validated['dutyEndAt']) ? Carbon::parse($validated['dutyEndAt']) : null,
            template: $template,
            dutyLocationId: (int) $validated['dutyLocationId'],
            assignmentType: DutyAssignmentType::from($validated['assignmentType']),
            notes: $validated['notes'] ?: null,
            createdBy: auth()->user(),
            editingAssignmentId: $validated['editingAssignmentId'] ? (int) $validated['editingAssignmentId'] : null,
        );

        if ($assignment->assignment_type === DutyAssignmentType::Emergency && $validated['editingAssignmentId'] === null) {
            $notifications->notifyEmergencyShiftAssigned($assignment, auth()->user());
        }

        $this->showOverrideModal = false;
        $this->resetForm();
        $this->refreshCalendar();
        Flux::toast(variant: 'success', text: $validated['editingAssignmentId']
            ? __('Duty assignment updated.')
            : __('Date override saved.'));
    }

    public function cancelAssignment(int $id): void
    {
        DutyAssignment::query()->findOrFail($id)->update([
            'status' => DutyAssignmentStatus::Cancelled,
        ]);

        $this->refreshCalendar();
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

    public function toggleWeekday(int $weekday): void
    {
        $days = array_map('intval', $this->selectedWeekdays);

        if (in_array($weekday, $days, true)) {
            $this->selectedWeekdays = array_values(array_filter($days, fn (int $day) => $day !== $weekday));
        } else {
            $this->selectedWeekdays = [...$days, $weekday];
        }
    }

    public function updatedFilterHealthAideId(): void
    {
        $this->refreshCalendar();
    }

    public function updatedTemplateId(): void
    {
        if ($this->templateId === null || $this->templateId === '') {
            return;
        }

        $template = DutyShiftTemplate::query()->find($this->templateId);

        if ($template === null) {
            return;
        }

        $reference = $this->dutyStartAt !== ''
            ? Carbon::parse($this->dutyStartAt)
            : Carbon::parse($this->weekStart)->setTime(7, 0);

        $window = $template->windowForDate($reference->copy()->startOfDay());
        $this->dutyStartAt = $window['starts_at']->format('Y-m-d\TH:i');
        $this->dutyEndAt = $window['ends_at']->format('Y-m-d\TH:i');
    }

    public function resetForm(): void
    {
        $this->healthAideId = null;
        $this->selectedHealthAideIds = [];
        $this->selectedWeekdays = [1, 2, 3, 4, 5, 6, 7];
        $this->templateId = null;
        $this->dateFrom = today()->toDateString();
        $this->dateTo = today()->toDateString();
        $this->dutyStartAt = '';
        $this->dutyEndAt = '';
        $this->assignmentType = DutyAssignmentType::Regular->value;
        $this->dutyLocationId = null;
        $this->notes = '';
        $this->editingAssignmentId = null;
        $this->resetValidation();
    }

    private function refreshCalendar(): void
    {
        unset($this->assignments, $this->calendarSegments, $this->weekDays);
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <flux:heading level="1">{{ __('Duty Roster') }}</flux:heading>
        <div class="flex flex-wrap items-center gap-2">
            <flux:select wire:model.live="filterHealthAideId" class="min-w-48">
                <option value="">{{ __('All health aides') }}</option>
                @foreach ($this->healthAides as $aide)
                    <option value="{{ $aide->id }}">{{ $aide->name }}</option>
                @endforeach
            </flux:select>
            <flux:button wire:click="previousWeek" icon="chevron-left">{{ __('Previous') }}</flux:button>
            <flux:button wire:click="goToToday">{{ __('Today') }}</flux:button>
            <flux:button wire:click="nextWeek" icon="chevron-right">{{ __('Next') }}</flux:button>
            <flux:button wire:click="openRecurringModal" icon="calendar-days">{{ __('Recurring Schedule') }}</flux:button>
            <flux:button variant="primary" wire:click="openOverrideModal" icon="plus">{{ __('Date Override') }}</flux:button>
        </div>
    </div>

    <flux:text class="text-sm text-zinc-500">
        {{ __('Week of :start – :end', [
            'start' => $this->weekDays->first()->format('M j, Y'),
            'end' => $this->weekDays->last()->format('M j, Y'),
        ]) }}
    </flux:text>

    <x-roster.week-calendar
        :week-days="$this->weekDays"
        :segments="$this->calendarSegments"
    />

    <flux:modal wire:model="showRecurringModal" class="md:w-2xl">
        <flux:heading size="lg">{{ __('Recurring Schedule') }}</flux:heading>
        <form wire:submit="saveRecurringSchedule" class="mt-4 space-y-4">
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
                        <label wire:key="recurring-aide-{{ $aide->id }}" class="flex cursor-pointer items-center gap-2 text-sm">
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

            <div>
                <flux:label class="mb-2">{{ __('Repeat on') }}</flux:label>
                <div class="flex flex-wrap gap-2">
                    @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $dayNumber => $dayLabel)
                        <label wire:key="weekday-{{ $dayNumber }}" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-zinc-200 px-2.5 py-1.5 text-sm dark:border-zinc-700">
                            <input
                                type="checkbox"
                                value="{{ $dayNumber }}"
                                wire:model="selectedWeekdays"
                                class="size-4 rounded border-zinc-400"
                            >
                            <span>{{ __($dayLabel) }}</span>
                        </label>
                    @endforeach
                </div>
                <flux:error name="selectedWeekdays" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="dateFrom" type="date" label="{{ __('From') }}" required />
                <flux:input wire:model="dateTo" type="date" label="{{ __('To') }}" required />
            </div>

            <flux:select wire:model.live="templateId" label="{{ __('Shift Template') }}">
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

            <flux:input wire:model.live="dutyStartAt" type="datetime-local" label="{{ __('Duty starts at') }}" required />
            <flux:input wire:model.live="dutyEndAt" type="datetime-local" label="{{ __('Duty ends at') }}" :required="$templateId === null || $templateId === ''" />
            @if ($this->dutyEndHint)
                <flux:text class="text-sm text-zinc-500">{{ $this->dutyEndHint }}</flux:text>
            @endif

            <flux:select wire:model="dutyLocationId" label="{{ __('Place') }}" required>
                <option value="">{{ __('Select location') }}</option>
                @foreach ($this->dutyLocations as $location)
                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                @endforeach
            </flux:select>

            <flux:textarea wire:model="notes" label="{{ __('Notes') }}" />

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" type="button" wire:click="$set('showRecurringModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Create Assignments') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showOverrideModal" class="md:w-lg">
        <flux:heading size="lg">
            {{ $editingAssignmentId ? __('Edit Duty Override') : __('Date Override') }}
        </flux:heading>
        <form wire:submit="saveOverride" class="mt-4 space-y-4">
            @if ($editingAssignmentId)
                <flux:text>{{ $this->healthAides->firstWhere('id', $healthAideId)?->name }}</flux:text>
            @else
                <flux:select wire:model="healthAideId" label="{{ __('Health Aide') }}" required>
                    <option value="">{{ __('Select aide') }}</option>
                    @foreach ($this->healthAides as $aide)
                        <option value="{{ $aide->id }}">{{ $aide->name }}</option>
                    @endforeach
                </flux:select>
            @endif

            <flux:select wire:model.live="templateId" label="{{ __('Shift Template') }}">
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

            <flux:input wire:model.live="dutyStartAt" type="datetime-local" label="{{ __('Duty starts at') }}" required />
            <flux:input wire:model.live="dutyEndAt" type="datetime-local" label="{{ __('Duty ends at') }}" required />
            @if ($this->dutyEndHint)
                <flux:text class="text-sm text-zinc-500">{{ $this->dutyEndHint }}</flux:text>
            @endif

            <flux:select wire:model="dutyLocationId" label="{{ __('Place') }}" required>
                <option value="">{{ __('Select location') }}</option>
                @foreach ($this->dutyLocations as $location)
                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                @endforeach
            </flux:select>

            <flux:textarea wire:model="notes" label="{{ __('Notes') }}" />

            <div class="flex justify-between gap-2">
                @if ($editingAssignmentId)
                    <flux:button type="button" variant="danger" wire:click="cancelAssignment({{ $editingAssignmentId }})" wire:confirm="{{ __('Cancel this duty assignment?') }}">
                        {{ __('Cancel duty') }}
                    </flux:button>
                @else
                    <span></span>
                @endif
                <div class="flex gap-2">
                    <flux:button variant="ghost" type="button" wire:click="$set('showOverrideModal', false)">{{ __('Close') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ $editingAssignmentId ? __('Update') : __('Save Override') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
</div>
