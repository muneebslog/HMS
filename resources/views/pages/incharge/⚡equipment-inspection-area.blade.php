<?php

use App\Enums\EquipmentInspectionArea;
use App\Enums\EquipmentInspectionShift;
use App\Models\EquipmentInspectionEntry;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Equipment Inspection Area')] class extends Component
{
    #[Locked]
    public string $areaValue;

    #[Url]
    public string $selectedDate = '';

    public function mount(string $area): void
    {
        $areaEnum = EquipmentInspectionArea::tryFrom($area);
        abort_unless($areaEnum !== null, 404);

        $this->areaValue = $areaEnum->value;

        if ($this->selectedDate === '') {
            $this->selectedDate = now()->format('Y-m-d');
        }
    }

    #[Computed]
    public function area(): EquipmentInspectionArea
    {
        return EquipmentInspectionArea::from($this->areaValue);
    }

    /**
     * @return list<EquipmentInspectionShift>
     */
    #[Computed]
    public function shifts(): array
    {
        return EquipmentInspectionShift::cases();
    }

    /**
     * @return Collection<int, EquipmentInspectionEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        return EquipmentInspectionEntry::with(['answers', 'registerRows', 'user'])
            ->where('area', $this->area)
            ->whereDate('checklist_date', $this->selectedDate)
            ->get()
            ->keyBy(fn (EquipmentInspectionEntry $entry) => $entry->shift->value);
    }

    /**
     * Find the submitted entry for a shift on the selected date.
     */
    public function entryFor(EquipmentInspectionShift $shift): ?EquipmentInspectionEntry
    {
        return $this->entries->get($shift->value);
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="1">{{ $this->area->label() }}</flux:heading>
                <flux:text class="text-zinc-500">{{ $this->area->description() }}</flux:text>
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <flux:field>
                    <flux:label>{{ __('Date') }}</flux:label>
                    <flux:input type="date" wire:model.live="selectedDate" />
                </flux:field>
                <flux:button variant="ghost" :href="route('incharge.equipment-inspections')" wire:navigate icon="arrow-left">
                    {{ __('Back') }}
                </flux:button>
            </div>
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
                                <span>{{ __('Checked by: :name', ['name' => $entry->checked_by_name]) }}</span>
                                <span>{{ __('Submitted at :time', ['time' => $entry->submitted_at->format('H:i')]) }}</span>
                                @if ($entry->hasFaults())
                                    <flux:badge size="sm" color="red" class="w-fit">{{ __('Faults reported') }}</flux:badge>
                                @endif
                            </div>
                        @else
                            <flux:text class="text-sm text-zinc-500">
                                {{ __('No checklist submitted for this shift yet.') }}
                            </flux:text>
                        @endif

                        <div class="flex justify-end">
                            <flux:button
                                variant="primary"
                                :href="route('incharge.equipment-inspections.form', [
                                    'area' => $areaValue,
                                    'shift' => $shift->value,
                                    'date' => $selectedDate,
                                ])"
                                wire:navigate
                            >
                                {{ $entry ? __('View Checklist') : __('Fill Checklist') }}
                            </flux:button>
                        </div>
                    </div>
                </flux:card>
            @endforeach
        </div>
    </div>
</div>
