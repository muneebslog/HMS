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

class MarkInvoiceReturn
{
    /**
     * Mark a walk-in invoice, lab invoice, or procedure payment as returned.
     *
     * Cash impact is immediate; management approval is audited afterward.
     */
    public function handle(User $user, Invoice|LabInvoice|ProcedurePayment $document): Model
    {
        return DB::transaction(function () use ($user, $document): Model {
            $locked = $document::query()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked instanceof ProcedurePayment) {
                return $this->markProcedurePaymentReturn($user, $locked);
            }

            return $this->markInvoiceReturn($user, $locked);
        });
    }

    /**
     * Mark a walk-in or lab invoice as returned.
     */
    private function markInvoiceReturn(User $user, Invoice|LabInvoice $invoice): Invoice|LabInvoice
    {
        if ($invoice->status === 'cancelled') {
            throw new InvalidArgumentException(__('Cancelled invoices cannot be returned.'));
        }

        if ($invoice->status === 'returned') {
            throw new InvalidArgumentException(__('This invoice has already been returned.'));
        }

        if ($invoice->status !== 'paid') {
            throw new InvalidArgumentException(__('Only paid invoices can be returned.'));
        }

        $invoice->update([
            'status' => 'returned',
            'return_approval_status' => ApprovalStatus::Pending,
            'return_requested_by' => $user->id,
            'return_reviewed_by' => null,
            'return_reviewed_at' => null,
            'return_note' => null,
        ]);

        return $invoice->refresh();
    }

    /**
     * Mark a procedure payment as returned.
     */
    private function markProcedurePaymentReturn(User $user, ProcedurePayment $payment): ProcedurePayment
    {
        if ($payment->isDiscarded()) {
            throw new InvalidArgumentException(__('Discarded payments cannot be returned.'));
        }

        if ($payment->isReturned()) {
            throw new InvalidArgumentException(__('This payment has already been returned.'));
        }

        $payment->update([
            'returned_at' => now(),
            'return_requested_by' => $user->id,
            'return_approval_status' => ApprovalStatus::Pending,
            'return_reviewed_by' => null,
            'return_reviewed_at' => null,
            'return_note' => null,
        ]);

        return $payment->refresh();
    }
}
