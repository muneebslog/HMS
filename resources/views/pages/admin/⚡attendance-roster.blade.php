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

    public ?int $healthAideId = null;

    public ?int $templateId = null;

    public string $date = '';

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

        $date = Carbon::parse($validated['date']);
        $template = $validated['templateId']
            ? DutyShiftTemplate::query()->find($validated['templateId'])
            : null;

        if ($template !== null) {
            $window = $template->windowForDate($date);
            $startsAt = $window['starts_at'];
            $endsAt = $window['ends_at'];
        } else {
            $startsAt = $date->copy()->setTimeFromTimeString($validated['customStart'] ?? '07:00');
            $endsAt = $date->copy()->setTimeFromTimeString($validated['customEnd'] ?? '15:00');
            if ($endsAt->lessThanOrEqualTo($startsAt)) {
                $endsAt->addDay();
            }
        }

        $assignment = DutyAssignment::query()->create([
            'health_aide_id' => $validated['healthAideId'],
            'duty_shift_template_id' => $template?->id,
            'date' => $date->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
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

    public function cancelAssignment(int $id): void
    {
        DutyAssignment::query()->findOrFail($id)->update([
            'status' => DutyAssignmentStatus::Cancelled,
        ]);

        unset($this->assignments);
        Flux::toast(variant: 'success', text: __('Duty assignment cancelled.'));
    }

    public function resetForm(): void
    {
        $this->healthAideId = null;
        $this->templateId = null;
        $this->date = today()->toDateString();
        $this->assignmentType = DutyAssignmentType::Regular->value;
        $this->customStart = null;
        $this->customEnd = null;
        $this->station = '';
        $this->notes = '';
        $this->resetValidation();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <flux:heading level="1">{{ __('Duty Roster') }}</flux:heading>
        <div class="flex gap-2">
            <flux:button wire:click="previousWeek" icon="chevron-left">{{ __('Previous') }}</flux:button>
            <flux:button wire:click="nextWeek" icon="chevron-right">{{ __('Next') }}</flux:button>
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
                            <flux:button size="xs" variant="ghost" wire:click="cancelAssignment({{ $assignment->id }})">{{ __('Cancel') }}</flux:button>
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
                <flux:button variant="ghost" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
