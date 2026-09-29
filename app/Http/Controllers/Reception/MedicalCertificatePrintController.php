<?php

namespace App\Http\Controllers\Reception;

use App\Http\Controllers\Controller;
use App\Models\MedicalCertificate;
use Illuminate\View\View;

class MedicalCertificatePrintController extends Controller
{
    /**
     * Display the printable medical certificate and count the print.
     */
    public function __invoke(MedicalCertificate $certificate): View
    {
        abort_if($certificate->isVoided(), 404);

        $certificate->updateQuietly([
            'print_count' => $certificate->print_count + 1,
            'last_printed_at' => now(),
        ]);

        return view('medical-certificates.print', [
            'certificate' => $certificate,
        ]);
    }
}
