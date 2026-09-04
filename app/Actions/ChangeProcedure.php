<?php

namespace App\Actions;

use App\Models\Procedure;
use App\Models\ProcedureChange;
use App\Models\ProcedureType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ChangeProcedure
{
    /**
     * Change a procedure's type and package amount, recording an audit row.
     *
     * @param  array{procedure_type_id: int, package_price: float|int|string, discount_amount?: float|int|string|null}  $data
     */
    public function handle(User $user, Procedure $procedure, array $data): ProcedureChange
    {
        $procedureType = ProcedureType::query()
            ->active()
            ->findOrFail($data['procedure_type_id']);

        $packagePrice = round((float) $data['package_price'], 2);
        $discountAmount = round((float) ($data['discount_amount'] ?? 0), 2);

        if ($packagePrice < 0) {
            throw new InvalidArgumentException(__('Package price must be zero or greater.'));
        }

        if ($discountAmount < 0) {
            throw new InvalidArgumentException(__('Discount must be zero or greater.'));
        }

        if ($discountAmount >= $packagePrice && $packagePrice > 0) {
            throw new InvalidArgumentException(__('Discount must be less than the package price.'));
        }

        if ($discountAmount > 0 && $packagePrice === 0.0) {
            throw new InvalidArgumentException(__('Discount must be less than the package price.'));
        }

        $toAmount = round($packagePrice - $discountAmount, 2);

        return DB::transaction(function () use ($user, $procedure, $procedureType, $packagePrice, $discountAmount, $toAmount): ProcedureChange {
            $locked = Procedure::query()
                ->whereKey($procedure->id)
                ->lockForUpdate()
                ->firstOrFail();

            $totalPaid = $locked->totalPaid();

            if ($toAmount < $totalPaid) {
                throw new InvalidArgumentException(__('Final package cannot be less than the total paid.'));
            }

            $change = ProcedureChange::create([
                'procedure_id' => $locked->id,
                'from_procedure_type_id' => $locked->procedure_type_id,
                'to_procedure_type_id' => $procedureType->id,
                'from_name' => $locked->name,
                'to_name' => $procedureType->name,
                'from_amount' => $locked->full_amount,
                'to_amount' => $toAmount,
                'package_price' => $packagePrice,
                'discount_amount' => $discountAmount,
                'changed_by' => $user->id,
            ]);

            $locked->update([
                'procedure_type_id' => $procedureType->id,
                'name' => $procedureType->name,
                'full_amount' => $toAmount,
            ]);

            return $change;
        });
    }
}
