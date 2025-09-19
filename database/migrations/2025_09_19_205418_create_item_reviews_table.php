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
        Schema::create('item_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_draft_id');
            $table->string('reviewer_id');
            $table->string('status');
            $table->text('feedback')->nullable();
            $table->json('rubric_scores')->nullable();
            $table->decimal('overall_score', 3, 1)->nullable();
            $table->timestamps();
            $table->foreign('item_draft_id')->references('id')->on('item_drafts')->onDelete('cascade');
            $table->index(['item_draft_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_reviews');
    }
};
