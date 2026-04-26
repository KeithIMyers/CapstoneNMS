<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5 Phase A — Embeddings + semantic search.
 *
 *  ai_providers gains two columns: embedding_model + embedding_dimensions.
 *  When embedding_model is set, that provider can serve embedding requests
 *  via EmbeddingClient. Most installs use one provider for both completions
 *  and embeddings (OpenAI does both; Ollama does both); Anthropic only does
 *  completions, so its row leaves these blank.
 *
 *  article_embeddings is the index. One row per (news_id, model) pair so
 *  swapping models triggers a clean re-embed without losing history.
 *  vector is JSON (an array of floats) — keeps the schema portable and
 *  lets MySQL ship without a vector extension. Cosine similarity is
 *  computed in PHP at query time; fine up to ~10k articles on shared
 *  hosting.
 *
 *  content_hash lets the indexer skip articles whose source text hasn't
 *  changed since the last embed — re-running the CRON costs near-zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_providers', 'embedding_model')) {
                $table->string('embedding_model', 120)->nullable()->after('default_model');
            }
            if (! Schema::hasColumn('ai_providers', 'embedding_dimensions')) {
                $table->unsignedSmallInteger('embedding_dimensions')->nullable()->after('embedding_model');
            }
        });

        if (! Schema::hasTable('article_embeddings')) {
            Schema::create('article_embeddings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('news_id')->index();
                $table->string('model', 120);
                $table->unsignedSmallInteger('dimensions');
                $table->longText('vector');           // JSON array of floats
                $table->string('content_hash', 64);   // SHA-256 of source text
                $table->timestamps();
                $table->unique(['news_id', 'model']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('article_embeddings');
        Schema::table('ai_providers', function (Blueprint $table) {
            foreach (['embedding_model', 'embedding_dimensions'] as $col) {
                if (Schema::hasColumn('ai_providers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
