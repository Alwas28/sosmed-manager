<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buffer_connections', function (Blueprint $table) {
            $table->string('organization_id')->nullable()->after('buffer_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('buffer_connections', function (Blueprint $table) {
            $table->dropColumn('organization_id');
        });
    }
};
