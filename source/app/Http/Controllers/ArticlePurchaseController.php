<?php

namespace App\Http\Controllers;

use App\Models\ArticlePurchase;
use App\Models\News;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Stripe;

/**
 * Pay-per-article flow. A non-subscriber clicks "Buy this article"
 * on a premium piece, lands in Stripe Checkout (one-time charge),
 * and after success is granted access via an article_purchases row.
 *
 * Settings keys (admin-editable in Site Settings → Paywall, env
 * fallback in config/paywall.php):
 *
 *   paywall_per_article_enabled        master switch
 *   paywall_per_article_price_cents    cost in cents
 *   paywall_per_article_ttl_days       0 = forever, otherwise expires
 */
class ArticlePurchaseController extends Controller
{
    public function checkout(Request $request, News $news)
    {
        // License feature gate: paywall + pay-per-article are Pro /
        // Enterprise only. On lower tiers the route stays registered
        // but refuses up front so editorial flagging still works
        // without billing fully wiring up.
        if (! app(\App\Services\Licensing\LicenseService::class)->feature('paywall')) {
            return redirect()->route('news.details', ['slug' => $news->slug]);
        }
        if (! $this->isEnabled()) {
            Session::flash('error_flash_message', 'Single-article purchases aren\'t available right now.');
            return redirect()->route('news.details', ['slug' => $news->slug]);
        }
        if (! $news->is_premium) {
            return redirect()->route('news.details', ['slug' => $news->slug]);
        }

        $user = Auth::user();
        if (! $user) {
            Session::flash('error_flash_message', 'Sign in or create an account to buy this article.');
            return redirect()->route('user_login');
        }

        if (method_exists($user, 'isPaidSubscriber') && $user->isPaidSubscriber()) {
            return redirect()->route('news.details', ['slug' => $news->slug]);
        }

        if (! config('cashier.secret')) {
            Session::flash('error_flash_message', 'Stripe isn\'t configured.');
            return redirect()->route('news.details', ['slug' => $news->slug]);
        }

        $priceCents = $this->priceCents();
        $ttlDays    = $this->ttlDays();

        $purchase = ArticlePurchase::create([
            'user_id'      => $user->id,
            'news_id'      => $news->id,
            'amount_cents' => $priceCents,
            'currency'     => 'USD',
            'expires_at'   => $ttlDays > 0 ? Carbon::now()->addDays($ttlDays) : null,
            'status'       => 'pending',
        ]);

        Stripe::setApiKey(config('cashier.secret'));

        $session = StripeSession::create([
            'mode'                  => 'payment',
            'success_url'           => route('news.details', ['slug' => $news->slug]).'?paid=1',
            'cancel_url'            => route('news.details', ['slug' => $news->slug]),
            'customer_email'        => $user->email,
            'metadata'              => [
                'article_purchase_id' => (string) $purchase->id,
                'kind'                => 'article_purchase',
            ],
            'line_items' => [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => 'usd',
                    'product_data' => [
                        'name'        => \Illuminate\Support\Str::limit(strip_tags((string) $news->title), 90),
                        'description' => 'Single-article access · '.(getcong('site_name') ?: config('app.name')),
                    ],
                    'unit_amount'  => $priceCents,
                ],
            ]],
        ]);

        $purchase->forceFill(['stripe_session_id' => $session->id])->save();

        return redirect($session->url, 303);
    }

    public static function isEnabled(): bool
    {
        $val = function_exists('getcong') ? getcong('paywall_per_article_enabled') : null;
        if ($val === null || $val === '') {
            return (bool) config('paywall.per_article.enabled', false);
        }
        return in_array(strtolower((string) $val), ['1', 'true', 'on', 'yes'], true);
    }

    public static function priceCents(): int
    {
        $val = function_exists('getcong') ? getcong('paywall_per_article_price_cents') : null;
        if ($val !== null && $val !== '' && preg_match('/^\d+$/', (string) $val)) {
            return (int) $val;
        }
        return (int) config('paywall.per_article.price_cents', 199);
    }

    public static function ttlDays(): int
    {
        $val = function_exists('getcong') ? getcong('paywall_per_article_ttl_days') : null;
        if ($val !== null && $val !== '' && preg_match('/^\d+$/', (string) $val)) {
            return (int) $val;
        }
        return (int) config('paywall.per_article.ttl_days', 0);
    }
}
