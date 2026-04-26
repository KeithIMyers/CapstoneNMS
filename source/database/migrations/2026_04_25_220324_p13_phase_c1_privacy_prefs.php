<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P13 Phase C.1 — privacy preferences + scheduled-deletion timestamp.
 *
 *   privacy_prefs           JSON bag of CCPA / GDPR-style toggles:
 *                             do_not_sell:           bool
 *                             marketing_email_opt_out: bool
 *                             behavioral_tracking_opt_out: bool
 *
 *   deletion_requested_at   when the user clicked "delete my account".
 *                           Login is blocked while this is set; the
 *                           PurgeDeletedAccountsCommand hard-deletes
 *                           the row + cascades after the grace
 *                           window. Clearing the timestamp restores
 *                           access (cancel-deletion flow).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'privacy_prefs')) {
                $table->json('privacy_prefs')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('users', 'deletion_requested_at')) {
                $table->timestamp('deletion_requested_at')->nullable()->after('privacy_prefs')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'deletion_requested_at')) {
                $table->dropColumn('deletion_requested_at');
            }
            if (Schema::hasColumn('users', 'privacy_prefs')) {
                $table->dropColumn('privacy_prefs');
            }
        });
    }
};
