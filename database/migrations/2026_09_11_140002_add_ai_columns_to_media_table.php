<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('source')->default('upload')->after('type'); // upload | ai_generated | ai_edited
            $table->string('ai_model')->nullable()->after('source');    // e.g. "openai:gpt-image-1"
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(['source', 'ai_model']);
        });
    }
};
