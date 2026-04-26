<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P14 Phase D.2 — straight article TTS read-aloud.
 *
 * Distinct from the existing podcast columns (which hold the
 * NotebookLM-style two-host conversation). `tts_audio_path` is a
 * single-voice narration of the article body, generated on demand
 * via the same TtsClient that powers podcasts.
 *
 *   tts_audio_path        storage-relative path to the .mp3
 *   tts_generated_at      cache-bust + freshness signal
 *   tts_voice             the voice key used (so the player can
 *                         label, and the regen button can re-pick)
 *   tts_chars             char count fed to TTS (cost accounting)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            if (! Schema::hasColumn('news', 'tts_audio_path')) {
                $table->string('tts_audio_path', 500)->nullable()->after('podcast_generated_at');
            }
            if (! Schema::hasColumn('news', 'tts_generated_at')) {
                $table->timestamp('tts_generated_at')->nullable()->after('tts_audio_path');
            }
            if (! Schema::hasColumn('news', 'tts_voice')) {
                $table->string('tts_voice', 60)->nullable()->after('tts_generated_at');
            }
            if (! Schema::hasColumn('news', 'tts_chars')) {
                $table->unsignedInteger('tts_chars')->nullable()->after('tts_voice');
            }
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            foreach (['tts_audio_path', 'tts_generated_at', 'tts_voice', 'tts_chars'] as $col) {
                if (Schema::hasColumn('news', $col)) $table->dropColumn($col);
            }
        });
    }
};
