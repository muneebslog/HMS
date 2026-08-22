<?php

use App\Models\HealthAide;
use App\Models\HealthAideLeave;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Health Aide Leave Calendar')] class extends Component
{
    public int $currentMonth;

    public int $currentYear;

    public ?string $selectedDate = null;

    public bool $showModal = false;

    public ?int $editingId = null;

    public ?int $healthAideId = null;

    public ?int $replacementHealthAideId = null;

    public ?string $dutyStartTime = null;

    public ?string $dutyEndTime = null;

    public bool $isInformed = false;

    public string $informedBy = '';

    public string $notes = '';

    public function mount(): void
    {
        if (! auth()->user()?->isAdmin() && ! auth()->user()?->isManagement()) {
            abort(403);
        }

        $this->currentMonth = now()->month;
        $this->currentYear = now()->year;
    }

    #[Computed]
    public function gridStart(): Carbon
    {
        return Carbon::createFromDate($this->currentYear, $this->currentMonth, 1)
            ->startOfMonth()
            ->startOfWeek(Carbon::SUNDAY);
    }

    #[Computed]
    public function gridEnd(): Carbon
    {
        return Carbon::createFromDate($this->currentYear, $this->currentMonth, 1)
            ->endOfMonth()
            ->endOfWeek(Carbon::SATURDAY);
    }

    #[Computed]
    public function calendarDays()
    {
        $days = collect();
        $cursor = $this->gridStart->copy();

        while ($cursor->lessThanOrEqualTo($this->gridEnd)) {
            $days->push($cursor->copy());
            $cursor->addDay();
        }

        return $days;
    }

    #[Computed]
    public function leavesByDate()
    {
        return HealthAideLeave::query()
            ->with(['healthAide', 'replacementHealthAide'])
            ->whereYear('leave_date', $this->currentYear)
            ->whereMonth('leave_date', $this->currentMonth)
            ->get()
            ->groupBy(fn (HealthAideLeave $leave) => $leave->leave_date->toDateString());
    }

    #[Computed]
    public function healthAides()
    {
        return HealthAide::query()->active()->orderBy('name')->get();
    }

    public function previousMonth(): void
    {
        $date = Carbon::createFromDate($this->currentYear, $this->currentMonth, 1)->subMonth();
        $this->currentMonth = $date->month;
        $this->currentYear = $date->year;
        unset($this->calendarDays, $this->leavesByDate);
    }

    public function nextMonth(): void
    {
        $date = Carbon::createFromDate($this->currentYear, $this->currentMonth, 1)->addMonth();
        $this->currentMonth = $date->month;
        $this->currentYear = $date->year;
        unset($this->calendarDays, $this->leavesByDate);
    }

    public function openForDate(string $date): void
    {
        $this->resetForm();
        $this->selectedDate = $date;
        $this->showModal = true;
    }

    public function saveLeave(): void
    {
        $validated = $this->validate([
            'selectedDate' => ['required', 'date'],
            'healthAideId' => ['required', 'exists:health_aides,id'],
            'replacementHealthAideId' => ['nullable', 'exists:health_aides,id', 'different:healthAideId'],
            'dutyStartTime' => ['nullable', 'date_format:H:i'],
            'dutyEndTime' => ['nullable', 'date_format:H:i'],
            'isInformed' => ['boolean'],
            'informedBy' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        HealthAideLeave::query()->updateOrCreate(
            [
                'health_aide_id' => $validated['healthAideId'],
                'leave_date' => $validated['selectedDate'],
            ],
            [
                'replacement_health_aide_id' => $validated['replacementHealthAideId'],
                'duty_start_time' => $validated['dutyStartTime'],
                'duty_end_time' => $validated['dutyEndTime'],
                'is_informed' => $validated['isInformed'],
                'informed_by' => $validated['informedBy'] ?: null,
                'notes' => $validated['notes'] ?: null,
                'created_by' => auth()->id(),
            ],
        );

        $this->showModal = false;
        unset($this->leavesByDate);
        Flux::toast(variant: 'success', text: __('Leave saved.'));
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->healthAideId = null;
        $this->replacementHealthAideId = null;
        $this->dutyStartTime = null;
        $this->dutyEndTime = null;
        $this->isInformed = false;
        $this->informedBy = '';
        $this->notes = '';
        $this->resetValidation();
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('Health Aide Leave Calendar') }}</flux:heading>
        <div class="flex gap-2">
            <flux:button wire:click="previousMonth" icon="chevron-left" />
            <flux:heading size="lg">{{ Carbon\Carbon::createFromDate($currentYear, $currentMonth, 1)->format('F Y') }}</flux:heading>
            <flux:button wire:click="nextMonth" icon="chevron-right" />
        </div>
    </div>

    <div class="grid grid-cols-7 gap-2">
        @foreach ($this->calendarDays as $day)
            @php($dateKey = $day->toDateString())
            <button
                type="button"
                wire:click="openForDate('{{ $dateKey }}')"
                wire:key="leave-day-{{ $dateKey }}"
                @class([
                    'min-h-24 rounded-lg border p-2 text-left',
                    'border-zinc-200 dark:border-zinc-700' => $day->month === $currentMonth,
                    'opacity-50' => $day->month !== $currentMonth,
                ])
            >
                <div class="font-medium">{{ $day->day }}</div>
                @foreach ($this->leavesByDate->get($dateKey, collect()) as $leave)
                    <div class="mt-1 rounded bg-zinc-100 px-1 text-xs dark:bg-zinc-800">{{ $leave->healthAide->name }}</div>
                @endforeach
            </button>
        @endforeach
    </div>

    <flux:modal wire:model="showModal" class="md:w-lg">
        <flux:heading size="lg">{{ __('Record Leave') }} — {{ $selectedDate }}</flux:heading>
        <form wire:submit="saveLeave" class="mt-4 space-y-4">
            <flux:select wire:model="healthAideId" label="{{ __('Health Aide') }}" required>
                <option value="">{{ __('Select aide') }}</option>
                @foreach ($this->healthAides as $aide)
                    <option value="{{ $aide->id }}">{{ $aide->name }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model="replacementHealthAideId" label="{{ __('Replacement') }}">
                <option value="">{{ __('None') }}</option>
                @foreach ($this->healthAides as $aide)
                    <option value="{{ $aide->id }}">{{ $aide->name }}</option>
                @endforeach
            </flux:select>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="dutyStartTime" type="time" label="{{ __('Duty Start') }}" />
                <flux:input wire:model="dutyEndTime" type="time" label="{{ __('Duty End') }}" />
            </div>
            <flux:checkbox wire:model="isInformed" label="{{ __('Replacement informed') }}" />
            <flux:input wire:model="informedBy" label="{{ __('Informed By') }}" />
            <flux:textarea wire:model="notes" label="{{ __('Notes') }}" />
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
