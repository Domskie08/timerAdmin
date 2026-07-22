<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dtimer_machines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->foreignId('license_id')->nullable()->unique()->constrained('licenses')->nullOnDelete();
            $table->string('device_name')->nullable();
            $table->string('device_id')->nullable()->index();
            $table->string('machine_id')->nullable()->index();
            $table->string('mac_address_hash', 64)->unique();
            $table->string('mac_address_display', 32);
            $table->string('app_version', 50)->nullable();
            $table->string('firmware_version', 50)->nullable();
            $table->string('wifi_status', 40)->nullable();
            $table->string('timer_status', 40)->nullable();
            $table->unsignedInteger('connected_users')->default(0);
            $table->unsignedInteger('active_sessions')->default(0);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->string('last_seen_ip', 45)->nullable();
            $table->timestamp('unlinked_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtimer_machines');
    }
};
