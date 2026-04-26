<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P13 Phase C.2 — per-article schema.org variant.
 *
 * Adds news.article_type so editors can mark an article as
 * reportage / opinion / review / analysis / background. Renders
 * as the matching Schema.org @type (ReportageNewsArticle,
 * OpinionNewsArticle, ReviewNewsArticle, AnalysisNewsArticle,
 * BackgroundNewsArticle) on the public page, which Google News
 * uses as a discovery signal.
 *
 * Default `reportage` keeps the legacy NewsArticle posture.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('news', 'article_type')) {
            Schema::table('news', function (Blueprint $table) {
                $table->string('article_type', 24)->default('reportage')->after('editorial_status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('news', 'article_type')) {
            Schema::table('news', function (Blueprint $table) {
                $table->dropColumn('article_type');
            });
        }
    }
};
