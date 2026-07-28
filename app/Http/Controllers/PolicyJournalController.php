<?php

namespace App\Http\Controllers;

use App\Models\PolicyJournal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PolicyJournalController extends Controller
{
    /**
     * Download an attachment from the given policy journal entry.
     */
    public function download(Request $request, PolicyJournal $policyJournal, int $index): StreamedResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->isAdmin()) {
            abort(403);
        }

        $attachment = $policyJournal->attachments[$index] ?? null;

        if ($attachment === null || ! Storage::disk('local')->exists($attachment['path'])) {
            abort(404);
        }

        return Storage::disk('local')->download(
            $attachment['path'],
            $attachment['original_name'] ?? basename($attachment['path'])
        );
    }
}
