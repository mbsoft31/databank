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
        Schema::create('concepts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('grade')->nullable();
            $table->string('strand')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['grade', 'strand']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('concepts');
    }
};
