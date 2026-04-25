<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('licenses', 'pc_name') || Schema::hasColumn('licenses', 'device_name')) {
            return;
        }

        Schema::table('licenses', function (Blueprint $table): void {
            $table->renameColumn('pc_name', 'device_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('licenses', 'device_name') || Schema::hasColumn('licenses', 'pc_name')) {
            return;
        }

        Schema::table('licenses', function (Blueprint $table): void {
            $table->renameColumn('device_name', 'pc_name');
        });
    }
};
