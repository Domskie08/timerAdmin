<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 12)->unique();
            $table->date('expires_at');
            $table->string('device_name')->nullable()->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->string('last_seen_ip', 45)->nullable();
            $table->string('app_version', 50)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};

