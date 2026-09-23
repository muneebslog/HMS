<?php

use App\Models\LabInvoice;
use App\Models\Patient;
use App\Services\PatientIntakeService;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Lab Cases')] class extends Component
{
    use WithPagination;

    /**
     * How many days back the date filter starts by default.
     */
    public const DEFAULT_DAYS_BACK = 3;

    public string $fromDate = '';

    public string $toDate = '';

    public string $search = '';

    #[Url]
    public bool $pendingOnly = false;

    public bool $showEditPatientModal = false;

    public ?int $editingPatientId = null;

    public string $editPatientName = '';

    public ?int $editPatientAge = null;

    public string $editPatientGender = '';

    /**
     * Default the date filter to the last few days.
     */
    public function mount(): void
    {
        $this->fromDate = now()->subDays(self::DEFAULT_DAYS_BACK)->toDateString();
        $this->toDate = now()->toDateString();
    }

    /**
     * Get the paginated lab cases (invoices) matching the filters, newest first.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, LabInvoice>
     */
    #[Computed]
    public function cases()
    {
        return $this->filteredQuery()
            ->with(['patient.family', 'items'])
            ->latest()
            ->paginate(25);
    }

    /**
     * Count the cases in the date range, and how many still have unfinished tests.
     *
     * @return array{total: int, pending: int}
     */
    #[Computed]
    public function summary(): array
    {
        $base = $this->filteredQuery(ignorePendingFilter: true);

        return [
            'total' => (clone $base)->count(),
            'pending' => (clone $base)->withPendingItems()->count(),
        ];
    }

    /**
     * Build the shared query for the list and the summary.
     */
    private function filteredQuery(bool $ignorePendingFilter = false)
    {
        [$from, $to] = $this->dateRange();

        return LabInvoice::query()
            ->where('status', '!=', 'returned')
            ->has('items')
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to))
            ->when(filled($this->search), function ($query) {
                $term = '%'.trim($this->search).'%';

                $query->where(function ($query) use ($term) {
                    $query->where('invoice_number', 'like', $term)
                        ->orWhereHas('patient', function ($patient) use ($term) {
                            $patient->where('name', 'like', $term)
                                ->orWhere('mrn', 'like', $term)
                                ->orWhereHas('family', fn ($family) => $family->where('phone', 'like', $term));
                        });
                });
            })
            ->when($this->pendingOnly && ! $ignorePendingFilter, fn ($query) => $query->withPendingItems());
    }

    /**
     * Parse the date filter into start/end timestamps, ignoring invalid input.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function dateRange(): array
    {
        $parse = function (string $value, bool $endOfDay): ?CarbonImmutable {
            if (blank($value)) {
                return null;
            }

            try {
                $date = CarbonImmutable::parse($value);
            } catch (\Throwable) {
                return null;
            }

            return $endOfDay ? $date->endOfDay() : $date->startOfDay();
        };

        return [$parse($this->fromDate, false), $parse($this->toDate, true)];
    }

    /**
     * Reset pagination whenever a filter changes.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['fromDate', 'toDate', 'search', 'pendingOnly'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Whether the current user may edit on this page (the "Lab Results Entry" permission).
     * Without it the list is search, status and open only.
     */
    #[Computed]
    public function canEdit(): bool
    {
        return auth()->user()?->canAccessRoute('lab.results.entry') ?? false;
    }

    /**
     * Open the modal to correct a case's patient name, age or gender.
     */
    public function openEditPatientModal(int $patientId): void
    {
        abort_unless($this->canEdit, 403);

        $patient = Patient::find($patientId);

        if (! $patient) {
            Flux::toast(variant: 'danger', text: __('Patient could not be found.'));

            return;
        }

        $this->editingPatientId = $patient->id;
        $this->editPatientName = $patient->name ?? '';
        $this->editPatientAge = $patient->age;
        $this->editPatientGender = $patient->gender ?? '';
        $this->resetValidation();
        $this->showEditPatientModal = true;
    }

    /**
     * Save the corrected patient details. Age and gender pick the normal ranges on the report.
     */
    public function savePatientDetails(): void
    {
        abort_unless($this->canEdit, 403);

        $validated = $this->validate([
            'editPatientName' => ['required', 'string', 'max:255'],
            'editPatientAge' => ['nullable', 'integer', 'min:0', 'max:150'],
            'editPatientGender' => ['nullable', 'string', 'in:male,female'],
        ]);

        $patient = Patient::find($this->editingPatientId);

        if (! $patient) {
            Flux::toast(variant: 'danger', text: __('Patient could not be found.'));
            $this->showEditPatientModal = false;

            return;
        }

        app(PatientIntakeService::class)->updatePatientDemographics(
            $patient,
            $validated['editPatientName'],
            $validated['editPatientAge'] ?? null,
            filled($validated['editPatientGender']) ? $validated['editPatientGender'] : null,
        );

        $this->showEditPatientModal = false;
        unset($this->cases);

        Flux::toast(variant: 'success', text: __('Patient details updated.'));
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading level="1">{{ __('Lab Cases') }}</flux:heading>
                <flux:text class="mt-1 text-sm">{{ __('Every lab slip, with how many of its tests are finished.') }}</flux:text>
            </div>

            <div class="flex gap-2">
                <flux:badge color="zinc">{{ trans_choice(':count case|:count cases', $this->summary['total'], ['count' => $this->summary['total']]) }}</flux:badge>
                <flux:badge color="amber">{{ __(':count awaiting results', ['count' => $this->summary['pending']]) }}</flux:badge>
            </div>
        </div>

        <flux:card>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-[repeat(2,minmax(0,11rem))_minmax(0,1fr)_auto] lg:items-end">
                <flux:input type="date" wire:model.live="fromDate" :label="__('From')" />
                <flux:input type="date" wire:model.live="toDate" :label="__('To')" />
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    :label="__('Search')"
                    icon="magnifying-glass"
                    placeholder="{{ __('Name, phone, MR or receipt no...') }}"
                />
                <flux:switch wire:model.live="pendingOnly" :label="__('Pending tests only')" class="lg:mb-2" />
            </div>
        </flux:card>

        <flux:card>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Patient') }}</flux:table.column>
                    <flux:table.column>{{ __('Age / Sex') }}</flux:table.column>
                    <flux:table.column>{{ __('Receipt No') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Progress') }}</flux:table.column>
                    <flux:table.column>{{ __('Registered') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->cases as $case)
                        @php
                            $done = $case->doneItemsCount();
                            $total = $case->items->count();
                            $isComplete = $case->isComplete();
                        @endphp
                        <flux:table.row wire:key="lab-case-{{ $case->id }}">
                            <flux:table.cell>
                                <div class="flex items-center gap-3">
                                    <flux:avatar size="sm" :name="$case->patient?->name ?? '?'" />
                                    <div class="min-w-0">
                                        <div class="font-medium uppercase text-zinc-900 dark:text-zinc-100">{{ $case->patient?->name ?? __('Unknown') }}</div>
                                        <div class="text-xs text-zinc-500">
                                            {{ $case->patient?->contactPhone() ?? __('No phone') }}
                                            @if ($case->patient?->mrn)
                                                · {{ $case->patient->mrn }}
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $case->patient?->age !== null ? __(':age Y', ['age' => $case->patient->age]) : '—' }}
                                @if ($case->patient?->gender)
                                    / {{ ucfirst($case->patient->gender) }}
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="font-mono">{{ $case->invoice_number }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($isComplete)
                                    <flux:badge size="sm" color="green" icon="check-circle">{{ __('Complete') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="amber" icon="clock">{{ __('Awaiting results') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center gap-2">
                                    <span class="w-8 text-xs tabular-nums text-zinc-500">{{ $done }}/{{ $total }}</span>
                                    <div class="h-1.5 w-24 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                        <div
                                            @class(['h-full rounded-full', 'bg-green-500' => $isComplete, 'bg-amber-500' => ! $isComplete])
                                            style="width: {{ $total > 0 ? round($done / $total * 100) : 0 }}%"
                                        ></div>
                                    </div>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">
                                {{ $case->created_at->format('d M Y') }}
                                <div class="text-xs text-zinc-500">{{ $case->created_at->format('g:i A') }}</div>
                            </flux:table.cell>
                            <flux:table.cell class="text-right">
                                <div class="flex justify-end gap-1">
                                    <flux:button size="sm" :href="route('lab.cases.show', $case)" wire:navigate>{{ __('Open') }}</flux:button>
                                    @if ($case->patient && $this->canEdit)
                                        <flux:button size="sm" variant="ghost" wire:click="openEditPatientModal({{ $case->patient->id }})">{{ __('Edit') }}</flux:button>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="7" class="py-8 text-center text-zinc-500">
                                {{ __('No lab cases in this date range.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <div class="mt-4">
                {{ $this->cases->links() }}
            </div>
        </flux:card>
    </div>

    <flux:modal wire:model="showEditPatientModal" class="w-full max-w-lg">
        <flux:heading level="2">{{ __('Edit Patient') }}</flux:heading>
        <flux:text class="mt-1 text-sm">{{ __('Age and sex decide which normal range prints on the report.') }}</flux:text>

        <form wire:submit="savePatientDetails" class="mt-6 space-y-4">
            <flux:input wire:model="editPatientName" :label="__('Name')" />

            <div class="grid grid-cols-2 gap-4">
                <flux:input type="number" min="0" max="150" wire:model="editPatientAge" :label="__('Age (years)')" />
                <flux:select wire:model="editPatientGender" :label="__('Sex')">
                    <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                    <flux:select.option value="male">{{ __('Male') }}</flux:select.option>
                    <flux:select.option value="female">{{ __('Female') }}</flux:select.option>
                </flux:select>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showEditPatientModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
