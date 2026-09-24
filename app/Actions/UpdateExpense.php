<?php

namespace App\Actions;

use App\Enums\ApprovalStatus;
use App\Models\Expense;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateExpense
{
    /**
     * Change an expense logged in the open shift. Any change sends it back for approval,
     * keeping the values it had before so the approver can see what changed.
     */
    public function handle(User $editor, Expense $expense, string $name, float $amount): Expense
    {
        return DB::transaction(function () use ($editor, $expense, $name, $amount): Expense {
            $locked = Expense::query()
                ->whereKey($expense->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->shift_id !== Shift::current()?->id) {
                throw new InvalidArgumentException(__('Only expenses in the open shift can be edited.'));
            }

            $name = trim($name);

            if ($locked->name === $name && (float) $locked->amount === round($amount, 2)) {
                return $locked;
            }

            $keepPrevious = $locked->previous_name !== null && $locked->isPendingApproval();

            $locked->update([
                'previous_name' => $keepPrevious ? $locked->previous_name : $locked->name,
                'previous_amount' => $keepPrevious ? $locked->previous_amount : $locked->amount,
                'name' => $name,
                'amount' => round($amount, 2),
                'approval_status' => ApprovalStatus::Pending,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_note' => null,
                'edited_at' => now(),
                'edited_by' => $editor->id,
            ]);

            return $locked->refresh();
        });
    }
}
