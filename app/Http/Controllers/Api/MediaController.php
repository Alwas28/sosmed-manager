<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Media::withCount('contents')->latest();

        if ($request->filled('type')) {
            $type = (string) $request->input('type');
            if (! in_array($type, ['image', 'video'], true)) {
                return $this->error('Tipe media tidak valid.', 422);
            }
            $query->where('type', $type);
        }

        if ($request->filled('q')) {
            $search = trim((string) $request->input('q'));
            $query->where('original_name', 'like', '%'.$search.'%');
        }

        $perPage = min(max((int) $request->integer('per_page', 24), 1), 100);
        $paginator = $query->paginate($perPage);

        return $this->success([
            'items' => array_map(fn (Media $media) => $this->payload($media), $paginator->items()),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'max:20480', 'mimes:jpg,jpeg,png,gif,webp,mp4,mov,webm'],
        ]);

        $created = [];
        foreach ($request->file('files') as $file) {
            $media = Media::create([
                'disk' => 'public',
                'path' => $file->store('media/'.now()->format('Y/m'), 'public'),
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'type' => str_starts_with((string) $file->getMimeType(), 'video') ? 'video' : 'image',
                'size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);

            ActivityLog::record('media_uploaded', $media->original_name, $media);
            $created[] = $this->payload($media);
        }

        return $this->success($created, 'Media diunggah.', 201);
    }

    public function destroy(Media $media): JsonResponse
    {
        if ($media->contents()->count() > 0) {
            return $this->error('Media ini masih dipakai konten dan tidak bisa dihapus.', 422);
        }

        ActivityLog::record('media_deleted', $media->original_name);
        $media->delete();

        return $this->success(null, 'Media dihapus.');
    }

    /** @return array<string, mixed> */
    private function payload(Media $media): array
    {
        return [
            'id' => $media->id,
            'name' => $media->original_name,
            'type' => $media->type,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'width' => $media->width,
            'height' => $media->height,
            'source' => $media->source,
            'url' => $media->url(),
            'is_ai_made' => $media->isAiMade(),
            'is_template_composed' => $media->isTemplateComposed(),
            'contents_count' => $media->contents_count ?? $media->contents()->count(),
            'created_at' => $media->created_at?->toISOString(),
        ];
    }
}
