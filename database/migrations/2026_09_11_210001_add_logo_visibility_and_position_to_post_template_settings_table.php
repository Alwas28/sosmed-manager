<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_template_settings', function (Blueprint $table) {
            // Default true / 'top-left' preserves the current behaviour for
            // everyone who already has a logo configured.
            $table->boolean('show_logo')->default(true)->after('font_path');
            $table->string('logo_position', 20)->default('top-left')->after('show_logo');
        });
    }

    public function down(): void
    {
        Schema::table('post_template_settings', function (Blueprint $table) {
            $table->dropColumn(['show_logo', 'logo_position']);
        });
    }
};
