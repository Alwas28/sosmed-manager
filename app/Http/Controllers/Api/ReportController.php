<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends ApiController
{
    public function activity(Request $request): JsonResponse
    {
        $range = (string) $request->input('range', 'today');
        $now = Carbon::now();

        $start = match ($range) {
            '7' => $now->copy()->subDays(6)->startOfDay(),
            '30' => $now->copy()->subDays(29)->startOfDay(),
            'year' => $now->copy()->startOfYear(),
            default => $now->copy()->startOfDay(),
        };

        $logs = ActivityLog::with('user:id,name,role_id')
            ->with('user.role:id,name,slug')
            ->whereBetween('created_at', [$start, $now])
            ->get();

        $perUser = [];
        foreach ($logs as $log) {
            if (! $log->user) {
                continue;
            }

            $id = $log->user->id;
            $perUser[$id] ??= [
                'user' => [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'role' => $log->user->role?->slug,
                    'role_label' => $log->user->role?->name,
                ],
                'total' => 0,
                'actions' => [],
            ];
            $perUser[$id]['total']++;
            $perUser[$id]['actions'][$log->action] =
                ($perUser[$id]['actions'][$log->action] ?? 0) + 1;
        }

        $summary = array_values($perUser);
        usort($summary, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $this->success([
            'range' => $range,
            'from' => $start->toISOString(),
            'to' => $now->toISOString(),
            'total_activities' => $logs->count(),
            'users' => $summary,
            'totals' => [
                'submitted' => $logs->where('action', 'submitted')->count(),
                'approved' => $logs->where('action', 'approved')->count(),
                'revision_requested' => $logs->where('action', 'revision_requested')->count(),
                'scheduled' => $logs->where('action', 'scheduled')->count(),
                'published' => $logs->whereIn('action', ['published', 'published_manually'])->count(),
            ],
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $query = User::with('role:id,name,slug')->withCount([
            'contents as total_contents' => fn ($q) => $q,
            'contents as published_contents' => fn ($q) => $q->where('status', 'published'),
        ])->latest();

        $items = $query->get()->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->slug,
            'role_label' => $user->role?->name,
            'total_contents' => $user->total_contents,
            'published_contents' => $user->published_contents,
        ])->values()->all();

        return $this->success($items);
    }
}
