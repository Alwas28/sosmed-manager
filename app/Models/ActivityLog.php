<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    /** Login / logout tidak dihitung sebagai "kesibukan" di dashboard. */
    public const PRESENCE_CATEGORY = 'auth';

    /** @var array<string, array{0: string, 1: string}> slug => [label, ikon] */
    public const CATEGORIES = [
        'auth' => ['Akun', 'fa-right-to-bracket'],
        'konten' => ['Konten', 'fa-file-lines'],
        'approval' => ['Approval', 'fa-list-check'],
        'media' => ['Media', 'fa-photo-film'],
        'akses' => ['Akses & Role', 'fa-user-shield'],
        'pengguna' => ['Pengguna', 'fa-users'],
        'integrasi' => ['Integrasi', 'fa-plug'],
        'lainnya' => ['Lainnya', 'fa-circle-info'],
    ];

    /** @var array<string, array{0: string, 1: string}> action => [label, kategori] */
    public const ACTIONS = [
        'login' => ['Masuk', 'auth'],
        'logout' => ['Keluar', 'auth'],
        'created' => ['Membuat konten', 'konten'],
        'updated' => ['Mengubah konten', 'konten'],
        'submitted' => ['Mengirim untuk approval', 'konten'],
        'withdrawn' => ['Menarik ke draft', 'konten'],
        'deleted' => ['Menghapus konten', 'konten'],
        'scheduled' => ['Menjadwalkan konten', 'konten'],
        'schedule_cancelled' => ['Membatalkan jadwal', 'konten'],
        'published' => ['Mempublikasikan konten', 'konten'],
        'publish_failed' => ['Publikasi gagal', 'konten'],
        'published_manually' => ['Menandai terbit manual', 'konten'],
        'approved' => ['Menyetujui konten', 'approval'],
        'revision_requested' => ['Meminta revisi', 'approval'],
        'media_uploaded' => ['Mengunggah media', 'media'],
        'media_deleted' => ['Menghapus media', 'media'],
        'role_created' => ['Membuat role', 'akses'],
        'role_updated' => ['Mengubah role', 'akses'],
        'role_deleted' => ['Menghapus role', 'akses'],
        'permissions_updated' => ['Mengubah izin role', 'akses'],
        'user_role_changed' => ['Mengganti role pengguna', 'pengguna'],
        'user_platform_changed' => ['Mengubah akses platform pengguna', 'pengguna'],
        'buffer_connected' => ['Menghubungkan Buffer', 'integrasi'],
        'buffer_disconnected' => ['Memutus Buffer', 'integrasi'],
        'buffer_synced' => ['Sinkron channel Buffer', 'integrasi'],
    ];

    protected $fillable = [
        'user_id', 'action', 'category', 'description', 'subject_type', 'subject_id', 'ip_address', 'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Catat aktivitas. Kegagalan mencatat tidak boleh menggagalkan aksi aslinya.
     */
    public static function record(string $action, ?string $description = null, ?Model $subject = null, ?int $userId = null, array $meta = []): ?self
    {
        try {
            return static::create([
                'user_id' => $userId ?? auth()->id(),
                'action' => $action,
                'category' => self::ACTIONS[$action][1] ?? 'lainnya',
                'description' => $description,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'ip_address' => app()->runningInConsole() ? null : request()->ip(),
                'meta' => $meta ?: null,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public static function labelFor(string $action): string
    {
        return self::ACTIONS[$action][0] ?? Str::headline($action);
    }

    public function actionLabel(): string
    {
        return self::labelFor($this->action);
    }

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORIES[$category][0] ?? Str::headline($category);
    }

    public static function categoryIcon(string $category): string
    {
        return 'fa-solid '.(self::CATEGORIES[$category][1] ?? 'fa-circle-info');
    }
}
