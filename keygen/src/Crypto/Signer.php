<?php

declare(strict_types=1);

namespace CapstoneNMS\Keygen\Crypto;

/**
 * Ed25519 signing helpers used by the keygen subcommands AND mirrored
 * by the runtime verifier (App\Services\Licensing\LicenseService).
 *
 * The license envelope wire format is:
 *
 *   <base64url(payload_json)>.<base64url(detached_signature)>
 *
 * `base64url` is the URL-safe variant (`-_` instead of `+/`, no `=`
 * padding). It survives email pastes and ad-hoc form fields without
 * mangling, and matches the encoding the runtime verifier expects.
 *
 * Signature is over the RAW JSON BYTES (not the base64-encoded form)
 * so a recipient who can decode the envelope can also recompute the
 * verify input deterministically.
 */
final class Signer
{
    /** Generate a new Ed25519 keypair. Returns ['public' => 32 bytes, 'private' => 64 bytes]. */
    public static function newKeypair(): array
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            throw new \RuntimeException('libsodium / sodium_crypto_sign_* functions not available — install ext-sodium.');
        }
        $kp = sodium_crypto_sign_keypair();
        return [
            'public'  => sodium_crypto_sign_publickey($kp),
            'private' => sodium_crypto_sign_secretkey($kp),
        ];
    }

    /**
     * Sign a JSON payload with the secret key and produce the wire-
     * format envelope: "<b64url(json)>.<b64url(sig)>".
     */
    public static function signEnvelope(array $payload, string $secretKey): string
    {
        $json = self::encodeJson($payload);
        $sig  = sodium_crypto_sign_detached($json, $secretKey);
        return self::base64UrlEncode($json).'.'.self::base64UrlEncode($sig);
    }

    /**
     * Verify an envelope against a public key. Returns the decoded
     * payload on success, null on any failure (bad format, bad
     * signature, malformed JSON). Constant-time at the libsodium
     * level so we don't leak timing.
     */
    public static function verifyEnvelope(string $envelope, string $publicKey): ?array
    {
        $envelope = trim($envelope);
        if ($envelope === '' || ! str_contains($envelope, '.')) return null;

        [$b64payload, $b64sig] = explode('.', $envelope, 2);
        $json = self::base64UrlDecode($b64payload);
        $sig  = self::base64UrlDecode($b64sig);
        if ($json === '' || $sig === '') return null;

        try {
            $ok = sodium_crypto_sign_verify_detached($sig, $json, $publicKey);
        } catch (\SodiumException $e) {
            return null;
        }
        if (! $ok) return null;

        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Canonical JSON encoding for the payload. Keys are sorted so
     * a regenerated envelope from the same payload is byte-identical
     * to the original — handy for diffing licenses.
     */
    public static function encodeJson(array $payload): string
    {
        // Sort recursively so signed-then-resigned of the same logical
        // payload produces identical bytes.
        $sorted = self::ksortRecursive($payload);
        return (string) json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function ksortRecursive(array $arr): array
    {
        ksort($arr);
        foreach ($arr as $k => $v) {
            if (is_array($v)) $arr[$k] = self::ksortRecursive($v);
        }
        return $arr;
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $s): string
    {
        $pad = (4 - strlen($s) % 4) % 4;
        $out = base64_decode(strtr($s, '-_', '+/').str_repeat('=', $pad), true);
        return $out === false ? '' : $out;
    }
}
