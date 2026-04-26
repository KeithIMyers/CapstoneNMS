<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * P7 Phase F (pre-Cashier) — rename the newsletter "subscriptions" table
 * to "newsletter_subscriptions" so Stripe Cashier can claim the
 * canonical "subscriptions" name in the next migration.
 *
 * Runs idempotently: if the legacy table doesn't exist (fresh install)
 * this is a no-op. If both tables somehow exist (mid-rollback), we
 * leave them alone — admins reconcile by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscriptions') && ! Schema::hasTable('newsletter_subscriptions')) {
            Schema::rename('subscriptions', 'newsletter_subscriptions');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('newsletter_subscriptions') && ! Schema::hasTable('subscriptions')) {
            Schema::rename('newsletter_subscriptions', 'subscriptions');
        }
    }
};
