<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = ActivityLog::with('user:id,name,role_id')
            ->with('user.role:id,name,slug')
            ->latest('created_at');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        $limit = min(max($request->integer('limit', 50), 1), 200);
        $items = $query->limit($limit)->get()->map(fn (ActivityLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'action_label' => ActivityLog::labelFor($log->action),
            'category' => $log->category,
            'description' => $log->description,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
                'role' => $log->user->role?->slug,
            ] : null,
            'meta' => $log->meta ?? [],
            'created_at' => $log->created_at?->toISOString(),
        ])->values()->all();

        return $this->success($items);
    }
}
