<?php

namespace App\Actions;

use App\Models\LabInvoiceItem;
use App\Models\User;
use InvalidArgumentException;

class CancelLabTest
{
    /**
     * Cancel a test the patient no longer wants. It leaves every lab and reception queue
     * (including an open retake) and counts as settled; the bill is not changed.
     */
    public function handle(User $user, LabInvoiceItem $item, string $reason): LabInvoiceItem
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException(__('A cancel reason is required.'));
        }

        if (! $item->canBeCancelled()) {
            throw new InvalidArgumentException(__('This test cannot be cancelled.'));
        }

        $item->update([
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
            'cancel_reason' => $reason,
        ]);

        return $item;
    }
}
