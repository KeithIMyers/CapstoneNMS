<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P11 Phase B — NotebookLM-style article podcasts.
 *
 *   news.podcast_audio_path     storage path of the concatenated mp3
 *   news.podcast_script         JSON array of {speaker,text} dialog
 *                               lines so we can replay / regen / review.
 *   news.podcast_generated_at   stamp for cache-busting in the player.
 *   ai_providers.tts_model      non-empty marks the provider as TTS-
 *                               capable. Reuses the same auth + base
 *                               URL as completions / embeddings /
 *                               images on the same row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            if (! Schema::hasColumn('news', 'podcast_audio_path')) {
                $table->string('podcast_audio_path', 500)->nullable();
            }
            if (! Schema::hasColumn('news', 'podcast_script')) {
                $table->json('podcast_script')->nullable();
            }
            if (! Schema::hasColumn('news', 'podcast_generated_at')) {
                $table->timestamp('podcast_generated_at')->nullable();
            }
        });

        Schema::table('ai_providers', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_providers', 'tts_model')) {
                $table->string('tts_model', 120)->nullable()->after('image_model');
            }
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            foreach (['podcast_audio_path', 'podcast_script', 'podcast_generated_at'] as $col) {
                if (Schema::hasColumn('news', $col)) $table->dropColumn($col);
            }
        });
        Schema::table('ai_providers', function (Blueprint $table) {
            if (Schema::hasColumn('ai_providers', 'tts_model')) {
                $table->dropColumn('tts_model');
            }
        });
    }
};
