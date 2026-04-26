<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P10 Phase A — autonomous news-agent personas + story queue.
 *
 * Schema:
 *   news_agents       persona configuration (writing style, KB, schedule,
 *                     per-agent provider + model overrides, image-gen
 *                     overrides).
 *   agent_stories     the queue of topics each agent is supposed to write.
 *                     Status enum tracks queued → drafting → drafted →
 *                     published → failed.
 *   users.is_agent    boolean discriminator so admin filters can hide
 *                     virtual authors from real-user views.
 *   ai_providers.image_model  non-empty marks the provider as
 *                     image-generation-capable. Reuses the existing
 *                     api_key + base_url + kind so a single OpenAI
 *                     provider can serve completions, embeddings, AND
 *                     images. Google "NanoBanana" providers run with
 *                     kind=google_imagen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_agent')) {
                $table->boolean('is_agent')->default(false)->index();
            }
        });

        Schema::table('ai_providers', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_providers', 'image_model')) {
                $table->string('image_model', 120)->nullable()->after('embedding_dimensions');
            }
        });

        if (! Schema::hasTable('news_agents')) {
            Schema::create('news_agents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique(); // the persona's User row
                $table->text('writing_style')->nullable();       // free-form prose describing voice
                $table->longText('knowledgebase')->nullable();   // markdown-ish baseline knowledge
                $table->json('topic_keywords')->nullable();      // ['sports','nfl','college football']

                // Per-agent model overrides. Null means "use default".
                $table->unsignedBigInteger('ai_provider_id')->nullable();
                $table->string('ai_model', 120)->nullable();
                $table->float('temperature')->default(0.5);
                $table->unsignedInteger('max_tokens')->default(3000);

                // Image generation overrides; null means "use the global default".
                $table->unsignedBigInteger('image_provider_id')->nullable();
                $table->string('image_model', 120)->nullable();
                $table->string('image_style_hint', 500)->nullable(); // appended to every image prompt

                // Schedule.
                $table->string('schedule_frequency', 16)->nullable(); // hourly|daily|weekly|null=manual
                $table->unsignedTinyInteger('schedule_hour_utc')->nullable();
                $table->unsignedSmallInteger('posts_per_run')->default(1);
                $table->unsignedSmallInteger('target_word_count')->default(600);

                $table->timestamp('last_run_at')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agent_stories')) {
            Schema::create('agent_stories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agent_id')->index();
                $table->string('topic', 500);                         // the prompt / headline idea
                $table->text('brief')->nullable();                    // optional editor notes
                $table->json('source_urls')->nullable();              // optional research seeds
                $table->unsignedBigInteger('category_id')->nullable();
                $table->string('status', 16)->default('queued')->index(); // queued|drafting|drafted|published|failed|skipped
                $table->unsignedSmallInteger('priority')->default(50);    // lower = higher priority
                $table->timestamp('scheduled_for')->nullable()->index();  // earliest run time
                $table->unsignedBigInteger('news_id')->nullable();        // FK once article exists
                $table->unsignedSmallInteger('attempt_count')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_stories');
        Schema::dropIfExists('news_agents');
        Schema::table('ai_providers', function (Blueprint $table) {
            if (Schema::hasColumn('ai_providers', 'image_model')) {
                $table->dropColumn('image_model');
            }
        });
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_agent')) {
                $table->dropIndex(['is_agent']);
                $table->dropColumn('is_agent');
            }
        });
    }
};
