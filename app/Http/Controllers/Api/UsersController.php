<?php

namespace App\Http\Controllers\Api;

use App\Enums\Platform;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UsersController extends ApiController
{
    public function index(): JsonResponse
    {
        Gate::authorize('user.view');

        $users = User::with('role')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'active' => (bool) $user->is_active,
                'role_id' => $user->role_id,
                'role' => $user->role ? [
                    'id' => $user->role->id,
                    'name' => $user->role->name,
                    'slug' => $user->role->slug,
                ] : null,
                'allowed_platforms' => $user->allowed_platforms ?? [],
                'is_administrator' => $user->isAdministrator(),
                'email_verified_at' => $user->email_verified_at?->toISOString(),
            ]);

        return $this->success([
            'items' => $users,
            'platforms' => collect(Platform::cases())->map(fn (Platform $platform) => [
                'value' => $platform->value,
                'label' => $platform->label(),
            ])->all(),
        ]);
    }

    public function updateActive(Request $request, User $user): JsonResponse
    {
        Gate::authorize('user.manage');

        abort_if($user->isAdministrator(), 422, 'Status Administrator tidak dapat diubah.');

        $validated = $request->validate([
            'active' => ['required', 'boolean'],
        ]);

        $user->is_active = (bool) $validated['active'];
        $user->save();

        ActivityLog::record(
            'user_active_changed',
            "{$user->name}: ".($user->is_active ? 'diaktifkan' : 'dinonaktifkan'),
            $user,
        );

        return $this->success([
            'user' => [
                'id' => $user->id,
                'active' => (bool) $user->is_active,
            ],
        ], ($user->is_active ? 'Pengguna '.$user->name.' diaktifkan.' : 'Pengguna '.$user->name.' dinonaktifkan.'));
    }

    public function updateRole(Request $request, User $user): JsonResponse
    {
        Gate::authorize('user.manage');

        $validated = $request->validate([
            'role_id' => ['nullable', Rule::exists('roles', 'id')],
        ]);

        $previous = $user->role?->name ?? 'tanpa role';
        $user->role_id = $validated['role_id'] === null
            ? null
            : (int) $validated['role_id'];
        $user->save();

        ActivityLog::record(
            'user_role_changed',
            "{$user->name}: {$previous} → ".($user->fresh()->role?->name ?? 'tanpa role'),
            $user,
        );

        return $this->success([
            'user' => [
                'id' => $user->id,
                'role_id' => $user->role_id,
                'role' => $user->role ? [
                    'id' => $user->role->id,
                    'name' => $user->role->name,
                    'slug' => $user->role->slug,
                ] : null,
            ],
        ], 'Role untuk '.$user->name.' diperbarui.');
    }

    public function updatePlatforms(Request $request, User $user): JsonResponse
    {
        Gate::authorize('user.platform');

        abort_if($user->isAdministrator(), 422, 'Administrator selalu bisa memposting ke semua platform.');

        $validated = $request->validate([
            'platforms' => ['present', 'array'],
            'platforms.*' => [Rule::in(Platform::values())],
        ]);

        $allowed = array_values(array_unique($validated['platforms']));
        $user->allowed_platforms = count($allowed) === count(Platform::values())
            ? null
            : $allowed;
        $user->save();

        $summary = $user->allowed_platforms === null
            ? 'semua platform'
            : (implode(', ', array_map(fn ($value) => Platform::tryLabel($value), $user->allowed_platforms)) ?: 'tidak ada platform');
        ActivityLog::record('user_platform_changed', "{$user->name}: {$summary}", $user);

        return $this->success([
            'user' => [
                'id' => $user->id,
                'allowed_platforms' => $user->allowed_platforms ?? [],
            ],
        ], 'Akses posting '.$user->name.' diperbarui.');
    }
}
