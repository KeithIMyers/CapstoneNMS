<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * One-shot Web Push VAPID key generator. Prints a public + private
 * key pair the admin pastes into .env. Generated once per install;
 * regenerating invalidates every existing push subscription on the
 * site, so don't rotate casually.
 *
 *   php artisan webpush:keys
 */
class WebPushKeysCommand extends Command
{
    protected $signature = 'webpush:keys';
    protected $description = 'Generate a VAPID key pair for Web Push notifications';

    public function handle(): int
    {
        if (! class_exists(VAPID::class)) {
            $this->error('minishlink/web-push is not installed. Run: composer require minishlink/web-push');
            return self::FAILURE;
        }

        $keys = VAPID::createVapidKeys();
        $this->line('');
        $this->info('Add these to your .env (regenerating invalidates existing subscriptions):');
        $this->line('');
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:admin@'.parse_url((string) config('app.url'), PHP_URL_HOST));
        $this->line('');
        $this->info('Run `php artisan config:cache` after updating .env.');
        return self::SUCCESS;
    }
}
