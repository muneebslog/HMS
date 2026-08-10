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

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($this->procedures as $procedure)
                @php
                    $pendingDoses = $this->pendingDoseCount($procedure);
                    $hasAlerts = $procedure->isVitalsOverdue() || $procedure->isFetalHeartOverdue() || $pendingDoses > 0;
                @endphp
                <a
                    href="{{ route('indoor.procedure', $procedure) }}"
                    wire:key="ward-procedure-{{ $procedure->id }}"
                    wire:navigate
                    class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:border-zinc-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-600"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <flux:heading level="3" class="truncate text-base font-semibold">
                                {{ $procedure->patient->name }}
                            </flux:heading>
                            <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $procedure->patient->mrn ?? __('No MRN') }}
                            </flux:text>
                        </div>
                        <flux:badge size="sm" color="blue">
                            {{ __('Room') }}: {{ $procedure->room?->number ?? $procedure->room_number ?? '-' }}
                        </flux:badge>
                    </div>

                    <div class="space-y-1 text-sm">
                        <flux:text class="font-medium">
                            {{ $procedure->procedureType?->name ?? $procedure->name }}
                        </flux:text>
                        <flux:text class="text-zinc-500 dark:text-zinc-400">
                            {{ __('Doctor') }}: {{ $procedure->doctor?->name ?? '-' }}
                        </flux:text>
                        <flux:text class="text-zinc-500 dark:text-zinc-400">
                            {{ __('Admitted') }}: {{ $procedure->admitted_at?->format('d M, g:i A') ?? '-' }}
                        </flux:text>
                    </div>

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
                        @if (! $hasAlerts)
                            <flux:badge size="sm" color="green">{{ __('On track') }}</flux:badge>
                        @endif
                    </div>
                </a>
            @empty
                <div class="col-span-full flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-zinc-300 bg-zinc-50 py-16 dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:icon name="building-office-2" class="size-10 text-zinc-400" />
                    <flux:text class="text-zinc-500 dark:text-zinc-400">
                        {{ filled($search) ? __('No admitted patients match your search.') : __('No patients are currently admitted on the ward.') }}
                    </flux:text>
                </div>
            @endforelse
        </div>

        <div class="mt-2">
            {{ $this->procedures->links() }}
        </div>
    </div>
</div>
