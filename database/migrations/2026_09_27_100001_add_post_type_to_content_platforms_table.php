<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_platforms', function (Blueprint $table) {
            $table->string('post_type')->default('post')->after('platform'); // post, reel, story
        });
    }

    public function down(): void
    {
        Schema::table('content_platforms', function (Blueprint $table) {
            $table->dropColumn('post_type');
        });
    }
};
