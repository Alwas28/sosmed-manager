<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_template_settings', function (Blueprint $table) {
            // Both in px, measured at a 1080px-wide reference photo (same
            // convention as headline_font_size) — scaled proportionally to
            // the actual photo width at render time. Defaults reproduce the
            // exact size the old headline-derived formula used to compute,
            // so nobody's existing look changes until they touch these.
            $table->unsignedSmallInteger('social_icon_size')->default(29)->after('icon_bg_color');
            $table->unsignedSmallInteger('social_username_font_size')->default(9)->after('social_icon_size');
        });
    }

    public function down(): void
    {
        Schema::table('post_template_settings', function (Blueprint $table) {
            $table->dropColumn(['social_icon_size', 'social_username_font_size']);
        });
    }
};
