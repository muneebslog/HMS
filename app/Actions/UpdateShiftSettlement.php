<?php

namespace App\Actions;

use App\Models\ShiftSettlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateShiftSettlement
{
    /**
     * Correct the received amount or notes of a settled shift (admins only).
     */
    public function handle(User $admin, ShiftSettlement $settlement, float $receivedAmount, ?string $notes = null): ShiftSettlement
    {
        if (! $admin->isAdmin()) {
            abort(403);
        }

        if ($receivedAmount < 0) {
            throw new InvalidArgumentException(__('Received amount cannot be negative.'));
        }

        return DB::transaction(function () use ($admin, $settlement, $receivedAmount, $notes): ShiftSettlement {
            $locked = ShiftSettlement::query()
                ->whereKey($settlement->id)
                ->lockForUpdate()
                ->firstOrFail();

            $receivedAmount = round($receivedAmount, 2);
            $amountChanged = $receivedAmount !== round($locked->received_amount, 2);

            $locked->update([
                'received_amount' => $receivedAmount,
                'difference' => round($receivedAmount - $locked->expected_amount, 2),
                'notes' => filled($notes) ? $notes : null,
                'previous_received_amount' => $amountChanged ? $locked->received_amount : $locked->previous_received_amount,
                'edited_by' => $admin->id,
                'edited_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
