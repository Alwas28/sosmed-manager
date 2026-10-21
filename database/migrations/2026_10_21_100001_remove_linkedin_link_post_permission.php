<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Per kebutuhan klien (perbaikan.pdf): Berita Link cuma lewat X, Facebook,
 * Threads (+WA Bisnis, di luar Buffer) — LinkedIn tidak pernah disebut sama
 * sekali di seluruh dokumen. Platform::supportsLinkPost() sudah diubah untuk
 * mengecualikan LinkedIn; izin "post.linkedin.link" yang sempat dibuat
 * migrasi sebelumnya jadi yatim (tidak pernah dihasilkan lagi oleh enum),
 * jadi dibersihkan di sini supaya tidak nongol kosong di Akses Kontrol.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->where('slug', 'post.linkedin.link')->delete();
    }

    public function down(): void
    {
        DB::table('permissions')->updateOrInsert(
            ['slug' => 'post.linkedin.link'],
            ['name' => 'Posting LinkedIn — Bagikan Link', 'group' => 'Akses Sosial Media', 'created_at' => now(), 'updated_at' => now()],
        );
    }
};
