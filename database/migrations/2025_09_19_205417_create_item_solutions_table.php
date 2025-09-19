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
        Schema::create('item_solutions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('itemable');
            $table->text('text_ar');
            $table->text('latex')->nullable();
            $table->string('solution_type')->default('worked');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_solutions');
    }
};
