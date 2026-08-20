<?php

namespace App\Actions;

use App\Enums\DripChargeStatus;
use App\Enums\DripLineStatus;
use App\Models\DripCharge;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CancelDripCharge
{
    /**
     * Cancel a pending drip charge and any active drip lines on its order.
     */
    public function handle(DripCharge $charge): void
    {
        if ($charge->status !== DripChargeStatus::Pending) {
            throw new InvalidArgumentException('Only pending drip charges can be cancelled.');
        }

        DB::transaction(function () use ($charge): void {
            $charge->update([
                'status' => DripChargeStatus::Cancelled,
            ]);

            if ($charge->medication_order_id === null) {
                return;
            }

            $charge->medicationOrder?->drips()
                ->whereIn('status', DripLineStatus::activeCases())
                ->update([
                    'status' => DripLineStatus::Cancelled,
                    'started_at' => null,
                    'started_by_health_aide_id' => null,
                    'check_due_at' => null,
                    'check_notified_at' => null,
                ]);
        });
    }
}
