<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_template_settings', function (Blueprint $table) {
            // Base headline font size in px, measured at a reference photo
            // width of 1080px — scaled proportionally to the actual photo's
            // width at render time. The compositor still auto-shrinks below
            // this if a long caption wouldn't otherwise fit the fixed card.
            $table->unsignedSmallInteger('headline_font_size')->default(40)->after('card_height');
        });
    }

    public function down(): void
    {
        Schema::table('post_template_settings', function (Blueprint $table) {
            $table->dropColumn('headline_font_size');
        });
    }
};
