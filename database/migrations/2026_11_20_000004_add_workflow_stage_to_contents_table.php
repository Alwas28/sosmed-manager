<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->string('workflow_stage')->default('wartawan')->index()->after('status');
            $table->timestamp('stage_due_at')->nullable()->after('workflow_stage');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['workflow_stage', 'stage_due_at']);
        });
    }
};
