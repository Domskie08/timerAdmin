<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            $table->date('expires_at')->nullable()->change();
        });

        DB::table('licenses')
            ->where('product_type', 'piso_wifi')
            ->update([
                'duration' => null,
                'expires_at' => null,
            ]);

        Schema::table('coin_sale_events', function (Blueprint $table): void {
            $table->foreignId('dtimer_machine_id')->nullable()->change();
            $table->string('product_type', 32)->default('piso_wifi')->after('license_id')->index();
            $table->index(['product_type', 'license_id', 'local_event_id'], 'coin_sale_events_product_license_event_index');
        });
    }

    public function down(): void
    {
        DB::table('coin_sale_events')
            ->whereNull('dtimer_machine_id')
            ->delete();

        Schema::table('coin_sale_events', function (Blueprint $table): void {
            $table->dropIndex('coin_sale_events_product_license_event_index');
            $table->dropColumn('product_type');
            $table->foreignId('dtimer_machine_id')->nullable(false)->change();
        });

        DB::table('licenses')
            ->where('product_type', 'piso_wifi')
            ->whereNull('expires_at')
            ->select(['id', 'created_at'])
            ->orderBy('id')
            ->chunkById(100, function ($licenses): void {
                foreach ($licenses as $license) {
                    $createdAt = $license->created_at
                        ? CarbonImmutable::parse($license->created_at)
                        : now();

                    DB::table('licenses')
                        ->where('id', $license->id)
                        ->update([
                            'duration' => '1_month',
                            'expires_at' => $createdAt->addMonthNoOverflow()->startOfDay()->toDateString(),
                        ]);
                }
            });

        Schema::table('licenses', function (Blueprint $table): void {
            $table->date('expires_at')->nullable(false)->change();
        });
    }
};
