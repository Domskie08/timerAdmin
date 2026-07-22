<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_sale_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->foreignId('dtimer_machine_id')->constrained('dtimer_machines')->cascadeOnDelete();
            $table->foreignId('license_id')->nullable()->constrained('licenses')->nullOnDelete();
            $table->string('local_event_id', 100);
            $table->timestamp('occurred_at')->index();
            $table->timestamp('received_at')->index();
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->string('currency', 3)->default('PHP');
            $table->unsignedInteger('pulse_count')->default(0);
            $table->string('session_id')->nullable()->index();
            $table->string('user_slot')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['dtimer_machine_id', 'local_event_id']);
            $table->index(['client_account_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_sale_events');
    }
};
