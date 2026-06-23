<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\ServiceQueue;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

class ReservationService
{
    /**
     * Reserve a token number in the given queue for a phone patient.
     */
    public function reserve(ServiceQueue $queue, int $tokenNumber, string $patientName): QueueToken
    {
        return DB::transaction(function () use ($queue, $tokenNumber, $patientName) {
            $lockedQueue = ServiceQueue::where('id', $queue->id)->lockForUpdate()->firstOrFail();

            $exists = QueueToken::where('service_queue_id', $lockedQueue->id)
                ->where('token_number', $tokenNumber)
                ->exists();

            if ($exists) {
                throw new \RuntimeException(__('Token number :number is already in use.', ['number' => $tokenNumber]));
            }

            $patient = Patient::create(['name' => $patientName]);

            $token = QueueToken::create([
                'service_queue_id' => $lockedQueue->id,
                'invoice_item_id' => null,
                'patient_id' => $patient->id,
                'token_number' => $tokenNumber,
                'status' => 'reserved',
            ]);

            $lockedQueue->update([
                'last_token_number' => max($lockedQueue->last_token_number, $tokenNumber),
            ]);

            return $token;
        });
    }

    /**
     * Mark a reserved token as arrived and create its invoice.
     */
    public function arrive(QueueToken $token): Invoice
    {
        return DB::transaction(function () use ($token) {
            $lockedQueue = ServiceQueue::where('id', $token->service_queue_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedToken = QueueToken::where('id', $token->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedToken->status !== 'reserved' || $lockedToken->invoice_item_id !== null) {
                throw new \RuntimeException(__('This token is not reserved.'));
            }

            $shift = Shift::currentForUser((int) auth()->id());

            if ($shift === null) {
                throw new \RuntimeException(__('Please open a shift first.'));
            }

            $service = Service::whereRaw('LOWER(name) = ?', ['consultation'])->first();

            if ($service === null) {
                throw new \RuntimeException(__('Consultation service is not configured.'));
            }

            $price = ServicePrice::query()
                ->where('service_id', $service->id)
                ->when(
                    $lockedQueue->doctor_id,
                    fn ($query) => $query->where('doctor_id', $lockedQueue->doctor_id),
                    fn ($query) => $query->whereNull('doctor_id')
                )
                ->first();

            $priceAmount = $price instanceof ServicePrice ? $price->price : 0;
            $doctorShare = $price instanceof ServicePrice ? $price->doctor_share : null;

            $invoice = Invoice::create([
                'patient_id' => $lockedToken->patient_id,
                'invoice_number' => Invoice::generateNumber(),
                'total' => $priceAmount,
                'status' => 'paid',
                'created_by' => auth()->id(),
                'shift_id' => $shift->id,
            ]);

            $invoiceItem = InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_id' => $service->id,
                'doctor_id' => $lockedQueue->doctor_id,
                'service_name' => $service->name,
                'doctor_name' => $lockedQueue->doctor?->name,
                'price' => $priceAmount,
                'doctor_share' => $doctorShare,
            ]);

            $lockedToken->update([
                'invoice_item_id' => $invoiceItem->id,
                'status' => 'waiting',
            ]);

            return $invoice;
        });
    }
}
