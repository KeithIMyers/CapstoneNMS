<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P9 Phase B — split ad_slots into two kinds:
 *
 *   "html"   — existing behavior. Editor pastes a snippet (AdSense
 *              code, header-bidding tag, custom <script>) and the
 *              client-side loader injects + EXECUTES it. The
 *              previous loader silently dropped <script> tags
 *              because innerHTML doesn't run them — site.js is
 *              fixed alongside this migration.
 *
 *   "banner" — structured fields: image_path + click_url + alt_text.
 *              Server renders an anchor with click tracking via the
 *              existing data-ad-slot wrapper. No editor JS, no
 *              network calls.
 *
 * Default for new rows is "banner" since it's the more common case
 * for newsrooms running direct-sold campaigns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_slots', function (Blueprint $table) {
            if (! Schema::hasColumn('ad_slots', 'kind')) {
                $table->string('kind', 16)->default('html')->after('placement');
            }
            if (! Schema::hasColumn('ad_slots', 'image_path')) {
                $table->string('image_path', 500)->nullable()->after('kind');
            }
            if (! Schema::hasColumn('ad_slots', 'click_url')) {
                $table->string('click_url', 1000)->nullable()->after('image_path');
            }
            if (! Schema::hasColumn('ad_slots', 'alt_text')) {
                $table->string('alt_text', 255)->nullable()->after('click_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ad_slots', function (Blueprint $table) {
            foreach (['kind', 'image_path', 'click_url', 'alt_text'] as $col) {
                if (Schema::hasColumn('ad_slots', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
