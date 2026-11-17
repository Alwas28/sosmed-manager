<?php

namespace App\Http\Controllers\Api;

use App\Enums\ContentCategory;
use App\Enums\ContentStatus;
use App\Enums\Platform;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;

class ReferenceController extends ApiController
{
    /** Opsi enum dan grup izin untuk kebutuhan form aplikasi. */
    public function index(): JsonResponse
    {
        return $this->success([
            'content_statuses' => collect(ContentStatus::cases())->map(fn (ContentStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
                'editable' => $status->isEditable(),
                'can_submit' => $status->canSubmit(),
            ]),
            'content_categories' => collect(ContentCategory::cases())->map(fn (ContentCategory $category) => [
                'value' => $category->value,
                'label' => $category->label(),
                'icon' => $category->icon(),
            ]),
            'platforms' => collect(Platform::cases())->map(fn (Platform $platform) => [
                'value' => $platform->value,
                'label' => $platform->label(),
                'icon' => $platform->icon(),
                'post_types' => $platform->postTypes(),
                'requires_media' => $platform->requiresMedia(),
                'caption_limit' => $platform->captionLimit(),
            ]),
            'permission_groups' => Permission::orderBy('id')
                ->get()
                ->groupBy('group')
                ->map(fn ($permissions, $group) => [
                    'name' => $group,
                    'permissions' => $permissions->map(fn (Permission $permission) => [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'slug' => $permission->slug,
                        'group' => $permission->group,
                        'description' => $permission->description,
                    ])->values(),
                ])
                ->values(),
        ]);
    }
}
