<?php

namespace App\Services;

use App\Enums\TokenResetType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServiceQueue;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class QueueService
{
    /**
     * Generate a queue token for the given invoice item.
     */
    public function generateToken(InvoiceItem $invoiceItem): QueueToken
    {
        /** @var Service $service */
        $service = $invoiceItem->service;

        /** @var Invoice $invoice */
        $invoice = $invoiceItem->invoice;

        /** @var Shift|null $shift */
        $shift = $invoice->shift;

        if ($shift === null) {
            throw new \RuntimeException('Invoice must be associated with a shift to generate a token.');
        }

        return DB::transaction(function () use ($invoiceItem, $service, $shift) {
            $queue = $this->findOrCreateQueue($service, $invoiceItem->doctor_id, $shift);

            $lockedQueue = ServiceQueue::where('id', $queue->id)->lockForUpdate()->firstOrFail();
            $nextNumber = $lockedQueue->last_token_number + 1;
            $lockedQueue->update(['last_token_number' => $nextNumber]);

            return QueueToken::create([
                'service_queue_id' => $queue->id,
                'invoice_item_id' => $invoiceItem->id,
                'token_number' => $nextNumber,
                'status' => 'waiting',
            ]);
        });
    }

    /**
     * Find an open queue matching the reset type, or create a new one.
     */
    private function findOrCreateQueue(Service $service, ?int $doctorId, Shift $shift): ServiceQueue
    {
        $resetType = $service->token_reset_type;

        $queue = match ($resetType) {
            TokenResetType::Shift => ServiceQueue::where('service_id', $service->id)
                ->where('doctor_id', $doctorId)
                ->where('shift_id', $shift->id)
                ->where('status', 'open')
                ->first(),
            TokenResetType::Daily => ServiceQueue::where('service_id', $service->id)
                ->where('doctor_id', $doctorId)
                ->whereDate('date', $shift->opened_at)
                ->where('status', 'open')
                ->first(),
        };

        if ($queue instanceof ServiceQueue) {
            return $queue;
        }

        $this->closeOpenQueues($service->id, $doctorId);

        return ServiceQueue::create([
            'service_id' => $service->id,
            'doctor_id' => $doctorId,
            'shift_id' => $shift->id,
            'date' => Carbon::parse($shift->opened_at)->toDateString(),
            'reset_type' => $resetType,
            'opened_at' => now(),
            'status' => 'open',
            'last_token_number' => 0,
        ]);
    }

    /**
     * Close any open queues for the given service and doctor.
     */
    private function closeOpenQueues(int $serviceId, ?int $doctorId): void
    {
        ServiceQueue::where('service_id', $serviceId)
            ->where('doctor_id', $doctorId)
            ->where('status', 'open')
            ->update([
                'status' => 'closed',
                'closed_at' => now(),
            ]);
    }
}
