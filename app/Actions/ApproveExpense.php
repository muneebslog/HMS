<?php

namespace App\Actions;

use App\Enums\ApprovalStatus;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ApproveExpense
{
    /**
     * Approve a pending shift expense.
     */
    public function handle(User $reviewer, Expense $expense): Expense
    {
        $this->authorize($reviewer);

        return DB::transaction(function () use ($reviewer, $expense): Expense {
            $locked = Expense::query()
                ->whereKey($expense->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isPendingApproval()) {
                throw new InvalidArgumentException(__('This expense is not pending approval.'));
            }

            $locked->update([
                'approval_status' => ApprovalStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => null,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Ensure the reviewer may approve expenses.
     */
    private function authorize(User $reviewer): void
    {
        if (! $reviewer->isAdmin() && ! $reviewer->isManagement()) {
            abort(403);
        }
    }
}
