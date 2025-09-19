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
        Schema::create('item_prods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('source_draft_id')->nullable();
            $table->string('stem_ar');
            $table->text('latex')->nullable();
            $table->string('item_type');
            $table->decimal('difficulty', 3, 1)->default(1.0);
            $table->json('meta')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('published_by')->nullable();
            $table->timestamps();
            $table->index(['item_type', 'published_at']);
            $table->foreign('source_draft_id')->references('id')->on('item_drafts')->onDelete('set null');
        });

        // Many-to-many: Prod-Concepts
        Schema::create('item_prod_concepts', function (Blueprint $table) {
            $table->uuid('item_prod_id');
            $table->uuid('concept_id');
            $table->primary(['item_prod_id', 'concept_id']);
            $table->foreign('item_prod_id')->references('id')->on('item_prod')->onDelete('cascade');
            $table->foreign('concept_id')->references('id')->on('concepts')->onDelete('cascade');
        });

        // Many-to-many: Prod-Tags
        Schema::create('item_prod_tags', function (Blueprint $table) {
            $table->uuid('item_prod_id');
            $table->uuid('tag_id');
            $table->primary(['item_prod_id', 'tag_id']);
            $table->foreign('item_prod_id')->references('id')->on('item_prod')->onDelete('cascade');
            $table->foreign('tag_id')->references('id')->on('tags')->onDelete('cascade');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_prods');
    }
};
