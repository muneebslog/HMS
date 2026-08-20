<?php

namespace App\Actions;

use App\Enums\DripChargeStatus;
use App\Enums\PaymentMode;
use App\Models\DripCharge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Shift;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MarkDripChargePaid
{
    public function __construct(
        private CreatePrintJob $createPrintJob,
        private QueueService $queueService,
    ) {}

    /**
     * Create a paid invoice for the drip charge, queue a print job, and mark it paid.
     */
    public function handle(
        DripCharge $charge,
        Shift $shift,
        User $paidBy,
        PaymentMode $paymentMode = PaymentMode::Cash,
        ?float $price = null,
    ): Invoice {
        if ($charge->status === DripChargeStatus::Paid) {
            throw new InvalidArgumentException('This drip charge has already been paid.');
        }

        if ($charge->status === DripChargeStatus::Cancelled) {
            throw new InvalidArgumentException('This drip charge was cancelled.');
        }

        $charge->loadMissing(['patient', 'service', 'doctor']);

        if ($charge->service === null) {
            throw new InvalidArgumentException('Drip charge service is missing.');
        }

        $amount = $price ?? $charge->suggested_price;

        if ($amount === null) {
            throw new InvalidArgumentException('Price is required.');
        }

        $invoice = DB::transaction(function () use ($charge, $shift, $paidBy, $paymentMode, $amount): Invoice {
            $invoice = Invoice::create([
                'patient_id' => $charge->patient_id,
                'invoice_number' => Invoice::generateNumber(),
                'total' => $amount,
                'status' => 'paid',
                'payment_mode' => $paymentMode,
                'created_by' => $paidBy->id,
                'shift_id' => $shift->id,
            ]);

            $invoiceItem = InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_id' => $charge->service_id,
                'doctor_id' => $charge->doctor_id,
                'service_name' => $charge->service->name,
                'doctor_name' => $charge->doctor?->name,
                'price' => $amount,
                'doctor_share' => $charge->doctor_share,
            ]);

            $this->queueService->generateToken($invoiceItem);

            $charge->update([
                'suggested_price' => $amount,
                'status' => DripChargeStatus::Paid,
                'invoice_id' => $invoice->id,
                'paid_by' => $paidBy->id,
                'paid_at' => now(),
            ]);

            return $invoice->fresh(['items', 'patient']) ?? $invoice;
        });

        $this->createPrintJob->create($invoice);

        return $invoice;
    }
}
