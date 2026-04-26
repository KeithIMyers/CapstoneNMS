<?php

namespace App\Console\Commands;

use App\Services\Licensing\LicenseService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Mint a development license for a local dev machine.
 *
 * Phase A: writes a plain-JSON license envelope (no Ed25519 signature
 * yet — Phase B's verifier rewrites this to call the keygen
 * library). The license is kind=development, which forces the
 * dev-banner ON across the public site and the Filament admin even
 * after Phase B's signature check is in place.
 *
 * The output file is gitignored from source/storage/app/private/
 * AND excluded from build/ by scripts/build.sh, so it never ends up
 * in a customer-facing dist artifact.
 *
 *   php artisan license:install-dev
 *   php artisan license:install-dev --tier=team --days=30
 *
 * Refuses to run when APP_ENV=production — even a developer who'd
 * accidentally run this on prod would only mint a development
 * license, which always shows the public banner. Prevents the
 * banner-less "production" license from ever being issued without
 * the keygen private key.
 */
class InstallDevLicenseCommand extends Command
{
    protected $signature = 'license:install-dev
                            {--tier=enterprise : Tier slug from config/capstone.php}
                            {--days=90 : Days until expiry}
                            {--customer=Local Developer : Display name on the License page}';

    protected $description = 'Mint a kind=development license file for local development. Refuses on APP_ENV=production.';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to mint a dev license on APP_ENV=production. If this is a dev machine, set APP_ENV=local.');
            return self::FAILURE;
        }

        $tier = (string) $this->option('tier');
        $tierConfig = (array) config("capstone.tiers.{$tier}", []);
        if ($tierConfig === []) {
            $this->error("Unknown tier '{$tier}'. Tiers: ".implode(', ', array_keys(config('capstone.tiers', []))));
            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $customer = (string) $this->option('customer');

        $payload = [
            'v'                       => 1,
            'id'                      => 'lic_dev_'.Str::lower(Str::random(12)),
            'kind'                    => LicenseService::KIND_DEVELOPMENT,
            'tier'                    => $tier,
            'customer'                => $customer,
            'customer_email'          => 'dev@localhost',
            // Loopback + .test / .local / .localhost suffixes — same
            // hosts the runtime always-allows when proxy-headers are
            // absent. A dev license matching these is a belt-and-
            // suspenders approach so the banner shows everywhere.
            'domains'                 => [
                'localhost', '127.0.0.1', '::1',
                '*.test', '*.local', '*.localhost',
            ],
            'limits' => [
                'admins'  => (int) ($tierConfig['admins']  ?? 1),
                'editors' => (int) ($tierConfig['editors'] ?? 0),
                'authors' => (int) ($tierConfig['authors'] ?? 0),
                'agents'  => (int) ($tierConfig['agents']  ?? 0),
            ],
            'features'                => (array) ($tierConfig['feature_flags'] ?? []),
            'force_powered_by_footer' => true, // dev licenses always show "Powered by"
            'issued_at'               => Carbon::now()->toIso8601String(),
            'expires_at'              => Carbon::now()->addDays($days)->toIso8601String(),
        ];

        // Phase B: this becomes "<base64(json)>.<base64(ed25519_sign(json))>".
        // Phase A: plain JSON. The runtime parser already accepts
        // either form, so the upgrade is signature-only.
        $envelope = json_encode($payload, JSON_PRETTY_PRINT);

        $disk = Storage::disk('local');
        $disk->put(LicenseService::STORAGE_PATH, $envelope);

        // Bust the LicenseService status cache so the next request
        // sees the new license without restarting the queue worker.
        app(LicenseService::class)->flush();

        $this->info('Development license installed.');
        $this->line('');
        $this->line('  Tier:      '.$tier.' (caps from config/capstone.php)');
        $this->line('  Customer:  '.$customer);
        $this->line('  Expires:   '.Carbon::parse($payload['expires_at'])->toDateString().' ('.$days.' days)');
        $this->line('  Domains:   '.implode(', ', $payload['domains']));
        $this->line('');
        $this->line('The dev banner will appear on every page until the license expires or you replace it with a kind=production license.');

        return self::SUCCESS;
    }
}
