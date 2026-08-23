<?php

use App\Models\Patient;
use App\Services\PatientIntakeService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('MR Lookup')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $selectedPatientId = null;

    public bool $isEditingPatient = false;

    public string $editName = '';

    public ?int $editAge = null;

    public bool $showRecentPatientsModal = false;

    /**
     * @return Collection<int, Patient>
     */
    #[Computed]
    public function patients(): Collection
    {
        if (mb_strlen(trim($this->search)) < 2) {
            return new Collection;
        }

        return app(PatientIntakeService::class)->findPatientsByMrnOrName($this->search);
    }

    #[Computed]
    public function isAdmin(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    /**
     * @return LengthAwarePaginator<int, Patient>
     */
    #[Computed]
    public function recentReceptionPatients(): LengthAwarePaginator
    {
        return app(PatientIntakeService::class)->paginateRecentReceptionPatients();
    }

    #[Computed]
    public function selectedPatient(): ?Patient
    {
        if ($this->selectedPatientId === null) {
            return null;
        }

        return Patient::query()
            ->with([
                'family',
                'queueTokens' => fn ($query) => $query
                    ->with([
                        'serviceQueue.service',
                        'serviceQueue.doctor',
                        'medicationOrders.doctor',
                        'medicationOrders.medicines',
                        'medicationOrders.injections',
                        'medicationOrders.drips.additives',
                    ])
                    ->latest('arrived_at')
                    ->limit(10),
                'invoices' => fn ($query) => $query->latest()->limit(10),
                'labInvoices' => fn ($query) => $query->with('items')->latest()->limit(10),
                'procedures' => fn ($query) => $query->with('doctor')->latest()->limit(10),
                'medicationOrders' => fn ($query) => $query
                    ->with(['doctor', 'medicines', 'injections', 'drips', 'queueToken.serviceQueue.service'])
                    ->latest()
                    ->limit(10),
                'vitals' => fn ($query) => $query->latest()->limit(10),
                'ultrasoundReports' => fn ($query) => $query->latest()->limit(10),
            ])
            ->find($this->selectedPatientId);
    }

    public function updatedSearch(): void
    {
        $this->selectedPatientId = null;
        unset($this->patients, $this->selectedPatient);
    }

    public function selectPatient(int $patientId): void
    {
        $this->cancelEditingPatient();
        $this->selectedPatientId = $patientId;
        unset($this->selectedPatient);
    }

    public function clearSelection(): void
    {
        $this->cancelEditingPatient();
        $this->selectedPatientId = null;
        unset($this->selectedPatient);
    }

    public function startEditingPatient(): void
    {
        $patient = $this->selectedPatient;

        if ($patient === null) {
            return;
        }

        $this->editName = $patient->name;
        $this->editAge = $patient->age;
        $this->isEditingPatient = true;
        $this->resetValidation(['editName', 'editAge']);
    }

    public function cancelEditingPatient(): void
    {
        $this->isEditingPatient = false;
        $this->editName = '';
        $this->editAge = null;
        $this->resetValidation(['editName', 'editAge']);
    }

    public function savePatientDetails(): void
    {
        $patient = $this->selectedPatient;

        if ($patient === null) {
            Flux::toast(variant: 'danger', text: __('Patient could not be found.'));

            return;
        }

        $validated = $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editAge' => ['nullable', 'integer', 'min:0', 'max:150'],
        ]);

        app(PatientIntakeService::class)->updatePatientDemographics(
            $patient,
            $validated['editName'],
            $validated['editAge'],
        );

        $this->cancelEditingPatient();
        unset($this->selectedPatient, $this->patients);

        Flux::toast(variant: 'success', text: __('Patient details updated.'));
    }

    public function openRecentPatientsModal(): void
    {
        $this->ensureAdmin();
        $this->resetPage();
        $this->showRecentPatientsModal = true;
        unset($this->recentReceptionPatients);
    }

    public function closeRecentPatientsModal(): void
    {
        $this->showRecentPatientsModal = false;
    }

    public function selectPatientFromRecentList(int $patientId): void
    {
        $this->ensureAdmin();
        $this->showRecentPatientsModal = false;
        $this->selectPatient($patientId);
    }

    private function ensureAdmin(): void
    {
        if (! auth()->user()?->isAdmin()) {
            abort(403);
        }
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading level="1">{{ __('MR Lookup') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">{{ __('Find a patient by MRN, name, or phone number.') }}</flux:text>
            </div>

            @if ($this->isAdmin)
                <flux:button type="button" variant="ghost" icon="users" wire:click="openRecentPatientsModal">
                    {{ __('See patients') }}
                </flux:button>
            @endif
        </div>

        <flux:card>
            <flux:field>
                <flux:label>{{ __('Search') }}</flux:label>
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="{{ __('MRN, patient name, or phone...') }}"
                    icon="magnifying-glass"
                    autofocus
                />
            </flux:field>
        </flux:card>

        @if ($selectedPatientId === null)
            @if (mb_strlen(trim($search)) < 2)
                <div class="flex flex-1 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-300 px-6 py-16 text-center dark:border-zinc-600">
                    <flux:icon name="magnifying-glass" class="size-10 text-zinc-400" />
                    <p class="text-base font-medium text-zinc-700 dark:text-zinc-200">{{ __('Search for a patient') }}</p>
                    <p class="text-sm text-zinc-500">{{ __('Enter at least 2 characters to search.') }}</p>
                </div>
            @else
                <div class="flex flex-col gap-2">
                    @forelse ($this->patients as $patient)
                        <button
                            type="button"
                            wire:key="mr-lookup-patient-{{ $patient->id }}"
                            wire:click="selectPatient({{ $patient->id }})"
                            class="flex w-full items-center gap-4 rounded-xl border border-zinc-200 bg-white px-4 py-4 text-left shadow-sm transition active:scale-[0.99] dark:border-zinc-700 dark:bg-zinc-800"
                        >
                            <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-zinc-900 text-xs font-bold text-white dark:bg-white dark:text-zinc-900">
                                {{ __('MR') }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-lg font-semibold text-zinc-900 dark:text-white">
                                    {{ $patient->name }}
                                </span>
                                <span class="mt-0.5 block truncate text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $patient->mrn ?? __('No MRN') }}
                                    @if ($patient->contactPhone())
                                        · {{ $patient->contactPhone() }}
                                    @endif
                                    @if ($patient->age)
                                        · {{ $patient->age }} {{ __('yrs') }}
                                    @endif
                                    @if ($patient->gender)
                                        · {{ ucfirst($patient->gender) }}
                                    @endif
                                </span>
                            </span>
                            <flux:icon name="chevron-right" class="size-5 shrink-0 text-zinc-400" />
                        </button>
                    @empty
                        <div class="flex flex-1 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-300 px-6 py-16 text-center dark:border-zinc-600">
                            <flux:icon name="user" class="size-10 text-zinc-400" />
                            <p class="text-base font-medium text-zinc-700 dark:text-zinc-200">{{ __('No patients found') }}</p>
                            <p class="text-sm text-zinc-500">{{ __('Try a different MRN, name, or phone number.') }}</p>
                        </div>
                    @endforelse
                </div>
            @endif
        @else
            @php($patient = $this->selectedPatient)

            @if ($patient)
                <div class="sticky top-0 z-10 -mx-4 border-b border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900 sm:mx-0 sm:rounded-xl sm:border">
                    <div class="flex items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-lg font-semibold text-zinc-900 dark:text-white">
                                {{ $patient->name }}
                            </p>
                            <p class="truncate text-sm text-zinc-500">
                                {{ $patient->mrn ?? __('No MRN') }}
                                @if ($patient->contactPhone())
                                    · {{ $patient->contactPhone() }}
                                @endif
                            </p>
                        </div>
                        <flux:button type="button" size="sm" variant="ghost" icon="arrow-left" wire:click="clearSelection">
                            {{ __('Back') }}
                        </flux:button>
                    </div>
                </div>

                <flux:card>
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <flux:heading size="sm">{{ __('Patient details') }}</flux:heading>
                        @if (! $isEditingPatient)
                            <flux:button type="button" size="sm" variant="ghost" icon="pencil-square" wire:click="startEditingPatient">
                                {{ __('Edit') }}
                            </flux:button>
                        @endif
                    </div>

                    @if ($isEditingPatient)
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>{{ __('Name') }}</flux:label>
                                <flux:input wire:model="editName" type="text" required />
                                <flux:error name="editName" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Age') }}</flux:label>
                                <flux:input wire:model="editAge" type="number" min="0" max="150" />
                                <flux:error name="editAge" />
                            </flux:field>
                        </div>

                        <div class="mt-4 flex justify-end gap-3">
                            <flux:button type="button" size="sm" variant="ghost" wire:click="cancelEditingPatient">
                                {{ __('Cancel') }}
                            </flux:button>
                            <flux:button type="button" size="sm" variant="primary" wire:click="savePatientDetails">
                                {{ __('Save') }}
                            </flux:button>
                        </div>
                    @else
                    <div class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <flux:text class="text-zinc-500">{{ __('Name') }}</flux:text>
                            <flux:text>{{ $patient->name }}</flux:text>
                        </div>
                        <div>
                            <flux:text class="text-zinc-500">{{ __('MRN') }}</flux:text>
                            <flux:text>{{ $patient->mrn ?? __('No MRN') }}</flux:text>
                        </div>
                        <div>
                            <flux:text class="text-zinc-500">{{ __('Phone') }}</flux:text>
                            <flux:text>{{ $patient->contactPhone() ?? '-' }}</flux:text>
                        </div>
                        <div>
                            <flux:text class="text-zinc-500">{{ __('Age') }}</flux:text>
                            <flux:text>{{ $patient->age ?? '-' }}</flux:text>
                        </div>
                        <div>
                            <flux:text class="text-zinc-500">{{ __('Gender') }}</flux:text>
                            <flux:text>{{ $patient->gender ? ucfirst($patient->gender) : '-' }}</flux:text>
                        </div>
                        <div>
                            <flux:text class="text-zinc-500">{{ __('Husband') }}</flux:text>
                            <flux:text>{{ $patient->husband_name ?? '-' }}</flux:text>
                        </div>
                        <div>
                            <flux:text class="text-zinc-500">{{ __('CNIC') }}</flux:text>
                            <flux:text>{{ $patient->cnic ?? '-' }}</flux:text>
                        </div>
                    </div>
                    @endif
                </flux:card>

                <flux:card>
                    <flux:heading size="sm" class="mb-3">{{ __('Recent visits') }}</flux:heading>
                    @forelse ($patient->queueTokens as $token)
                        <div wire:key="mr-visit-{{ $token->id }}" class="border-b border-zinc-100 py-2 last:border-0 dark:border-zinc-700">
                            <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                {{ __('Token') }} #{{ $token->token_number }}
                                · {{ $token->serviceQueue?->service?->name ?? __('Unknown service') }}
                            </p>
                            <p class="text-xs text-zinc-500">
                                {{ $token->arrived_at?->timezone(config('app.timezone'))->format('d M Y, h:i A') ?? '-' }}
                                @if ($token->serviceQueue?->doctor)
                                    · {{ $token->serviceQueue->doctor->name }}
                                @endif
                                · {{ ucfirst($token->status) }}
                            </p>
                            @if ($token->medicationOrders->isNotEmpty())
                                <div class="mt-3 grid gap-3 md:grid-cols-2">
                                    @foreach ($token->medicationOrders as $order)
                                        <x-paper-slip wire:key="mr-visit-{{ $token->id }}-medication-{{ $order->id }}">
                                            <div class="flex items-start justify-between gap-2 border-b border-dashed border-zinc-400/70 pb-2">
                                                <div>
                                                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-zinc-500">
                                                        {{ __('Medication slip') }}
                                                    </p>
                                                    <p class="mt-1 text-xs text-zinc-600">
                                                        {{ $order->created_at?->timezone(config('app.timezone'))->format('d M Y, h:i A') }}
                                                    </p>
                                                </div>
                                                <flux:badge size="sm" color="{{ $order->status === \App\Enums\MedicationOrderStatus::Administered ? 'green' : 'zinc' }}">
                                                    {{ $order->status->label() }}
                                                </flux:badge>
                                            </div>

                                            @if (filled($order->complaint_or_diagnosis))
                                                <p class="text-xs text-zinc-600">
                                                    <span class="font-semibold">{{ __('Diagnosis') }}:</span>
                                                    {{ $order->complaint_or_diagnosis }}
                                                </p>
                                            @endif

                                            @foreach ($order->medicines as $medicine)
                                                <p class="text-sm text-zinc-800">
                                                    {{ $medicine->name }}
                                                    <span class="text-zinc-500">— {{ $medicine->dose->label() }}</span>
                                                </p>
                                            @endforeach

                                            @foreach ($order->injections as $injection)
                                                <p class="text-sm text-zinc-800">
                                                    {{ $injection->name }}
                                                    <span class="text-zinc-500">— {{ $injection->administration_type->label() }}</span>
                                                </p>
                                            @endforeach

                                            @foreach ($order->drips as $drip)
                                                <div>
                                                    <p class="text-sm text-zinc-800">{{ $drip->name }}</p>
                                                    @foreach ($drip->additives as $additive)
                                                        <p class="ms-3 text-xs text-zinc-500">+ {{ $additive->name }}</p>
                                                    @endforeach
                                                </div>
                                            @endforeach

                                            @if ($order->medicines->isEmpty() && $order->injections->isEmpty() && $order->drips->isEmpty())
                                                <p class="text-sm text-zinc-500">{{ __('No medication items recorded.') }}</p>
                                            @endif

                                            @if (filled($order->notes))
                                                <p class="text-xs text-zinc-600">
                                                    <span class="font-semibold">{{ __('Notes:') }}</span>
                                                    {{ $order->notes }}
                                                </p>
                                            @endif
                                        </x-paper-slip>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500">{{ __('No visits found.') }}</p>
                    @endforelse
                </flux:card>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <flux:card>
                        <flux:heading size="sm" class="mb-3">{{ __('Walk-in invoices') }}</flux:heading>
                        @forelse ($patient->invoices as $invoice)
                            <div wire:key="mr-invoice-{{ $invoice->id }}" class="border-b border-zinc-100 py-2 last:border-0 dark:border-zinc-700">
                                <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ $invoice->invoice_number }}
                                    · {{ number_format($invoice->total, 2) }}
                                </p>
                                <p class="text-xs text-zinc-500">
                                    {{ $invoice->created_at?->timezone(config('app.timezone'))->format('d M Y, h:i A') }}
                                    · {{ ucfirst($invoice->status) }}
                                </p>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-500">{{ __('None') }}</p>
                        @endforelse
                    </flux:card>

                    <flux:card>
                        <flux:heading size="sm" class="mb-3">{{ __('Lab invoices') }}</flux:heading>
                        @forelse ($patient->labInvoices as $invoice)
                            <div wire:key="mr-lab-invoice-{{ $invoice->id }}" class="border-b border-zinc-100 py-2 last:border-0 dark:border-zinc-700">
                                <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ $invoice->invoice_number }}
                                    · {{ number_format($invoice->total, 2) }}
                                </p>
                                <p class="text-xs text-zinc-500">
                                    {{ $invoice->created_at?->timezone(config('app.timezone'))->format('d M Y, h:i A') }}
                                    · {{ ucfirst($invoice->status) }}
                                </p>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-500">{{ __('None') }}</p>
                        @endforelse
                    </flux:card>
                </div>

                <flux:card>
                    <flux:heading size="sm" class="mb-3">{{ __('Procedures') }}</flux:heading>
                    @forelse ($patient->procedures as $procedure)
                        <div wire:key="mr-procedure-{{ $procedure->id }}" class="border-b border-zinc-100 py-2 last:border-0 dark:border-zinc-700">
                            <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $procedure->name }}</p>
                            <p class="text-xs text-zinc-500">
                                {{ $procedure->created_at?->timezone(config('app.timezone'))->format('d M Y, h:i A') }}
                                @if ($procedure->doctor)
                                    · {{ $procedure->doctor->name }}
                                @endif
                            </p>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500">{{ __('None') }}</p>
                    @endforelse
                </flux:card>

                <flux:card>
                    <flux:heading size="sm" class="mb-3">{{ __('Medication orders') }}</flux:heading>
                    @forelse ($patient->medicationOrders as $order)
                        <div wire:key="mr-medication-{{ $order->id }}" class="border-b border-zinc-100 py-3 last:border-0 dark:border-zinc-700">
                            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ $order->created_at?->timezone(config('app.timezone'))->format('d M Y, h:i A') }}
                                </p>
                                <flux:badge size="sm" color="{{ $order->status === \App\Enums\MedicationOrderStatus::Administered ? 'green' : 'zinc' }}">
                                    {{ $order->status->label() }}
                                </flux:badge>
                            </div>
                            <p class="text-xs text-zinc-500">
                                {{ $order->queueToken?->serviceQueue?->service?->name ?? __('Unknown service') }}
                                @if ($order->doctor)
                                    · {{ $order->doctor->name }}
                                @endif
                            </p>
                            @if ($order->medicines->isNotEmpty())
                                <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-200">
                                    {{ __('Medicines') }}:
                                    {{ $order->medicines->pluck('name')->join(', ') }}
                                </p>
                            @endif
                            @if ($order->injections->isNotEmpty())
                                <p class="text-sm text-zinc-700 dark:text-zinc-200">
                                    {{ __('Injections') }}:
                                    {{ $order->injections->pluck('name')->join(', ') }}
                                </p>
                            @endif
                            @if ($order->drips->isNotEmpty())
                                <p class="text-sm text-zinc-700 dark:text-zinc-200">
                                    {{ __('Drips') }}:
                                    {{ $order->drips->pluck('name')->join(', ') }}
                                </p>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500">{{ __('None') }}</p>
                    @endforelse
                </flux:card>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <flux:card>
                        <flux:heading size="sm" class="mb-3">{{ __('Vitals') }}</flux:heading>
                        @forelse ($patient->vitals as $vital)
                            <div wire:key="mr-vital-{{ $vital->id }}" class="border-b border-zinc-100 py-2 last:border-0 dark:border-zinc-700">
                                <p class="text-sm text-zinc-700 dark:text-zinc-200">
                                    {{ __('Temp') }} {{ $vital->temperature ?? '-' }}
                                    · {{ __('BP') }} {{ $vital->bp_systolic ?? '-' }}/{{ $vital->bp_diastolic ?? '-' }}
                                    · {{ __('BSR') }} {{ $vital->bsr ?? '-' }}
                                </p>
                                <p class="text-xs text-zinc-500">
                                    {{ $vital->created_at?->timezone(config('app.timezone'))->format('d M Y, h:i A') }}
                                </p>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-500">{{ __('None') }}</p>
                        @endforelse
                    </flux:card>

                    <flux:card>
                        <flux:heading size="sm" class="mb-3">{{ __('Ultrasound') }}</flux:heading>
                        @forelse ($patient->ultrasoundReports as $report)
                            <div wire:key="mr-ultrasound-{{ $report->id }}" class="border-b border-zinc-100 py-2 last:border-0 dark:border-zinc-700">
                                <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ $report->created_at?->timezone(config('app.timezone'))->format('d M Y, h:i A') }}
                                </p>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-500">{{ __('None') }}</p>
                        @endforelse
                    </flux:card>
                </div>
            @else
                <flux:callout variant="danger">{{ __('Patient could not be found.') }}</flux:callout>
                <flux:button type="button" variant="ghost" wire:click="clearSelection">{{ __('Back to search') }}</flux:button>
            @endif
        @endif
    </div>

    <flux:modal wire:model="showRecentPatientsModal" class="md:max-w-3xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Recent reception patients') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Patients with the latest queue, invoice, lab, or procedure activity from reception.') }}
                </flux:text>
            </div>

            <div class="max-h-[28rem] space-y-2 overflow-y-auto">
                @forelse ($this->recentReceptionPatients as $patient)
                    <button
                        type="button"
                        wire:key="recent-reception-patient-{{ $patient->id }}"
                        wire:click="selectPatientFromRecentList({{ $patient->id }})"
                        class="flex w-full items-center gap-4 rounded-xl border border-zinc-200 bg-white px-4 py-3 text-left transition hover:border-zinc-300 active:scale-[0.99] dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-600"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-base font-semibold text-zinc-900 dark:text-white">
                                {{ $patient->name }}
                            </span>
                            <span class="mt-0.5 block truncate text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $patient->mrn ?? __('No MRN') }}
                                @if ($patient->contactPhone())
                                    · {{ $patient->contactPhone() }}
                                @endif
                                @if ($patient->age)
                                    · {{ $patient->age }} {{ __('yrs') }}
                                @endif
                            </span>
                        </span>
                        <span class="shrink-0 text-right text-xs text-zinc-500 dark:text-zinc-400">
                            @if ($patient->last_reception_at)
                                <span class="block font-medium text-zinc-700 dark:text-zinc-300">
                                    {{ Carbon::parse($patient->last_reception_at)->timezone(config('app.timezone'))->format('d M Y') }}
                                </span>
                                <span>{{ Carbon::parse($patient->last_reception_at)->timezone(config('app.timezone'))->format('h:i A') }}</span>
                            @else
                                -
                            @endif
                        </span>
                    </button>
                @empty
                    <div class="rounded-xl border border-dashed border-zinc-300 px-6 py-10 text-center dark:border-zinc-600">
                        <p class="text-sm text-zinc-500">{{ __('No reception patients found.') }}</p>
                    </div>
                @endforelse
            </div>

            @if ($this->recentReceptionPatients->hasPages())
                <div>
                    {{ $this->recentReceptionPatients->links() }}
                </div>
            @endif

            <div class="flex justify-end">
                <flux:button type="button" variant="ghost" wire:click="closeRecentPatientsModal">
                    {{ __('Close') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
