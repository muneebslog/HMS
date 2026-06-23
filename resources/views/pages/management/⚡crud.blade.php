<?php

use App\Enums\TokenResetType;
use App\Models\Doctor;
use App\Models\LabTest;
use App\Models\Service;
use App\Models\ServicePrice;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Management')] class extends Component
{
    public string $activeTab = 'doctors';

    public bool $showModal = false;

    public ?int $editingId = null;

    #[Validate]
    public string $doctorName = '';

    #[Validate]
    public string $doctorSpecialization = '';

    #[Validate]
    public bool $doctorPayoutDaily = false;

    #[Validate]
    public string $serviceName = '';

    #[Validate]
    public bool $serviceIsStandalone = false;

    #[Validate]
    public string $serviceTokenResetType = '';

    #[Validate]
    public ?int $priceServiceId = null;

    #[Validate]
    public ?int $priceDoctorId = null;

    #[Validate]
    public string $priceAmount = '';

    #[Validate]
    public string $priceDoctorShare = '';

    #[Validate]
    public string $labTestName = '';

    #[Validate]
    public string $labTestCode = '';

    #[Validate]
    public string $labTestPrice = '';

    #[Validate]
    public string $labTestTimeRequired = '';

    #[Validate]
    public bool $labTestIsInHouse = true;

    /**
     * Get the validation rules for the current tab.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return match ($this->activeTab) {
            'doctors' => [
                'doctorName' => ['required', 'string', 'max:255'],
                'doctorSpecialization' => ['required', 'string', 'max:255'],
                'doctorPayoutDaily' => ['boolean'],
            ],
            'services' => [
                'serviceName' => [
                    'required',
                    'string',
                    'max:255',
                    function ($attribute, $value, $fail) {
                        if (strtolower($value) !== 'consultation') {
                            return;
                        }

                        $existing = \App\Models\Service::whereRaw('LOWER(name) = ?', ['consultation'])
                            ->when($this->editingId, fn ($query) => $query->where('id', '!=', $this->editingId))
                            ->first();

                        if ($existing !== null) {
                            $fail(__('A service named consultation already exists.'));
                        }
                    },
                ],
                'serviceIsStandalone' => ['boolean'],
                'serviceTokenResetType' => ['required', 'string', 'in:'.implode(',', array_column(TokenResetType::cases(), 'value'))],
            ],
            'servicePrices' => [
                'priceServiceId' => ['required', 'integer', 'exists:services,id'],
                'priceDoctorId' => ['nullable', 'integer', 'exists:doctors,id'],
                'priceAmount' => ['required', 'numeric', 'min:0'],
                'priceDoctorShare' => ['nullable', 'numeric', 'min:0', 'max:100'],
            ],
            'labTests' => [
                'labTestName' => ['required', 'string', 'max:255'],
                'labTestCode' => ['required', 'string', 'max:255', Rule::unique('lab_tests', 'test_code')->ignore($this->editingId)],
                'labTestPrice' => ['required', 'numeric', 'min:0'],
                'labTestTimeRequired' => ['required', 'string', 'max:255'],
                'labTestIsInHouse' => ['boolean'],
            ],
            default => [],
        };
    }

    /**
     * Open the modal to create a new record.
     */
    public function create(): void
    {
        $this->resetForm();
        $this->editingId = null;
        $this->showModal = true;
    }

    /**
     * Open the modal to edit an existing record.
     */
    public function edit(int $id): void
    {
        $this->resetForm();
        $this->editingId = $id;

        match ($this->activeTab) {
            'doctors' => $this->loadDoctor($id),
            'services' => $this->loadService($id),
            'servicePrices' => $this->loadServicePrice($id),
            'labTests' => $this->loadLabTest($id),
        };

        $this->showModal = true;
    }

    /**
     * Load doctor data into the form.
     */
    private function loadDoctor(int $id): void
    {
        $doctor = Doctor::findOrFail($id);

        $this->doctorName = $doctor->name;
        $this->doctorSpecialization = $doctor->specialization;
        $this->doctorPayoutDaily = $doctor->payout_daily;
    }

    /**
     * Load service data into the form.
     */
    private function loadService(int $id): void
    {
        $service = Service::findOrFail($id);

        $this->serviceName = $service->name;
        $this->serviceIsStandalone = $service->is_standalone;
        $this->serviceTokenResetType = $service->token_reset_type->value;
    }

    /**
     * Load service price data into the form.
     */
    private function loadServicePrice(int $id): void
    {
        $price = ServicePrice::findOrFail($id);

        $this->priceServiceId = $price->service_id;
        $this->priceDoctorId = $price->doctor_id;
        $this->priceAmount = (string) $price->price;
        $this->priceDoctorShare = $price->doctor_share !== null ? (string) $price->doctor_share : '';
    }

    /**
     * Load lab test data into the form.
     */
    private function loadLabTest(int $id): void
    {
        $labTest = LabTest::findOrFail($id);

        $this->labTestName = $labTest->test_name;
        $this->labTestCode = $labTest->test_code;
        $this->labTestPrice = (string) $labTest->test_price;
        $this->labTestTimeRequired = $labTest->time_required;
        $this->labTestIsInHouse = $labTest->is_in_house;
    }

    /**
     * Reset all form fields.
     */
    private function resetForm(): void
    {
        $this->reset([
            'doctorName',
            'doctorSpecialization',
            'doctorPayoutDaily',
            'serviceName',
            'serviceIsStandalone',
            'serviceTokenResetType',
            'priceServiceId',
            'priceDoctorId',
            'priceAmount',
            'priceDoctorShare',
            'labTestName',
            'labTestCode',
            'labTestPrice',
            'labTestTimeRequired',
            'labTestIsInHouse',
        ]);

        $this->resetErrorBag();
    }

    /**
     * Store or update the current record.
     */
    public function save(): void
    {
        $validated = $this->validate();

        match ($this->activeTab) {
            'doctors' => $this->saveDoctor($validated),
            'services' => $this->saveService($validated),
            'servicePrices' => $this->saveServicePrice($validated),
            'labTests' => $this->saveLabTest($validated),
        };

        $this->showModal = false;
        $this->resetForm();
    }

    /**
     * Persist doctor data.
     *
     * @param array<string, mixed> $validated
     */
    private function saveDoctor(array $validated): void
    {
        $data = [
            'name' => $validated['doctorName'],
            'specialization' => $validated['doctorSpecialization'],
            'payout_daily' => $validated['doctorPayoutDaily'],
        ];

        if ($this->editingId) {
            Doctor::findOrFail($this->editingId)->update($data);
            Flux::toast(variant: 'success', text: __('Doctor updated.'));
        } else {
            Doctor::create($data);
            Flux::toast(variant: 'success', text: __('Doctor created.'));
        }
    }

    /**
     * Persist service data.
     *
     * @param array<string, mixed> $validated
     */
    private function saveService(array $validated): void
    {
        $data = [
            'name' => $validated['serviceName'],
            'is_standalone' => $validated['serviceIsStandalone'],
            'token_reset_type' => $validated['serviceTokenResetType'],
        ];

        if ($this->editingId) {
            Service::findOrFail($this->editingId)->update($data);
            Flux::toast(variant: 'success', text: __('Service updated.'));
        } else {
            Service::create($data);
            Flux::toast(variant: 'success', text: __('Service created.'));
        }
    }

    /**
     * Persist service price data.
     *
     * @param array<string, mixed> $validated
     */
    private function saveServicePrice(array $validated): void
    {
        $data = [
            'service_id' => $validated['priceServiceId'],
            'doctor_id' => $validated['priceDoctorId'],
            'price' => $validated['priceAmount'],
            'doctor_share' => $validated['priceDoctorShare'] !== '' ? $validated['priceDoctorShare'] : null,
        ];

        if ($this->editingId) {
            ServicePrice::findOrFail($this->editingId)->update($data);
            Flux::toast(variant: 'success', text: __('Service price updated.'));
        } else {
            ServicePrice::create($data);
            Flux::toast(variant: 'success', text: __('Service price created.'));
        }
    }

    /**
     * Persist lab test data.
     *
     * @param array<string, mixed> $validated
     */
    private function saveLabTest(array $validated): void
    {
        $data = [
            'test_name' => $validated['labTestName'],
            'test_code' => $validated['labTestCode'],
            'test_price' => $validated['labTestPrice'],
            'time_required' => $validated['labTestTimeRequired'],
            'is_in_house' => $validated['labTestIsInHouse'],
        ];

        if ($this->editingId) {
            LabTest::findOrFail($this->editingId)->update($data);
            Flux::toast(variant: 'success', text: __('Lab test updated.'));
        } else {
            LabTest::create($data);
            Flux::toast(variant: 'success', text: __('Lab test created.'));
        }
    }

    /**
     * Delete the current record.
     */
    public function delete(int $id): void
    {
        match ($this->activeTab) {
            'doctors' => Doctor::findOrFail($id)->delete(),
            'services' => Service::findOrFail($id)->delete(),
            'servicePrices' => ServicePrice::findOrFail($id)->delete(),
            'labTests' => LabTest::findOrFail($id)->delete(),
        };

        Flux::toast(variant: 'success', text: __('Record deleted.'));
    }

    /**
     * Switch the active tab and reset state.
     */
    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetForm();
        $this->editingId = null;
        $this->showModal = false;
    }

    /**
     * Get the list of doctors.
     *
     * @return Collection<int, Doctor>
     */
    #[Computed]
    public function doctors(): Collection
    {
        return Doctor::orderBy('name')->get();
    }

    /**
     * Get the list of services.
     *
     * @return Collection<int, Service>
     */
    #[Computed]
    public function services(): Collection
    {
        return Service::orderBy('name')->get();
    }

    /**
     * Get the list of service prices with relations.
     *
     * @return Collection<int, ServicePrice>
     */
    #[Computed]
    public function servicePrices(): Collection
    {
        return ServicePrice::with(['service', 'doctor'])->orderBy('id', 'desc')->get();
    }

    /**
     * Get the list of lab tests.
     *
     * @return Collection<int, LabTest>
     */
    #[Computed]
    public function labTests(): Collection
    {
        return LabTest::orderBy('test_name')->get();
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading level="1">{{ __('Management') }}</flux:heading>
                <flux:button variant="primary" icon="plus" wire:click="create">
                    {{ __('Add new') }}
                </flux:button>
            </div>

            <flux:card>
                <div class="border-b border-zinc-200 dark:border-zinc-700">
                    <nav class="-mb-px flex gap-6" aria-label="Tabs">
                        <button
                            type="button"
                            wire:click="switchTab('doctors')"
                            class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $activeTab === 'doctors' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
                        >
                            {{ __('Doctors') }}
                        </button>
                        <button
                            type="button"
                            wire:click="switchTab('services')"
                            class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $activeTab === 'services' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
                        >
                            {{ __('Services') }}
                        </button>
                        <button
                            type="button"
                            wire:click="switchTab('servicePrices')"
                            class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $activeTab === 'servicePrices' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
                        >
                            {{ __('Service Prices') }}
                        </button>
                        <button
                            type="button"
                            wire:click="switchTab('labTests')"
                            class="cursor-pointer border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $activeTab === 'labTests' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:text-zinc-300' }}"
                        >
                            {{ __('Lab Tests') }}
                        </button>
                    </nav>
                </div>

                <div class="mt-6">
                @if ($activeTab === 'labTests')
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Test Name') }}</flux:table.column>
                            <flux:table.column>{{ __('Test Code') }}</flux:table.column>
                            <flux:table.column>{{ __('Price') }}</flux:table.column>
                            <flux:table.column>{{ __('Time Required') }}</flux:table.column>
                            <flux:table.column>{{ __('In House') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->labTests as $labTest)
                                <flux:table.row wire:key="lab-test-{{ $labTest->id }}">
                                    <flux:table.cell>{{ $labTest->test_name }}</flux:table.cell>
                                    <flux:table.cell>{{ $labTest->test_code }}</flux:table.cell>
                                    <flux:table.cell>{{ number_format($labTest->test_price, 2) }}</flux:table.cell>
                                    <flux:table.cell>{{ $labTest->time_required }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($labTest->is_in_house)
                                            <flux:badge size="sm" color="green">{{ __('Yes') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('Send out') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $labTest->id }})" />
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $labTest->id }})" wire:confirm="{{ __('Are you sure you want to delete this lab test?') }}" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="6" class="text-center text-zinc-500">
                                        {{ __('No lab tests found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                @elseif ($activeTab === 'doctors')
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Name') }}</flux:table.column>
                            <flux:table.column>{{ __('Specialization') }}</flux:table.column>
                            <flux:table.column>{{ __('Daily Payout') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->doctors as $doctor)
                                <flux:table.row wire:key="doctor-{{ $doctor->id }}">
                                    <flux:table.cell>{{ $doctor->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $doctor->specialization }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($doctor->payout_daily)
                                            <flux:badge size="sm" color="green">{{ __('Yes') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('No') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $doctor->id }})" />
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $doctor->id }})" wire:confirm="{{ __('Are you sure you want to delete this doctor?') }}" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                        {{ __('No doctors found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                @elseif ($activeTab === 'services')
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Name') }}</flux:table.column>
                            <flux:table.column>{{ __('Standalone') }}</flux:table.column>
                            <flux:table.column>{{ __('Token Reset') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->services as $service)
                                <flux:table.row wire:key="service-{{ $service->id }}">
                                    <flux:table.cell>{{ $service->name }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($service->is_standalone)
                                            <flux:badge size="sm" color="green">{{ __('Yes') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('No') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $service->token_reset_type->label() }}</flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $service->id }})" />
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $service->id }})" wire:confirm="{{ __('Are you sure you want to delete this service?') }}" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="4" class="text-center text-zinc-500">
                                        {{ __('No services found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Service') }}</flux:table.column>
                            <flux:table.column>{{ __('Doctor') }}</flux:table.column>
                            <flux:table.column>{{ __('Price') }}</flux:table.column>
                            <flux:table.column>{{ __('Doctor Share') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @forelse ($this->servicePrices as $price)
                                <flux:table.row wire:key="price-{{ $price->id }}">
                                    <flux:table.cell>{{ $price->service->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $price->doctor?->name ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>{{ number_format($price->price, 2) }}</flux:table.cell>
                                    <flux:table.cell>{{ $price->doctor_share !== null ? number_format($price->doctor_share, 2).'%' : '-' }}</flux:table.cell>
                                    <flux:table.cell class="text-right">
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $price->id }})" />
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $price->id }})" wire:confirm="{{ __('Are you sure you want to delete this service price?') }}" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="5" class="text-center text-zinc-500">
                                        {{ __('No service prices found.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                @endif
            </div>
        </flux:card>
    </div>

    <flux:modal wire:model="showModal" class="w-full max-w-lg">
        <flux:heading level="2">
            {{ $editingId ? __('Edit :resource', ['resource' => match($activeTab) { 'doctors' => __('Doctor'), 'services' => __('Service'), 'labTests' => __('Lab Test'), default => __('Service Price') }]) : __('Create :resource', ['resource' => match($activeTab) { 'doctors' => __('Doctor'), 'services' => __('Service'), 'labTests' => __('Lab Test'), default => __('Service Price') }]) }}
        </flux:heading>

        <form wire:submit="save" class="mt-6 space-y-6">
            @if ($activeTab === 'doctors')
                <flux:field>
                    <flux:label>{{ __('Name') }}</flux:label>
                    <flux:input wire:model="doctorName" type="text" required />
                    <flux:error name="doctorName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Specialization') }}</flux:label>
                    <flux:input wire:model="doctorSpecialization" type="text" required />
                    <flux:error name="doctorSpecialization" />
                </flux:field>

                <flux:field>
                    <flux:switch wire:model="doctorPayoutDaily" :label="__('Daily payout')" />
                    <flux:error name="doctorPayoutDaily" />
                </flux:field>
            @elseif ($activeTab === 'services')
                <flux:field>
                    <flux:label>{{ __('Name') }}</flux:label>
                    <flux:input wire:model="serviceName" type="text" required />
                    <flux:error name="serviceName" />
                </flux:field>

                <flux:field>
                    <flux:switch wire:model="serviceIsStandalone" :label="__('Standalone service')" />
                    <flux:error name="serviceIsStandalone" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Token reset') }}</flux:label>
                    <flux:select wire:model="serviceTokenResetType" required>
                        @foreach (TokenResetType::cases() as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="serviceTokenResetType" />
                </flux:field>
            @elseif ($activeTab === 'servicePrices')
                <flux:field>
                    <flux:label>{{ __('Service') }}</flux:label>
                    <flux:select wire:model="priceServiceId" required>
                        <option value="">{{ __('Select a service') }}</option>
                        @foreach ($this->services as $service)
                            <option value="{{ $service->id }}">{{ $service->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="priceServiceId" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Doctor') }}</flux:label>
                    <flux:select wire:model="priceDoctorId">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($this->doctors as $doctor)
                            <option value="{{ $doctor->id }}">{{ $doctor->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="priceDoctorId" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Price') }}</flux:label>
                    <flux:input wire:model="priceAmount" type="number" step="0.01" min="0" required />
                    <flux:error name="priceAmount" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Doctor Share (%)') }}</flux:label>
                    <flux:input wire:model="priceDoctorShare" type="number" step="0.01" min="0" max="100" />
                    <flux:error name="priceDoctorShare" />
                </flux:field>
            @else
                <flux:field>
                    <flux:label>{{ __('Test Name') }}</flux:label>
                    <flux:input wire:model="labTestName" type="text" required />
                    <flux:error name="labTestName" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Test Code') }}</flux:label>
                    <flux:input wire:model="labTestCode" type="text" required />
                    <flux:error name="labTestCode" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Price') }}</flux:label>
                    <flux:input wire:model="labTestPrice" type="number" step="0.01" min="0" required />
                    <flux:error name="labTestPrice" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Time Required') }}</flux:label>
                    <flux:input wire:model="labTestTimeRequired" type="text" required />
                    <flux:error name="labTestTimeRequired" />
                </flux:field>

                <flux:field>
                    <flux:switch wire:model="labTestIsInHouse" :label="__('In house test')" />
                    <flux:error name="labTestIsInHouse" />
                </flux:field>
            @endif

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ $editingId ? __('Update') : __('Save') }}
                </flux:button>
            </div>
            </form>
    </flux:modal>
</div>
