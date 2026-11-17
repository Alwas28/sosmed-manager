<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AccessControlController extends ApiController
{
    public function index(): JsonResponse
    {
        Gate::authorize('access.manage');

        $roles = Role::orderByDesc('is_locked')
            ->orderBy('name')
            ->with('permissions')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'is_administrator' => $role->isAdministrator(),
                'permissions' => $role->permissions->pluck('slug')->all(),
            ]);

        $groups = Permission::orderBy('id')
            ->get()
            ->groupBy('group')
            ->map(fn ($permissions, $name) => [
                'name' => $name,
                'permissions' => $permissions->map(fn (Permission $permission) => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'slug' => $permission->slug,
                    'group' => $permission->group,
                    'description' => $permission->description,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return $this->success([
            'roles' => $roles,
            'permission_groups' => $groups,
        ]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        Gate::authorize('access.manage');

        if ($role->isAdministrator()) {
            return $this->error('Administrator selalu memiliki seluruh izin.', 422);
        }

        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'exists:permissions,slug'],
        ]);

        $ids = Permission::whereIn('slug', $validated['permissions'])
            ->pluck('id')
            ->all();
        $changes = $role->permissions()->sync($ids);

        ActivityLog::record(
            'permissions_updated',
            "{$role->name}: +".count($changes['attached']).' izin, -'.count($changes['detached']).' izin',
            $role,
            meta: [
                'added' => Permission::whereIn('id', $changes['attached'])->pluck('slug')->all(),
                'removed' => Permission::whereIn('id', $changes['detached'])->pluck('slug')->all(),
            ],
        );

        return $this->success([
            'role' => [
                'id' => $role->id,
                'slug' => $role->slug,
                'permissions' => $role->permissions()->pluck('slug')->all(),
            ],
        ], 'Hak akses berhasil diperbarui.');
    }
}
