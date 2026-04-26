<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregated scroll-depth tracking on the news table.
 *
 *  - scroll_samples: how many session-end beacons we've collected
 *  - scroll_depth_total: sum of max-scroll percentages
 *
 * Average completion rate for an article = scroll_depth_total /
 * scroll_samples. Stored as integers to avoid float drift on hot
 * counter increments. Read-time times completion is a much better
 * "did this resonate?" signal than view count alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            if (! Schema::hasColumn('news', 'scroll_samples')) {
                $table->unsignedInteger('scroll_samples')->default(0)->after('views');
            }
            if (! Schema::hasColumn('news', 'scroll_depth_total')) {
                $table->unsignedBigInteger('scroll_depth_total')->default(0)->after('scroll_samples');
            }
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            foreach (['scroll_depth_total', 'scroll_samples'] as $col) {
                if (Schema::hasColumn('news', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
