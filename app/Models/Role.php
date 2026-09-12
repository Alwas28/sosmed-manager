<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'is_locked'];

    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isAdministrator(): bool
    {
        return $this->slug === 'administrator';
    }

    public function hasPermission(string $slug): bool
    {
        if ($this->isAdministrator()) {
            return true;
        }

        return $this->permissions->contains('slug', $slug);
    }
}
