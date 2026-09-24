<?php

namespace App\Actions;

use App\Models\LabInvoiceItem;
use App\Models\LabSampleRetake;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RequestSampleRetake
{
    /**
     * Ask reception to call the patient back for a new sample of an in-house test.
     * The test leaves the lab's receiving queue until reception prints the retake slip.
     */
    public function handle(User $user, int $itemId, string $reason): LabSampleRetake
    {
        $item = LabInvoiceItem::query()
            ->where('is_in_house', true)
            ->whereNull('results_completed_at')
            ->whereDoesntHave('retakes', fn ($retakes) => $retakes->open())
            ->whereHas('labInvoice', fn ($invoice) => $invoice->where('status', '!=', 'returned'))
            ->find($itemId);

        if ($item === null) {
            throw new InvalidArgumentException(__('A retake cannot be requested for this test.'));
        }

        return DB::transaction(function () use ($user, $item, $reason): LabSampleRetake {
            $retake = $item->retakes()->create([
                'reason' => trim($reason),
                'requested_by' => $user->id,
            ]);

            $item->update([
                'sample_received_at' => null,
                'sample_received_by' => null,
                'sample_received_by_health_aide_id' => null,
            ]);

            return $retake;
        });
    }
}
