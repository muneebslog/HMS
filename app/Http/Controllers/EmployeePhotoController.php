<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeePhotoController extends Controller
{
    /**
     * Display the given employee profile photo inline.
     */
    public function __invoke(Request $request, Employee $employee): StreamedResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->isAdmin()) {
            abort(403);
        }

        if (! filled($employee->photo_path) || ! Storage::disk('local')->exists($employee->photo_path)) {
            abort(404);
        }

        return Storage::disk('local')->response($employee->photo_path);
    }
}
