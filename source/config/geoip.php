<?php

/*
 * MaxMind GeoLite2 country lookup.
 *
 * Setup:
 *   1. composer install pulls geoip2/geoip2.
 *   2. Sign up for a free MaxMind account, generate a license key,
 *      download GeoLite2-Country.mmdb.
 *   3. Drop it at the path below (default: storage/app/geoip/...).
 *      A monthly CRON refresh keeps it current; the file is stable
 *      enough that quarterly is fine for casual use.
 *
 * The whole geo pipeline fails soft when the database is missing,
 * so leaving it unconfigured doesn't break anything.
 */
return [
    'database_path' => env('GEOIP_DATABASE_PATH', storage_path('app/geoip/GeoLite2-Country.mmdb')),
];
