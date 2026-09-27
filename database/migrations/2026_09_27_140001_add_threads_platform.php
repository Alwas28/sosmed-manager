<?php

use App\Enums\Platform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string GROUP = 'Akses Sosial Media';

    /** Sama seperti migrasi izin sosial media sebelumnya: role yang sudah bisa membuat/publikasi konten ikut dapat akses platform baru. */
    private const array INHERIT_FROM = ['content.create', 'publish.manage'];

    public function up(): void
    {
        $sourceIds = DB::table('permissions')->whereIn('slug', self::INHERIT_FROM)->pluck('id');
        $roleIds = DB::table('permission_role')->whereIn('permission_id', $sourceIds)->distinct()->pluck('role_id');

        foreach (Platform::Threads->postPermissions() as $slug => $name) {
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
        DB::table('permissions')->whereIn('slug', array_keys(Platform::Threads->postPermissions()))->delete();
    }
};
