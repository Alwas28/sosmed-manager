<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_template_settings', function (Blueprint $table) {
            // Fixed card height as a percentage of the photo's height — the
            // card no longer grows with a long caption; the headline text
            // shrinks (and, as a last resort, truncates) to fit instead.
            $table->decimal('card_height', 4, 2)->default(26.00)->after('card_margin_bottom');
        });
    }

    public function down(): void
    {
        Schema::table('post_template_settings', function (Blueprint $table) {
            $table->dropColumn('card_height');
        });
    }
};
