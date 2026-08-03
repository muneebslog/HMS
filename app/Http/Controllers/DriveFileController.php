<?php

namespace App\Http\Controllers;

use App\Models\DriveFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DriveFileController extends Controller
{
    /**
     * Download the given drive file.
     */
    public function download(Request $request, DriveFile $driveFile): StreamedResponse
    {
        $this->authorizeAccess($request);

        if (! Storage::disk('local')->exists($driveFile->disk_path)) {
            abort(404);
        }

        return Storage::disk('local')->download(
            $driveFile->disk_path,
            $driveFile->original_filename
        );
    }

    /**
     * Stream the given drive file inline for viewing.
     */
    public function view(Request $request, DriveFile $driveFile): StreamedResponse
    {
        $this->authorizeAccess($request);

        if (! Storage::disk('local')->exists($driveFile->disk_path)) {
            abort(404);
        }

        return Storage::disk('local')->response(
            $driveFile->disk_path,
            $driveFile->original_filename,
            [
                'Content-Type' => $driveFile->mime_type,
                'Content-Disposition' => 'inline; filename="'.$driveFile->original_filename.'"',
            ]
        );
    }

    /**
     * Abort unless the authenticated user is an admin or management user.
     */
    private function authorizeAccess(Request $request): void
    {
        $user = $request->user();

        if ($user === null || (! $user->isAdmin() && ! $user->isManagement())) {
            abort(403);
        }
    }
}
