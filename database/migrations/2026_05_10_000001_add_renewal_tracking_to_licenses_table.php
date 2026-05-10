<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            $table->foreignId('consumed_by_license_id')
                ->nullable()
                ->after('device_secret')
                ->constrained('licenses')
                ->nullOnDelete();
            $table->timestamp('consumed_at')->nullable()->after('consumed_by_license_id');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('consumed_by_license_id');
            $table->dropColumn('consumed_at');
        });
    }
};
