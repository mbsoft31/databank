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
        Schema::create('exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind');
            $table->json('params');
            $table->string('status')->default('pending');
            $table->string('file_path')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('requested_by')->nullable();
            $table->timestamps();
            $table->index(['status', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
