<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            $table->string('device_id')->nullable()->after('device_name')->index();
        });

        DB::table('licenses')
            ->whereNull('device_id')
            ->whereNotNull('machine_id')
            ->update([
                'device_id' => DB::raw('machine_id'),
            ]);
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            $table->dropColumn('device_id');
        });
    }
};
