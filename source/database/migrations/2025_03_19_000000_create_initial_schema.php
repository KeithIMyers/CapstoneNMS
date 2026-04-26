<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Initial schema for the news CMS. This consolidates the original
 * "first install" tables that the project used to bootstrap from a
 * SQL dump.
 *
 * Every table is wrapped in `Schema::hasTable()` so the migration is a
 * no-op against an already-populated database. On a clean install it
 * provisions the full data model expected by the app and admin panel,
 * so subsequent migrations only have to layer on additions and
 * refinements (status enums, hierarchical categories, the news_tag
 * pivot, 2FA columns, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 120)->unique();
                $table->unsignedTinyInteger('status')->default(1);
                $table->unsignedInteger('cat_order')->default(0);
                $table->timestamps();
                $table->index('status');
                $table->index('cat_order');
            });
        }

        if (! Schema::hasTable('tags')) {
            Schema::create('tags', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80);
                $table->string('slug', 100)->unique();
                $table->timestamps();
                $table->index('slug');
            });
        }

        if (! Schema::hasTable('news')) {
            Schema::create('news', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('slug')->unique();
                $table->text('excerpt')->nullable();
                $table->longText('content')->nullable();
                $table->string('image')->nullable();
                $table->text('video_embed_code')->nullable();
                $table->string('tags')->nullable();      // legacy CSV; superseded by news_tag pivot
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('category_id')->index();
                $table->unsignedTinyInteger('status')->default(0);    // legacy 0/1 mirror
                $table->boolean('is_featured')->default(false);
                $table->unsignedBigInteger('date')->nullable();        // legacy unix-ts publish date
                $table->unsignedBigInteger('views')->default(0);
                $table->json('display_order')->nullable();
                $table->timestamps();

                $table->index('status');
                $table->index('is_featured');
                $table->index('date');
            });
        }

        if (! Schema::hasTable('news_gallery')) {
            Schema::create('news_gallery', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('news_id')->index();
                $table->string('image');
            });
        }

        if (! Schema::hasTable('comments')) {
            Schema::create('comments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('post_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('parent_comment_id')->nullable()->index();
                $table->text('content');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('home_sections')) {
            Schema::create('home_sections', function (Blueprint $table) {
                $table->id();
                $table->string('section_name');
                $table->string('post_type', 32)->default('news');
                $table->text('post_ids')->nullable();      // CSV of news ids
                $table->string('display_style', 32)->default('grid');
                $table->unsignedInteger('section_order')->default(0);
                $table->unsignedTinyInteger('status')->default(1);
            });
        }

        if (! Schema::hasTable('reactions')) {
            Schema::create('reactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('news_id')->index();
                $table->string('type', 32);
                $table->timestamps();
                $table->unique(['user_id', 'news_id']);
            });
        }

        if (! Schema::hasTable('reports')) {
            Schema::create('reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('post_id')->index();
                $table->text('message');
                $table->unsignedBigInteger('date')->nullable();
            });
        }

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('favorite')) {
            Schema::create('favorite', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('post_id')->index();
                $table->unique(['user_id', 'post_id']);
            });
        }

        // The user-facing "users" + Laravel cache/jobs tables come from
        // Laravel's own bootstrap migrations; nothing to do here.
    }

    public function down(): void
    {
        // Intentionally a no-op. This is the baseline for the project; the
        // user-data tables above should never be dropped by `migrate:rollback`.
    }
};
