<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P13 Phase C.3 — block-based article body.
 *
 * Adds news.content_blocks (JSON). When set, the public detail page
 * renders the blocks via the BlockRenderer service; when null, the
 * legacy HTML `content` column still works. Editors opt in
 * per-article via the new Builder field on the news form, so
 * existing articles need no migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('news', 'content_blocks')) {
            Schema::table('news', function (Blueprint $table) {
                // longText to keep JSON above MySQL's 64 KB TEXT cap;
                // long-form articles with many blocks easily clear that.
                $table->longText('content_blocks')->nullable()->after('content');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('news', 'content_blocks')) {
            Schema::table('news', function (Blueprint $table) {
                $table->dropColumn('content_blocks');
            });
        }
    }
};
