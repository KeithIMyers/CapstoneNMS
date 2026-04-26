<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2 Phase G3 — Multilingual articles.
 *
 * Rather than storing per-locale JSON blobs on each column (Spatie
 * translatable style), we use "translation siblings": each News row is
 * fully self-contained in one locale, and rows that are translations of
 * the same piece share a `translation_group_id`. The canonical article's
 * group_id matches its own id for simplicity when backfilling existing
 * rows.
 *
 *   - `locale` is a BCP-47 code (e.g., "en", "es", "zh-Hans")
 *   - `translation_group_id` is nullable only transitionally — backfilled
 *     below to each row's own id.
 *
 * A uniqueness constraint is enforced in application code (NewsForm)
 * rather than in the schema so editors can't accidentally ship two
 * Spanish versions of the same piece; a DB-level unique would fire during
 * the Filament repeater save dance and produce poor UX.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            if (! Schema::hasColumn('news', 'locale')) {
                $table->string('locale', 12)->default('en')->after('slug');
                $table->index('locale');
            }
            if (! Schema::hasColumn('news', 'translation_group_id')) {
                $table->unsignedBigInteger('translation_group_id')->nullable()->after('locale');
                $table->index('translation_group_id');
            }
        });

        // Backfill: every existing article is the canonical of its own group.
        DB::table('news')
            ->whereNull('translation_group_id')
            ->update(['translation_group_id' => DB::raw('id')]);
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            if (Schema::hasColumn('news', 'translation_group_id')) {
                $table->dropIndex(['translation_group_id']);
                $table->dropColumn('translation_group_id');
            }
            if (Schema::hasColumn('news', 'locale')) {
                $table->dropIndex(['locale']);
                $table->dropColumn('locale');
            }
        });
    }
};
