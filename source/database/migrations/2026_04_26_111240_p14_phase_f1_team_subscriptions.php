<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P14 Phase F.1 — group / team subscriptions.
 *
 *   subscription_tiers       gains is_team + min_seats + max_seats
 *                            so admins can mark a tier as a team
 *                            plan and bound the seat-count picker
 *                            on the public subscribe page.
 *
 *   team_subscriptions       one row per active team plan. The
 *                            owner_user_id is whoever paid; the
 *                            tier_slug doubles as Cashier
 *                            subscription `name` (matches the
 *                            single-seat plan model) so the
 *                            existing Paywall + GiftService gates
 *                            stay consistent.
 *
 *   team_seats               one row per assigned or pending seat.
 *                            invited_email + invitation_token_hash
 *                            are populated on invite; cleared when
 *                            member_user_id fills on accept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_tiers', function (Blueprint $table) {
            if (! Schema::hasColumn('subscription_tiers', 'is_team')) {
                $table->boolean('is_team')->default(false)->after('annual_price_cents');
            }
            if (! Schema::hasColumn('subscription_tiers', 'min_seats')) {
                $table->unsignedSmallInteger('min_seats')->default(1)->after('is_team');
            }
            if (! Schema::hasColumn('subscription_tiers', 'max_seats')) {
                $table->unsignedSmallInteger('max_seats')->default(1)->after('min_seats');
            }
        });

        if (! Schema::hasTable('team_subscriptions')) {
            Schema::create('team_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('owner_user_id')->index();
                $table->string('tier_slug', 60)->index();
                $table->unsignedSmallInteger('seat_count')->default(1);
                $table->string('team_name', 120)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('team_seats')) {
            Schema::create('team_seats', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('team_subscription_id')->index();
                $table->unsignedBigInteger('member_user_id')->nullable()->index();
                $table->string('invited_email', 255)->nullable()->index();
                $table->string('invitation_token_hash', 64)->nullable()->index();
                $table->string('status', 16)->default('open'); // open|invited|active|removed
                $table->timestamp('invited_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('removed_at')->nullable();
                $table->timestamps();
                $table->unique(['team_subscription_id', 'member_user_id'], 'team_seat_unique_member');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('team_seats');
        Schema::dropIfExists('team_subscriptions');
        Schema::table('subscription_tiers', function (Blueprint $table) {
            foreach (['is_team', 'min_seats', 'max_seats'] as $col) {
                if (Schema::hasColumn('subscription_tiers', $col)) $table->dropColumn($col);
            }
        });
    }
};
