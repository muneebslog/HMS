<?php

use App\Models\Family;
use App\Services\PatientMergeService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Merge Duplicates')] class extends Component
{
    public string $phoneSearch = '';

    /** @var array<string, bool> */
    public array $selected = [];

    public ?int $confirmFamilyId = null;

    public bool $showConfirmModal = false;

    /**
     * Families with duplicate patients under the same phone.
     *
     * @return Collection<int, Family>
     */
    #[Computed]
    public function groups(): Collection
    {
        return app(PatientMergeService::class)->duplicatePhoneGroups($this->phoneSearch);
    }

    public function mount(): void
    {
        $this->syncSelections($this->groups);
    }

    public function updatedPhoneSearch(): void
    {
        unset($this->groups);
        $this->syncSelections($this->groups);
    }

    /**
     * Open the merge confirmation modal for a family group.
     */
    public function confirmMerge(int $familyId): void
    {
        $this->confirmFamilyId = $familyId;
        $this->showConfirmModal = true;
    }

    /**
     * Close the merge confirmation modal.
     */
    public function closeConfirmModal(): void
    {
        $this->showConfirmModal = false;
        $this->confirmFamilyId = null;
    }

    /**
     * Merge the checked patients in the confirmed family group.
     */
    public function mergeConfirmed(): void
    {
        if ($this->confirmFamilyId === null) {
            return;
        }

        $family = $this->groups->firstWhere('id', $this->confirmFamilyId);

        if ($family === null) {
            $this->closeConfirmModal();

            return;
        }

        $patientIds = $family->patients
            ->filter(fn ($patient): bool => (bool) ($this->selected[(string) $patient->id] ?? false))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        try {
            $winner = app(PatientMergeService::class)->merge($patientIds);
        } catch (ValidationException $exception) {
            $this->closeConfirmModal();

            Flux::toast(
                variant: 'danger',
                text: collect($exception->errors())->flatten()->first() ?? __('Unable to merge patients.'),
            );

            return;
        }

        foreach ($patientIds as $patientId) {
            if ($patientId !== $winner->id) {
                unset($this->selected[(string) $patientId]);
            }
        }

        $this->closeConfirmModal();
        unset($this->groups);
        $this->syncSelections($this->groups);

        Flux::toast(
            variant: 'success',
            text: __('Merged into :mrn (:name).', [
                'mrn' => $winner->mrn,
                'name' => $winner->name,
            ]),
        );
    }

    /**
     * Ensure every visible patient has a selection entry (default checked).
     *
     * @param  Collection<int, Family>  $groups
     */
    private function syncSelections(Collection $groups): void
    {
        foreach ($groups as $family) {
            foreach ($family->patients as $patient) {
                $key = (string) $patient->id;

                if (! array_key_exists($key, $this->selected)) {
                    $this->selected[$key] = true;
                }
            }
        }
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="1">{{ __('Merge Duplicates') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">
                    {{ __('Review patients that share a phone number. Uncheck anyone who is a different person, then merge the rest into the oldest record.') }}
                </flux:text>
            </div>
        </div>

        <flux:card>
            <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading level="2">{{ __('Duplicate phone groups') }}</flux:heading>

                <flux:input
                    wire:model.live.debounce.300ms="phoneSearch"
                    placeholder="{{ __('Search phone...') }}"
                    class="w-full sm:w-56"
                />
            </div>

            @forelse ($this->groups as $family)
                <div wire:key="family-{{ $family->id }}" class="mb-6 overflow-hidden rounded-lg border border-zinc-200 last:mb-0 dark:border-zinc-700">
                    <div class="flex flex-col gap-3 border-b border-zinc-200 bg-zinc-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-700 dark:bg-zinc-800/50">
                        <div>
                            <flux:heading level="3" class="text-base">{{ $family->phone }}</flux:heading>
                            <flux:text class="text-sm text-zinc-500">
                                {{ __(':count patients', ['count' => $family->patients_count]) }}
                            </flux:text>
                        </div>

                        <flux:button
                            variant="primary"
                            size="sm"
                            wire:click="confirmMerge({{ $family->id }})"
                        >
                            {{ __('Merge selected') }}
                        </flux:button>
                    </div>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Merge') }}</flux:table.column>
                            <flux:table.column>{{ __('MRN') }}</flux:table.column>
                            <flux:table.column>{{ __('Name') }}</flux:table.column>
                            <flux:table.column>{{ __('Age / Gender') }}</flux:table.column>
                            <flux:table.column>{{ __('Husband') }}</flux:table.column>
                            <flux:table.column>{{ __('CNIC') }}</flux:table.column>
                            <flux:table.column>{{ __('Records') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($family->patients as $patient)
                                <flux:table.row wire:key="patient-{{ $patient->id }}">
                                    <flux:table.cell>
                                        <flux:checkbox wire:model.live="selected.{{ $patient->id }}" />
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $patient->mrn }}</flux:table.cell>
                                    <flux:table.cell>{{ $patient->name }}</flux:table.cell>
                                    <flux:table.cell>
                                        {{ $patient->age ?? '-' }}
                                        /
                                        {{ $patient->gender ?? '-' }}
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $patient->husband_name ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>{{ $patient->cnic ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:text class="text-xs text-zinc-500">
                                            {{ __('Visits') }}: {{ $patient->queue_tokens_count }}
                                            · {{ __('Inv') }}: {{ $patient->invoices_count }}
                                            · {{ __('Lab') }}: {{ $patient->lab_invoices_count }}
                                            · {{ __('Proc') }}: {{ $patient->procedures_count }}
                                            · {{ __('Vitals') }}: {{ $patient->vitals_count }}
                                            · {{ __('US') }}: {{ $patient->ultrasound_reports_count }}
                                        </flux:text>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @empty
                <flux:text class="py-8 text-center text-zinc-500">
                    {{ __('No duplicate phone groups found.') }}
                </flux:text>
            @endforelse
        </flux:card>
    </div>

    <flux:modal wire:model="showConfirmModal" class="w-full max-w-md">
        <flux:heading level="2">{{ __('Confirm merge') }}</flux:heading>

        <flux:text class="mt-3">
            {{ __('Checked patients will be merged into the oldest record (earliest MRN). Related visits, invoices, labs, procedures, vitals, and ultrasound reports will move with them. This cannot be undone.') }}
        </flux:text>

        <div class="mt-6 flex justify-end gap-3">
            <flux:button type="button" variant="ghost" wire:click="closeConfirmModal">
                {{ __('Cancel') }}
            </flux:button>
            <flux:button type="button" variant="primary" wire:click="mergeConfirmed">
                {{ __('Merge') }}
            </flux:button>
        </div>
    </flux:modal>
</div>
