<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P14 Phase F.2 — referral codes + reward tracking.
 *
 *   users.referral_code             unique code per user, lazily
 *                                   generated on first request.
 *   users.referred_by_user_id       set at signup when `?ref=CODE`
 *                                   was carried in via cookie or
 *                                   query param.
 *
 *   referral_rewards                one row per (referrer, referee)
 *                                   conversion. Reward types are
 *                                   open-ended ("gift_articles",
 *                                   "free_months", "credit") so
 *                                   admins can flip fulfillment
 *                                   strategy without a schema
 *                                   change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'referral_code')) {
                $table->string('referral_code', 20)->nullable()->unique()->after('image');
            }
            if (! Schema::hasColumn('users', 'referred_by_user_id')) {
                $table->unsignedBigInteger('referred_by_user_id')->nullable()->index()->after('referral_code');
            }
        });

        if (! Schema::hasTable('referral_rewards')) {
            Schema::create('referral_rewards', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('referrer_user_id')->index();
                $table->unsignedBigInteger('referee_user_id')->nullable()->index();
                $table->string('referee_email', 255)->nullable();
                $table->string('reward_type', 32);                  // gift_articles | free_months | credit
                $table->integer('reward_amount')->default(0);
                $table->string('status', 24)->default('pending');   // pending | granted | fulfilled | revoked
                $table->timestamp('triggered_at')->nullable();
                $table->timestamp('granted_at')->nullable();
                $table->timestamp('fulfilled_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['referrer_user_id', 'referee_user_id'], 'referral_rewards_pair_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'referred_by_user_id')) $table->dropColumn('referred_by_user_id');
            if (Schema::hasColumn('users', 'referral_code')) $table->dropColumn('referral_code');
        });
    }
};
