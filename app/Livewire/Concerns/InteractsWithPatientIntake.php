<?php

namespace App\Livewire\Concerns;

use App\Models\Patient;
use App\Services\PatientIntakeService;

trait InteractsWithPatientIntake
{
    public string $patientPhone = '';

    public bool $hasNoPhone = false;

    public ?int $selectedPatientId = null;

    /**
     * @var list<array{id: int, name: string, mrn: ?string, age: ?int, gender: ?string, phone: ?string}>
     */
    public array $matchedPatients = [];

    /**
     * Whether the patient name input should be shown for creating a new person.
     */
    public function shouldShowPatientNameField(): bool
    {
        return $this->hasNoPhone
            || (strlen($this->patientPhone) === 11 && $this->selectedPatientId === null);
    }

    /**
     * Clear the phone and matches when skipping contact number.
     */
    public function updatedHasNoPhone(bool $value): void
    {
        if ($value) {
            $this->patientPhone = '';
            $this->matchedPatients = [];
            $this->selectedPatientId = null;
        }

        $this->resetValidation(['patientPhone']);
    }

    /**
     * Look up family members when the phone is complete.
     */
    public function updatedPatientPhone(string $value): void
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        $this->patientPhone = $digits;

        if ($this->hasNoPhone) {
            return;
        }

        $this->selectedPatientId = null;

        if (strlen($digits) !== 11) {
            $this->matchedPatients = [];

            return;
        }

        $this->loadMatchesFromPhone($digits);
    }

    /**
     * Select an existing patient from search results.
     */
    public function selectMatchedPatient(int $patientId): void
    {
        $patient = Patient::query()->with('family')->findOrFail($patientId);

        $this->selectedPatientId = $patient->id;
        $this->patientName = $patient->name;

        if (property_exists($this, 'husbandName')) {
            $this->husbandName = $patient->husband_name ?? '';
        }

        if (property_exists($this, 'patientAge')) {
            $this->patientAge = $patient->age;
        }

        if (property_exists($this, 'patientGender') && filled($patient->gender)) {
            $this->patientGender = $patient->gender;
        }

        $phone = $patient->contactPhone();

        if (filled($phone)) {
            $this->hasNoPhone = false;
            $this->patientPhone = $phone;
            $this->loadMatchesFromPhone($phone);
        } else {
            $this->hasNoPhone = true;
            $this->patientPhone = '';
            $this->matchedPatients = [$this->patientMatchPayload($patient)];
        }
    }

    /**
     * Clear the selected patient so a new family member can be created.
     */
    public function clearSelectedPatient(): void
    {
        $this->selectedPatientId = null;
    }

    /**
     * Start adding a new family member under the current phone.
     */
    public function addNewFamilyMember(): void
    {
        $this->selectedPatientId = null;
        $this->patientName = '';

        if (property_exists($this, 'husbandName')) {
            $this->husbandName = '';
        }

        if (property_exists($this, 'patientAge')) {
            $this->patientAge = null;
        }

        if (property_exists($this, 'patientGender')) {
            $this->patientGender = '';
        }
    }

    /**
     * Validation rules for phone / skip-phone intake fields.
     *
     * @return array<string, list<string>>
     */
    protected function patientIntakePhoneRules(): array
    {
        return [
            'patientPhone' => [$this->hasNoPhone ? 'nullable' : 'required', 'digits:11'],
            'selectedPatientId' => ['nullable', 'integer', 'exists:patients,id'],
            'hasNoPhone' => ['boolean'],
        ];
    }

    /**
     * Resolve the intake patient from selection or create a new one.
     *
     * @param  array{name: string, husband_name?: ?string, cnic?: ?string, age?: ?int, gender?: ?string}  $attributes
     */
    protected function resolveIntakePatient(array $attributes): Patient
    {
        return app(PatientIntakeService::class)->resolvePatient(
            $this->selectedPatientId,
            $this->hasNoPhone ? null : $this->patientPhone,
            $attributes,
        );
    }

    /**
     * Reset shared intake fields.
     *
     * @return list<string>
     */
    protected function patientIntakeResetFields(): array
    {
        return [
            'patientPhone',
            'hasNoPhone',
            'selectedPatientId',
            'matchedPatients',
        ];
    }

    /**
     * Load family members for a phone number into matchedPatients.
     */
    private function loadMatchesFromPhone(string $phone): void
    {
        $family = app(PatientIntakeService::class)->findFamilyByPhone($phone);

        $this->matchedPatients = $family === null
            ? []
            : $family->patients
                ->map(fn (Patient $patient): array => $this->patientMatchPayload($patient))
                ->values()
                ->all();
    }

    /**
     * @return array{id: int, name: string, mrn: ?string, age: ?int, gender: ?string, phone: ?string}
     */
    private function patientMatchPayload(Patient $patient): array
    {
        return [
            'id' => $patient->id,
            'name' => $patient->name,
            'mrn' => $patient->mrn,
            'age' => $patient->age,
            'gender' => $patient->gender,
            'phone' => $patient->contactPhone(),
        ];
    }
}
