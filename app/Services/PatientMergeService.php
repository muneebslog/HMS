<?php

namespace App\Services;

use App\Models\Family;
use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\QueueToken;
use App\Models\UltrasoundReport;
use App\Models\Vital;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PatientMergeService
{
    /**
     * Families with a phone number that have two or more patients.
     *
     * @return Collection<int, Family>
     */
    public function duplicatePhoneGroups(?string $phoneSearch = null): Collection
    {
        $query = Family::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->has('patients', '>=', 2)
            ->withCount('patients')
            ->with(['patients' => function ($builder): void {
                $builder->orderBy('id')
                    ->withCount([
                        'queueTokens',
                        'invoices',
                        'labInvoices',
                        'procedures',
                        'vitals',
                        'ultrasoundReports',
                    ]);
            }])
            ->orderBy('phone');

        if (filled($phoneSearch)) {
            $digits = preg_replace('/\D/', '', $phoneSearch) ?? '';

            if ($digits !== '') {
                $query->where('phone', 'like', '%'.$digits.'%');
            }
        }

        return $query->get();
    }

    /**
     * Detach a patient from their family phone so they no longer appear under that number.
     */
    public function unlinkFromPhone(Patient $patient): Patient
    {
        return DB::transaction(function () use ($patient): Patient {
            $lockedPatient = Patient::query()
                ->with('family')
                ->whereKey($patient->id)
                ->lockForUpdate()
                ->firstOrFail();

            $familyId = $lockedPatient->family_id;

            if ($familyId === null) {
                return $lockedPatient;
            }

            $lockedPatient->update(['family_id' => null]);

            $familyStillHasPatients = Patient::query()
                ->where('family_id', $familyId)
                ->exists();

            if (! $familyStillHasPatients) {
                Family::query()->whereKey($familyId)->delete();
            }

            return $lockedPatient->fresh(['family']) ?? $lockedPatient;
        });
    }

    /**
     * Merge selected patients into the oldest (lowest id). Losers' related rows are reassigned.
     *
     * @param  list<int>  $patientIds
     */
    public function merge(array $patientIds): Patient
    {
        $patientIds = collect($patientIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (count($patientIds) < 2) {
            throw ValidationException::withMessages([
                'patients' => __('Select at least two patients to merge.'),
            ]);
        }

        return DB::transaction(function () use ($patientIds): Patient {
            $patients = Patient::query()
                ->with('family')
                ->whereKey($patientIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($patients->count() !== count($patientIds)) {
                throw ValidationException::withMessages([
                    'patients' => __('One or more selected patients could not be found.'),
                ]);
            }

            $familyIds = $patients->pluck('family_id')->unique()->values();

            if ($familyIds->count() !== 1 || $familyIds->first() === null) {
                throw ValidationException::withMessages([
                    'patients' => __('Patients must belong to the same family with a phone number.'),
                ]);
            }

            $phone = $patients->first()?->family?->phone;

            if (blank($phone)) {
                throw ValidationException::withMessages([
                    'patients' => __('Patients must share a family phone number.'),
                ]);
            }

            /** @var Patient $winner */
            $winner = $patients->first();
            $losers = $patients->slice(1)->values();

            $this->fillBlankWinnerAttributes($winner, $losers);

            $loserIds = $losers->pluck('id')->all();

            $this->reassignRelatedRecords($winner->id, $loserIds);

            Patient::query()->whereKey($loserIds)->delete();

            return $winner->fresh(['family']) ?? $winner;
        });
    }

    /**
     * Copy blank profile fields on the winner from the newest losers that have values.
     *
     * @param  Collection<int, Patient>  $losers
     */
    private function fillBlankWinnerAttributes(Patient $winner, Collection $losers): void
    {
        $fields = ['husband_name', 'cnic', 'age', 'gender'];

        foreach ($losers->sortByDesc('id') as $loser) {
            foreach ($fields as $field) {
                if (blank($winner->{$field}) && filled($loser->{$field})) {
                    $winner->{$field} = $loser->{$field};
                }
            }
        }

        if ($winner->isDirty()) {
            $winner->save();
        }
    }

    /**
     * Point related records at the surviving patient.
     *
     * @param  list<int>  $loserIds
     */
    private function reassignRelatedRecords(int $winnerId, array $loserIds): void
    {
        foreach ([
            Invoice::class,
            LabInvoice::class,
            QueueToken::class,
            Vital::class,
            Procedure::class,
            UltrasoundReport::class,
        ] as $modelClass) {
            $modelClass::query()
                ->whereIn('patient_id', $loserIds)
                ->update(['patient_id' => $winnerId]);
        }
    }
}
