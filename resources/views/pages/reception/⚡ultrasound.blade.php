<?php

use App\Enums\UltrasoundBiophysicalProfile;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use App\Models\UltrasoundReport;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Ultrasound')] class extends Component
{
    #[Validate]
    public ?int $selectedQueueId = null;

    #[Validate]
    public ?int $selectedTokenId = null;

    #[Validate]
    public string $reportDate = '';

    #[Validate]
    public string $name = '';

    #[Validate]
    public ?int $age = null;

    #[Validate]
    public string $fetusStatus = '';

    #[Validate]
    public string $bpdMeas = '';

    #[Validate]
    public string $bpdAge = '';

    #[Validate]
    public string $femurMeas = '';

    #[Validate]
    public string $femurAge = '';

    #[Validate]
    public string $acMeas = '';

    #[Validate]
    public string $acAge = '';

    #[Validate]
    public string $crlMeas = '';

    #[Validate]
    public string $crlAge = '';

    #[Validate]
    public string $gestAge = '';

    #[Validate]
    public string $edd = '';

    #[Validate]
    public string $heartMotion = '';

    #[Validate]
    public string $placenta = '';

    #[Validate]
    public string $placentaGrade = '';

    #[Validate]
    public string $amnioticFluid = '';

    #[Validate]
    public string $presentation = '';

    public bool $ltVentricular = false;

    public bool $bpdLevel = false;

    public bool $feralStomach = false;

    public bool $kidneys = false;

    public bool $bladder = false;

    public bool $spine = false;

    #[Validate]
    public string $bpp = '';

    #[Validate]
    public string $conclusionLine1 = '';

    #[Validate]
    public string $conclusionLine2 = '';

    /**
     * Initialize the component.
     */
    public function mount(): void
    {
        $this->reportDate = now()->toDateString();
    }

    /**
     * Get the validation rules for the form.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'selectedQueueId' => ['required', 'integer', 'exists:service_queues,id'],
            'selectedTokenId' => ['required', 'integer', 'exists:queue_tokens,id'],
            'reportDate' => ['required', 'date'],
            'name' => ['required', 'string', 'max:255'],
            'age' => ['nullable', 'integer', 'min:0', 'max:150'],
            'fetusStatus' => ['nullable', 'string', 'max:255'],
            'bpdMeas' => ['nullable', 'string', 'max:255'],
            'bpdAge' => ['nullable', 'string', 'max:255'],
            'femurMeas' => ['nullable', 'string', 'max:255'],
            'femurAge' => ['nullable', 'string', 'max:255'],
            'acMeas' => ['nullable', 'string', 'max:255'],
            'acAge' => ['nullable', 'string', 'max:255'],
            'crlMeas' => ['nullable', 'string', 'max:255'],
            'crlAge' => ['nullable', 'string', 'max:255'],
            'gestAge' => ['nullable', 'string', 'max:255'],
            'edd' => ['nullable', 'string', 'max:255'],
            'heartMotion' => ['nullable', 'string', 'max:255'],
            'placenta' => ['nullable', 'string', 'max:255'],
            'placentaGrade' => ['nullable', 'string', 'max:255'],
            'amnioticFluid' => ['nullable', 'string', 'max:255'],
            'presentation' => ['nullable', 'string', 'max:255'],
            'bpp' => ['nullable', 'string', 'in:'.implode(',', UltrasoundBiophysicalProfile::values())],
            'conclusionLine1' => ['nullable', 'string', 'max:255'],
            'conclusionLine2' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Reset the token selection when the queue changes.
     */
    public function updatedSelectedQueueId(): void
    {
        $this->selectedTokenId = null;
        $this->resetPatientFields();
        $this->resetValidation(['selectedTokenId', 'name', 'age']);
    }

    /**
     * Load patient details when a token is selected.
     */
    public function updatedSelectedTokenId(): void
    {
        $this->resetPatientFields();

        $token = $this->selectedToken;

        if ($token === null || $token->patient === null) {
            return;
        }

        $this->name = $token->patient->name;
        $this->age = $token->patient->age;
    }

    /**
     * Reset patient-related fields.
     */
    private function resetPatientFields(): void
    {
        $this->reset([
            'name',
            'age',
            'fetusStatus',
            'bpdMeas',
            'bpdAge',
            'femurMeas',
            'femurAge',
            'acMeas',
            'acAge',
            'crlMeas',
            'crlAge',
            'gestAge',
            'edd',
            'heartMotion',
            'placenta',
            'placentaGrade',
            'amnioticFluid',
            'presentation',
            'ltVentricular',
            'bpdLevel',
            'feralStomach',
            'kidneys',
            'bladder',
            'spine',
            'bpp',
            'conclusionLine1',
            'conclusionLine2',
        ]);
        $this->reportDate = now()->toDateString();
    }

    /**
     * Clear the form.
     */
    public function clear(): void
    {
        $this->reset([
            'selectedQueueId',
            'selectedTokenId',
            'reportDate',
            'name',
            'age',
            'fetusStatus',
            'bpdMeas',
            'bpdAge',
            'femurMeas',
            'femurAge',
            'acMeas',
            'acAge',
            'crlMeas',
            'crlAge',
            'gestAge',
            'edd',
            'heartMotion',
            'placenta',
            'placentaGrade',
            'amnioticFluid',
            'presentation',
            'ltVentricular',
            'bpdLevel',
            'feralStomach',
            'kidneys',
            'bladder',
            'spine',
            'bpp',
            'conclusionLine1',
            'conclusionLine2',
        ]);
        $this->reportDate = now()->toDateString();
        $this->resetValidation();
    }

    /**
     * Save the ultrasound report.
     */
    public function save(): void
    {
        $validated = $this->validate();

        $shift = Shift::current();

        if ($shift === null) {
            Flux::toast(variant: 'danger', text: __('Please open a shift first.'));

            return;
        }

        $token = $this->selectedToken;

        if ($token === null || $token->patient === null) {
            Flux::toast(variant: 'danger', text: __('Token or patient not found.'));

            return;
        }

        if ($token->service_queue_id !== $this->selectedQueueId) {
            Flux::toast(variant: 'danger', text: __('Selected token does not belong to the selected queue.'));

            return;
        }

        $report = DB::transaction(function () use ($token) {
            $report = UltrasoundReport::create([
                'queue_token_id' => $token->id,
                'patient_id' => $token->patient_id,
                'doctor_id' => $token->serviceQueue?->doctor_id,
                'service_queue_id' => $token->service_queue_id,
                'report_date' => $this->reportDate,
                'name' => $this->name,
                'age' => $this->age,
                'fetus_status' => $this->fetusStatus,
                'bpd_meas' => $this->bpdMeas,
                'bpd_age' => $this->bpdAge,
                'femur_meas' => $this->femurMeas,
                'femur_age' => $this->femurAge,
                'ac_meas' => $this->acMeas,
                'ac_age' => $this->acAge,
                'crl_meas' => $this->crlMeas,
                'crl_age' => $this->crlAge,
                'gest_age' => $this->gestAge,
                'edd' => $this->edd,
                'heart_motion' => $this->heartMotion,
                'placenta' => $this->placenta,
                'placenta_grade' => $this->placentaGrade,
                'amniotic_fluid' => $this->amnioticFluid,
                'presentation' => $this->presentation,
                'lt_ventricular' => $this->ltVentricular,
                'bpd_level' => $this->bpdLevel,
                'feral_stomach' => $this->feralStomach,
                'kidneys' => $this->kidneys,
                'bladder' => $this->bladder,
                'spine' => $this->spine,
                'bpp' => $this->bpp,
                'conclusion_line1' => $this->conclusionLine1,
                'conclusion_line2' => $this->conclusionLine2,
            ]);

            $token->update(['status' => 'served']);

            return $report;
        });

        Flux::toast(variant: 'success', text: __('Ultrasound report saved.'));

        $this->redirectRoute('reception.ultrasound.print', ['report' => $report->id], navigate: false);
    }

    /**
     * Get the currently selected token.
     */
    #[Computed]
    public function selectedToken(): ?QueueToken
    {
        if ($this->selectedTokenId === null) {
            return null;
        }

        return QueueToken::with('patient', 'serviceQueue.doctor')->find($this->selectedTokenId);
    }

    /**
     * Get the consultation service.
     */
    #[Computed]
    public function consultationService(): ?Service
    {
        return Service::whereRaw('LOWER(name) = ?', ['consultation'])->first();
    }

    /**
     * Get open consultation queues for the current shift.
     *
     * @return Collection<int, ServiceQueue>
     */
    #[Computed]
    public function queues(): Collection
    {
        $service = $this->consultationService;

        if ($service === null) {
            return new Collection();
        }

        $shift = Shift::current();

        if ($shift === null) {
            return new Collection();
        }

        return ServiceQueue::with('doctor')
            ->where('service_id', $service->id)
            ->where('status', 'open')
            ->where('shift_id', $shift->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * Get the tokens available in the selected queue.
     *
     * @return Collection<int, QueueToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        if ($this->selectedQueueId === null) {
            return new Collection();
        }

        return QueueToken::with('patient')
            ->where('service_queue_id', $this->selectedQueueId)
            ->whereIn('status', ['waiting', 'serving'])
            ->orderBy('token_number')
            ->get();
    }
}; ?>

<div>
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading level="1">{{ __('Ultrasound') }}</flux:heading>
        </div>

        <flux:card>
            <form class="grid grid-cols-1 items-end gap-6 md:grid-cols-12">
                <flux:field class="md:col-span-5">
                    <flux:label>{{ __('Doctor queue') }}</flux:label>
                    <flux:select wire:model.live="selectedQueueId" required>
                        <option value="">{{ __('Select a queue') }}</option>
                        @foreach ($this->queues as $queue)
                            <option value="{{ $queue->id }}">
                                {{ $queue->doctor?->name ?? __('No doctor') }}
                                ({{ __('Token') }} {{ $queue->last_token_number }})
                            </option>
                        @endforeach
                    </flux:select>
                    <flux:error name="selectedQueueId" />
                </flux:field>

                <flux:field class="md:col-span-5">
                    <flux:label>{{ __('Token') }}</flux:label>
                    <flux:select wire:model.live="selectedTokenId" required>
                        <option value="">{{ __('Select a token') }}</option>
                        @foreach ($this->tokens as $token)
                            <option value="{{ $token->id }}">
                                #{{ $token->token_number }} - {{ $token->patient?->name ?? '-' }}
                            </option>
                        @endforeach
                    </flux:select>
                    <flux:error name="selectedTokenId" />
                </flux:field>

                <flux:field class="md:col-span-2">
                    <flux:label>{{ __('Date') }}</flux:label>
                    <flux:input wire:model="reportDate" type="date" required />
                    <flux:error name="reportDate" />
                </flux:field>
            </form>
        </flux:card>

        @if ($this->selectedToken)
            <flux:card>
                <flux:heading level="2" class="mb-4">{{ __('Patient details') }}</flux:heading>

                <div class="grid grid-cols-1 gap-6 md:grid-cols-12">
                    <flux:field class="md:col-span-6">
                        <flux:label>{{ __('Patient name') }}</flux:label>
                        <flux:input wire:model="name" type="text" required />
                        <flux:error name="name" />
                    </flux:field>

                    <flux:field class="md:col-span-3">
                        <flux:label>{{ __('Age') }}</flux:label>
                        <flux:input wire:model="age" type="number" min="0" max="150" />
                        <flux:error name="age" />
                    </flux:field>

                    <flux:field class="md:col-span-3">
                        <flux:label>{{ __('Fetus present in') }}</flux:label>
                        <flux:input wire:model="fetusStatus" type="text" placeholder="e.g. intrauterine" />
                        <flux:error name="fetusStatus" />
                    </flux:field>
                </div>
            </flux:card>

            <flux:card>
                <flux:heading level="2" class="mb-4">{{ __('Fetal measurements') }}</flux:heading>

                <div class="grid grid-cols-1 gap-6 md:grid-cols-12">
                    <flux:field class="md:col-span-3">
                        <flux:label>{{ __('BPD (mm)') }}</flux:label>
                        <flux:input wire:model="bpdMeas" type="text" />
                        <flux:error name="bpdMeas" />
                    </flux:field>

                    <flux:field class="md:col-span-3">
                        <flux:label>{{ __('BPD gest. age (wks)') }}</flux:label>
                        <flux:input wire:model="bpdAge" type="text" />
                        <flux:error name="bpdAge" />
                    </flux:field>

                    <flux:field class="md:col-span-3">
                        <flux:label>{{ __('Femur (mm)') }}</flux:label>
                        <flux:input wire:model="femurMeas" type="text" />
                        <flux:error name="femurMeas" />
                    </flux:field>

                    <flux:field class="md:col-span-3">
                        <flux:label>{{ __('Femur gest. age (wks)') }}</flux:label>
                        <flux:input wire:model="femurAge" type="text" />
                        <flux:error name="femurAge" />
                    </flux:field>

                    <flux:field class="md:col-span-3">
                        <flux:label>{{ __('AC (mm)') }}</flux:label>
                        <flux:input wire:model="acMeas" type="text" />
                        <flux:error name="acMeas" />
                    </flux:field>

                    <flux:field class="md:col-span-3">
                        <flux:label>{{ __('AC gest. age (wks)') }}</flux:label>
                        <flux:input wire:model="acAge" type="text" />
                        <flux:error name="acAge" />
                    </flux:field>

                    <flux:field class="md:col-span-3">
                        <flux:label>{{ __('CRL (mm)') }}</flux:label>
                        <flux:input wire:model="crlMeas" type="text" />
                        <flux:error name="crlMeas" />
                    </flux:field>

                    <flux:field class="md:col-span-3">
                        <flux:label>{{ __('CRL gest. age (wks)') }}</flux:label>
                        <flux:input wire:model="crlAge" type="text" />
                        <flux:error name="crlAge" />
                    </flux:field>
                </div>
            </flux:card>

            <flux:card>
                <flux:heading level="2" class="mb-4">{{ __('Clinical details') }}</flux:heading>

                <div class="grid grid-cols-1 gap-6 md:grid-cols-12">
                    <flux:field class="md:col-span-3">
                        <flux:label>{{ __('Gestational age (wks)') }}</flux:label>
                        <flux:input wire:model="gestAge" type="text" />
                        <flux:error name="gestAge" />
                    </flux:field>

                    <flux:field class="md:col-span-3">
                        <flux:label>{{ __('EDD') }}</flux:label>
                        <flux:input wire:model="edd" type="text" />
                        <flux:error name="edd" />
                    </flux:field>

                    <flux:field class="md:col-span-3">
                        <flux:label>{{ __('Fetal heart motion') }}</flux:label>
                        <flux:input wire:model="heartMotion" type="text" />
                        <flux:error name="heartMotion" />
                    </flux:field>

                    <flux:field class="md:col-span-3">
                        <flux:label>{{ __('Placenta') }}</flux:label>
                        <flux:input wire:model="placenta" type="text" />
                        <flux:error name="placenta" />
                    </flux:field>

                    <flux:field class="md:col-span-4">
                        <flux:label>{{ __('Position with grade') }}</flux:label>
                        <flux:input wire:model="placentaGrade" type="text" />
                        <flux:error name="placentaGrade" />
                    </flux:field>

                    <flux:field class="md:col-span-4">
                        <flux:label>{{ __('Amniotic fluid volume') }}</flux:label>
                        <flux:input wire:model="amnioticFluid" type="text" />
                        <flux:error name="amnioticFluid" />
                    </flux:field>

                    <flux:field class="md:col-span-4">
                        <flux:label>{{ __('Lie / presentation') }}</flux:label>
                        <flux:input wire:model="presentation" type="text" />
                        <flux:error name="presentation" />
                    </flux:field>
                </div>
            </flux:card>

            <flux:card>
                <flux:heading level="2" class="mb-4">{{ __('Anatomy checkmarks') }}</flux:heading>

                <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
                    <flux:field>
                        <flux:label>{{ __('Lt ventricular') }}</flux:label>
                        <flux:select wire:model.boolean="ltVentricular">
                            <option value="0">{{ __('No') }}</option>
                            <option value="1">{{ __('Yes') }}</option>
                        </flux:select>
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('BPD level') }}</flux:label>
                        <flux:select wire:model.boolean="bpdLevel">
                            <option value="0">{{ __('No') }}</option>
                            <option value="1">{{ __('Yes') }}</option>
                        </flux:select>
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Fetal stomach') }}</flux:label>
                        <flux:select wire:model.boolean="feralStomach">
                            <option value="0">{{ __('No') }}</option>
                            <option value="1">{{ __('Yes') }}</option>
                        </flux:select>
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Kidneys') }}</flux:label>
                        <flux:select wire:model.boolean="kidneys">
                            <option value="0">{{ __('No') }}</option>
                            <option value="1">{{ __('Yes') }}</option>
                        </flux:select>
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Bladder') }}</flux:label>
                        <flux:select wire:model.boolean="bladder">
                            <option value="0">{{ __('No') }}</option>
                            <option value="1">{{ __('Yes') }}</option>
                        </flux:select>
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Spine') }}</flux:label>
                        <flux:select wire:model.boolean="spine">
                            <option value="0">{{ __('No') }}</option>
                            <option value="1">{{ __('Yes') }}</option>
                        </flux:select>
                    </flux:field>
                </div>
            </flux:card>

            <flux:card>
                <flux:heading level="2" class="mb-4">{{ __('Biophysical profile') }}</flux:heading>

                <flux:radio.group wire:model="bpp" class="flex flex-wrap gap-6">
                    @foreach (App\Enums\UltrasoundBiophysicalProfile::cases() as $profile)
                        <flux:radio value="{{ $profile->value }}">{{ $profile->label() }}</flux:radio>
                    @endforeach
                </flux:radio.group>
                <flux:error name="bpp" />
            </flux:card>

            <flux:card>
                <flux:heading level="2" class="mb-4">{{ __('Conclusion') }}</flux:heading>

                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Conclusion line 1') }}</flux:label>
                        <flux:input wire:model="conclusionLine1" type="text" />
                        <flux:error name="conclusionLine1" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Conclusion line 2') }}</flux:label>
                        <flux:input wire:model="conclusionLine2" type="text" />
                        <flux:error name="conclusionLine2" />
                    </flux:field>
                </div>
            </flux:card>

            <div class="flex gap-3">
                <flux:button type="button" variant="primary" icon="document-check" wire:click="save">
                    {{ __('Save & print') }}
                </flux:button>

                <flux:button type="button" variant="ghost" wire:click="clear">
                    {{ __('Clear') }}
                </flux:button>
            </div>
        @endif
    </div>
</div>
