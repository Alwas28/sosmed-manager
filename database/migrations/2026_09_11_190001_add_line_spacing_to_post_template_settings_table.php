<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_template_settings', function (Blueprint $table) {
            // Line-height multiplier for the headline when it wraps to
            // multiple lines (e.g. 1.30 = 130% of the font size per line).
            $table->decimal('line_spacing', 3, 2)->default(1.30)->after('headline_font_size');
        });
    }

    public function down(): void
    {
        Schema::table('post_template_settings', function (Blueprint $table) {
            $table->dropColumn('line_spacing');
        });
    }
};
