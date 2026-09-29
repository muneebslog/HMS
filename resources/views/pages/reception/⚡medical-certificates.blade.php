<?php

use App\Enums\MedicalCertificateType;
use App\Models\Doctor;
use App\Models\MedicalCertificate;
use App\Models\Patient;
use App\Services\PatientIntakeService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Medical Certificates')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $typeFilter = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $patientSearch = '';

    public ?int $patientId = null;

    public string $type = 'sick_leave';

    public string $patientTitle = 'Mr.';

    public string $patientName = '';

    public string $gender = 'male';

    public string $relation = 's/o';

    public string $guardianName = '';

    public ?int $age = null;

    public string $mrn = '';

    public string $cnic = '';

    public string $diagnosis = '';

    public bool $showDiagnosis = true;

    public string $startDate = '';

    public string $endDate = '';

    public string $resumeDate = '';

    public string $timeFrom = '';

    public string $timeTo = '';

    public ?int $gestationWeeks = null;

    public string $expectedDeliveryDate = '';

    public ?int $doctorId = null;

    public string $doctorName = '';

    public bool $showVoidModal = false;

    public ?int $voidingId = null;

    public string $voidReason = '';

    /**
     * Issued certificates, newest first.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, MedicalCertificate>
     */
    #[Computed]
    public function certificates()
    {
        return MedicalCertificate::query()
            ->with('issuedBy')
            ->when(filled($this->typeFilter), fn ($query) => $query->where('type', $this->typeFilter))
            ->when(filled($this->search), function ($query) {
                $term = '%'.trim($this->search).'%';

                $query->where(fn ($query) => $query
                    ->where('patient_name', 'like', $term)
                    ->orWhere('serial_no', 'like', $term)
                    ->orWhere('mrn', 'like', $term)
                    ->orWhere('guardian_name', 'like', $term));
            })
            ->latest('issued_at')
            ->latest('id')
            ->paginate(25);
    }

    /**
     * Patients matching the search inside the form.
     *
     * @return Collection<int, Patient>
     */
    #[Computed]
    public function patientResults(): Collection
    {
        if ($this->patientId !== null || mb_strlen(trim($this->patientSearch)) < 2) {
            return new Collection;
        }

        return app(PatientIntakeService::class)->findPatientsByMrnOrName($this->patientSearch)->take(8);
    }

    /**
     * Active doctors who can sign certificates.
     *
     * @return Collection<int, Doctor>
     */
    #[Computed]
    public function doctors(): Collection
    {
        return Doctor::query()->active()->orderBy('name')->get();
    }

    /**
     * Reset pagination whenever a list filter changes.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'typeFilter'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Keep the pronouns in step with the chosen title.
     */
    public function updatedPatientTitle(string $value): void
    {
        $this->gender = MedicalCertificate::TITLES[$value] ?? $this->gender;
        $this->relation = match ($value) {
            'Mrs.' => 'w/o',
            'Miss', 'Baby' => 'd/o',
            default => 's/o',
        };
    }

    /**
     * Copy the chosen doctor's name into the editable signature name.
     */
    public function updatedDoctorId(?int $value): void
    {
        $doctor = $this->doctors->firstWhere('id', $value);

        if ($doctor !== null) {
            $this->doctorName = $this->signatureName($doctor);
        }
    }

    /**
     * Pick sensible dates when the certificate type changes.
     */
    public function updatedType(string $value): void
    {
        if ($value === MedicalCertificateType::Fitness->value && blank($this->resumeDate)) {
            $this->resumeDate = today()->addDay()->toDateString();
        }

        if ($value === MedicalCertificateType::Attendance->value) {
            $this->endDate = '';
        } elseif (blank($this->endDate)) {
            $this->endDate = today()->addDays(2)->toDateString();
        }
    }

    /**
     * Open an empty form for a new certificate.
     */
    public function create(): void
    {
        $this->resetForm();

        $ownDoctor = auth()->user()?->doctor;

        if ($ownDoctor !== null) {
            $this->doctorId = $ownDoctor->id;
            $this->doctorName = $this->signatureName($ownDoctor);
        }

        $this->showForm = true;
    }

    /**
     * Fill the form from the chosen patient's record; every field stays editable.
     */
    public function selectPatient(int $patientId): void
    {
        $patient = Patient::query()->findOrFail($patientId);
        $details = MedicalCertificate::detailsFromPatient($patient);

        $this->patientId = $patient->id;
        $this->patientSearch = '';
        $this->patientTitle = $details['patient_title'];
        $this->patientName = $details['patient_name'];
        $this->gender = $details['gender'];
        $this->relation = $details['relation'];
        $this->guardianName = (string) $details['guardian_name'];
        $this->age = $details['age'];
        $this->mrn = (string) $details['mrn'];
        $this->cnic = (string) $details['cnic'];
        $this->diagnosis = (string) $details['diagnosis'];

        if ($details['doctor_id'] !== null && auth()->user()?->doctor === null) {
            $this->doctorId = $details['doctor_id'];
            $this->updatedDoctorId($details['doctor_id']);
        }
    }

    /**
     * Unlink the patient record, keeping whatever has been typed.
     */
    public function clearPatient(): void
    {
        $this->patientId = null;
    }

    /**
     * Load an existing certificate into the form for correction.
     */
    public function edit(int $certificateId): void
    {
        $certificate = MedicalCertificate::query()->valid()->findOrFail($certificateId);

        $this->resetForm();
        $this->editingId = $certificate->id;
        $this->patientId = $certificate->patient_id;
        $this->type = $certificate->type->value;
        $this->patientTitle = $certificate->patient_title;
        $this->patientName = $certificate->patient_name;
        $this->gender = $certificate->gender;
        $this->relation = $certificate->relation;
        $this->guardianName = (string) $certificate->guardian_name;
        $this->age = $certificate->age;
        $this->mrn = (string) $certificate->mrn;
        $this->cnic = (string) $certificate->cnic;
        $this->diagnosis = (string) $certificate->diagnosis;
        $this->showDiagnosis = $certificate->show_diagnosis;
        $this->startDate = $certificate->start_date->toDateString();
        $this->endDate = (string) $certificate->end_date?->toDateString();
        $this->resumeDate = (string) $certificate->resume_date?->toDateString();
        $this->timeFrom = (string) $certificate->time_from;
        $this->timeTo = (string) $certificate->time_to;
        $this->gestationWeeks = $certificate->gestation_weeks;
        $this->expectedDeliveryDate = (string) $certificate->expected_delivery_date?->toDateString();
        $this->doctorId = $certificate->doctor_id;
        $this->doctorName = $certificate->doctor_name;
        $this->showForm = true;
    }

    /**
     * Save the certificate and open it for printing.
     */
    public function save(): void
    {
        $type = MedicalCertificateType::from($this->type);
        $needsEndDate = $type !== MedicalCertificateType::Attendance;

        $this->validate([
            'type' => ['required', Rule::enum(MedicalCertificateType::class)],
            'patientId' => ['nullable', 'integer', 'exists:patients,id'],
            'patientTitle' => ['required', Rule::in(array_keys(MedicalCertificate::TITLES))],
            'patientName' => ['required', 'string', 'max:255'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'relation' => ['required', Rule::in(MedicalCertificate::RELATIONS)],
            'guardianName' => ['nullable', 'string', 'max:255'],
            'age' => ['nullable', 'integer', 'min:0', 'max:150'],
            'mrn' => ['nullable', 'string', 'max:50'],
            'cnic' => ['nullable', 'string', 'max:20'],
            'diagnosis' => [Rule::requiredIf($type === MedicalCertificateType::SickLeave && $this->showDiagnosis), 'nullable', 'string', 'max:255'],
            'startDate' => ['required', 'date'],
            'endDate' => [Rule::requiredIf($needsEndDate), 'nullable', 'date', 'after_or_equal:startDate'],
            'resumeDate' => [Rule::requiredIf($type === MedicalCertificateType::Fitness), 'nullable', 'date'],
            'timeFrom' => ['nullable', 'date_format:H:i'],
            'timeTo' => ['nullable', 'date_format:H:i'],
            'gestationWeeks' => ['nullable', 'integer', 'min:1', 'max:45'],
            'expectedDeliveryDate' => ['nullable', 'date'],
            'doctorId' => ['nullable', 'integer', 'exists:doctors,id'],
            'doctorName' => ['required', 'string', 'max:255'],
        ], attributes: [
            'patientName' => __('name'),
            'startDate' => __('from date'),
            'endDate' => __('to date'),
            'resumeDate' => __('fit from date'),
            'doctorName' => __('doctor name'),
        ]);

        $isAttendance = $type === MedicalCertificateType::Attendance;
        $isFitness = $type === MedicalCertificateType::Fitness;
        $isMaternity = $type === MedicalCertificateType::Maternity;

        $attributes = [
            'type' => $type,
            'patient_id' => $this->patientId,
            'doctor_id' => $this->doctorId,
            'patient_title' => $this->patientTitle,
            'patient_name' => Str::squish($this->patientName),
            'gender' => $this->gender,
            'relation' => $this->relation,
            'guardian_name' => filled($this->guardianName) ? Str::squish($this->guardianName) : null,
            'age' => $this->age,
            'mrn' => filled($this->mrn) ? trim($this->mrn) : null,
            'cnic' => filled($this->cnic) ? trim($this->cnic) : null,
            'diagnosis' => filled($this->diagnosis) ? Str::squish($this->diagnosis) : null,
            'show_diagnosis' => $this->showDiagnosis,
            'start_date' => $this->startDate,
            'end_date' => $isAttendance || blank($this->endDate) ? null : $this->endDate,
            'resume_date' => $isFitness && filled($this->resumeDate) ? $this->resumeDate : null,
            'time_from' => $isAttendance && filled($this->timeFrom) ? $this->timeFrom : null,
            'time_to' => $isAttendance && filled($this->timeTo) ? $this->timeTo : null,
            'gestation_weeks' => $isMaternity ? $this->gestationWeeks : null,
            'expected_delivery_date' => $isMaternity && filled($this->expectedDeliveryDate) ? $this->expectedDeliveryDate : null,
            'doctor_name' => Str::squish($this->doctorName),
        ];

        if ($this->editingId !== null) {
            $certificate = MedicalCertificate::query()->valid()->findOrFail($this->editingId);
            $certificate->update($attributes);
        } else {
            $certificate = MedicalCertificate::query()->create([
                ...$attributes,
                'issued_by' => auth()->id(),
                'issued_at' => now(),
            ]);
        }

        $this->showForm = false;
        $this->resetForm();
        unset($this->certificates);

        Flux::toast(variant: 'success', text: __('Certificate :serial saved.', ['serial' => $certificate->serial_no]));

        $this->js('window.open('.json_encode(route('reception.medical-certificates.print', $certificate)).', "_blank")');
    }

    /**
     * Ask for a reason before voiding a certificate.
     */
    public function confirmVoid(int $certificateId): void
    {
        $this->voidingId = $certificateId;
        $this->voidReason = '';
        $this->resetValidation('voidReason');
        $this->showVoidModal = true;
    }

    /**
     * Cancel a certificate so it no longer prints or verifies as valid.
     */
    public function voidCertificate(): void
    {
        $this->validate(['voidReason' => ['required', 'string', 'max:255']], attributes: ['voidReason' => __('reason')]);

        MedicalCertificate::query()->valid()->findOrFail($this->voidingId)->update([
            'voided_at' => now(),
            'voided_by' => auth()->id(),
            'void_reason' => Str::squish($this->voidReason),
        ]);

        $this->showVoidModal = false;
        $this->voidingId = null;
        unset($this->certificates);

        Flux::toast(variant: 'success', text: __('Certificate voided.'));
    }

    /**
     * The doctor's name as printed under the signature line.
     */
    private function signatureName(Doctor $doctor): string
    {
        $name = Str::squish($doctor->name);

        return Str::startsWith(Str::lower($name), 'dr') ? $name : 'Dr. '.$name;
    }

    /**
     * Clear the form back to a fresh sick leave certificate.
     */
    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'patientSearch', 'patientId', 'type', 'patientTitle', 'patientName', 'gender', 'relation',
            'guardianName', 'age', 'mrn', 'cnic', 'diagnosis', 'showDiagnosis', 'resumeDate', 'timeFrom', 'timeTo',
            'gestationWeeks', 'expectedDeliveryDate', 'doctorId', 'doctorName',
        ]);
        $this->resetValidation();

        $this->startDate = today()->toDateString();
        $this->endDate = today()->addDays(2)->toDateString();
    }
}; ?>

<div>
    @php
        $selectedType = MedicalCertificateType::tryFrom($type) ?? MedicalCertificateType::SickLeave;
    @endphp

    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <flux:heading level="1">{{ __('Medical Certificates') }}</flux:heading>
                <flux:text class="mt-1 text-sm">
                    {{ __('Pick the patient, check the details, save and print. Each certificate gets a serial number and a QR code anyone can scan to verify it.') }}
                </flux:text>
            </div>
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New Certificate') }}</flux:button>
        </div>

        <flux:card>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-[minmax(0,1fr)_14rem] sm:items-end">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    :label="__('Search')"
                    icon="magnifying-glass"
                    placeholder="{{ __('Name, father / husband name, MR or certificate no...') }}"
                />
                <flux:select wire:model.live="typeFilter" :label="__('Type')">
                    <flux:select.option value="">{{ __('All types') }}</flux:select.option>
                    @foreach (MedicalCertificateType::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </flux:card>

        <flux:card>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Certificate') }}</flux:table.column>
                    <flux:table.column>{{ __('Patient') }}</flux:table.column>
                    <flux:table.column>{{ __('Period') }}</flux:table.column>
                    <flux:table.column>{{ __('Doctor') }}</flux:table.column>
                    <flux:table.column>{{ __('Issued') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->certificates as $certificate)
                        <flux:table.row wire:key="certificate-{{ $certificate->id }}">
                            <flux:table.cell>
                                <div class="font-mono text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $certificate->serial_no }}</div>
                                <div class="mt-1 flex flex-wrap items-center gap-1">
                                    <flux:badge size="sm" color="zinc">{{ $certificate->type->label() }}</flux:badge>
                                    @if ($certificate->isVoided())
                                        <flux:badge size="sm" color="red">{{ __('Voided') }}</flux:badge>
                                    @endif
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="font-medium uppercase text-zinc-900 dark:text-zinc-100">{{ $certificate->patient_title }} {{ $certificate->patient_name }}</div>
                                <div class="text-xs text-zinc-500">
                                    @if ($certificate->guardian_name)
                                        {{ $certificate->relation }} <span class="uppercase">{{ $certificate->guardian_name }}</span> ·
                                    @endif
                                    {{ $certificate->mrn ?: __('No MR') }}
                                </div>
                                @if ($certificate->isVoided())
                                    <div class="text-xs text-red-600 dark:text-red-400">{{ __('Voided: :reason', ['reason' => $certificate->void_reason]) }}</div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">
                                {{ $certificate->start_date->format('d-m-Y') }}
                                @if ($certificate->end_date)
                                    <div class="text-xs text-zinc-500">{{ __('to :date (:days days)', ['date' => $certificate->end_date->format('d-m-Y'), 'days' => $certificate->totalDays()]) }}</div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $certificate->doctor_name }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">
                                {{ $certificate->issued_at->format('d M Y') }}
                                <div class="text-xs text-zinc-500">
                                    {{ $certificate->issued_at->format('g:i A') }} · {{ $certificate->issuedBy?->name ?? __('unknown') }}
                                </div>
                                <div class="text-xs text-zinc-500">{{ trans_choice('{0} Not printed|{1} Printed once|[2,*] Printed :count times', $certificate->print_count) }}</div>
                            </flux:table.cell>
                            <flux:table.cell class="text-right">
                                @unless ($certificate->isVoided())
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        <flux:button size="sm" icon="printer" :href="route('reception.medical-certificates.print', $certificate)" target="_blank">
                                            {{ __('Print') }}
                                        </flux:button>
                                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $certificate->id }})">{{ __('Edit') }}</flux:button>
                                        <flux:button size="sm" variant="ghost" icon="x-circle" wire:click="confirmVoid({{ $certificate->id }})">{{ __('Void') }}</flux:button>
                                    </div>
                                @endunless
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="py-8 text-center text-zinc-500">
                                {{ __('No certificates yet.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <div class="mt-4">
                {{ $this->certificates->links() }}
            </div>
        </flux:card>
    </div>

    <flux:modal wire:model="showForm" class="w-full max-w-3xl">
        <flux:heading level="2">{{ $editingId ? __('Edit Certificate') : __('New Medical Certificate') }}</flux:heading>
        <flux:text class="mt-1 text-sm">{{ __('Details are filled in from the patient record. Change anything before saving.') }}</flux:text>

        <form wire:submit="save" class="mt-6 space-y-6">
            <flux:radio.group wire:model.live="type" :label="__('Certificate type')" variant="segmented">
                @foreach (MedicalCertificateType::cases() as $case)
                    <flux:radio value="{{ $case->value }}" :label="$case->label()" />
                @endforeach
            </flux:radio.group>

            <div>
                @if ($patientId)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-sm dark:border-teal-800 dark:bg-teal-950">
                        <span>{{ __('Linked to patient record :mrn', ['mrn' => $mrn ?: '#'.$patientId]) }}</span>
                        <flux:button size="xs" variant="ghost" wire:click="clearPatient">{{ __('Unlink') }}</flux:button>
                    </div>
                @else
                    <flux:input
                        wire:model.live.debounce.300ms="patientSearch"
                        :label="__('Find patient')"
                        icon="magnifying-glass"
                        placeholder="{{ __('Name, MR or phone...') }}"
                    />
                    @if ($this->patientResults->isNotEmpty())
                        <div class="mt-2 divide-y divide-zinc-200 overflow-hidden rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                            @foreach ($this->patientResults as $result)
                                <button
                                    type="button"
                                    wire:key="patient-result-{{ $result->id }}"
                                    wire:click="selectPatient({{ $result->id }})"
                                    class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800"
                                >
                                    <span>
                                        <span class="font-medium uppercase">{{ $result->name }}</span>
                                        @if ($result->husband_name)
                                            <span class="text-zinc-500 uppercase">· {{ $result->husband_name }}</span>
                                        @endif
                                    </span>
                                    <span class="text-xs text-zinc-500">{{ $result->mrn }} · {{ $result->contactPhone() ?? __('No phone') }}</span>
                                </button>
                            @endforeach
                        </div>
                    @elseif (mb_strlen(trim($patientSearch)) >= 2)
                        <flux:text class="mt-2 text-sm">{{ __('No patient found. You can still type the details below.') }}</flux:text>
                    @endif
                @endif
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-[8rem_minmax(0,1fr)]">
                <flux:select wire:model.live="patientTitle" :label="__('Title')">
                    @foreach (array_keys(MedicalCertificate::TITLES) as $title)
                        <flux:select.option value="{{ $title }}">{{ $title }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="patientName" :label="__('Name')" class="uppercase" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-[8rem_minmax(0,1fr)_7rem]">
                <flux:select wire:model="relation" :label="__('Relation')">
                    @foreach (MedicalCertificate::RELATIONS as $option)
                        <flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="guardianName" :label="__('Father / husband name')" class="uppercase" />
                <flux:input type="number" wire:model="age" :label="__('Age (years)')" min="0" max="150" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <flux:select wire:model="gender" :label="__('Write as')">
                    <flux:select.option value="male">{{ __('He / him') }}</flux:select.option>
                    <flux:select.option value="female">{{ __('She / her') }}</flux:select.option>
                </flux:select>
                <flux:input wire:model="mrn" :label="__('MR No.')" />
                <flux:input wire:model="cnic" :label="__('CNIC')" />
            </div>

            <div class="space-y-2">
                <flux:input wire:model="diagnosis" :label="__('Disease / diagnosis')" placeholder="{{ __('e.g. Enteric Fever') }}" />
                <flux:checkbox wire:model="showDiagnosis" :label="__('Print the disease on the certificate')" />
            </div>

            @if ($selectedType === MedicalCertificateType::Maternity)
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:input type="number" wire:model="gestationWeeks" :label="__('Weeks pregnant')" min="1" max="45" />
                    <flux:input type="date" wire:model="expectedDeliveryDate" :label="__('Expected date of delivery')" />
                </div>
            @endif

            @if ($selectedType === MedicalCertificateType::Attendance)
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <flux:input type="date" wire:model="startDate" :label="__('Visit date')" />
                    <flux:input type="time" wire:model="timeFrom" :label="__('From time')" />
                    <flux:input type="time" wire:model="timeTo" :label="__('To time')" />
                </div>
            @else
                <div @class(['grid grid-cols-1 gap-4', 'sm:grid-cols-3' => $selectedType === MedicalCertificateType::Fitness, 'sm:grid-cols-2' => $selectedType !== MedicalCertificateType::Fitness])>
                    <flux:input type="date" wire:model="startDate" :label="$selectedType === MedicalCertificateType::Fitness ? __('Treated from') : __('From')" />
                    <flux:input type="date" wire:model="endDate" :label="$selectedType === MedicalCertificateType::Fitness ? __('Treated to') : __('To')" />
                    @if ($selectedType === MedicalCertificateType::Fitness)
                        <flux:input type="date" wire:model="resumeDate" :label="__('Fit to resume from')" />
                    @endif
                </div>
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="doctorId" :label="__('Doctor')" :placeholder="__('Choose doctor...')">
                    @foreach ($this->doctors as $doctor)
                        <flux:select.option value="{{ $doctor->id }}">{{ $doctor->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="doctorName" :label="__('Name under signature')" />
            </div>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showForm', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" icon="printer" wire:loading.attr="disabled" wire:target="save">
                    {{ $editingId ? __('Save & Print') : __('Issue & Print') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showVoidModal" class="w-full max-w-md">
        <flux:heading level="2">{{ __('Void certificate') }}</flux:heading>
        <flux:text class="mt-1 text-sm">{{ __('A voided certificate can no longer be printed, and its QR code will show it as cancelled.') }}</flux:text>

        <form wire:submit="voidCertificate" class="mt-6 space-y-4">
            <flux:input wire:model="voidReason" :label="__('Reason')" placeholder="{{ __('e.g. Wrong dates, reissued') }}" />

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showVoidModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="danger">{{ __('Void') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
