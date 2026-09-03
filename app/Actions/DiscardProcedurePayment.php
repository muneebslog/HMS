<?php

namespace App\Actions;

use App\Models\ProcedurePayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DiscardProcedurePayment
{
    /**
     * Discard a procedure payment so it no longer counts as collected.
     */
    public function handle(User $admin, ProcedurePayment $payment): ProcedurePayment
    {
        if (! $admin->isAdmin()) {
            abort(403);
        }

        return DB::transaction(function () use ($admin, $payment): ProcedurePayment {
            $locked = ProcedurePayment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isDiscarded()) {
                throw new InvalidArgumentException(__('This payment has already been discarded.'));
            }

            $locked->update([
                'discarded_at' => now(),
                'discarded_by' => $admin->id,
            ]);

            return $locked->refresh();
        });
    }
}
