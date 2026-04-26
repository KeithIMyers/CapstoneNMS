<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P13 Phase B.2 — reading lists + reading history + comment likes.
 *
 *   reading_lists           per-user named lists ("Saved", "For
 *                           later", "AI", …). Default list auto-
 *                           created on first use.
 *   reading_list_items      pivot: list ↔ article + optional note.
 *   reading_history         per-user, per-article last_read_at +
 *                           scroll-depth so a "continue reading"
 *                           surface can show partially-read pieces.
 *   comment_likes           per-comment 👍 from authenticated readers.
 *                           Simpler than a full polymorphic reaction
 *                           model and matches what readers expect of
 *                           comments (article reactions stay on news_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reading_lists')) {
            Schema::create('reading_lists', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name', 120);
                $table->string('slug', 80);
                $table->boolean('is_default')->default(false);
                $table->timestamps();
                $table->unique(['user_id', 'slug']);
            });
        }

        if (! Schema::hasTable('reading_list_items')) {
            Schema::create('reading_list_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('reading_list_id')->index();
                $table->unsignedBigInteger('news_id')->index();
                $table->text('note')->nullable();
                $table->timestamp('added_at')->nullable();
                $table->timestamps();
                $table->unique(['reading_list_id', 'news_id'], 'reading_list_news_unique');
            });
        }

        if (! Schema::hasTable('reading_history')) {
            Schema::create('reading_history', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('news_id')->index();
                $table->timestamp('last_read_at')->nullable();
                $table->unsignedTinyInteger('scroll_depth_pct')->nullable();
                $table->unsignedSmallInteger('read_count')->default(1);
                $table->timestamps();
                $table->unique(['user_id', 'news_id'], 'reading_history_user_news_unique');
            });
        }

        if (! Schema::hasTable('comment_likes')) {
            Schema::create('comment_likes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('comment_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->timestamps();
                $table->unique(['comment_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_likes');
        Schema::dropIfExists('reading_history');
        Schema::dropIfExists('reading_list_items');
        Schema::dropIfExists('reading_lists');
    }
};
