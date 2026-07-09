<?php

namespace App\Services;

use App\Models\AdminNotification;
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
     * Reserve a token number in the given queue for a patient.
     */
    public function reserve(ServiceQueue $queue, int $tokenNumber, string $patientName, ?string $patientPhone): QueueToken
    {
        return DB::transaction(function () use ($queue, $tokenNumber, $patientName, $patientPhone) {
            $lockedQueue = ServiceQueue::where('id', $queue->id)->lockForUpdate()->firstOrFail();

            $exists = QueueToken::where('service_queue_id', $lockedQueue->id)
                ->where('token_number', $tokenNumber)
                ->exists();

            if ($exists) {
                throw new \RuntimeException(__('Token number :number is already in use.', ['number' => $tokenNumber]));
            }

            $patient = Patient::create([
                'name' => $patientName,
                'phone' => $patientPhone,
            ]);

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

            if (filled($patientPhone)) {
                $estimatedTime = $lockedQueue->doctor?->duty_start_time !== null
                    ? $lockedQueue->doctor->duty_start_time->copy()->addMinutes(($tokenNumber - 1) * 5)
                    : null;

                app(SmsService::class)->sendAppointmentConfirmation(
                    $patientPhone,
                    $lockedQueue->doctor,
                    $tokenNumber,
                    $estimatedTime
                );
            }

            if (blank($patientPhone)) {
                AdminNotification::create([
                    'user_id' => auth()->id(),
                    'type' => 'reservation_without_phone',
                    'title' => __('Token issued without contact number'),
                    'message' => __(
                        'Receptionist :name issued token :number for :patient without a contact number.',
                        [
                            'name' => auth()->user()?->name ?? __('Unknown'),
                            'number' => $tokenNumber,
                            'patient' => $patientName,
                        ]
                    ),
                    'actionable_url' => route('reception.reservation'),
                    'metadata' => [
                        'token_id' => $token->id,
                        'token_number' => $tokenNumber,
                        'patient_id' => $patient->id,
                        'patient_name' => $patientName,
                        'queue_id' => $lockedQueue->id,
                    ],
                ]);
            }

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

            $shift = Shift::current();

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
