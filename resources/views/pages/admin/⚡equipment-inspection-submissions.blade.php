<?php

use App\Enums\EquipmentInspectionArea;
use App\Enums\EquipmentInspectionShift;
use App\Models\EquipmentInspectionEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Equipment Inspection Submissions')] class extends Component
{
    #[Url]
    public string $selectedDate = '';

    #[Url]
    public string $selectedArea = '';

    #[Url]
    public string $selectedShift = '';

    public function mount(): void
    {
        if ($this->selectedDate === '') {
            $this->selectedDate = now()->format('Y-m-d');
        }
    }

    /**
     * @return list<EquipmentInspectionArea>
     */
    #[Computed]
    public function areas(): array
    {
        return EquipmentInspectionArea::cases();
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
        $query = EquipmentInspectionEntry::with(['user', 'answers', 'registerRows'])
            ->whereDate('checklist_date', $this->selectedDate)
            ->orderBy('area')
            ->orderBy('shift');

        if ($this->selectedArea !== '') {
            $query->where('area', $this->selectedArea);
        }

        if ($this->selectedShift !== '') {
            $query->where('shift', $this->selectedShift);
        }

        return $query->get();
    }

    #[Computed]
    public function selectedEntry(): ?EquipmentInspectionEntry
    {
        if ($this->selectedArea === '' || $this->selectedShift === '') {
            return null;
        }

        return $this->entries->first(
            fn (EquipmentInspectionEntry $entry) => $entry->area->value === $this->selectedArea
                && $entry->shift->value === $this->selectedShift
        );
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div>
            <flux:heading level="1">{{ __('Equipment Inspection Submissions') }}</flux:heading>
            <flux:text class="text-zinc-500">
                {{ __('Review daily hospital equipment & quality assurance checklists.') }}
            </flux:text>
        </div>

        <flux:card>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:field>
                    <flux:label>{{ __('Date') }}</flux:label>
                    <flux:input type="date" wire:model.live="selectedDate" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('Area') }}</flux:label>
                    <flux:select wire:model.live="selectedArea">
                        <option value="">{{ __('All areas') }}</option>
                        @foreach ($this->areas as $area)
                            <option value="{{ $area->value }}">{{ $area->label() }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('Shift') }}</flux:label>
                    <flux:select wire:model.live="selectedShift">
                        <option value="">{{ __('All shifts') }}</option>
                        @foreach ($this->shifts as $shift)
                            <option value="{{ $shift->value }}">{{ $shift->label() }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>
            </div>
        </flux:card>

        <flux:card>
            <flux:heading level="2" class="mb-4">
                {{ __('Submissions for :date', ['date' => Carbon::parse($selectedDate)->format('M j, Y')]) }}
            </flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Area') }}</flux:table.column>
                    <flux:table.column>{{ __('Shift') }}</flux:table.column>
                    <flux:table.column>{{ __('Checked By') }}</flux:table.column>
                    <flux:table.column>{{ __('Submitted') }}</flux:table.column>
                    <flux:table.column>{{ __('Faults') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->entries as $entry)
                        <flux:table.row wire:key="entry-{{ $entry->id }}">
                            <flux:table.cell>{{ $entry->area->label() }}</flux:table.cell>
                            <flux:table.cell>{{ $entry->shift->label() }}</flux:table.cell>
                            <flux:table.cell>{{ $entry->checked_by_name }}</flux:table.cell>
                            <flux:table.cell>{{ $entry->submitted_at->format('H:i') }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($entry->hasFaults())
                                    <flux:badge size="sm" color="red">{{ __('Yes') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="green">{{ __('No') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="text-right">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    wire:click="$set('selectedArea', '{{ $entry->area->value }}'); $set('selectedShift', '{{ $entry->shift->value }}')"
                                >
                                    {{ __('View') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="text-center text-zinc-500">
                                {{ __('No submissions for this date.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>

        @if ($this->selectedEntry)
            @include('pages.incharge.partials.equipment-inspection-view', ['entry' => $this->selectedEntry])
        @endif
    </div>
</div>
