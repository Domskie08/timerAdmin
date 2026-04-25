<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_photos', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 120)->nullable();
            $table->string('image_path');
            $table->string('image_name');
            $table->unsignedInteger('position')->default(0)->index();
            $table->boolean('is_visible')->default(true)->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_photos');
    }
};
