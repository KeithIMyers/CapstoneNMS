<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8 Phase B — wire ingestion + article series.
 *
 * Wire sources:
 *   wire_sources       admin-defined RSS feeds we periodically poll
 *   wire_items_seen    de-dup table so each guid is only ingested once
 *
 * The CRON command (wire:ingest) walks every active wire_source, fetches
 * its feed, and lands new items as draft articles in the configured
 * default category. A per-source toggle (ai_rewrite) routes the item
 * through the compose agent before the draft is created.
 *
 * Article series:
 *   article_series       editor-defined investigations / multi-part
 *                        coverage with their own landing slug + intro.
 *   news.series_id       FK + sort_in_series so articles can be
 *                        ordered within a series.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wire_sources')) {
            Schema::create('wire_sources', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('feed_url', 500);
                $table->boolean('is_active')->default(true)->index();
                $table->boolean('ai_rewrite')->default(false);
                $table->unsignedBigInteger('default_category_id')->nullable();
                $table->unsignedBigInteger('default_author_id')->nullable();
                $table->timestamp('last_fetched_at')->nullable();
                $table->unsignedSmallInteger('limit_per_run')->default(10);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wire_items_seen')) {
            Schema::create('wire_items_seen', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('source_id')->index();
                $table->string('guid', 500);
                $table->unsignedBigInteger('news_id')->nullable();
                $table->timestamp('seen_at')->useCurrent();
                $table->unique(['source_id', 'guid'], 'wire_seen_unique');
            });
        }

        if (! Schema::hasTable('article_series')) {
            Schema::create('article_series', function (Blueprint $table) {
                $table->id();
                $table->string('name', 200);
                $table->string('slug', 200)->unique();
                $table->text('description')->nullable();
                $table->string('hero_image')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();
            });
        }

        Schema::table('news', function (Blueprint $table) {
            if (! Schema::hasColumn('news', 'series_id')) {
                $table->unsignedBigInteger('series_id')->nullable()->index()->after('category_id');
            }
            if (! Schema::hasColumn('news', 'sort_in_series')) {
                $table->unsignedSmallInteger('sort_in_series')->default(0)->after('series_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            foreach (['series_id', 'sort_in_series'] as $col) {
                if (Schema::hasColumn('news', $col)) {
                    if ($col === 'series_id') $table->dropIndex(['series_id']);
                    $table->dropColumn($col);
                }
            }
        });
        Schema::dropIfExists('article_series');
        Schema::dropIfExists('wire_items_seen');
        Schema::dropIfExists('wire_sources');
    }
};
