<?php

namespace App\Actions;

use App\Enums\MedicationOrderStatus;
use App\Models\MedicationOrder;
use App\Models\QueueToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RecallMedicationOrder
{
    /**
     * Recall the latest order for editing or start a replacement after delivery.
     */
    public function handle(QueueToken $token, User $actor): MedicationOrder
    {
        return DB::transaction(function () use ($token, $actor): MedicationOrder {
            $lockedToken = QueueToken::query()
                ->with('serviceQueue.service')
                ->lockForUpdate()
                ->findOrFail($token->id);

            if ($lockedToken->patient_id === null
                || ! in_array($lockedToken->status, ['waiting', 'serving', 'served'], true)
                || ! $lockedToken->serviceQueue?->service?->needs_medication) {
                throw new InvalidArgumentException('This patient is not available for medication recall.');
            }

            $latestOrder = $lockedToken->medicationOrders()
                ->lockForUpdate()
                ->first();

            if ($latestOrder?->status === MedicationOrderStatus::Draft) {
                return $latestOrder;
            }

            if ($latestOrder?->status === MedicationOrderStatus::Pending) {
                $latestOrder->update(['status' => MedicationOrderStatus::Draft]);

                return $latestOrder;
            }

            return MedicationOrder::create([
                'queue_token_id' => $lockedToken->id,
                'patient_id' => $lockedToken->patient_id,
                'doctor_id' => $lockedToken->serviceQueue?->doctor_id,
                'prescribed_by' => $actor->id,
                'status' => MedicationOrderStatus::Draft,
            ]);
        });
    }
}
