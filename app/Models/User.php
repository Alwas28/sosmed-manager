<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Platform;
use App\Enums\PostType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role_id', 'allowed_platforms'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isAdministrator(): bool
    {
        return $this->role?->slug === 'administrator';
    }

    public function hasPermission(string $slug): bool
    {
        return (bool) $this->role?->hasPermission($slug);
    }

    /**
     * Boleh memposting ke platform ini? Dua lapis, keduanya harus lolos:
     *  1. izin role "post.{platform}.{jenis}" (menu Akses Kontrol), dan
     *  2. pembatasan per pengguna (users.allowed_platforms; null = tidak dibatasi).
     * Tanpa $type = cukup punya salah satu jenis postingan di platform itu.
     * Administrator selalu boleh.
     */
    public function canPostTo(string $platform, ?string $type = null): bool
    {
        if ($this->isAdministrator()) {
            return true;
        }

        if ($this->allowed_platforms !== null && ! in_array($platform, $this->allowed_platforms, true)) {
            return false;
        }

        $enum = Platform::tryFrom($platform);

        if (! $enum) {
            return false;
        }

        return $type === null
            ? $this->allowedPostTypes($enum) !== []
            : in_array($type, array_map(fn (PostType $t) => $t->value, $this->allowedPostTypes($enum)), true);
    }

    /** @return array<int, PostType> jenis postingan yang boleh dipakai user ini di platform tsb */
    public function allowedPostTypes(Platform $platform): array
    {
        if ($this->allowed_platforms !== null && ! $this->isAdministrator() && ! in_array($platform->value, $this->allowed_platforms, true)) {
            return [];
        }

        return array_values(array_filter(
            $platform->postTypes(),
            fn (PostType $t) => $this->hasPermission($platform->postPermission($t)) || $this->isAdministrator(),
        ));
    }

    /** @return array<int, string> */
    public function postablePlatforms(): array
    {
        return array_values(array_filter(Platform::values(), fn (string $p) => $this->canPostTo($p)));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'allowed_platforms' => 'array',
        ];
    }
}
