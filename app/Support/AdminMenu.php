<?php

namespace App\Support;

use App\Models\User;

/**
 * Single source of truth for the admin sidebar.
 *
 * Each item maps a route to the permission that unlocks it. The sidebar
 * renders from here (hiding items / empty sections the user can't access)
 * and routes/web.php reads the same list to attach `can:` middleware, so
 * the menu and the route guards can never drift apart.
 */
class AdminMenu
{
    /**
     * @return array<string, list<array{label: string, icon: string, route: string, uri: string, permission: ?string}>>
     */
    public static function sections(): array
    {
        return [
            'Utama' => [
                self::item('Dashboard', 'fa-table-columns', 'dashboard', 'dashboard', null),
                self::item('Kalender Konten', 'fa-calendar-days', 'calendar', 'kalender', 'calendar.view'),
            ],
            'Konten' => [
                self::item('Semua Konten', 'fa-file-lines', 'konten.index', 'konten', 'content.view'),
                self::item('Draft', 'fa-pen-ruler', 'konten.draft', 'konten/draft', 'content.view'),
                self::item('Menunggu Approval', 'fa-hourglass-half', 'konten.approval', 'konten/menunggu-approval', 'content.view'),
                self::item('Perlu Revisi', 'fa-pen-to-square', 'konten.revision', 'konten/revisi', 'content.view'),
                self::item('Disetujui', 'fa-circle-check', 'konten.approved', 'konten/disetujui', 'content.view'),
                self::item('Terjadwal', 'fa-clock', 'konten.scheduled', 'konten/terjadwal', 'content.view'),
                self::item('Dipublikasikan', 'fa-paper-plane', 'konten.published', 'konten/dipublikasikan', 'content.view'),
                self::item('Gagal', 'fa-triangle-exclamation', 'konten.failed', 'konten/gagal', 'content.view'),
            ],
            'Produksi' => [
                self::item('Pustaka Media', 'fa-photo-film', 'media', 'media', 'media.view'),
                self::item('Antrian Approval', 'fa-list-check', 'approval.queue', 'approval', 'approval.view'),
            ],
            'Distribusi' => [
                self::item('Akun Social Media', 'fa-hashtag', 'social.accounts', 'social-media', 'social.view'),
                self::item('Integrasi Buffer', 'fa-plug', 'buffer', 'buffer', 'buffer.manage'),
            ],
            'Analitik' => [
                self::item('Laporan', 'fa-chart-line', 'reports', 'laporan', 'report.view'),
            ],
            'Pengaturan' => [
                self::item('Pengguna', 'fa-users', 'users.index', 'pengguna', 'user.view'),
                self::item('Role Akses', 'fa-user-shield', 'roles.index', 'role-akses', 'role.view'),
                self::item('Akses Kontrol', 'fa-key', 'access-control.index', 'akses-kontrol', 'access.manage'),
                self::item('Integrasi AI', 'fa-robot', 'ai.settings', 'pengaturan/ai', 'ai.manage'),
                self::item('Generate Gambar AI', 'fa-image', 'image-ai.settings', 'pengaturan/generate-gambar', 'ai.manage'),
                self::item('Template Postingan', 'fa-stamp', 'post-template.settings', 'pengaturan/template-postingan', 'ai.manage'),
                self::item('Log Aktivitas', 'fa-clock-rotate-left', 'logs', 'log-aktivitas', 'log.view'),
            ],
        ];
    }

    /**
     * Sections (and their items) the given user is allowed to see.
     *
     * @return array<string, list<array{label: string, icon: string, route: string, uri: string, permission: ?string}>>
     */
    public static function visibleSections(?User $user): array
    {
        $visible = [];

        foreach (self::sections() as $section => $items) {
            $allowed = array_values(array_filter(
                $items,
                fn (array $item) => $item['permission'] === null || (bool) $user?->hasPermission($item['permission']),
            ));

            if ($allowed !== []) {
                $visible[$section] = $allowed;
            }
        }

        return $visible;
    }

    /**
     * @return array{label: string, icon: string, route: string, uri: string, permission: ?string}
     */
    private static function item(string $label, string $icon, string $route, string $uri, ?string $permission): array
    {
        return compact('label', 'icon', 'route', 'uri', 'permission');
    }
}
