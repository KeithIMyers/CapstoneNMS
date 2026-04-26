<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P7 Phase F — paywall flag on articles.
 *
 * `is_premium` marks articles that require an active subscription to
 * read in full. Public visitors see a preview of the lead paragraphs +
 * a "Subscribe to read more" CTA; subscribers see the full body.
 *
 * Cashier's own tables (subscriptions, subscription_items, customer
 * columns on users) are published into database/migrations/ from the
 * laravel/cashier package by the deploy script before migrate runs,
 * so we don't keep our own copy of those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            if (! Schema::hasColumn('news', 'is_premium')) {
                $table->boolean('is_premium')->default(false)->index()->after('is_breaking');
            }
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            if (Schema::hasColumn('news', 'is_premium')) {
                $table->dropIndex(['is_premium']);
                $table->dropColumn('is_premium');
            }
        });
    }
};
