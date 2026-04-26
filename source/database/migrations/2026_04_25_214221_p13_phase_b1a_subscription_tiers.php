<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P13 Phase B.1a — admin-managed subscription tiers.
 *
 * Replaces the single hardcoded "default" subscription with a tier
 * model. Each row is an offering on the public /subscribe page; the
 * tier `slug` doubles as the Cashier subscription name, so calls
 * like `$user->subscribed('bronze')` or `$user->newSubscription(
 * 'bronze', $priceId)` slot in cleanly without changing Cashier's
 * model.
 *
 * If the legacy STRIPE_DEFAULT_PRICE env is set, we seed a "default"
 * tier so the existing single-plan behavior keeps working through
 * the new schema. New installs start with no tiers — admins add
 * them via the Filament resource.
 *
 * `entitlements` is a JSON bag describing what the tier unlocks
 * beyond paywall bypass — e.g. {"ask_quota":"unlimited"} or
 * {"comment_color":"gold"}. Reserved for future tier-specific
 * features; unused today.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscription_tiers')) {
            Schema::create('subscription_tiers', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 60)->unique();
                $table->string('name', 120);
                $table->text('description')->nullable();
                $table->string('stripe_price_monthly', 191)->nullable();
                $table->string('stripe_price_annual', 191)->nullable();
                $table->unsignedInteger('monthly_price_cents')->nullable();
                $table->unsignedInteger('annual_price_cents')->nullable();
                $table->string('currency', 8)->default('USD');
                $table->json('entitlements')->nullable();
                $table->boolean('active')->default(true);
                $table->boolean('is_default')->default(false);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        // Backfill: if the legacy env vars are set and there are no
        // tiers yet, seed a default tier. New installs see an empty
        // state in the admin and the public subscribe page.
        $legacyMonthly = env('STRIPE_DEFAULT_PRICE');
        $legacyAnnual  = env('STRIPE_ANNUAL_PRICE');
        if ($legacyMonthly && DB::table('subscription_tiers')->count() === 0) {
            DB::table('subscription_tiers')->insert([
                'slug'                  => 'default',
                'name'                  => 'Member',
                'description'           => 'Full access to premium articles, ad-light experience, and member-only features.',
                'stripe_price_monthly'  => $legacyMonthly,
                'stripe_price_annual'   => $legacyAnnual ?: null,
                'currency'              => 'USD',
                'active'                => true,
                'is_default'            => true,
                'sort_order'            => 0,
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_tiers');
    }
};
