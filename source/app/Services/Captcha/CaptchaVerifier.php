<?php

namespace App\Services\Captcha;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verify a CAPTCHA response against the configured provider.
 * Two providers supported:
 *
 *   recaptcha — Google reCAPTCHA v2 / v3 (POST to siteverify with
 *               secret + response). Score check only on v3.
 *   turnstile — Cloudflare Turnstile (POST to siteverify; same shape,
 *               more privacy-friendly).
 *
 * The provider, site key, and secret are read from the settings table:
 *   captcha_provider     none | recaptcha | turnstile
 *   captcha_site_key     public site key (used in the rendered widget)
 *   captcha_secret_key   secret key (server-side only)
 *
 * Fail-closed: any error verifying counts as "not verified" so a flaky
 * provider can't be used to bypass the gate.
 *
 * isEnabled() checks the provider config independently of comments_allow_
 * guests so the comment controller can decide whether to show the widget.
 */
class CaptchaVerifier
{
    public function isEnabled(): bool
    {
        $p = $this->provider();
        if ($p === null) return false;
        return ! empty($this->siteKey()) && ! empty($this->secretKey());
    }

    public function provider(): ?string
    {
        $val = function_exists('getcong') ? trim((string) getcong('captcha_provider')) : '';
        return in_array($val, ['recaptcha', 'turnstile'], true) ? $val : null;
    }

    public function siteKey(): ?string
    {
        $v = function_exists('getcong') ? trim((string) getcong('captcha_site_key')) : '';
        return $v === '' ? null : $v;
    }

    private function secretKey(): ?string
    {
        $v = function_exists('getcong') ? trim((string) getcong('captcha_secret_key')) : '';
        return $v === '' ? null : $v;
    }

    /**
     * Verify the visitor's CAPTCHA token. Reads the provider-specific
     * field name from the request:
     *   recaptcha → g-recaptcha-response
     *   turnstile → cf-turnstile-response
     */
    public function verify(Request $request): bool
    {
        if (! $this->isEnabled()) return true; // open mode

        $provider = $this->provider();
        $token = match ($provider) {
            'recaptcha' => (string) $request->input('g-recaptcha-response'),
            'turnstile' => (string) $request->input('cf-turnstile-response'),
            default     => '',
        };
        if ($token === '') return false;

        $endpoint = match ($provider) {
            'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify',
            'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            default     => null,
        };
        if (! $endpoint) return false;

        try {
            $response = Http::asForm()->timeout(8)->post($endpoint, [
                'secret'   => $this->secretKey(),
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Captcha verify threw', ['provider' => $provider, 'error' => $e->getMessage()]);
            return false;
        }

        if (! $response->successful()) return false;
        $data = $response->json();
        if (! is_array($data) || empty($data['success'])) return false;

        // For reCAPTCHA v3, score below 0.5 means likely bot.
        if ($provider === 'recaptcha' && isset($data['score']) && $data['score'] < 0.5) {
            return false;
        }
        return true;
    }
}
