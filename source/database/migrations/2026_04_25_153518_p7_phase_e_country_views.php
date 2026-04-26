<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7 Phase E — per-article country view aggregates.
 *
 * Daily-bucketed counts of article views by ISO 3166-1 alpha-2 country
 * code. Populated incrementally by NewsController::trackView() through
 * an INSERT … ON DUPLICATE KEY UPDATE on the (article_id, country, day)
 * unique key.
 *
 * Aggregating at write-time rather than logging individual events keeps
 * row count bounded (≈ countries × articles × days) — comfortable on
 * shared MySQL even at 10k articles. Per-event log can come later if
 * we want hour-of-day patterns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('article_country_views')) {
            Schema::create('article_country_views', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('article_id');
                $table->char('country_code', 2);
                $table->date('day');
                $table->unsignedInteger('views')->default(0);
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

                $table->unique(['article_id', 'country_code', 'day'], 'acv_unique');
                $table->index('day');
                $table->index('country_code');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('article_country_views');
    }
};
