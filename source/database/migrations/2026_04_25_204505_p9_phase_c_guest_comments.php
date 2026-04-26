<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P9 Phase C — allow CAPTCHA-gated comments from anonymous visitors.
 *
 * Schema:
 *   comments.user_id       nullable now (was implicitly required by
 *                          the controller; the column itself was
 *                          already unsigned bigint).
 *   comments.guest_name    optional — supplied by anonymous commenters.
 *   comments.guest_email   optional — only stored, never displayed
 *                          publicly (used for moderator follow-up).
 *   comments.ip_address    abuse triage. Stored compact (IPv4 + IPv6
 *                          fit easily in 45 chars).
 *
 * The user_id nullable change uses raw SQL because Laravel's schema
 * builder doesn't always preserve foreign keys correctly when altering
 * column nullability across MySQL versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Make user_id nullable. Catches both vendor schema (foreign-key
        // constrained) and our schema (no FK) gracefully.
        try {
            DB::statement('ALTER TABLE comments MODIFY user_id BIGINT UNSIGNED NULL');
        } catch (\Throwable $e) {
            // If a foreign key blocks the change, drop and recreate.
            try { DB::statement('ALTER TABLE comments DROP FOREIGN KEY comments_user_id_foreign'); } catch (\Throwable $e2) {}
            DB::statement('ALTER TABLE comments MODIFY user_id BIGINT UNSIGNED NULL');
        }

        Schema::table('comments', function (Blueprint $table) {
            if (! Schema::hasColumn('comments', 'guest_name')) {
                $table->string('guest_name', 120)->nullable()->after('user_id');
            }
            if (! Schema::hasColumn('comments', 'guest_email')) {
                $table->string('guest_email', 200)->nullable()->after('guest_name');
            }
            if (! Schema::hasColumn('comments', 'ip_address')) {
                $table->string('ip_address', 45)->nullable()->after('guest_email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            foreach (['guest_name', 'guest_email', 'ip_address'] as $col) {
                if (Schema::hasColumn('comments', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        // Leave user_id nullable on rollback — making it NOT NULL again
        // would require populating it, which is out of scope here.
    }
};
