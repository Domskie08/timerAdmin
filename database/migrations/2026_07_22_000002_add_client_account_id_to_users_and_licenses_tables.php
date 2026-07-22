<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('client_account_id')
                ->nullable()
                ->after('is_admin')
                ->constrained('client_accounts')
                ->nullOnDelete();
            $table->index(['is_admin', 'client_account_id']);
        });

        Schema::table('licenses', function (Blueprint $table): void {
            $table->foreignId('client_account_id')
                ->nullable()
                ->after('created_by')
                ->constrained('client_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_account_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['is_admin', 'client_account_id']);
            $table->dropConstrainedForeignId('client_account_id');
        });
    }
};
