<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2 Phase F schema:
 *   - news_sources: per-article citations / source links rendered in the
 *     article footer.
 *   - news.fact_check_status enum (verified | disputed | unverified | null).
 *   - topics: editorially curated landing pages — a Topic has a hero image,
 *     description, slug, and an auto-include filter (category_id +
 *     keyword + tags) plus a topic_news pivot for manual pinning.
 *   - news_headlines: A/B variants for an article's headline. Each variant
 *     records impressions and clicks; the homepage / list views pick a
 *     variant weighted by performance, sticky per session.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('news_sources')) {
            Schema::create('news_sources', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('news_id')->index();
                $table->string('label', 255);
                $table->string('url', 500)->nullable();
                $table->string('publisher', 200)->nullable();
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();
            });
        }

        Schema::table('news', function (Blueprint $table) {
            if (! Schema::hasColumn('news', 'fact_check_status')) {
                $table->string('fact_check_status', 16)->nullable()->after('canonical_url');
                $table->index('fact_check_status');
            }
        });

        if (! Schema::hasTable('topics')) {
            Schema::create('topics', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('hero_image')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedInteger('sort')->default(0);
                // auto-include rules expressed as JSON: { categories: [...],
                // tags: [...], keyword: "..." }. Empty / null means no
                // auto-include — the topic is fully manual.
                $table->json('auto_rules')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('topic_news')) {
            Schema::create('topic_news', function (Blueprint $table) {
                $table->unsignedBigInteger('topic_id');
                $table->unsignedBigInteger('news_id');
                $table->unsignedSmallInteger('sort')->default(0);
                $table->primary(['topic_id', 'news_id']);
                $table->index('news_id');
            });
        }

        if (! Schema::hasTable('news_headlines')) {
            Schema::create('news_headlines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('news_id')->index();
                $table->string('variant', 255);
                $table->boolean('is_default')->default(false);
                $table->unsignedBigInteger('impressions')->default(0);
                $table->unsignedBigInteger('clicks')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('news_headlines');
        Schema::dropIfExists('topic_news');
        Schema::dropIfExists('topics');
        Schema::table('news', function (Blueprint $table) {
            if (Schema::hasColumn('news', 'fact_check_status')) {
                $table->dropIndex(['fact_check_status']);
                $table->dropColumn('fact_check_status');
            }
        });
        Schema::dropIfExists('news_sources');
    }
};
