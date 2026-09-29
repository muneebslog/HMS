<?php

namespace App\Http\Controllers;

use App\Models\MedicalCertificate;
use Illuminate\Http\Response;

/**
 * Public (no login) page reached by scanning the QR on a printed medical certificate.
 * Found only by the certificate's random token, never by the sequential serial number.
 */
class MedicalCertificateVerificationController extends Controller
{
    /**
     * Confirm whether the certificate is genuine and still valid.
     */
    public function __invoke(string $token): Response
    {
        $certificate = MedicalCertificate::query()
            ->where('verification_token', $token)
            ->firstOrFail();

        return response()
            ->view('medical-certificates.verify', ['certificate' => $certificate])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store, private');
    }
}
