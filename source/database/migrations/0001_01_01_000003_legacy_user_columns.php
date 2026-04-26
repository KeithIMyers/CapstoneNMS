<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the legacy CodeCanyon-era user columns for installs whose
 * create_users_table migration ran before those fields were added to
 * the canonical schema.
 *
 * Each Schema::table call is guarded with hasColumn so this is a
 * no-op on fresh installs that picked up the updated
 * create_users_table (which already provisions all of these inline).
 *
 * Why this matters: later migrations reference these columns —
 * e.g. p13_phase_c1_privacy_prefs uses `->after('phone')`. On an
 * install that ran the old create_users_table and got partway
 * through subsequent migrations before failing, those columns are
 * absent and the chain doesn't recover.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'slug')) {
                $table->string('slug')->nullable()->unique()->after('rememberToken');
            }
            if (! Schema::hasColumn('users', 'bio')) {
                $table->text('bio')->nullable();
            }
            if (! Schema::hasColumn('users', 'twitter_handle')) {
                $table->string('twitter_handle')->nullable();
            }
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 40)->nullable();
            }
            if (! Schema::hasColumn('users', 'image')) {
                $table->string('image')->nullable();
            }
            if (! Schema::hasColumn('users', 'status')) {
                $table->unsignedTinyInteger('status')->default(1)->index();
            }
            if (! Schema::hasColumn('users', 'is_agent')) {
                $table->boolean('is_agent')->default(false)->index();
            }
            if (! Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id')->nullable()->unique();
            }
            if (! Schema::hasColumn('users', 'ai_daily_budget_microusd')) {
                $table->unsignedBigInteger('ai_daily_budget_microusd')->nullable();
            }
            if (! Schema::hasColumn('users', 'ai_calls_per_minute')) {
                $table->unsignedSmallInteger('ai_calls_per_minute')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intentionally one-way. The downstream migrations reference
        // these columns; rolling them back would just guarantee
        // failure on the next migrate.
    }
};
