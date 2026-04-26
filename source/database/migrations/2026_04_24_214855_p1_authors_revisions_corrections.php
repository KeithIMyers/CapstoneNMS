<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1 schema: public-author profile fields, multi-byline pivot, and a
 * corrections/revisions log per article.
 *
 *  - users gets `slug`, `bio`, `twitter_handle` so we can build
 *    /author/{slug} pages with a real bio.
 *  - news_authors links a single article to many users with a role
 *    (primary, contributor, photographer, illustrator, etc.). The
 *    legacy news.user_id column is kept; the convention is that the
 *    primary byline is the user_id row, additional bylines live in
 *    the pivot.
 *  - news_revisions records an editor-visible corrections / updates
 *    log. Editors append entries from the Filament repeater; the
 *    article footer renders them with timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'slug')) {
                $table->string('slug', 100)->nullable()->after('name');
                $table->index('slug');
            }
            if (! Schema::hasColumn('users', 'bio')) {
                $table->text('bio')->nullable()->after('slug');
            }
            if (! Schema::hasColumn('users', 'twitter_handle')) {
                $table->string('twitter_handle', 60)->nullable()->after('bio');
            }
        });

        if (! Schema::hasTable('news_authors')) {
            Schema::create('news_authors', function (Blueprint $table) {
                $table->unsignedBigInteger('news_id');
                $table->unsignedBigInteger('user_id');
                $table->string('role', 32)->default('contributor');
                $table->unsignedSmallInteger('sort')->default(0);
                $table->primary(['news_id', 'user_id']);
                $table->index('user_id');
            });
        }

        if (! Schema::hasTable('news_revisions')) {
            Schema::create('news_revisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('news_id')->index();
                $table->unsignedBigInteger('editor_id')->nullable()->index();
                $table->string('kind', 24)->default('update');     // update | correction | clarification
                $table->text('note');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('news_revisions');
        Schema::dropIfExists('news_authors');

        Schema::table('users', function (Blueprint $table) {
            foreach (['twitter_handle', 'bio'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
            if (Schema::hasColumn('users', 'slug')) {
                $table->dropIndex(['slug']);
                $table->dropColumn('slug');
            }
        });
    }
};
