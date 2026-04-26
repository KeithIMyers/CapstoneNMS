<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P14 Phase E.1 — accessibility preferences for authenticated users.
 *
 * Anonymous readers' choices live in localStorage only. Once a
 * reader signs in, server-side prefs override localStorage so
 * accommodations follow the reader across devices.
 *
 * Stored as a single JSON column rather than five booleans so
 * future preference toggles don't need a migration each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'accessibility_prefs')) {
                $table->json('accessibility_prefs')->nullable()->after('privacy_prefs');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'accessibility_prefs')) {
                $table->dropColumn('accessibility_prefs');
            }
        });
    }
};
