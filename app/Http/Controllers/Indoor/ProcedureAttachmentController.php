<?php

namespace App\Http\Controllers\Indoor;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ProcedureAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProcedureAttachmentController extends Controller
{
    /**
     * Stream the given procedure attachment inline for viewing.
     */
    public function __invoke(Request $request, ProcedureAttachment $attachment): StreamedResponse
    {
        $user = $request->user();

        $allowedRoles = [
            UserRole::Indoor,
            UserRole::Admin,
            UserRole::Doctor,
            UserRole::Receptionist,
        ];

        if ($user === null || ! in_array($user->role, $allowedRoles, true)) {
            abort(403);
        }

        if (! Storage::disk('local')->exists($attachment->path)) {
            abort(404);
        }

        return Storage::disk('local')->response(
            $attachment->path,
            $attachment->original_name,
            filled($attachment->mime_type) ? ['Content-Type' => $attachment->mime_type] : []
        );
    }
}
