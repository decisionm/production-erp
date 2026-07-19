<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\LoginRequest;
use App\Modules\Core\Http\Resources\UserResource;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
}
