<?php

namespace App\Actions;

use App\Enums\ApprovalStatus;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RejectExpense
{
    /**
     * Reject a pending expense so it no longer counts toward shift cash.
     */
    public function handle(User $reviewer, Expense $expense, ?string $note = null): Expense
    {
        $this->authorize($reviewer);

        return DB::transaction(function () use ($reviewer, $expense, $note): Expense {
            $locked = Expense::query()
                ->whereKey($expense->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isPendingApproval()) {
                throw new InvalidArgumentException(__('This expense is not pending approval.'));
            }

            $locked->update([
                'approval_status' => ApprovalStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Ensure the reviewer may reject expenses.
     */
    private function authorize(User $reviewer): void
    {
        if (! $reviewer->isAdmin() && ! $reviewer->isManagement()) {
            abort(403);
        }
    }
}
