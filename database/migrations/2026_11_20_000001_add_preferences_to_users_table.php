<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_push')->default(true)->after('is_active');
            $table->boolean('notify_device')->default(true)->after('notify_push');
            $table->boolean('notify_email')->default(false)->after('notify_device');
            $table->boolean('auto_sync_buffer')->default(true)->after('notify_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'notify_push',
                'notify_device',
                'notify_email',
                'auto_sync_buffer',
            ]);
        });
    }
};
