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
        Schema::create('content_hashes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_draft_id');
            $table->string('content_hash', 64);
            $table->text('normalized_content'); // Use text instead of string for longer content
            $table->longText('similarity_tokens'); // Use longText for JSON storage
            $table->timestamps();
            $table->foreign('item_draft_id')->references('id')->on('item_drafts')->onDelete('cascade');
            $table->unique(['content_hash', 'item_draft_id'], 'content_hash_item_unique');
            $table->index('content_hash');
            $table->index('item_draft_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_hashes');
    }
};
