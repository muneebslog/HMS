<?php

use App\Enums\ProcedureMedicationDoseStatus;
use App\Models\Procedure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Indoor Ward')] class extends Component
{
    use WithPagination;

    public string $search = '';

    /**
     * Reset pagination when the search term changes.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Get the paginated list of patients currently on the ward.
     */
    #[Computed]
    public function procedures(): LengthAwarePaginator
    {
        return Procedure::query()
            ->onWard()
            ->with(['patient', 'room', 'procedureType', 'doctor', 'medications.doses'])
            ->when(trim($this->search) !== '', function ($query) {
                $term = '%'.trim($this->search).'%';

                $query->whereHas('patient', function ($patientQuery) use ($term) {
                    $patientQuery->where('name', 'like', $term)
                        ->orWhere('mrn', 'like', $term);
                });
            })
            ->latest('admitted_at')
            ->paginate(15);
    }

    /**
     * Count pending medication doses that are already due for the given procedure.
     */
    public function pendingDoseCount(Procedure $procedure): int
    {
        $now = now();

        return $procedure->medications
            ->flatMap(fn ($medication) => $medication->doses)
            ->filter(fn ($dose) => $dose->status === ProcedureMedicationDoseStatus::Pending && $dose->due_at->lte($now))
            ->count();
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Indoor Ward') }}</flux:heading>
            <flux:badge size="lg" color="blue">{{ __(':count on ward', ['count' => $this->procedures->total()]) }}</flux:badge>
        </div>

        <flux:card>
            <flux:field>
                <flux:label>{{ __('Search by name or MR number') }}</flux:label>
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="{{ __('Patient name or MRN...') }}"
                    icon="magnifying-glass"
                />
            </flux:field>
        </flux:card>

        <flux:card class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Patient') }}</flux:table.column>
                    <flux:table.column>{{ __('Room') }}</flux:table.column>
                    <flux:table.column>{{ __('Procedure') }}</flux:table.column>
                    <flux:table.column>{{ __('Doctor') }}</flux:table.column>
                    <flux:table.column>{{ __('Admitted') }}</flux:table.column>
                    <flux:table.column>{{ __('Alerts') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->procedures as $procedure)
                        @php
                            $pendingDoses = $this->pendingDoseCount($procedure);
                        @endphp
                        <flux:table.row wire:key="ward-procedure-{{ $procedure->id }}">
                            <flux:table.cell>
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $procedure->patient->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $procedure->patient->mrn ?? __('No MRN') }}</div>
                            </flux:table.cell>
                            <flux:table.cell>{{ $procedure->room?->number ?? $procedure->room_number ?? '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $procedure->procedureType?->name ?? $procedure->name }}</flux:table.cell>
                            <flux:table.cell>{{ $procedure->doctor?->name ?? '-' }}</flux:table.cell>
                            <flux:table.cell>{{ $procedure->admitted_at?->format('d M, g:i A') ?? '-' }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="flex flex-wrap gap-1">
                                    @if ($procedure->isVitalsOverdue())
                                        <flux:badge size="sm" color="red">{{ __('Vitals overdue') }}</flux:badge>
                                    @endif
                                    @if ($procedure->isFetalHeartOverdue())
                                        <flux:badge size="sm" color="red">{{ __('FHR overdue') }}</flux:badge>
                                    @endif
                                    @if ($pendingDoses > 0)
                                        <flux:badge size="sm" color="amber">{{ __(':count dose(s) due', ['count' => $pendingDoses]) }}</flux:badge>
                                    @endif
                                    @if (! $procedure->isVitalsOverdue() && ! $procedure->isFetalHeartOverdue() && $pendingDoses === 0)
                                        <flux:badge size="sm" color="green">{{ __('On track') }}</flux:badge>
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="text-right">
                                <flux:button
                                    size="sm"
                                    variant="primary"
                                    icon="clipboard-document-list"
                                    :href="route('indoor.procedure', $procedure)"
                                    wire:navigate
                                >
                                    {{ __('Open Chart') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="7" class="text-center text-zinc-500">
                                {{ filled($search) ? __('No admitted patients match your search.') : __('No patients are currently admitted on the ward.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>

        <div class="mt-2">
            {{ $this->procedures->links() }}
        </div>
    </div>
</div>
