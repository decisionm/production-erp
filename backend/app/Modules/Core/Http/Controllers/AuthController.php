<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\ChangePasswordRequest;
use App\Modules\Core\Http\Requests\LoginRequest;
use App\Modules\Core\Http\Resources\UserResource;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): UserResource
    {
        if (! Auth::attempt($request->validated(), remember: true)) {
            throw new AuthenticationException('Invalid credentials.');
        }

        if (! Auth::user()->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new AuthenticationException('This account has been deactivated.');
        }

        $request->session()->regenerate();

        return UserResource::make(Auth::user()->load('roles.permissions'));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): UserResource
    {
        return UserResource::make($request->user()->load('roles.permissions'));
    }

    /**
     * Self-service password change for the logged-in user. Verifies the
     * current password by hash (guard-agnostic, works for both the session
     * SPA and token clients) before setting the new one — the `password`
     * cast hashes it on write.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Your current password is incorrect.',
            ]);
        }

        $user->update(['password' => $data['password']]);

        return response()->json(['message' => 'Password changed.']);
    }
}
