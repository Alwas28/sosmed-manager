<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends ApiController
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return $this->error('Email atau kata sandi salah.', 401);
        }

        $request->session()->regenerate();

        /** @var \App\Models\User $user */
        $user = Auth::user();
        ActivityLog::record('login', 'Masuk melalui API', null, $user->id);

        return $this->success($this->userPayload($user), 'Berhasil masuk.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success($this->userPayload($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        ActivityLog::record('logout', 'Keluar melalui API', null, $user->id);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->success(null, 'Berhasil keluar.');
    }
}
