<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_updates', function (Blueprint $table): void {
            $table->string('product_type', 30)
                ->default('timer_app')
                ->after('id')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('app_updates', function (Blueprint $table): void {
            $table->dropIndex(['product_type']);
            $table->dropColumn('product_type');
        });
    }
};
