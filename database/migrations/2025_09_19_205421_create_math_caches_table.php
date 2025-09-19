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
        Schema::create('math_caches', function (Blueprint $table) {
            $table->id();
            $table->text('latex_input');
            $table->string('engine')->default('mathjax');
            $table->boolean('display_mode')->default(false);
            $table->text('rendered_output');
            $table->string('output_format')->default('svg');
            $table->timestamps();
            $table->unique(['latex_input', 'engine', 'display_mode']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('math_caches');
    }
};
