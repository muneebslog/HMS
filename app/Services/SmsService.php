<?php

namespace App\Services;

use App\Enums\SmsStatus;
use App\Models\Doctor;
use App\Models\SmsLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    /**
     * Check whether SMS sending is enabled.
     */
    public function enabled(): bool
    {
        return config('services.veevo_sms.enabled', false)
            && filled(config('services.veevo_sms.hash'));
    }

    /**
     * Send an appointment confirmation SMS.
     */
    public function sendAppointmentConfirmation(string $phone, Doctor $doctor, int $tokenNumber, ?CarbonInterface $estimatedTime = null, ?SmsLog $log = null): bool
    {
        $receiver = $this->normalizePhone($phone);

        if ($receiver === null) {
            $log?->update([
                'status' => SmsStatus::Failed,
                'provider_response' => __('Invalid phone number'),
            ]);

            return false;
        }

        $message = $this->buildAppointmentMessage($doctor, $tokenNumber, $estimatedTime);

        $log?->update([
            'phone' => $receiver,
            'message' => $message,
        ]);

        $sent = $this->send($receiver, $message, $log);

        if ($log !== null) {
            $log->update([
                'status' => $sent ? SmsStatus::Sent : SmsStatus::Failed,
                'sent_at' => $sent ? now() : null,
            ]);
        }

        return $sent;
    }

    /**
     * Normalize a local Pakistani phone number to international format.
     *
     * Converts 03XXXXXXXXX -> +923XXXXXXXXX.
     */
    public function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/[^0-9+]/', '', $phone);

        if (str_starts_with($digits, '+92')) {
            return $digits;
        }

        if (str_starts_with($digits, '03') && strlen($digits) === 11) {
            return '+92'.substr($digits, 1);
        }

        return null;
    }

    /**
     * Build the appointment confirmation message text.
     */
    public function buildAppointmentMessage(Doctor $doctor, int $tokenNumber, ?CarbonInterface $estimatedTime = null): string
    {
        $base = sprintf(
            'Assalam-o-Alaikum from Mohsin Medical Complex. Your appointment with Dr. %s is token #%d',
            $doctor->name,
            $tokenNumber
        );

        if ($estimatedTime !== null) {
            $base .= sprintf(' at approximately %s', $estimatedTime->format('g:i A'));
        }

        $base .= '. Please arrive 10 minutes early. This time is computer estimated and not exact. If you are late by more than 10 tokens, you will need a new token.';

        return $base;
    }

    /**
     * Send an SMS via the VeevoTech API.
     */
    public function send(string $receiver, string $message, ?SmsLog $log = null): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $endpoint = config('services.veevo_sms.endpoint', 'https://api.veevotech.com/v3/sendsms');

        try {
            $response = Http::timeout(15)
                ->post($endpoint, [
                    'hash' => config('services.veevo_sms.hash'),
                    'receivernum' => $receiver,
                    'textmessage' => $message,
                ]);

            if ($response->successful()) {
                return true;
            }

            $log?->update(['provider_response' => $response->body()]);

            Log::warning('VeevoTech SMS returned non-successful response.', [
                'receiver' => $receiver,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            $log?->update(['provider_response' => $e->getMessage()]);

            Log::error('Failed to send VeevoTech SMS.', [
                'receiver' => $receiver,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
