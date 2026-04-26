<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5 Phase E — Persistent article lint results.
 *
 * Stores the latest lint findings per article so editors can:
 *   - see lint outcomes across the newsroom (most-flagged list)
 *   - skip re-running lint on articles that haven't changed
 *
 * Findings are also still stashed in the session by the in-editor "Run
 * lint" action — that path stays the live preview. The CRON populates
 * this table for cross-article reporting.
 *
 * One row per article (latest replaces previous). Counts duplicated
 * out of the JSON for cheap dashboard queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('article_lint_results')) {
            Schema::create('article_lint_results', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('news_id')->unique();
                $table->json('findings');
                $table->unsignedSmallInteger('critical_count')->default(0)->index();
                $table->unsignedSmallInteger('warn_count')->default(0);
                $table->unsignedSmallInteger('info_count')->default(0);
                $table->string('model', 120)->nullable();
                $table->timestamp('ran_at')->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('article_lint_results');
    }
};
