<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buffer_connection_id')->constrained()->cascadeOnDelete();
            $table->string('buffer_profile_id')->unique();
            $table->string('service');            // facebook, instagram, twitter, linkedin, tiktok, ...
            $table->string('service_type')->nullable();
            $table->string('username')->nullable();
            $table->string('display_name')->nullable();
            $table->string('avatar')->nullable();
            $table->string('timezone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_channels');
    }
};
