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

class ApproveReturn
{
    /**
     * Approve a pending invoice or procedure payment return.
     */
    public function handle(User $reviewer, Invoice|LabInvoice|ProcedurePayment $document): Model
    {
        $this->authorize($reviewer);

        return DB::transaction(function () use ($reviewer, $document): Model {
            $locked = $document::query()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isReturnPending()) {
                throw new InvalidArgumentException(__('This return is not pending approval.'));
            }

            $locked->update([
                'return_approval_status' => ApprovalStatus::Approved,
                'return_reviewed_by' => $reviewer->id,
                'return_reviewed_at' => now(),
                'return_note' => null,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Ensure the reviewer may approve returns.
     */
    private function authorize(User $reviewer): void
    {
        if (! $reviewer->isAdmin() && ! $reviewer->isManagement()) {
            abort(403);
        }
    }
}
