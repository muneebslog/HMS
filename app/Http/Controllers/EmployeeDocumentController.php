<?php

namespace App\Http\Controllers;

use App\Models\EmployeeDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocumentController extends Controller
{
    /**
     * Download the given employee document.
     */
    public function download(Request $request, EmployeeDocument $document): StreamedResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->isAdmin()) {
            abort(403);
        }

        if (! Storage::disk('local')->exists($document->file_path)) {
            abort(404);
        }

        return Storage::disk('local')->download(
            $document->file_path,
            $document->original_name ?? basename($document->file_path)
        );
    }
}
