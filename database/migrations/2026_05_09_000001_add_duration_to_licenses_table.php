<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DURATION_OPTIONS = [
        ['value' => '1_month', 'months' => 1],
        ['value' => '3_months', 'months' => 3],
        ['value' => '6_months', 'months' => 6],
        ['value' => '1_year', 'months' => 12],
    ];

    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            $table->string('duration', 32)->nullable()->after('code');
        });

        DB::table('licenses')
            ->select(['id', 'created_at', 'expires_at'])
            ->orderBy('id')
            ->chunkById(100, function ($licenses): void {
                foreach ($licenses as $license) {
                    $duration = $this->inferDuration($license->created_at, $license->expires_at);

                    if (! $duration) {
                        continue;
                    }

                    DB::table('licenses')
                        ->where('id', $license->id)
                        ->update(['duration' => $duration]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            $table->dropColumn('duration');
        });
    }

    private function inferDuration(?string $createdAt, ?string $expiresAt): ?string
    {
        if (! $createdAt || ! $expiresAt) {
            return null;
        }

        $created = CarbonImmutable::parse($createdAt);
        $expiry = CarbonImmutable::parse($expiresAt)->toDateString();

        foreach (self::DURATION_OPTIONS as $option) {
            $expectedExpiry = $created
                ->addMonthsNoOverflow($option['months'])
                ->startOfDay()
                ->toDateString();

            if ($expectedExpiry === $expiry) {
                return $option['value'];
            }
        }

        return null;
    }
};
