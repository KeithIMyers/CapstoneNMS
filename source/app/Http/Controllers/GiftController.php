<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Services\Paywall\GiftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Subscriber-driven gift-article flow.
 *
 *   GET  /articles/{news}/gift   form to enter a recipient email.
 *   POST /articles/{news}/gift   issue the gift + email it.
 *
 * Authorization:
 *   - must be authenticated
 *   - must be an active subscriber (paywall.subscription_name plan)
 *   - article must be is_premium (free articles don't need gifting)
 *
 * The actual mint + email lives in GiftService.
 */
class GiftController extends Controller
{
    public function __construct(private readonly GiftService $service) {}

    public function show(Request $request, News $news)
    {
        if (! $news->is_premium) {
            return redirect()->route('news.details', ['slug' => $news->slug]);
        }

        $user = Auth::user();
        if (! $user) {
            Session::flash('error_flash_message', 'Sign in to gift articles.');
            return redirect()->route('user_login');
        }

        $status = $this->service->status($user);

        return view('pages.article_gift', [
            'article' => $news,
            'status'  => $status,
            'recentGiftUrl' => null,
        ]);
    }

    public function send(Request $request, News $news): RedirectResponse
    {
        if (! $news->is_premium) {
            return redirect()->route('news.details', ['slug' => $news->slug]);
        }

        $user = Auth::user();
        if (! $user) abort(403);

        if (! $this->service->canIssue($user)) {
            Session::flash('error_flash_message', match ($this->service->status($user)['reason']) {
                'gifts_disabled'      => 'Article gifting isn\'t available right now.',
                'not_subscribed'      => 'You need an active subscription to gift articles.',
                'monthly_cap_reached' => 'You\'ve reached your monthly gift limit. Resets at month-end.',
                default               => 'You can\'t gift this article right now.',
            });
            return redirect()->route('articles.gift.show', ['news' => $news->id]);
        }

        $request->validate([
            'recipient_email' => 'nullable|email|max:255',
        ]);

        $token = $this->service->issue(
            $news,
            $user,
            (string) $request->input('recipient_email') ?: null,
        );
        if (! $token) {
            Session::flash('error_flash_message', 'Couldn\'t create the gift link. Try again in a moment.');
            return redirect()->route('articles.gift.show', ['news' => $news->id]);
        }

        $url = $this->service->giftUrl($news, $token);

        // Stash the URL so the form re-render can show "your link is..."
        // alongside a copy-to-clipboard control. Survives one request.
        Session::flash('flash_message',
            $request->filled('recipient_email')
                ? 'Sent — your friend will get the link in their inbox.'
                : 'Gift link created. Copy it below and share it however you like.'
        );
        Session::flash('gift_url', $url);

        return redirect()->route('articles.gift.show', ['news' => $news->id]);
    }
}
