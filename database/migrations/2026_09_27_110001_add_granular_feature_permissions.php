<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** slug => [name, group, existing permission whose holders inherit it] */
    private const array NEW_PERMISSIONS = [
        'report.export' => ['Mengunduh / ekspor laporan', 'Laporan', 'report.view'],
        'template.manage' => ['Mengatur template postingan', 'Asisten AI', 'ai.manage'],
        'image_ai.manage' => ['Mengatur generate gambar AI', 'Asisten AI', 'ai.manage'],
        'user.platform' => ['Mengatur akses platform posting tiap pengguna', 'Pengguna & Akses', 'user.manage'],
    ];

    public function up(): void
    {
        foreach (self::NEW_PERMISSIONS as $slug => [$name, $group, $inheritFrom]) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $slug],
                ['name' => $name, 'group' => $group, 'created_at' => now(), 'updated_at' => now()],
            );

            $newId = DB::table('permissions')->where('slug', $slug)->value('id');
            $sourceId = DB::table('permissions')->where('slug', $inheritFrom)->value('id');

            if (! $sourceId) {
                continue;
            }

            $roleIds = DB::table('permission_role')->where('permission_id', $sourceId)->pluck('role_id');

            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $newId, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('slug', array_keys(self::NEW_PERMISSIONS))->delete();
    }
};
