<?php

/*
 * Web Push (VAPID) configuration. Generate the key pair once with
 *
 *   php artisan webpush:keys
 *
 * and paste the output into .env as VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY.
 * The subject must be a mailto: URL or your own https:// URL — push
 * services use it to identify who's sending the notification.
 *
 * Leaving any of the three blank silently disables Web Push: visitors
 * won't see the subscribe prompt, and the breaking-news observer skips
 * dispatch.
 */
return [
    'vapid_public'  => env('VAPID_PUBLIC_KEY'),
    'vapid_private' => env('VAPID_PRIVATE_KEY'),
    'subject'       => env('VAPID_SUBJECT', 'mailto:'.env('MAIL_FROM_ADDRESS', 'admin@'.parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST))),
];
