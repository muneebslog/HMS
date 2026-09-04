<?php

namespace App\Actions;

use App\Enums\ApprovalStatus;
use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\ProcedurePayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RejectReturn
{
    /**
     * Reject a pending return and restore the sale to shift cash.
     */
    public function handle(User $reviewer, Invoice|LabInvoice|ProcedurePayment $document, ?string $note = null): Model
    {
        $this->authorize($reviewer);

        return DB::transaction(function () use ($reviewer, $document, $note): Model {
            $locked = $document::query()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isReturnPending()) {
                throw new InvalidArgumentException(__('This return is not pending approval.'));
            }

            if ($locked instanceof ProcedurePayment) {
                $locked->update([
                    'returned_at' => null,
                    'return_approval_status' => ApprovalStatus::Rejected,
                    'return_reviewed_by' => $reviewer->id,
                    'return_reviewed_at' => now(),
                    'return_note' => $note,
                ]);
            } else {
                $locked->update([
                    'status' => 'paid',
                    'return_approval_status' => ApprovalStatus::Rejected,
                    'return_reviewed_by' => $reviewer->id,
                    'return_reviewed_at' => now(),
                    'return_note' => $note,
                ]);
            }

            return $locked->refresh();
        });
    }

    /**
     * Ensure the reviewer may reject returns.
     */
    private function authorize(User $reviewer): void
    {
        if (! $reviewer->isAdmin() && ! $reviewer->isManagement()) {
            abort(403);
        }
    }
}
