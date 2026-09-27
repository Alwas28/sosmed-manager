<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->string('category', 30)->default('lainnya');
            $table->text('description')->nullable();
            $table->nullableMorphs('subject');
            $table->string('ip_address', 45)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['category', 'created_at']);
        });

        // Bawa riwayat konten yang sudah ada supaya dashboard langsung punya data.
        DB::table('activity_logs')->insertUsing(
            ['user_id', 'action', 'category', 'description', 'subject_type', 'subject_id', 'created_at'],
            DB::table('content_logs')
                ->leftJoin('contents', 'contents.id', '=', 'content_logs.content_id')
                ->select([
                    'content_logs.user_id',
                    'content_logs.action',
                    DB::raw("CASE WHEN content_logs.action IN ('approved', 'revision_requested') THEN 'approval' ELSE 'konten' END"),
                    DB::raw("TRIM(CONCAT(COALESCE(contents.title, ''), CASE WHEN content_logs.note IS NULL OR content_logs.note = '' THEN '' ELSE CONCAT(' — ', content_logs.note) END))"),
                    DB::raw("'App\\\\Models\\\\Content'"),
                    'content_logs.content_id',
                    'content_logs.created_at',
                ]),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
