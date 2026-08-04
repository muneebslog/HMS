<?php

namespace App\Actions;

use App\Models\Doctor;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\User;

class ResolveDripShareDoctor
{
    /**
     * Resolve which doctor receives share for a drip charge.
     *
     * Prefer the logged-in doctor when they have a configured share for the
     * drip service; otherwise fall back to the doctor named "mo".
     *
     * @return array{doctor: Doctor|null, doctor_share: float|null}
     */
    public function resolve(Service $service, ?User $user): array
    {
        $loggedInDoctor = $user?->doctor;

        if ($loggedInDoctor instanceof Doctor) {
            $price = ServicePrice::query()
                ->where('service_id', $service->id)
                ->where('doctor_id', $loggedInDoctor->id)
                ->whereNotNull('doctor_share')
                ->first();

            if ($price !== null) {
                return [
                    'doctor' => $loggedInDoctor,
                    'doctor_share' => $price->doctor_share,
                ];
            }
        }

        $defaultDoctor = Doctor::query()
            ->active()
            ->whereRaw('LOWER(TRIM(name)) = ?', ['mo'])
            ->first();

        if ($defaultDoctor === null) {
            return [
                'doctor' => null,
                'doctor_share' => null,
            ];
        }

        $defaultPrice = ServicePrice::query()
            ->where('service_id', $service->id)
            ->where('doctor_id', $defaultDoctor->id)
            ->first();

        return [
            'doctor' => $defaultDoctor,
            'doctor_share' => $defaultPrice?->doctor_share,
        ];
    }
}
