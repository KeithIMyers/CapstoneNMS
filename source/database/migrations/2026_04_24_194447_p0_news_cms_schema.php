<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P0 schema for the news CMS:
 *
 *  - News gets editorial workflow + scheduled-publish timestamps, SEO meta,
 *    article anatomy fields (subtitle/kicker/dateline), image metadata.
 *  - Categories get parent_id for hierarchy + a description.
 *  - Comments get a moderation status enum.
 *  - New `tags` and `news_tag` tables replace the legacy CSV `news.tags`
 *    column (which is left in place for now; a follow-up command will
 *    backfill the pivot from the CSV).
 *
 * Existing rows are backfilled so the frontend keeps working with the
 * new `editorial_status` / `published_at` columns regardless of which
 * code path queries them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            if (! Schema::hasColumn('news', 'editorial_status')) {
                $table->string('editorial_status', 24)->default('draft')->after('status');
                $table->index('editorial_status');
            }
            if (! Schema::hasColumn('news', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('editorial_status');
                $table->index('published_at');
            }
            if (! Schema::hasColumn('news', 'unpublished_at')) {
                $table->timestamp('unpublished_at')->nullable()->after('published_at');
            }

            // SEO
            if (! Schema::hasColumn('news', 'meta_title')) {
                $table->string('meta_title', 255)->nullable()->after('excerpt');
            }
            if (! Schema::hasColumn('news', 'meta_description')) {
                $table->string('meta_description', 320)->nullable()->after('meta_title');
            }
            if (! Schema::hasColumn('news', 'canonical_url')) {
                $table->string('canonical_url', 500)->nullable()->after('meta_description');
            }

            // Article anatomy
            if (! Schema::hasColumn('news', 'subtitle')) {
                $table->string('subtitle', 500)->nullable()->after('title');
            }
            if (! Schema::hasColumn('news', 'kicker')) {
                $table->string('kicker', 80)->nullable()->after('subtitle');
            }
            if (! Schema::hasColumn('news', 'dateline')) {
                $table->string('dateline', 120)->nullable()->after('kicker');
            }

            // Image metadata
            if (! Schema::hasColumn('news', 'image_alt')) {
                $table->string('image_alt', 255)->nullable()->after('image');
            }
            if (! Schema::hasColumn('news', 'image_caption')) {
                $table->string('image_caption', 500)->nullable()->after('image_alt');
            }
            if (! Schema::hasColumn('news', 'image_credit')) {
                $table->string('image_credit', 200)->nullable()->after('image_caption');
            }

            if (! Schema::hasColumn('news', 'reading_time_minutes')) {
                $table->unsignedSmallInteger('reading_time_minutes')->nullable()->after('image_credit');
            }
        });

        // Backfill editorial_status / published_at from the legacy `status`
        // boolean and the unix-timestamp `date` field. We only run this
        // when the new columns are still at their defaults to make the
        // migration safely re-runnable.
        DB::table('news')
            ->where('editorial_status', 'draft')
            ->whereNull('published_at')
            ->where('status', 1)
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $publishedAt = null;
                    if (! empty($row->date) && is_numeric($row->date)) {
                        $publishedAt = date('Y-m-d H:i:s', (int) $row->date);
                    } elseif (! empty($row->created_at)) {
                        $publishedAt = $row->created_at;
                    }

                    DB::table('news')->where('id', $row->id)->update([
                        'editorial_status' => 'published',
                        'published_at' => $publishedAt,
                    ]);
                }
            });

        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'parent_id')) {
                $table->unsignedBigInteger('parent_id')->nullable()->after('id');
                $table->index('parent_id');
            }
            if (! Schema::hasColumn('categories', 'description')) {
                $table->text('description')->nullable()->after('slug');
            }
        });

        Schema::table('comments', function (Blueprint $table) {
            if (! Schema::hasColumn('comments', 'status')) {
                $table->string('status', 16)->default('pending')->after('content');
                $table->index('status');
            }
        });

        // Backfill: existing comments are presumed approved (the old code
        // path auto-published them).
        DB::table('comments')->where('status', 'pending')->update(['status' => 'approved']);

        if (! Schema::hasTable('tags')) {
            Schema::create('tags', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80);
                $table->string('slug', 100)->unique();
                $table->string('description', 500)->nullable();
                $table->timestamps();
                $table->index('slug');
            });
        }

        if (! Schema::hasTable('news_tag')) {
            Schema::create('news_tag', function (Blueprint $table) {
                $table->unsignedBigInteger('news_id');
                $table->unsignedBigInteger('tag_id');
                $table->primary(['news_id', 'tag_id']);
                $table->index('tag_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('news_tag');
        Schema::dropIfExists('tags');

        Schema::table('comments', function (Blueprint $table) {
            if (Schema::hasColumn('comments', 'status')) {
                $table->dropIndex(['status']);
                $table->dropColumn('status');
            }
        });

        Schema::table('categories', function (Blueprint $table) {
            foreach (['description'] as $col) {
                if (Schema::hasColumn('categories', $col)) {
                    $table->dropColumn($col);
                }
            }
            if (Schema::hasColumn('categories', 'parent_id')) {
                $table->dropIndex(['parent_id']);
                $table->dropColumn('parent_id');
            }
        });

        Schema::table('news', function (Blueprint $table) {
            $cols = [
                'editorial_status', 'published_at', 'unpublished_at',
                'meta_title', 'meta_description', 'canonical_url',
                'subtitle', 'kicker', 'dateline',
                'image_alt', 'image_caption', 'image_credit',
                'reading_time_minutes',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('news', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
