<?php

namespace App\Actions;

use App\Enums\PaymentMode;
use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\Shift;
use App\Models\User;
use InvalidArgumentException;

class ChangePaymentMode
{
    /**
     * Switch a paid invoice of the open shift between cash and online, recording who changed it.
     */
    public function handle(User $user, Invoice|LabInvoice $invoice, PaymentMode $mode): Invoice|LabInvoice
    {
        if ($invoice->shift_id !== Shift::current()?->id) {
            throw new InvalidArgumentException(__('Only invoices in the open shift can be changed.'));
        }

        if (in_array($invoice->status, ['returned', 'cancelled'], true)) {
            throw new InvalidArgumentException(__('Returned or cancelled invoices cannot be changed.'));
        }

        if ($invoice->payment_mode === $mode) {
            return $invoice;
        }

        $invoice->update([
            'payment_mode' => $mode,
            'payment_mode_changed_at' => now(),
            'payment_mode_changed_by' => $user->id,
        ]);

        return $invoice;
    }
}
