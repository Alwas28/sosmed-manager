<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

abstract class ApiController extends Controller
{
    /** @param mixed $data */
    protected function success($data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /** @param mixed $errors */
    protected function error(string $message, int $status = 400, $errors = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    /** @return array<string, mixed> */
    protected function userPayload(User $user): array
    {
        $user->loadMissing('role.permissions');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'active' => (bool) $user->is_active,
            'role' => [
                'id' => $user->role?->id,
                'name' => $user->role?->name,
                'slug' => $user->role?->slug,
                'label' => $user->role?->name ?? 'Tanpa peran',
            ],
            'role_id' => $user->role_id,
            'allowed_platforms' => $user->allowed_platforms ?? [],
            'permissions' => $this->permissionSlugs($user),
            'is_administrator' => $user->isAdministrator(),
        ];
    }

    /** @return array<int, string> */
    protected function permissionSlugs(User $user): array
    {
        if ($user->isAdministrator()) {
            return \App\Models\Permission::query()->pluck('slug')->all();
        }

        return $user->role?->permissions->pluck('slug')->all() ?? [];
    }
}
