<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowStage extends Model
{
    protected $fillable = [
        'slug',
        'label',
        'role_slug',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Peran yang berhak mengambil keputusan approval pada tahap `review`.
     * Null berarti tidak ada pembatasan peran (kompatibel dengan data lama).
     */
    public static function approverRole(): ?string
    {
        $stage = static::query()->where('slug', 'review')->first();

        if (! $stage || ! $stage->is_active) {
            return null;
        }

        return $stage->role_slug;
    }
}
