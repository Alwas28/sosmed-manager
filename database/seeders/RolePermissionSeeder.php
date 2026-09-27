<?php

namespace Database\Seeders;

use App\Enums\Platform;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            'Konten' => [
                'content.view' => 'Melihat konten',
                'content.create' => 'Membuat konten',
                'content.edit' => 'Mengubah konten',
                'content.delete' => 'Menghapus konten',
                'content.submit' => 'Mengirim konten untuk approval',
            ],
            'Approval' => [
                'approval.view' => 'Melihat antrian approval',
                'approval.approve' => 'Menyetujui konten',
                'approval.reject' => 'Menolak / meminta revisi konten',
            ],
            'Penjadwalan & Publikasi' => [
                'schedule.manage' => 'Menjadwalkan konten',
                'publish.manage' => 'Mempublikasikan konten ke Buffer',
            ],
            'Media' => [
                'media.view' => 'Melihat pustaka media',
                'media.upload' => 'Mengunggah media',
                'media.delete' => 'Menghapus media',
            ],
            'Kalender' => [
                'calendar.view' => 'Melihat kalender konten',
            ],
            'Social & Buffer' => [
                'social.view' => 'Melihat akun social media',
                'social.manage' => 'Mengelola akun social media',
                'buffer.manage' => 'Mengelola integrasi Buffer',
            ],
            'Laporan' => [
                'report.view' => 'Melihat laporan',
                'report.export' => 'Mengunduh / ekspor laporan',
            ],
            'Pengguna & Akses' => [
                'user.view' => 'Melihat daftar pengguna',
                'user.manage' => 'Mengelola pengguna & assign role',
                'user.platform' => 'Mengatur akses platform posting tiap pengguna',
                'role.view' => 'Melihat daftar role',
                'role.manage' => 'Membuat / mengubah / menghapus role',
                'access.manage' => 'Mengatur akses kontrol tiap role',
                'log.view' => 'Melihat log aktivitas',
            ],
            'Asisten AI' => [
                'ai.use' => 'Memakai asisten AI di form konten',
                'ai.manage' => 'Mengatur integrasi AI (provider & model)',
                'image_ai.manage' => 'Mengatur generate gambar AI',
                'template.manage' => 'Mengatur template postingan',
            ],
        ];

        // Akses posting per platform & jenis postingan (mis. post.instagram.story).
        $postSlugs = [];

        foreach (Platform::cases() as $platform) {
            foreach ($platform->postPermissions() as $slug => $name) {
                $groups['Akses Sosial Media'][$slug] = $name;
                $postSlugs[] = $slug;
            }
        }

        $allSlugs = [];

        foreach ($groups as $group => $permissions) {
            foreach ($permissions as $slug => $name) {
                Permission::updateOrCreate(
                    ['slug' => $slug],
                    ['name' => $name, 'group' => $group],
                );
                $allSlugs[] = $slug;
            }
        }

        Permission::whereNotIn('slug', $allSlugs)->delete();

        $roles = [
            [
                'name' => 'Administrator',
                'slug' => 'administrator',
                'is_locked' => true,
                'description' => 'Akses penuh ke seluruh sistem.',
                'permissions' => $allSlugs,
            ],
            [
                'name' => 'Content Creator',
                'slug' => 'content-creator',
                'is_locked' => true,
                'description' => 'Membuat dan menyunting konten serta media.',
                'permissions' => [
                    'calendar.view',
                    'content.view', 'content.create', 'content.edit', 'content.delete', 'content.submit',
                    'media.view', 'media.upload', 'media.delete',
                    'ai.use',
                    ...$postSlugs,
                ],
            ],
            [
                'name' => 'Reviewer',
                'slug' => 'reviewer',
                'is_locked' => true,
                'description' => 'Meninjau, menyetujui, atau menolak konten.',
                'permissions' => [
                    'calendar.view', 'content.view',
                    'approval.view', 'approval.approve', 'approval.reject', 'report.view', 'report.export',
                ],
            ],
            [
                'name' => 'Publisher',
                'slug' => 'publisher',
                'is_locked' => true,
                'description' => 'Menjadwalkan dan mempublikasikan konten via Buffer.',
                'permissions' => [
                    'calendar.view', 'content.view',
                    'schedule.manage', 'publish.manage', 'social.view', 'buffer.manage', 'report.view', 'report.export',
                    ...$postSlugs,
                ],
            ],
        ];

        foreach ($roles as $data) {
            $permissions = $data['permissions'];
            unset($data['permissions']);

            $role = Role::updateOrCreate(['slug' => $data['slug']], $data);
            $role->permissions()->sync(
                Permission::whereIn('slug', $permissions)->pluck('id')
            );
        }
    }
}
