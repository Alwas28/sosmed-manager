<?php

use App\Enums\Platform;
use App\Enums\PostType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string GROUP = 'Akses Sosial Media';

    /** Sama seperti migrasi izin sosial media sebelumnya: role yang sudah bisa membuat/publikasi konten ikut dapat akses jenis postingan baru. */
    private const array INHERIT_FROM = ['content.create', 'publish.manage'];

    public function up(): void
    {
        $sourceIds = DB::table('permissions')->whereIn('slug', self::INHERIT_FROM)->pluck('id');
        $roleIds = DB::table('permission_role')->whereIn('permission_id', $sourceIds)->distinct()->pluck('role_id');

        foreach (Platform::cases() as $platform) {
            if (! $platform->supportsLinkPost()) {
                continue;
            }

            $slug = $platform->postPermission(PostType::Link);
            $name = $platform->postPermissions()[$slug];

            DB::table('permissions')->updateOrInsert(
                ['slug' => $slug],
                ['name' => $name, 'group' => self::GROUP, 'created_at' => now(), 'updated_at' => now()],
            );

            $id = DB::table('permissions')->where('slug', $slug)->value('id');

            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        foreach (Platform::cases() as $platform) {
            if ($platform->supportsLinkPost()) {
                DB::table('permissions')->where('slug', $platform->postPermission(PostType::Link))->delete();
            }
        }
    }
};
