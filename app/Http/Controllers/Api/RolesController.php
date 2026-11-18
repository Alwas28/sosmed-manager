<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RolesController extends ApiController
{
    public function index(): JsonResponse
    {
        Gate::authorize('role.view');

        $roles = Role::withCount(['permissions', 'users'])
            ->orderByDesc('is_locked')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'is_locked' => $role->is_locked,
                'is_administrator' => $role->isAdministrator(),
                'permissions_count' => $role->permissions_count,
                'users_count' => $role->users_count,
            ]);

        return $this->success(['items' => $roles]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('role.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'description' => $validated['description'] ?: null,
        ]);

        ActivityLog::record('role_created', $role->name, $role);

        return $this->success(['role' => $this->payload($role)], 'Role berhasil dibuat.', 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        Gate::authorize('role.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($role->id)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $role->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?: null,
            'slug' => $role->is_locked ? $role->slug : Str::slug($validated['name']),
        ]);

        ActivityLog::record('role_updated', $role->name, $role);

        return $this->success(['role' => $this->payload($role)], 'Role berhasil diperbarui.');
    }

    public function destroy(Role $role): JsonResponse
    {
        Gate::authorize('role.manage');

        if ($role->is_locked) {
            return $this->error('Role bawaan tidak dapat dihapus.', 422);
        }

        if ($role->users()->count() > 0) {
            return $this->error(
                'Role masih dipakai oleh '.$role->users()->count().' pengguna.',
                422,
            );
        }

        ActivityLog::record('role_deleted', $role->name);
        $role->delete();

        return $this->success(null, 'Role dihapus.');
    }

    /** @return array<string, mixed> */
    private function payload(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description,
            'is_locked' => $role->is_locked,
            'is_administrator' => $role->isAdministrator(),
        ];
    }
}
