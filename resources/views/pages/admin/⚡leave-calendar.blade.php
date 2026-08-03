<?php

use App\Models\EmployeeLeave;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Leave Calendar')] class extends Component
{
    public int $currentMonth;

    public int $currentYear;

    public ?string $selectedDate = null;

    public bool $showModal = false;

    public ?int $editingId = null;

    public string $employeeName = '';

    public string $replacementName = '';

    public ?string $dutyStartTime = null;

    public ?string $dutyEndTime = null;

    public bool $isInformed = false;

    public string $informedBy = '';

    public string $notes = '';

    public ?int $deletingId = null;

    /**
     * Restrict the page to admin users.
     */
    public function mount(): void
    {
        if (! auth()->user()?->isAdmin()) {
            abort(403);
        }

        $this->currentMonth = now()->month;
        $this->currentYear = now()->year;
    }

    /**
     * Get the start of the calendar grid (Sunday before or on the first of the month).
     */
    #[Computed]
    public function gridStart(): Carbon
    {
        return Carbon::createFromDate($this->currentYear, $this->currentMonth, 1)
            ->startOfMonth()
            ->startOfWeek(Carbon::SUNDAY);
    }

    /**
     * Get the end of the calendar grid (Saturday after or on the last of the month).
     */
    #[Computed]
    public function gridEnd(): Carbon
    {
        return Carbon::createFromDate($this->currentYear, $this->currentMonth, 1)
            ->endOfMonth()
            ->endOfWeek(Carbon::SATURDAY);
    }

    /**
     * Get the days to display in the calendar grid.
     *
     * @return \Illuminate\Support\Collection<int, Carbon>
     */
    #[Computed]
    public function calendarDays(): \Illuminate\Support\Collection
    {
        $days = collect();
        $date = $this->gridStart->copy();

        while ($date <= $this->gridEnd) {
            $days->push($date->copy());
            $date->addDay();
        }

        return $days;
    }

    /**
     * Get the leave count for each date in the current grid.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function leaveCounts(): array
    {
        return EmployeeLeave::whereBetween('leave_date', [$this->gridStart, $this->gridEnd])
            ->selectRaw('leave_date, count(*) as count')
            ->groupBy('leave_date')
            ->pluck('count', 'leave_date')
            ->all();
    }

    /**
     * Get the leaves for the currently selected date.
     *
     * @return Collection<int, EmployeeLeave>
     */
    #[Computed]
    public function leavesForSelectedDate(): Collection
    {
        if ($this->selectedDate === null) {
            return new Collection();
        }

        return EmployeeLeave::with('creator')
            ->whereDate('leave_date', $this->selectedDate)
            ->orderBy('duty_start_time')
            ->get();
    }

    /**
     * Select a date and open the modal.
     */
    public function selectDate(string $date): void
    {
        $this->selectedDate = $date;
        $this->resetForm();
        $this->showModal = true;
    }

    /**
     * Reset the form for adding a new leave entry.
     */
    public function openCreateModal(): void
    {
        $this->resetForm();
    }

    /**
     * Load a leave entry into the form for editing.
     */
    public function editLeave(int $id): void
    {
        $leave = EmployeeLeave::findOrFail($id);

        $this->editingId = $leave->id;
        $this->employeeName = $leave->employee_name;
        $this->replacementName = $leave->replacement_name ?? '';
        $this->dutyStartTime = $leave->duty_start_time?->format('H:i');
        $this->dutyEndTime = $leave->duty_end_time?->format('H:i');
        $this->isInformed = $leave->is_informed;
        $this->informedBy = $leave->informed_by ?? '';
        $this->notes = $leave->notes ?? '';
        $this->resetValidation();
    }

    /**
     * Save a new or edited leave entry.
     */
    public function saveLeave(): void
    {
        $validated = $this->validate([
            'employeeName' => [
                'required',
                'string',
                'max:255',
                // Rule::unique('employee_leaves', 'employee_name')
                //     ->where(fn ($query) => $query->whereDate('leave_date', $this->selectedDate))
                //     ->ignore($this->editingId),
            ],
            'replacementName' => ['nullable', 'string', 'max:255', 'different:employeeName'],
            'dutyStartTime' => ['nullable', 'date_format:H:i'],
            'dutyEndTime' => ['nullable', 'date_format:H:i', 'after:dutyStartTime'],
            'isInformed' => ['boolean'],
            'informedBy' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $data = [
            'employee_name' => $validated['employeeName'],
            'leave_date' => $this->selectedDate,
            'replacement_name' => $validated['replacementName'] ?: null,
            'duty_start_time' => $validated['dutyStartTime'] ? $validated['dutyStartTime'].':00' : null,
            'duty_end_time' => $validated['dutyEndTime'] ? $validated['dutyEndTime'].':00' : null,
            'is_informed' => $validated['isInformed'],
            'informed_by' => $validated['informedBy'] ?: null,
            'notes' => $validated['notes'] ?: null,
        ];

        if ($this->editingId === null) {
            $data['created_by'] = auth()->id();

            $leave = EmployeeLeave::create($data);

            app(\App\Services\NotificationService::class)->notifyEmployeeLeaveCreated($leave, auth()->user());

            Flux::toast(variant: 'success', text: __('Leave entry added successfully.'));
        } else {
            $leave = EmployeeLeave::findOrFail($this->editingId);
            $leave->update($data);

            Flux::toast(variant: 'success', text: __('Leave entry updated successfully.'));
        }

        $this->resetForm();
    }

    /**
     * Confirm deletion of a leave entry.
     */
    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
    }

    /**
     * Delete the confirmed leave entry.
     */
    public function deleteLeave(): void
    {
        if ($this->deletingId === null) {
            return;
        }

        EmployeeLeave::findOrFail($this->deletingId)->delete();
        $this->deletingId = null;

        Flux::toast(variant: 'success', text: __('Leave entry deleted successfully.'));
    }

    /**
     * Cancel the delete confirmation.
     */
    public function cancelDelete(): void
    {
        $this->deletingId = null;
    }

    /**
     * Close the modal and reset the form.
     */
    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    /**
     * Navigate to the previous month.
     */
    public function previousMonth(): void
    {
        $date = Carbon::createFromDate($this->currentYear, $this->currentMonth, 1)->subMonth();
        $this->currentMonth = $date->month;
        $this->currentYear = $date->year;
    }

    /**
     * Navigate to the next month.
     */
    public function nextMonth(): void
    {
        $date = Carbon::createFromDate($this->currentYear, $this->currentMonth, 1)->addMonth();
        $this->currentMonth = $date->month;
        $this->currentYear = $date->year;
    }

    /**
     * Reset the form fields.
     */
    private function resetForm(): void
    {
        $this->editingId = null;
        $this->employeeName = '';
        $this->replacementName = '';
        $this->dutyStartTime = null;
        $this->dutyEndTime = null;
        $this->isInformed = false;
        $this->informedBy = '';
        $this->notes = '';
        $this->resetValidation();
    }

    /**
     * Get the formatted duty time for display.
     */
    public function dutyTimeLabel(EmployeeLeave $leave): string
    {
        if ($leave->duty_start_time === null || $leave->duty_end_time === null) {
            return '-';
        }

        return $leave->duty_start_time->format('H:i').' - '.$leave->duty_end_time->format('H:i');
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Leave Calendar') }}</flux:heading>

            <div class="flex items-center gap-2">
                <flux:button variant="outline" icon="chevron-left" wire:click="previousMonth" />

                <flux:heading level="2" class="min-w-[10rem] text-center">
                    {{ Carbon::createFromDate($currentYear, $currentMonth, 1)->format('F Y') }}
                </flux:heading>

                <flux:button variant="outline" icon="chevron-right" wire:click="nextMonth" />
            </div>
        </div>

        <flux:card>
            <div class="grid grid-cols-7 gap-px overflow-hidden rounded-lg border border-zinc-200 bg-zinc-200 dark:border-zinc-700 dark:bg-zinc-700">
                @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dayLabel)
                    <div class="bg-zinc-50 p-3 text-center text-sm font-semibold text-zinc-600 dark:bg-zinc-900 dark:text-zinc-300">
                        {{ __($dayLabel) }}
                    </div>
                @endforeach

                @foreach ($this->calendarDays as $day)
                    @php
                        $isCurrentMonth = $day->month === $currentMonth;
                        $dateString = $day->format('Y-m-d');
                        $count = $this->leaveCounts[$dateString] ?? 0;
                        $isToday = $day->isToday();
                    @endphp

                    <div
                        wire:key="day-{{ $dateString }}"
                        wire:click="selectDate('{{ $dateString }}')"
                        class="group relative min-h-[7rem] cursor-pointer bg-white p-2 transition hover:bg-zinc-50 dark:bg-zinc-800 dark:hover:bg-zinc-700 {{ ! $isCurrentMonth ? 'bg-zinc-50/50 text-zinc-400 dark:bg-zinc-900/50' : '' }} {{ $isToday ? 'ring-1 ring-inset ring-blue-400' : '' }}"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium {{ $isToday ? 'rounded-full bg-blue-500 px-2 py-0.5 text-white' : '' }}">
                                {{ $day->day }}
                            </span>

                            @if ($count > 0)
                                <flux:badge size="sm" color="rose">{{ $count }}</flux:badge>
                            @endif
                        </div>

                        @if ($count > 0)
                            <div class="mt-2 space-y-1">
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $count }} {{ $count === 1 ? __('leave') : __('leaves') }}
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </flux:card>
    </div>

    <flux:modal wire:model="showModal" class="w-full max-w-3xl">
        <div class="flex items-center justify-between">
            <flux:heading level="2">
                {{ $selectedDate ? Carbon::parse($selectedDate)->format('F j, Y') : __('Leave Details') }}
            </flux:heading>

            <flux:button variant="primary" icon="plus" wire:click="openCreateModal" size="sm">
                {{ __('Add Leave') }}
            </flux:button>
        </div>

        @if ($this->leavesForSelectedDate->isNotEmpty())
            <div class="mt-6 space-y-3">
                @foreach ($this->leavesForSelectedDate as $leave)
                    <div wire:key="leave-{{ $leave->id }}" class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/50">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <flux:heading level="3" class="text-base">{{ $leave->employee_name }}</flux:heading>
                                    <flux:badge size="sm" color="{{ $leave->is_informed ? 'green' : 'amber' }}">
                                        {{ $leave->is_informed ? __('Informed') : __('Not informed') }}
                                    </flux:badge>
                                </div>

                                <div class="text-sm text-zinc-600 dark:text-zinc-400">
                                    <span class="font-medium">{{ __('Replaced by:') }}</span>
                                    {{ $leave->replacement_name ?? '-' }}
                                </div>

                                <div class="text-sm text-zinc-600 dark:text-zinc-400">
                                    <span class="font-medium">{{ __('Duty time:') }}</span>
                                    {{ $this->dutyTimeLabel($leave) }}
                                </div>

                                @if ($leave->is_informed && filled($leave->informed_by))
                                    <div class="text-sm text-zinc-600 dark:text-zinc-400">
                                        <span class="font-medium">{{ __('Informed by:') }}</span>
                                        {{ $leave->informed_by }}
                                    </div>
                                @endif

                                @if (filled($leave->notes))
                                    <div class="text-sm text-zinc-600 dark:text-zinc-400">
                                        <span class="font-medium">{{ __('Notes:') }}</span>
                                        {{ $leave->notes }}
                                    </div>
                                @endif
                            </div>

                            <div class="flex items-center gap-2">
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editLeave({{ $leave->id }})" />
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $leave->id }})" />
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <flux:text class="mt-6 text-center text-zinc-500 dark:text-zinc-400">
                {{ __('No leave entries for this date.') }}
            </flux:text>
        @endif

        <flux:separator class="my-6" />

        <form wire:submit="saveLeave" class="space-y-5">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Who is on leave') }}</flux:label>
                    <flux:input wire:model="employeeName" placeholder="{{ __('Employee name') }}" required />
                    <flux:error name="employeeName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Replaced by') }}</flux:label>
                    <flux:input wire:model="replacementName" placeholder="{{ __('Replacement name') }}" />
                    <flux:error name="replacementName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Duty start time') }}</flux:label>
                    <flux:input type="time" wire:model="dutyStartTime" />
                    <flux:error name="dutyStartTime" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Duty end time') }}</flux:label>
                    <flux:input type="time" wire:model="dutyEndTime" />
                    <flux:error name="dutyEndTime" />
                </flux:field>
            </div>

            <div class="flex items-center gap-4">
                <flux:switch wire:model="isInformed" :label="__('Has he been informed?')" />
            </div>

            <flux:field>
                <flux:label>{{ __('Informed by') }}</flux:label>
                <flux:input wire:model="informedBy" placeholder="{{ __('Name of the person who informed') }}" />
                <flux:error name="informedBy" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Notes') }}</flux:label>
                <flux:textarea wire:model="notes" rows="3" />
                <flux:error name="notes" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="closeModal">
                    {{ __('Close') }}
                </flux:button>

                <flux:button type="submit" variant="primary">
                    {{ $editingId === null ? __('Add Leave') : __('Update Leave') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="deletingId" class="w-full max-w-sm">
        <flux:heading level="2">{{ __('Delete Leave Entry') }}</flux:heading>

        <flux:text class="mt-2">
            {{ __('Are you sure you want to delete this leave entry? This action cannot be undone.') }}
        </flux:text>

        <div class="mt-6 flex justify-end gap-2">
            <flux:button type="button" variant="ghost" wire:click="cancelDelete">
                {{ __('Cancel') }}
            </flux:button>

            <flux:button type="button" variant="danger" wire:click="deleteLeave">
                {{ __('Delete') }}
            </flux:button>
        </div>
    </flux:modal>
</div>
