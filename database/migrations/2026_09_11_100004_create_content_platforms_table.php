<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_platforms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->string('platform'); // facebook, instagram, twitter, linkedin, tiktok
            $table->foreignId('social_channel_id')->nullable()->constrained('social_channels')->nullOnDelete();
            $table->unique(['content_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_platforms');
    }
};
