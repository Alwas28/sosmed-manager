<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_template_settings', function (Blueprint $table) {
            // 'brand' (default) = each platform keeps its own real brand
            // colour, as before. 'custom' = every icon uses $icon_color.
            $table->string('icon_style', 10)->default('brand')->after('social_usernames');
            $table->string('icon_color', 7)->default('#FFFFFF')->after('icon_style');
            // The circle behind each icon — white by default (unchanged
            // look). Setting it equal to the banner colour makes the circle
            // blend away, leaving just the icon glyph floating on the card.
            $table->string('icon_bg_color', 7)->default('#FFFFFF')->after('icon_color');
        });
    }

    public function down(): void
    {
        Schema::table('post_template_settings', function (Blueprint $table) {
            $table->dropColumn(['icon_style', 'icon_color', 'icon_bg_color']);
        });
    }
};
