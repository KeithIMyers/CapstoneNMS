<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add breaking-news flag + auto-expiry timestamp to articles. Powers the
 * red banner partial (partials/site/breaking.blade.php) and the
 * breaking-story chip on the homepage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            if (! Schema::hasColumn('news', 'is_breaking')) {
                $table->boolean('is_breaking')->default(false)->after('is_featured');
                $table->index('is_breaking');
            }
            if (! Schema::hasColumn('news', 'breaking_until')) {
                $table->timestamp('breaking_until')->nullable()->after('is_breaking');
            }
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            if (Schema::hasColumn('news', 'breaking_until')) {
                $table->dropColumn('breaking_until');
            }
            if (Schema::hasColumn('news', 'is_breaking')) {
                $table->dropIndex(['is_breaking']);
                $table->dropColumn('is_breaking');
            }
        });
    }
};
