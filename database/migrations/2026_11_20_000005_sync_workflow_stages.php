<?php

use App\Enums\WorkflowStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Alur kerja sekarang tetap empat tahap. Tabel workflow_stages hanya dipakai
     * sebagai referensi tampilan; source of truth ada di WorkflowStep.
     */
    public function up(): void
    {
        $slugs = [];

        foreach (WorkflowStep::stages() as $stage) {
            $slugs[] = $stage['slug'];
            DB::table('workflow_stages')->updateOrInsert(
                ['slug' => $stage['slug']],
                [
                    'label' => $stage['label'],
                    'role_slug' => $stage['role_slug'],
                    'sort_order' => $stage['sort_order'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        DB::table('workflow_stages')->whereNotIn('slug', $slugs)->delete();
    }

    public function down(): void
    {
        // Biarkan data tetap; migrasi 000003 yang menghapus tabel saat rollback.
    }
};
