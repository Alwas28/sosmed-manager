<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_template_settings', function (Blueprint $table) {
            // Both stored as a percentage of the photo's width/height.
            $table->decimal('card_margin_x', 4, 2)->default(4.50)->after('text_color');
            $table->decimal('card_margin_bottom', 4, 2)->default(3.00)->after('card_margin_x');
        });
    }

    public function down(): void
    {
        Schema::table('post_template_settings', function (Blueprint $table) {
            $table->dropColumn(['card_margin_x', 'card_margin_bottom']);
        });
    }
};
