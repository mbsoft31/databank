<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('filename');
            $table->string('mime_type');
            $table->bigInteger('size_bytes');
            $table->string('storage_path');
            $table->json('meta')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
            $table->index('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
