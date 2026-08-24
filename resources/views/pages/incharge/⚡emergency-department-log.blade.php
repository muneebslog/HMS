<?php

use App\Enums\EmergencyDepartmentShift;
use App\Models\EmergencyDepartmentLogEntry;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('ER Operational Log')] class extends Component
{
    #[Url]
    public string $selectedDate = '';

    public function mount(): void
    {
        if ($this->selectedDate === '') {
            $this->selectedDate = now()->format('Y-m-d');
        }
    }

    /**
     * @return list<EmergencyDepartmentShift>
     */
    #[Computed]
    public function shifts(): array
    {
        return EmergencyDepartmentShift::cases();
    }

    /**
     * @return Collection<int, EmergencyDepartmentLogEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        return EmergencyDepartmentLogEntry::with(['answers', 'user'])
            ->whereDate('checklist_date', $this->selectedDate)
            ->get()
            ->keyBy(fn (EmergencyDepartmentLogEntry $entry) => $entry->shift->value);
    }

    /**
     * Find the submitted entry for a shift on the selected date.
     */
    public function entryFor(EmergencyDepartmentShift $shift): ?EmergencyDepartmentLogEntry
    {
        return $this->entries->get($shift->value);
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="1">{{ __('Emergency Department Operational Log') }}</flux:heading>
                <flux:text class="text-zinc-500">
                    {{ __('Standard operating checklist & departmental audit — fill once per shift.') }}
                </flux:text>
            </div>
            <flux:field>
                <flux:label>{{ __('Date') }}</flux:label>
                <flux:input type="date" wire:model.live="selectedDate" />
            </flux:field>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            @foreach ($this->shifts as $shift)
                @php
                    $entry = $this->entryFor($shift);
                @endphp
                <flux:card wire:key="shift-{{ $shift->value }}-{{ $selectedDate }}">
                    <div class="flex flex-col gap-4">
                        <div class="flex items-start justify-between gap-3">
                            <flux:heading level="2">{{ $shift->label() }}</flux:heading>
                            @if ($entry)
                                <flux:badge size="sm" color="green">{{ __('Submitted') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="amber">{{ __('Due') }}</flux:badge>
                            @endif
                        </div>

                        @if ($entry)
                            <div class="flex flex-col gap-1 text-sm text-zinc-500">
                                <span>{{ __('Completed by: :name', ['name' => $entry->completed_by_name]) }}</span>
                                <span>{{ __('Submitted at :time', ['time' => $entry->submitted_at->format('H:i')]) }}</span>
                                @if ($entry->hasFaults())
                                    <flux:badge size="sm" color="red" class="w-fit">{{ __('Faults reported') }}</flux:badge>
                                @endif
                            </div>
                        @else
                            <flux:text class="text-sm text-zinc-500">
                                {{ __('No operational log submitted for this shift yet.') }}
                            </flux:text>
                        @endif

                        <div class="flex justify-end">
                            <flux:button
                                variant="primary"
                                :href="route('incharge.emergency-department-log.form', ['shift' => $shift->value, 'date' => $selectedDate])"
                                wire:navigate
                            >
                                {{ $entry ? __('View Log') : __('Fill Log') }}
                            </flux:button>
                        </div>
                    </div>
                </flux:card>
            @endforeach
        </div>
    </div>
</div>
