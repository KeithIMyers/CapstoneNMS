<?php

/*
 * Image-CDN provider defaults. The Site Settings → Image CDN tab
 * overrides these per-install via getcong(); env values shipped here
 * are the fall-throughs.
 *
 *   provider             local | cloudflare | imgix
 *   quality              default JPEG quality (1..100). Drivers
 *                        clamp to provider-recommended ranges
 *                        (CF default 85, imgix default 80).
 *   cloudflare.zone      e.g. https://example.com (defaults to
 *                        APP_URL when blank)
 *   imgix.source         imgix subdomain, e.g. "mysite-prod"
 */
return [
    'provider'   => env('IMAGE_CDN_PROVIDER', 'local'),
    'quality'    => env('IMAGE_CDN_QUALITY'),
    'cloudflare' => [
        'zone' => env('IMAGE_CDN_CLOUDFLARE_ZONE'),
    ],
    'imgix' => [
        'source' => env('IMAGE_CDN_IMGIX_SOURCE'),
    ],
];
