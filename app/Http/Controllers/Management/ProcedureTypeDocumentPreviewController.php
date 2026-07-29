<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\ProcedureTypeDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProcedureTypeDocumentPreviewController extends Controller
{
    /**
     * Display a private procedure type document inline.
     */
    public function __invoke(ProcedureTypeDocument $document): StreamedResponse
    {
        if (! Storage::disk('local')->exists($document->path)) {
            abort(404);
        }

        return Storage::disk('local')->response(
            $document->path,
            $document->original_name,
            ['Content-Type' => $document->resolvedMimeType()]
        );
    }
}
