<?php

namespace App\Http\Controllers;

use App\Models\EmployeeQualification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeQualificationDownloadController extends Controller
{
    /**
     * Download the given employee qualification document.
     */
    public function __invoke(Request $request, EmployeeQualification $qualification): StreamedResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->isAdmin()) {
            abort(403);
        }

        if (! filled($qualification->document_path) || ! Storage::disk('local')->exists($qualification->document_path)) {
            abort(404);
        }

        return Storage::disk('local')->download(
            $qualification->document_path,
            $qualification->original_name ?? basename($qualification->document_path)
        );
    }
}
