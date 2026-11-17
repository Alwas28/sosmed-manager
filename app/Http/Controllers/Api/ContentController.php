<?php

namespace App\Http\Controllers\Api;

use App\Enums\ContentCategory;
use App\Enums\ContentStatus;
use App\Enums\Platform;
use App\Models\Content;
use App\Models\ContentApproval;
use App\Models\ContentPlatform;
use App\Services\Content\ContentApprovalService;
use App\Services\Content\ContentPublishService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ContentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Content::with([
            'creator:id,name,role_id',
            'creator.role:id,name,slug',
            'approver:id,name',
            'media',
            'platforms',
        ])->latest();

        if ($request->filled('status')) {
            $status = ContentStatus::tryFrom((string) $request->input('status'));
            if (! $status) {
                return $this->error('Status tidak valid.', 422);
            }
            $query->where('status', $status->value);
        }

        if ($request->filled('category')) {
            $category = ContentCategory::tryFrom((string) $request->input('category'));
            if (! $category) {
                return $this->error('Kategori tidak valid.', 422);
            }
            $query->where('category', $category->value);
        }

        if ($request->boolean('mine')) {
            $query->where('created_by', $request->user()->id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);
        $paginator = $query->paginate($perPage);

        return $this->success([
            'items' => array_map(
                fn (Content $content) => $this->payload($content),
                $paginator->items()
            ),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Content $content): JsonResponse
    {
        return $this->success($this->payload($content->loadMissing([
            'creator:id,name,role_id',
            'creator.role:id,name,slug',
            'approver:id,name',
            'approvals.reviewer:id,name',
            'logs',
            'media',
            'platforms',
        ])));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'caption' => ['nullable', 'string'],
            'category' => ['required', 'string'],
            'link_url' => ['nullable', 'url', 'max:255'],
            'link_title' => ['nullable', 'string', 'max:255'],
            'link_description' => ['nullable', 'string'],
            'platforms' => ['sometimes', 'array'],
            'platforms.*.platform' => ['required_with:platforms', 'string'],
            'platforms.*.post_type' => ['nullable', 'string', 'max:50'],
            'platforms.*.social_channel_id' => ['nullable', 'integer', 'exists:social_channels,id'],
        ]);

        $category = ContentCategory::tryFrom($data['category']);
        if (! $category) {
            return $this->error('Kategori tidak valid.', 422);
        }

        $error = $this->validatePlatforms($data['platforms'] ?? []);
        if ($error !== null) {
            return $this->error($error, 422);
        }

        $content = DB::transaction(function () use ($data, $request, $category) {
            $content = Content::create([
                'title' => $data['title'],
                'caption' => $data['caption'] ?? null,
                'category' => $category->value,
                'status' => ContentStatus::Draft->value,
                'created_by' => $request->user()->id,
                'link_url' => $data['link_url'] ?? null,
                'link_title' => $data['link_title'] ?? null,
                'link_description' => $data['link_description'] ?? null,
            ]);

            $this->syncPlatforms($content, $data['platforms'] ?? []);
            $content->logActivity('created', null, ContentStatus::Draft->value);

            return $content;
        });

        return $this->success($this->payload($content->load([
            'creator:id,name,role_id',
            'creator.role:id,name,slug',
            'media',
            'platforms',
        ])), 'Konten dibuat sebagai draft.', 201);
    }

    public function update(Request $request, Content $content): JsonResponse
    {
        $user = $request->user();
        $isOwner = $content->created_by === $user->id;
        $canEditRole = $user->isAdministrator() || $user->hasPermission('content.edit');
        $editorFinishing = $canEditRole
            && in_array($content->status, [ContentStatus::PendingApproval, ContentStatus::Revision], true);

        if (! $canEditRole && ! ($isOwner && $content->status->isEditable())) {
            return $this->error('Anda tidak memiliki akses menyunting konten ini.', 403);
        }

        if ($canEditRole && ! $content->status->isEditable() && ! $editorFinishing) {
            return $this->error('Status konten tidak dapat disunting.', 422);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'caption' => ['sometimes', 'nullable', 'string'],
            'category' => ['sometimes', 'string'],
            'link_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'link_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'link_description' => ['sometimes', 'nullable', 'string'],
            'submit' => ['sometimes', 'boolean'],
            'platforms' => ['sometimes', 'array'],
            'platforms.*.platform' => ['required_with:platforms', 'string'],
            'platforms.*.post_type' => ['nullable', 'string', 'max:50'],
            'platforms.*.social_channel_id' => ['nullable', 'integer', 'exists:social_channels,id'],
        ]);

        if (array_key_exists('category', $data)) {
            $category = ContentCategory::tryFrom($data['category']);
            if (! $category) {
                return $this->error('Kategori tidak valid.', 422);
            }
            $data['category'] = $category->value;
        }

        if (isset($data['platforms'])) {
            $error = $this->validatePlatforms($data['platforms']);
            if ($error !== null) {
                return $this->error($error, 422);
            }
        }

        $submit = (bool) ($data['submit'] ?? false);
        unset($data['submit'], $data['platforms']);

        DB::transaction(function () use ($content, $data, $request, $submit, $editorFinishing) {
            $from = $content->status->value;
            $content->fill($data);

            if ($submit && $content->status->canSubmit()) {
                $content->status = ContentStatus::PendingApproval;
                $content->scheduled_at = null;
            } elseif ($submit && $editorFinishing) {
                $content->status = ContentStatus::Approved;
                $content->approved_by = $request->user()->id;
                $content->scheduled_at = null;
            }

            $content->save();

            if (isset($request->platforms)) {
                $this->syncPlatforms($content, $request->platforms);
            }

            if ($submit) {
                $content->logActivity(
                    $editorFinishing ? 'approved' : 'submitted',
                    $from,
                    $content->status->value,
                    $editorFinishing ? 'Editor menyunting dan menyetujui.' : null
                );
            } else {
                $content->logActivity('updated', $from, $content->status->value);
            }
        });

        return $this->success($this->payload($content->refresh()->load([
            'creator:id,name,role_id',
            'creator.role:id,name,slug',
            'approver:id,name',
            'media',
            'platforms',
        ])));
    }

    public function destroy(Content $content): JsonResponse
    {
        $content->logActivity('deleted', $content->status->value, null);
        $content->delete();

        return $this->success(null, 'Konten dihapus.');
    }

    public function submit(Request $request, Content $content): JsonResponse
    {
        if (! $content->status->canSubmit()) {
            return $this->error('Hanya konten Draft atau Perlu Revisi yang bisa diajukan.', 422);
        }

        if (! $content->status->isEditable()) {
            return $this->error('Status konten tidak dapat diajukan.', 422);
        }

        $from = $content->status->value;
        $content->status = ContentStatus::PendingApproval;
        $content->scheduled_at = null;
        $content->save();
        $content->logActivity('submitted', $from, ContentStatus::PendingApproval->value);

        return $this->success($this->payload($content->load([
            'creator:id,name,role_id',
            'creator.role:id,name,slug',
            'approver:id,name',
            'media',
            'platforms',
        ])), 'Konten diajukan ke Editor.');
    }

    public function approve(Request $request, Content $content): JsonResponse
    {
        if ($content->status !== ContentStatus::PendingApproval) {
            return $this->error('Konten tidak sedang menunggu persetujuan Editor.', 422);
        }

        app(ContentApprovalService::class)->approve(
            $content,
            $request->user(),
            $request->input('note')
        );

        return $this->success($this->payload($content->refresh()->load([
            'creator:id,name,role_id',
            'creator.role:id,name,slug',
            'approver:id,name',
            'media',
            'platforms',
        ])), 'Konten disetujui Editor.');
    }

    public function requestRevision(Request $request, Content $content): JsonResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ]);

        if ($content->status !== ContentStatus::Approved) {
            return $this->error('Publisher hanya dapat meminta revisi pada konten yang sudah disetujui Editor.', 422);
        }

        DB::transaction(function () use ($content, $request, $data) {
            $from = $content->status->value;
            $content->status = ContentStatus::Revision;
            $content->approved_by = null;
            $content->scheduled_at = null;
            $content->save();

            ContentApproval::create([
                'content_id' => $content->id,
                'reviewer_id' => $request->user()->id,
                'decision' => 'revision',
                'note' => $data['note'],
            ]);

            $content->logActivity(
                'revision_requested',
                $from,
                ContentStatus::Revision->value,
                $data['note']
            );
        });

        return $this->success($this->payload($content->refresh()->load([
            'creator:id,name,role_id',
            'creator.role:id,name,slug',
            'approver:id,name',
            'media',
            'platforms',
        ])), 'Revisi diminta. Editor akan memperbaikinya.');
    }

    public function schedule(Request $request, Content $content): JsonResponse
    {
        $data = $request->validate([
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);

        try {
            app(ContentPublishService::class)->schedule(
                $content,
                $request->user(),
                Carbon::parse($data['scheduled_at'])
            );
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->payload($content->refresh()->load([
            'creator:id,name,role_id',
            'creator.role:id,name,slug',
            'approver:id,name',
            'media',
            'platforms',
        ])), 'Jadwal publikasi disimpan.');
    }

    public function publish(Request $request, Content $content): JsonResponse
    {
        $service = app(ContentPublishService::class);

        if ($request->boolean('via_buffer')) {
            try {
                $service->publishViaBuffer($content, $request->user());
            } catch (\Throwable $e) {
                return $this->error($e->getMessage(), 422);
            }
        } else {
            try {
                $service->markPublishedManually($content, $request->user());
            } catch (\Throwable $e) {
                return $this->error($e->getMessage(), 422);
            }
        }

        return $this->success($this->payload($content->refresh()->load([
            'creator:id,name,role_id',
            'creator.role:id,name,slug',
            'approver:id,name',
            'media',
            'platforms',
        ])), 'Konten dipublikasikan.');
    }

    public function logs(Content $content): JsonResponse
    {
        return $this->success($content->loadMissing('logs')->logs->map(fn ($log) => [
            'id' => $log->id,
            'action' => $log->action,
            'from' => $log->from_status,
            'to' => $log->to_status,
            'note' => $log->note,
            'created_at' => $log->created_at?->toISOString(),
        ])->values()->all());
    }

    private function payload(Content $content): array
    {
        return [
            'id' => $content->id,
            'title' => $content->title,
            'caption' => $content->caption,
            'category' => [
                'value' => $content->category->value,
                'label' => $content->category->label(),
            ],
            'status' => [
                'value' => $content->status->value,
                'label' => $content->status->label(),
            ],
            'revision_note' => $content->pendingRevisionNote(),
            'scheduled_at' => $content->scheduled_at?->toISOString(),
            'published_at' => $content->published_at?->toISOString(),
            'buffer_post_ids' => $content->buffer_post_ids ?? [],
            'link_url' => $content->link_url,
            'link_title' => $content->link_title,
            'link_description' => $content->link_description,
            'author' => $content->creator ? [
                'id' => $content->creator->id,
                'name' => $content->creator->name,
                'role' => $content->creator->role?->slug,
            ] : null,
            'approver' => $content->approver ? [
                'id' => $content->approver->id,
                'name' => $content->approver->name,
            ] : null,
            'media' => $content->media->map(fn ($media) => [
                'id' => $media->id,
                'name' => $media->original_name,
                'type' => $media->type,
                'url' => $media->url(),
            ])->values()->all(),
            'platforms' => $content->platforms->map(fn ($platform) => [
                'id' => $platform->id,
                'platform' => $platform->platform,
                'label' => Platform::tryFrom($platform->platform)?->label() ?? $platform->platform,
                'post_type' => $platform->post_type,
                'social_channel_id' => $platform->social_channel_id,
            ])->values()->all(),
            'created_at' => $content->created_at?->toISOString(),
            'updated_at' => $content->updated_at?->toISOString(),
        ];
    }

    private function validatePlatforms(array $platforms): ?string
    {
        foreach ($platforms as $item) {
            $platform = Platform::tryFrom((string) ($item['platform'] ?? ''));
            if (! $platform) {
                return 'Platform tidak valid: '.($item['platform'] ?? '').'.';
            }
        }

        return null;
    }

    private function syncPlatforms(Content $content, array $platforms): void
    {
        $rows = [];
        foreach ($platforms as $item) {
            $platform = Platform::tryFrom((string) $item['platform']);
            $rows[] = [
                'content_id' => $content->id,
                'platform' => $platform->value,
                'post_type' => $item['post_type'] ?? 'post',
                'social_channel_id' => $item['social_channel_id'] ?? null,
            ];
        }

        $content->platforms()->delete();
        if ($rows !== []) {
            ContentPlatform::insert($rows);
        }
    }
}
