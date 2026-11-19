<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->success(array_merge($this->userPayload($user), [
            'email_verified' => $user->hasVerifiedEmail(),
            'email_verified_at' => $user->email_verified_at?->toISOString(),
        ]));
    }

    public function updateInformation(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique(User::class)->ignore($user->id),
            ],
        ]);

        $user->fill($validated);
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        $user->save();

        return $this->success(array_merge($this->userPayload($user->refresh()), [
            'email_verified' => $user->hasVerifiedEmail(),
            'email_verified_at' => $user->email_verified_at?->toISOString(),
        ]), 'Profil diperbarui.');
    }

    /**
     * Simpan foto profil sebagai string base64 di kolom users.avatar.
     * Menerima base64 murni maupun data URI (data:image/...;base64,xxx).
     * Kirim avatar = null untuk menghapus foto dan kembali ke inisial.
     */
    public function updateAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'avatar' => ['nullable', 'string'],
        ]);

        $avatar = $validated['avatar'] ?? null;

        if ($avatar !== null) {
            // Buang prefix data URI bila ada, sisakan payload base64 saja.
            if (str_starts_with($avatar, 'data:')) {
                $comma = strpos($avatar, ',');
                $avatar = $comma === false ? '' : substr($avatar, $comma + 1);
            }

            $decoded = base64_decode($avatar, true);
            if ($decoded === false || $decoded === '') {
                return $this->error('Data gambar avatar tidak valid.', 422);
            }
            if (strlen($decoded) > 1_500_000) {
                return $this->error('Ukuran avatar maksimal 1,5 MB.', 422);
            }
            $info = @getimagesizefromstring($decoded);
            if ($info === false || ! str_starts_with($info['mime'] ?? '', 'image/')) {
                return $this->error('File avatar harus berupa gambar.', 422);
            }
        }

        $user->avatar = $avatar;
        $user->save();

        return $this->success(array_merge($this->userPayload($user->refresh()), [
            'email_verified' => $user->hasVerifiedEmail(),
            'email_verified_at' => $user->email_verified_at?->toISOString(),
        ]), $avatar === null ? 'Avatar dihapus.' : 'Avatar diperbarui.');
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->update(['password' => Hash::make($validated['password'])]);

        return $this->success(null, 'Kata sandi diperbarui.');
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $user->tokens()->delete();
        $user->delete();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->success(null, 'Akun dihapus.');
    }

    public function resendVerification(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return $this->success(['verified' => true], 'Email sudah terverifikasi.');
        }

        $user->sendEmailVerificationNotification();

        return $this->success(['verified' => false], 'Tautan verifikasi dikirim.');
    }
}
