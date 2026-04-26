<?php

namespace App\Http\Controllers;

use App\Models\NewsletterProduct;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

class NewsletterController extends Controller
{
    /**
     * Step 1: collect an email address + chosen products. Idempotent
     * per (email, product) — re-submitting the same combo just re-
     * sends a confirmation rather than erroring.
     *
     * Form may submit `products[]=slug,slug` to subscribe to multiple
     * products at once. With no products[], we default to the
     * is_default product so the legacy single-form footer still works.
     */
    public function subscribe(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'email'      => 'required|email:rfc|max:255',
            'source'     => 'nullable|string|max:64',
            'products'   => 'nullable|array',
            'products.*' => 'string|max:80',
        ]);
        if ($validator->fails()) {
            Session::flash('error_flash_message', $validator->errors()->first('email'));
            return redirect()->back()->withInput();
        }

        $email = strtolower(trim((string) $request->input('email')));

        // Resolve which products the visitor is opting into. Defaults
        // to just the is_default product when none are supplied.
        $slugs = collect((array) $request->input('products', []))
            ->map(fn ($s) => trim((string) $s))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $products = empty($slugs)
            ? collect(array_filter([NewsletterProduct::default()]))
            : NewsletterProduct::active()->whereIn('slug', $slugs)->get();

        if ($products->isEmpty()) {
            Session::flash('error_flash_message', 'No newsletter is configured yet.');
            return redirect()->back();
        }

        foreach ($products as $product) {
            $sub = Subscription::firstOrCreate(
                ['email' => $email, 'product_id' => $product->id],
                [
                    'token'     => Subscription::newToken(),
                    'source'    => (string) $request->input('source', 'homepage'),
                    'signup_ip' => $request->ip(),
                ]
            );

            // If unsubscribed previously, re-arm and re-confirm by email.
            if ($sub->unsubscribed_at) {
                $sub->forceFill([
                    'unsubscribed_at' => null,
                    'confirmed_at'    => null,
                    'token'           => Subscription::newToken(),
                ])->save();
            }

            $this->sendConfirmation($sub, $product);
        }

        Session::flash('flash_message', 'Almost there — check your inbox to confirm.');
        return redirect()->back();
    }

    /**
     * Step 2: confirm via the link. Token is bound to one (email,
     * product) row; rotates on confirm so the URL becomes single-use.
     */
    public function confirm(string $token)
    {
        $sub = Subscription::where('token', $token)->with('product')->first();
        if (! $sub) {
            return view('pages.newsletter-confirm', ['ok' => false, 'reason' => 'expired']);
        }

        if ($sub->confirmed_at === null) {
            $sub->forceFill([
                'confirmed_at' => now(),
                'token'        => Subscription::newToken(),
            ])->save();

            // Fan out to any active drip campaigns listening for
            // subscriber_confirmed. Idempotent on re-entry.
            try {
                app(\App\Services\Newsletter\SequenceDispatcher::class)->start(
                    \App\Models\EmailSequence::TRIGGER_SUBSCRIBER_CONFIRMED,
                    $sub->email,
                );
            } catch (\Throwable $e) {
                \Log::warning('Sequence start (subscriber confirmed) failed: '.$e->getMessage());
            }
        }

        return view('pages.newsletter-confirm', [
            'ok'      => true,
            'product' => $sub->product,
        ]);
    }

    public function unsubscribe(string $token)
    {
        $sub = Subscription::where('token', $token)->with('product')->first();
        if (! $sub) {
            return view('pages.newsletter-confirm', ['ok' => false, 'reason' => 'expired']);
        }

        $sub->forceFill([
            'unsubscribed_at' => now(),
            'token'           => Subscription::newToken(),
        ])->save();

        return view('pages.newsletter-confirm', [
            'ok'           => true,
            'unsubscribed' => true,
            'product'      => $sub->product,
        ]);
    }

    private function sendConfirmation(Subscription $sub, NewsletterProduct $product): void
    {
        try {
            Mail::send('emails.newsletter-confirm', [
                'confirmUrl'     => url('/newsletter/confirm/'.$sub->token),
                'unsubscribeUrl' => url('/newsletter/unsubscribe/'.$sub->token),
                'email'          => $sub->email,
                'product'        => $product,
            ], function ($message) use ($sub, $product) {
                $message->to($sub->email)->subject(
                    'Confirm your '.$product->name.' subscription'
                );
            });
        } catch (\Throwable $e) {
            Log::warning('newsletter confirm send failed', [
                'email'      => $sub->email,
                'product_id' => $product->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
