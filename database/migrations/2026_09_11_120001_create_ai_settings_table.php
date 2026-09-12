<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('provider')->default('anthropic'); // anthropic | openai | gemini | openai_compatible
            $table->string('model')->nullable();
            $table->text('api_key')->nullable();     // stored encrypted (cast)
            $table->string('base_url')->nullable();  // override / self-hosted endpoint
            $table->unsignedSmallInteger('max_tokens')->default(600);
            $table->decimal('temperature', 3, 2)->default(0.70);
            $table->text('system_prompt')->nullable(); // brand voice / tone
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
