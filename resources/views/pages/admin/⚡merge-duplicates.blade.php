<?php

use App\Models\Family;
use App\Models\Patient;
use App\Services\PatientMergeService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
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

    public bool $mergingAll = false;

    public bool $showConfirmModal = false;

    public int $confirmGroupCount = 0;

    public int $confirmPatientCount = 0;

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
        $family = $this->groups->firstWhere('id', $familyId);

        if ($family === null) {
            return;
        }

        $patientIds = $this->selectedPatientIdsForFamily($family);

        if (count($patientIds) < 2) {
            Flux::toast(
                variant: 'danger',
                text: __('Select at least two patients to merge.'),
            );

            return;
        }

        $this->confirmFamilyId = $familyId;
        $this->mergingAll = false;
        $this->confirmGroupCount = 1;
        $this->confirmPatientCount = count($patientIds);
        $this->showConfirmModal = true;
    }

    /**
     * Open the merge confirmation modal for every group with 2+ checked patients.
     */
    public function confirmMergeAll(): void
    {
        $batches = $this->mergeableBatches();

        if ($batches === []) {
            Flux::toast(
                variant: 'danger',
                text: __('No groups have at least two checked patients to merge.'),
            );

            return;
        }

        $this->confirmFamilyId = null;
        $this->mergingAll = true;
        $this->confirmGroupCount = count($batches);
        $this->confirmPatientCount = collect($batches)->sum(fn (array $ids): int => count($ids));
        $this->showConfirmModal = true;
    }

    /**
     * Close the merge confirmation modal.
     */
    public function closeConfirmModal(): void
    {
        $this->showConfirmModal = false;
        $this->confirmFamilyId = null;
        $this->mergingAll = false;
        $this->confirmGroupCount = 0;
        $this->confirmPatientCount = 0;
    }

    /**
     * Remove a patient from the shared phone family without deleting their record.
     */
    public function unlinkFromPhone(int $patientId): void
    {
        $patient = Patient::query()->find($patientId);

        if ($patient === null) {
            Flux::toast(
                variant: 'danger',
                text: __('Patient could not be found.'),
            );

            return;
        }

        $name = $patient->name;

        app(PatientMergeService::class)->unlinkFromPhone($patient);

        unset($this->selected[(string) $patientId]);
        unset($this->groups);
        $this->syncSelections($this->groups);

        Flux::toast(
            variant: 'success',
            text: __('Unlinked :name from the phone number.', ['name' => $name]),
        );
    }

    /**
     * Merge the checked patients for the confirmed scope (one group or all).
     */
    public function mergeConfirmed(): void
    {
        if ($this->mergingAll) {
            $this->mergeAllConfirmed();

            return;
        }

        if ($this->confirmFamilyId === null) {
            return;
        }

        $family = $this->groups->firstWhere('id', $this->confirmFamilyId);

        if ($family === null) {
            $this->closeConfirmModal();

            return;
        }

        $patientIds = $this->selectedPatientIdsForFamily($family);

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

        $this->forgetMergedLosers($patientIds, $winner->id);
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
     * Merge every group that currently has two or more checked patients.
     */
    private function mergeAllConfirmed(): void
    {
        $batches = $this->mergeableBatches();
        $mergedGroups = 0;
        $errors = [];

        foreach ($batches as $patientIds) {
            try {
                $winner = app(PatientMergeService::class)->merge($patientIds);
                $this->forgetMergedLosers($patientIds, $winner->id);
                $mergedGroups++;
            } catch (ValidationException $exception) {
                $errors[] = collect($exception->errors())->flatten()->first()
                    ?? __('Unable to merge patients.');
            }
        }

        $this->closeConfirmModal();
        unset($this->groups);
        $this->syncSelections($this->groups);

        if ($mergedGroups > 0) {
            Flux::toast(
                variant: 'success',
                text: __('Merged :count phone groups.', ['count' => $mergedGroups]),
            );
        }

        if ($errors !== []) {
            Flux::toast(
                variant: 'danger',
                text: $errors[0],
            );
        }
    }

    /**
     * Groups that currently have at least two checked patients.
     *
     * @return list<list<int>>
     */
    private function mergeableBatches(): array
    {
        $batches = [];

        foreach ($this->groups as $family) {
            $patientIds = $this->selectedPatientIdsForFamily($family);

            if (count($patientIds) >= 2) {
                $batches[] = $patientIds;
            }
        }

        return $batches;
    }

    /**
     * Checked patient ids for a family group.
     *
     * @return list<int>
     */
    private function selectedPatientIdsForFamily(Family $family): array
    {
        return $family->patients
            ->filter(fn (Patient $patient): bool => (bool) ($this->selected[(string) $patient->id] ?? false))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Drop selection keys for patients deleted by a merge.
     *
     * @param  list<int>  $patientIds
     */
    private function forgetMergedLosers(array $patientIds, int $winnerId): void
    {
        foreach ($patientIds as $patientId) {
            if ($patientId !== $winnerId) {
                unset($this->selected[(string) $patientId]);
            }
        }
    }

    /**
     * Ensure every visible patient has a selection entry.
     * Same-name lookalikes are checked by default; distinct names are not.
     *
     * @param  Collection<int, Family>  $groups
     */
    private function syncSelections(Collection $groups): void
    {
        foreach ($groups as $family) {
            $duplicateNames = $family->patients
                ->map(fn (Patient $patient): string => $this->normalizedName($patient->name))
                ->countBy()
                ->filter(fn (int $count): bool => $count >= 2)
                ->keys()
                ->all();

            foreach ($family->patients as $patient) {
                $key = (string) $patient->id;

                if (! array_key_exists($key, $this->selected)) {
                    $this->selected[$key] = in_array(
                        $this->normalizedName($patient->name),
                        $duplicateNames,
                        true,
                    );
                }
            }
        }
    }

    /**
     * Normalize a patient name for duplicate comparison.
     */
    private function normalizedName(string $name): string
    {
        return Str::lower(trim($name));
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading level="1">{{ __('Merge Duplicates') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">
                    {{ __('Same-name patients under a phone are checked by default. Uncheck anyone who is a different person, then merge into the oldest record.') }}
                </flux:text>
            </div>

            @if ($this->groups->isNotEmpty())
                <flux:button variant="primary" wire:click="confirmMergeAll">
                    {{ __('Merge all') }}
                </flux:button>
            @endif
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
                            <flux:table.column>{{ __('Actions') }}</flux:table.column>
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
                                    <flux:table.cell>
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            wire:click="unlinkFromPhone({{ $patient->id }})"
                                            wire:confirm="{{ __('Unlink :name from :phone? They will keep their records but no longer appear under this number.', ['name' => $patient->name, 'phone' => $family->phone]) }}"
                                        >
                                            {{ __('Unlink') }}
                                        </flux:button>
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
            @if ($mergingAll)
                {{ __('This will merge :patients checked patients across :groups phone groups into the oldest record in each group. Related visits, invoices, labs, procedures, vitals, and ultrasound reports will move with them. This cannot be undone.', [
                    'patients' => $confirmPatientCount,
                    'groups' => $confirmGroupCount,
                ]) }}
            @else
                {{ __('Checked patients will be merged into the oldest record (earliest MRN). Related visits, invoices, labs, procedures, vitals, and ultrasound reports will move with them. This cannot be undone.') }}
            @endif
        </flux:text>

        <div class="mt-6 flex justify-end gap-3">
            <flux:button type="button" variant="ghost" wire:click="closeConfirmModal">
                {{ __('Cancel') }}
            </flux:button>
            <flux:button type="button" variant="primary" wire:click="mergeConfirmed">
                {{ $mergingAll ? __('Merge all') : __('Merge') }}
            </flux:button>
        </div>
    </flux:modal>
</div>
