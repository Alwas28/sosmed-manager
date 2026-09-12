<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_template_settings', function (Blueprint $table) {
            // Map of platform slug => handle, e.g. {"facebook":"@kendariinfo",
            // "twitter":"@kendari_info"} — usernames often differ per
            // platform, so this is keyed per platform rather than a single
            // shared handle.
            $table->json('social_usernames')->nullable()->after('social_platforms');
        });
    }

    public function down(): void
    {
        Schema::table('post_template_settings', function (Blueprint $table) {
            $table->dropColumn('social_usernames');
        });
    }
};
