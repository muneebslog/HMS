<?php

namespace App\Services;

use App\Enums\PrintJobStatus;
use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\Patient;
use App\Models\PrintJob;
use App\Models\Procedure;
use App\Models\QueueToken;
use App\Models\UltrasoundReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TokenAdministrationService
{
    /**
     * Update the patient name and phone linked to a queue token.
     */
    public function updatePatientDetails(User $admin, QueueToken $token, string $name, ?string $phone): QueueToken
    {
        $this->ensureAdmin($admin);

        return DB::transaction(function () use ($admin, $token, $name, $phone) {
            $lockedToken = $this->lockToken($token->id);
            $patient = $this->resolvePatient($lockedToken);

            $before = [
                'name' => $patient->name,
                'phone' => $patient->phone,
            ];

            $patient->update([
                'name' => $name,
                'phone' => $phone,
            ]);

            $after = [
                'name' => $patient->name,
                'phone' => $patient->phone,
            ];

            $this->notifyAfterCommit(function () use ($admin, $lockedToken, $before, $after): void {
                app(NotificationService::class)->notifyTokenPatientUpdated(
                    $admin,
                    $lockedToken,
                    $before,
                    $after
                );
            });

            return $lockedToken->fresh(['patient', 'invoiceItem.invoice']);
        });
    }

    /**
     * Revert an arrived reservation token back to reserved and cancel its invoice.
     */
    public function markAsNotArrived(User $admin, QueueToken $token): QueueToken
    {
        $this->ensureAdmin($admin);

        return DB::transaction(function () use ($admin, $token) {
            $lockedToken = $this->lockToken($token->id);

            if (
                $lockedToken->origin !== 'reservation'
                || $lockedToken->arrived_at === null
                || ! in_array($lockedToken->status, ['waiting', 'serving'], true)
            ) {
                throw new \RuntimeException(__('This token cannot be marked as not arrived.'));
            }

            $beforeStatus = $lockedToken->status;
            $invoice = $lockedToken->invoiceItem?->invoice;
            $cancelledInvoiceNumber = $invoice?->invoice_number;

            if ($invoice !== null) {
                $this->cancelInvoice($invoice);
            }

            $lockedToken->update([
                'invoice_item_id' => null,
                'status' => 'reserved',
                'arrived_at' => null,
                'displayed_at' => null,
            ]);

            $this->notifyAfterCommit(function () use ($admin, $lockedToken, $beforeStatus, $cancelledInvoiceNumber): void {
                $notifications = app(NotificationService::class);

                $notifications->notifyTokenStatusReversed(
                    $admin,
                    $lockedToken,
                    $beforeStatus,
                    'reserved'
                );

                if ($cancelledInvoiceNumber !== null) {
                    $notifications->notifyInvoiceCancelled(
                        $admin,
                        $lockedToken,
                        $cancelledInvoiceNumber
                    );
                }
            });

            return $lockedToken->fresh(['patient', 'invoiceItem.invoice']);
        });
    }

    /**
     * Revert a served token back to waiting.
     */
    public function markAsNotServed(User $admin, QueueToken $token): QueueToken
    {
        $this->ensureAdmin($admin);

        return DB::transaction(function () use ($admin, $token) {
            $lockedToken = $this->lockToken($token->id);

            if ($lockedToken->status !== 'served') {
                throw new \RuntimeException(__('This token is not served.'));
            }

            $beforeStatus = $lockedToken->status;

            $lockedToken->update([
                'status' => 'waiting',
            ]);

            $this->notifyAfterCommit(function () use ($admin, $lockedToken, $beforeStatus): void {
                app(NotificationService::class)->notifyTokenStatusReversed(
                    $admin,
                    $lockedToken,
                    $beforeStatus,
                    'waiting'
                );
            });

            return $lockedToken->fresh(['patient', 'invoiceItem.invoice']);
        });
    }

    /**
     * Remove an unarrived reserved token and optionally its unused patient.
     */
    public function revertReserved(User $admin, QueueToken $token): void
    {
        $this->ensureAdmin($admin);

        DB::transaction(function () use ($admin, $token) {
            $lockedToken = $this->lockToken($token->id);

            if ($lockedToken->status !== 'reserved' || $lockedToken->arrived_at !== null || $lockedToken->invoice_item_id !== null) {
                throw new \RuntimeException(__('Only unarrived reserved tokens can be reverted.'));
            }

            $snapshot = [
                'token_id' => $lockedToken->id,
                'token_number' => $lockedToken->token_number,
                'queue_id' => $lockedToken->service_queue_id,
                'patient_id' => $lockedToken->patient_id,
                'patient_name' => $lockedToken->patient?->name,
            ];

            $patientId = $lockedToken->patient_id;

            $lockedToken->patientCalls()->delete();
            $lockedToken->delete();

            if ($patientId !== null) {
                $patient = Patient::query()->lockForUpdate()->find($patientId);

                if ($patient !== null && ! $this->patientHasOtherReferences($patient)) {
                    $patient->delete();
                }
            }

            $this->notifyAfterCommit(function () use ($admin, $snapshot): void {
                app(NotificationService::class)->notifyReservationReverted($admin, $snapshot);
            });
        });
    }

    /**
     * Ensure the acting user is an admin.
     */
    private function ensureAdmin(User $user): void
    {
        if (! $user->isAdmin()) {
            abort(403);
        }
    }

    /**
     * Lock the token row for update.
     */
    private function lockToken(int $tokenId): QueueToken
    {
        return QueueToken::query()
            ->with(['patient', 'invoiceItem.invoice', 'patientCalls'])
            ->whereKey($tokenId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Resolve the patient linked to the token.
     */
    private function resolvePatient(QueueToken $token): Patient
    {
        $patient = $token->patient;

        if ($patient === null && $token->invoiceItem?->invoice?->patient !== null) {
            $patient = $token->invoiceItem->invoice->patient;
            $token->update(['patient_id' => $patient->id]);
        }

        if ($patient === null) {
            throw new \RuntimeException(__('This token has no linked patient.'));
        }

        return Patient::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Cancel an invoice and stop any pending print jobs.
     */
    private function cancelInvoice(Invoice $invoice): void
    {
        $lockedInvoice = Invoice::query()
            ->whereKey($invoice->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($lockedInvoice->status === 'cancelled') {
            return;
        }

        PrintJob::query()
            ->where('invoice_id', $lockedInvoice->id)
            ->where('status', PrintJobStatus::Pending->value)
            ->get()
            ->each(function (PrintJob $job): void {
                $job->markAsFailed(__('Invoice cancelled by admin.'));
            });

        $lockedInvoice->update(['status' => 'cancelled']);
    }

    /**
     * Determine whether the patient is still referenced by other records.
     */
    private function patientHasOtherReferences(Patient $patient): bool
    {
        if ($patient->queueTokens()->exists()) {
            return true;
        }

        if (Invoice::query()->where('patient_id', $patient->id)->exists()) {
            return true;
        }

        if (LabInvoice::query()->where('patient_id', $patient->id)->exists()) {
            return true;
        }

        if (Procedure::query()->where('patient_id', $patient->id)->exists()) {
            return true;
        }

        return UltrasoundReport::query()->where('patient_id', $patient->id)->exists();
    }

    /**
     * Run a callback after the current transaction commits.
     *
     * @param  callable(): void  $callback
     */
    private function notifyAfterCommit(callable $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }
}
