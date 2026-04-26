<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P11 Phase C — auto-translation on publish.
 *
 * news.auto_translate_locales is a JSON array of BCP-47 codes ("es",
 * "fr", "zh-Hans", etc.). On the published-state transition, the
 * NewsAutoTranslateObserver walks the list and produces translation
 * siblings via the existing Phase G3 translation_group plumbing.
 * Already-translated locales are skipped so a re-publish doesn't
 * fan out duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            if (! Schema::hasColumn('news', 'auto_translate_locales')) {
                $table->json('auto_translate_locales')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            if (Schema::hasColumn('news', 'auto_translate_locales')) {
                $table->dropColumn('auto_translate_locales');
            }
        });
    }
};
