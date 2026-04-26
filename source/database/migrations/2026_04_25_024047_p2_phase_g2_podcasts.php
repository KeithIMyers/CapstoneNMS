<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2 Phase G2 — Podcasts.
 *
 *  - podcast_shows: a podcast "show" (e.g., "Daily Brief"). Supplies the
 *    metadata the iTunes / Apple Podcasts RSS feed needs: title, author,
 *    owner contact, explicit flag, categories, artwork. Explicit fields
 *    rather than a free-form JSON so the feed generator stays predictable.
 *
 *  - podcast_episodes: per-episode audio file metadata, published_at,
 *    duration, optional season / episode numbers, explicit override per
 *    episode, show notes (HTML). media_url is the fully-qualified URL to
 *    the audio file (the admin uploads to the public disk).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('podcast_shows')) {
            Schema::create('podcast_shows', function (Blueprint $table) {
                $table->id();
                $table->string('title', 200);
                $table->string('slug', 200)->unique();
                $table->text('description')->nullable();
                $table->string('author', 150)->nullable();
                $table->string('owner_name', 150)->nullable();
                $table->string('owner_email', 200)->nullable();
                $table->string('language', 8)->default('en-us');
                $table->string('itunes_category', 100)->nullable();
                $table->string('itunes_subcategory', 100)->nullable();
                $table->string('artwork_path')->nullable();
                $table->boolean('explicit')->default(false);
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('podcast_episodes')) {
            Schema::create('podcast_episodes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('show_id')->index();
                $table->string('title', 255);
                $table->string('slug', 255);
                $table->text('description')->nullable();
                $table->longText('show_notes')->nullable();
                $table->string('media_url', 500);
                $table->unsignedBigInteger('media_size_bytes')->nullable();
                $table->string('media_mime', 100)->default('audio/mpeg');
                $table->unsignedInteger('duration_seconds')->nullable();
                $table->unsignedSmallInteger('season_number')->nullable();
                $table->unsignedSmallInteger('episode_number')->nullable();
                $table->string('episode_type', 16)->default('full'); // full | trailer | bonus
                $table->boolean('explicit')->nullable();
                $table->timestamp('published_at')->nullable()->index();
                $table->timestamps();
                $table->unique(['show_id', 'slug']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('podcast_episodes');
        Schema::dropIfExists('podcast_shows');
    }
};
