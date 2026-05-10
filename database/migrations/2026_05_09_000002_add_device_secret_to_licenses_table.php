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
            $table->string('device_secret', 64)->nullable()->after('machine_id');
        });

        DB::table('licenses')
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(100, function ($licenses): void {
                foreach ($licenses as $license) {
                    DB::table('licenses')
                        ->where('id', $license->id)
                        ->update([
                            'device_secret' => $this->generateDeviceSecret(),
                        ]);
                }
            });

        Schema::table('licenses', function (Blueprint $table): void {
            $table->unique('device_secret');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            $table->dropUnique('licenses_device_secret_unique');
            $table->dropColumn('device_secret');
        });
    }

    private function generateDeviceSecret(): string
    {
        do {
            $secret = bin2hex(random_bytes(32));
        } while (DB::table('licenses')->where('device_secret', $secret)->exists());

        return $secret;
    }
};
