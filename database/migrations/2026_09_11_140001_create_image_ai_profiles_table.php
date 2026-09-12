<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_ai_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('label');           // e.g. "OpenAI gpt-image-1"
            $table->string('provider');        // openai | gemini
            $table->string('model');
            $table->text('api_key')->nullable();   // stored encrypted (cast)
            $table->string('base_url')->nullable();
            $table->string('size')->default('1024x1024'); // OpenAI only, ignored by drivers that don't use it
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_ai_profiles');
    }
};
