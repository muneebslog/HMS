<?php

namespace App\Actions;

use App\Enums\DripLineStatus;
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
    public function handle(QueueToken $token, User $actor, string $fulfilledStrategy = 'clear'): MedicationOrder
    {
        if (! in_array($fulfilledStrategy, ['clear', 'duplicate', 'reopen'], true)) {
            throw new InvalidArgumentException('Invalid fulfilled-order recall strategy.');
        }

        return DB::transaction(function () use ($token, $actor, $fulfilledStrategy): MedicationOrder {
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

            if ($latestOrder?->status === MedicationOrderStatus::Administered) {
                return match ($fulfilledStrategy) {
                    'duplicate' => $this->duplicateAsDraft($latestOrder, $actor),
                    'reopen' => $this->reopenAdministeredOrder($latestOrder, $actor),
                    default => $this->createBlankDraft($lockedToken, $actor),
                };
            }

            return $this->createBlankDraft($lockedToken, $actor);
        });
    }

    private function createBlankDraft(QueueToken $token, User $actor): MedicationOrder
    {
        return MedicationOrder::create([
            'queue_token_id' => $token->id,
            'patient_id' => $token->patient_id,
            'doctor_id' => $token->serviceQueue?->doctor_id,
            'prescribed_by' => $actor->id,
            'status' => MedicationOrderStatus::Draft,
        ]);
    }

    private function duplicateAsDraft(MedicationOrder $source, User $actor): MedicationOrder
    {
        $source->loadMissing(['symptoms', 'medicines', 'injections', 'drips.additives']);

        $duplicate = MedicationOrder::create([
            'queue_token_id' => $source->queue_token_id,
            'patient_id' => $source->patient_id,
            'doctor_id' => $source->doctor_id,
            'prescribed_by' => $actor->id,
            'status' => MedicationOrderStatus::Draft,
            'complaint_or_diagnosis' => $source->complaint_or_diagnosis,
            'notes' => $source->notes,
        ]);

        $duplicate->symptoms()->sync($source->symptoms->modelKeys());

        foreach ($source->medicines as $medicine) {
            $copy = $medicine->replicate(['medication_order_id', 'delivered_at', 'delivered_by_health_aide_id']);
            $duplicate->medicines()->save($copy);
        }

        foreach ($source->injections as $injection) {
            $copy = $injection->replicate(['medication_order_id', 'delivered_at', 'delivered_by_health_aide_id']);
            $duplicate->injections()->save($copy);
        }

        foreach ($source->drips as $drip) {
            $dripCopy = $drip->replicate([
                'medication_order_id',
                'status',
                'started_at',
                'started_by_health_aide_id',
                'check_due_at',
                'check_notified_at',
                'done_at',
                'done_by_health_aide_id',
                'done_by_user_id',
            ]);
            $dripCopy->status = DripLineStatus::Pending;
            $duplicate->drips()->save($dripCopy);

            foreach ($drip->additives as $additive) {
                $dripCopy->additives()->save($additive->replicate(['medication_order_drip_id']));
            }
        }

        return $duplicate;
    }

    private function reopenAdministeredOrder(MedicationOrder $order, User $actor): MedicationOrder
    {
        $order->update([
            'prescribed_by' => $actor->id,
            'status' => MedicationOrderStatus::Draft,
            'administered_by' => null,
            'administered_by_health_aide_id' => null,
            'administered_at' => null,
        ]);

        $order->medicines()->update([
            'delivered_at' => null,
            'delivered_by_health_aide_id' => null,
        ]);
        $order->injections()->update([
            'delivered_at' => null,
            'delivered_by_health_aide_id' => null,
        ]);
        $order->drips()->update([
            'status' => DripLineStatus::Pending,
            'started_at' => null,
            'started_by_health_aide_id' => null,
            'check_due_at' => null,
            'check_notified_at' => null,
            'done_at' => null,
            'done_by_health_aide_id' => null,
            'done_by_user_id' => null,
        ]);

        return $order->refresh();
    }
}
