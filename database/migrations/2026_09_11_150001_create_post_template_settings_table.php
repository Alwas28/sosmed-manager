<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_template_settings', function (Blueprint $table) {
            $table->id();
            $table->string('logo_path')->nullable();
            $table->string('font_path')->nullable();
            $table->string('brand_name')->default('');
            $table->string('banner_color')->default('#0B5E34');
            $table->string('text_color')->default('#FFFFFF');
            $table->boolean('show_social_row')->default(true);
            $table->json('social_platforms')->nullable(); // Platform enum values to show as icons
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_template_settings');
    }
};
