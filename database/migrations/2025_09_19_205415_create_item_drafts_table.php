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
        Schema::create('item_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('stem_ar');
            $table->text('latex')->nullable();
            $table->string('item_type');
            $table->decimal('difficulty', 3, 1)->default(1.0);
            $table->json('meta')->nullable();
            $table->string('status')->default('draft');
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamps();
            $table->index(['status', 'item_type']);
            $table->index('created_by');
        });

        // Many-to-many: Draft-Concepts
        Schema::create('item_draft_concepts', function (Blueprint $table) {
            $table->uuid('item_draft_id');
            $table->uuid('concept_id');
            $table->primary(['item_draft_id', 'concept_id']);
            $table->foreign('item_draft_id')->references('id')->on('item_drafts')->onDelete('cascade');
            $table->foreign('concept_id')->references('id')->on('concepts')->onDelete('cascade');
        });

        // Many-to-many: Draft-Tags
        Schema::create('item_draft_tags', function (Blueprint $table) {
            $table->uuid('item_draft_id');
            $table->uuid('tag_id');
            $table->primary(['item_draft_id', 'tag_id']);
            $table->foreign('item_draft_id')->references('id')->on('item_drafts')->onDelete('cascade');
            $table->foreign('tag_id')->references('id')->on('tags')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_drafts');
    }
};
