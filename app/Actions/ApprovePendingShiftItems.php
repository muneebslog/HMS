<?php

namespace App\Actions;

use App\Enums\ApprovalStatus;
use App\Models\Expense;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ApprovePendingShiftItems
{
    public function __construct(
        public ApproveExpense $approveExpense,
        public ApproveReturn $approveReturn,
    ) {}

    /**
     * Approve every pending expense and return on a shift. Returns how many items were approved.
     */
    public function handle(User $reviewer, Shift $shift): int
    {
        if ($shift->isBeforeFinanceTracking()) {
            throw new InvalidArgumentException(__('This shift is from before finance tracking started.'));
        }

        if ($shift->settlement()->exists()) {
            throw new InvalidArgumentException(__('This shift has already been settled.'));
        }

        return DB::transaction(function () use ($reviewer, $shift): int {
            $documents = $shift->expenses()->where('approval_status', ApprovalStatus::Pending)->get()
                ->concat($shift->invoices()->where('status', 'returned')->where('return_approval_status', ApprovalStatus::Pending)->get())
                ->concat($shift->labInvoices()->where('status', 'returned')->where('return_approval_status', ApprovalStatus::Pending)->get())
                ->concat($shift->procedurePayments()->whereNotNull('returned_at')->where('return_approval_status', ApprovalStatus::Pending)->get());

            foreach ($documents as $document) {
                if ($document instanceof Expense) {
                    $this->approveExpense->handle($reviewer, $document);
                } else {
                    $this->approveReturn->handle($reviewer, $document);
                }
            }

            return $documents->count();
        });
    }
}
