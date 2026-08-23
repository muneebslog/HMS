<?php

namespace App\Services;

use App\Models\Family;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PatientIntakeService
{
    /**
     * Find a family by exact phone number, with patients eager-loaded.
     */
    public function findFamilyByPhone(string $phone): ?Family
    {
        return Family::query()
            ->where('phone', $phone)
            ->with(['patients' => fn ($query) => $query->orderBy('name')])
            ->first();
    }

    /**
     * Search patients by MRN, name, or family phone.
     *
     * @return Collection<int, Patient>
     */
    public function findPatientsByMrnOrName(string $query): Collection
    {
        $term = trim($query);

        if ($term === '') {
            return new Collection;
        }

        return Patient::query()
            ->with('family')
            ->where(function ($builder) use ($term) {
                $builder->where('mrn', 'like', '%'.$term.'%')
                    ->orWhere('name', 'like', '%'.$term.'%')
                    ->orWhereHas('family', function ($familyQuery) use ($term) {
                        $familyQuery->where('phone', 'like', '%'.$term.'%');
                    });
            })
            ->orderBy('name')
            ->limit(25)
            ->get();
    }

    /**
     * Paginate patients with recent reception activity.
     *
     * @return LengthAwarePaginator<int, Patient>
     */
    public function paginateRecentReceptionPatients(int $perPage = 15): LengthAwarePaginator
    {
        $lastActivitySubquery = <<<'SQL'
            (SELECT MAX(activity_at) FROM (
                SELECT COALESCE(arrived_at, created_at) AS activity_at FROM queue_tokens WHERE patient_id = patients.id
                UNION ALL
                SELECT created_at AS activity_at FROM invoices WHERE patient_id = patients.id
                UNION ALL
                SELECT created_at AS activity_at FROM lab_invoices WHERE patient_id = patients.id
                UNION ALL
                SELECT created_at AS activity_at FROM procedures WHERE patient_id = patients.id
            ) AS activities)
        SQL;

        return Patient::query()
            ->with('family')
            ->select('patients.*')
            ->selectRaw("{$lastActivitySubquery} as last_reception_at")
            ->where(function ($query): void {
                $query->whereHas('queueTokens')
                    ->orWhereHas('invoices')
                    ->orWhereHas('labInvoices')
                    ->orWhereHas('procedures');
            })
            ->orderByDesc('last_reception_at')
            ->paginate($perPage);
    }

    /**
     * Resolve an existing family by phone or create one.
     */
    public function resolveOrCreateFamily(?string $phone): ?Family
    {
        if (blank($phone)) {
            return null;
        }

        return Family::query()->firstOrCreate(['phone' => $phone]);
    }

    /**
     * Create a patient optionally attached to a family.
     *
     * @param  array{name: string, husband_name?: ?string, cnic?: ?string, age?: ?int, gender?: ?string}  $attributes
     */
    public function createPatient(array $attributes, ?Family $family = null): Patient
    {
        return Patient::create([
            ...$attributes,
            'family_id' => $family?->id,
        ]);
    }

    /**
     * Resolve an existing selected patient or create a new one under the phone family.
     *
     * @param  array{name: string, husband_name?: ?string, cnic?: ?string, age?: ?int, gender?: ?string}  $attributes
     */
    public function resolvePatient(?int $selectedPatientId, ?string $phone, array $attributes): Patient
    {
        if ($selectedPatientId !== null) {
            $patient = Patient::query()->with('family')->findOrFail($selectedPatientId);

            $updates = collect($attributes)
                ->only(['name', 'husband_name', 'cnic', 'age', 'gender'])
                ->filter(fn ($value) => $value !== null && $value !== '')
                ->all();

            if ($updates !== []) {
                $patient->update($updates);
            }

            if (filled($phone) && $patient->contactPhone() !== $phone) {
                $this->updateContactPhone($patient, $phone);
            }

            return $patient->fresh(['family']);
        }

        $family = $this->resolveOrCreateFamily($phone);

        return $this->createPatient($attributes, $family)->fresh(['family']);
    }

    /**
     * Update a patient's name and age.
     */
    public function updatePatientDemographics(Patient $patient, string $name, ?int $age): Patient
    {
        return DB::transaction(function () use ($patient, $name, $age) {
            $lockedPatient = Patient::query()
                ->whereKey($patient->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPatient->update([
                'name' => $name,
                'age' => $age,
            ]);

            return $lockedPatient->fresh(['family']);
        });
    }

    /**
     * Update or clear the patient's family contact phone.
     */
    public function updateContactPhone(Patient $patient, ?string $phone): Patient
    {
        return DB::transaction(function () use ($patient, $phone) {
            $lockedPatient = Patient::query()
                ->with('family')
                ->whereKey($patient->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (blank($phone)) {
                $lockedPatient->update(['family_id' => null]);

                return $lockedPatient->fresh(['family']);
            }

            if ($lockedPatient->family !== null) {
                $conflict = Family::query()
                    ->where('phone', $phone)
                    ->whereKeyNot($lockedPatient->family_id)
                    ->exists();

                if ($conflict) {
                    throw ValidationException::withMessages([
                        'phone' => __('This phone number already belongs to another family.'),
                    ]);
                }

                $lockedPatient->family->update(['phone' => $phone]);

                return $lockedPatient->fresh(['family']);
            }

            $family = $this->resolveOrCreateFamily($phone);
            $lockedPatient->update(['family_id' => $family?->id]);

            return $lockedPatient->fresh(['family']);
        });
    }

    /**
     * Notify admins when intake skips collecting a phone number.
     *
     * @param  'walk_in'|'reservation'|'lab'|'procedure'  $context
     * @param  array<string, mixed>  $metadata
     */
    public function notifyWithoutPhone(
        User $user,
        Patient $patient,
        string $context,
        ?QueueToken $token = null,
        array $metadata = []
    ): void {
        app(NotificationService::class)->notifyPatientWithoutPhone(
            $user,
            $patient,
            $context,
            $token,
            $metadata
        );
    }
}
