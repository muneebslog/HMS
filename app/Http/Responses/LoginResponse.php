<?php

namespace App\Http\Responses;

use App\Enums\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse($request): Response|RedirectResponse|JsonResponse
    {
        $user = $request->user();

        if ($user !== null && $user->role === UserRole::User) {
            return redirect()->to(route('pending-role'));
        }

        return redirect()->intended(config('fortify.home', '/dashboard'));
    }
}
