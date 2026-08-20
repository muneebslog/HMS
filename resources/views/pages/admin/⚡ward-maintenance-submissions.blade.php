<?php

use App\Enums\WardMaintenanceShift;
use App\Models\WardMaintenanceEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Ward Maintenance Submissions')] class extends Component
{
    #[Url]
    public string $selectedDate = '';

    #[Url]
    public string $selectedShift = '';

    public function mount(): void
    {
        if ($this->selectedDate === '') {
            $this->selectedDate = now()->format('Y-m-d');
        }
    }

    /**
     * @return list<WardMaintenanceShift>
     */
    #[Computed]
    public function shifts(): array
    {
        return WardMaintenanceShift::cases();
    }

    /**
     * @return Collection<int, WardMaintenanceEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        $query = WardMaintenanceEntry::with(['user', 'answers', 'faults'])
            ->whereDate('checklist_date', $this->selectedDate)
            ->orderBy('shift');

        if ($this->selectedShift !== '') {
            $query->where('shift', $this->selectedShift);
        }

        return $query->get();
    }

    #[Computed]
    public function selectedEntry(): ?WardMaintenanceEntry
    {
        if ($this->selectedShift === '') {
            return null;
        }

        return $this->entries->first(
            fn (WardMaintenanceEntry $entry) => $entry->shift->value === $this->selectedShift
        );
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div>
            <flux:heading level="1">{{ __('Ward Maintenance Submissions') }}</flux:heading>
            <flux:text class="text-zinc-500">
                {{ __('Review daily Gyne Ward & Private Rooms maintenance checklists.') }}
            </flux:text>
        </div>

        <flux:card>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Date') }}</flux:label>
                    <flux:input type="date" wire:model.live="selectedDate" />
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
                    <flux:table.column>{{ __('Shift') }}</flux:table.column>
                    <flux:table.column>{{ __('Checked By') }}</flux:table.column>
                    <flux:table.column>{{ __('Submitted') }}</flux:table.column>
                    <flux:table.column>{{ __('Faults') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->entries as $entry)
                        <flux:table.row wire:key="entry-{{ $entry->id }}">
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
                                <flux:button size="sm" variant="ghost" wire:click="$set('selectedShift', '{{ $entry->shift->value }}')">
                                    {{ __('View') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                {{ __('No submissions for this date.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>

        @if ($this->selectedEntry)
            @include('pages.incharge.partials.ward-maintenance-view', ['entry' => $this->selectedEntry])
        @endif
    </div>
</div>
