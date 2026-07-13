<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_updates', function (Blueprint $table): void {
            $table->string('external_download_url', 2048)->nullable()->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('app_updates', function (Blueprint $table): void {
            $table->dropColumn('external_download_url');
        });
    }
};
