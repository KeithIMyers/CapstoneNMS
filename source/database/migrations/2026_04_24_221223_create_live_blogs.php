<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live blogs — rolling-coverage event pages with timestamped entries.
 * Used for election nights, breaking-news events, sports games, etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('live_blogs')) {
            Schema::create('live_blogs', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('slug')->unique();
                $table->text('summary')->nullable();
                $table->string('hero_image')->nullable();
                $table->string('status', 16)->default('draft');     // draft | active | closed
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
                $table->index('status');
                $table->index('started_at');
            });
        }

        if (! Schema::hasTable('live_blog_entries')) {
            Schema::create('live_blog_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('live_blog_id')->index();
                $table->unsignedBigInteger('posted_by_user_id')->nullable();
                $table->string('headline')->nullable();
                $table->longText('body');
                $table->boolean('is_pinned')->default(false)->index();
                $table->timestamp('posted_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('live_blog_entries');
        Schema::dropIfExists('live_blogs');
    }
};
