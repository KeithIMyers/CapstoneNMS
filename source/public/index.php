<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
 * CapstoneNMS — layout-aware front controller.
 *
 * On most hosts this file lives at <install>/public/index.php and the
 * Laravel app is one level up. Some shared hosts (DirectAdmin, cPanel,
 * Plesk) hard-code public_html/ as the doc root and don't allow it to
 * be reassigned. Three layout states this file handles, in priority
 * order:
 *
 *   1. STANDARD  — index.php at <install>/public/, app at <install>/.
 *                  Bootstrap is __DIR__.'/../bootstrap/app.php'.
 *
 *   2. RELAID    — customer extracted the dist directly into
 *                  public_html/ and the installer's relayout step
 *                  moved the app into <doc-root>/_app/. Bootstrap is
 *                  __DIR__.'/_app/bootstrap/app.php'.
 *
 *   3. PRE-LAYOUT — customer just extracted the dist directly into
 *                   public_html/ and the relayout hasn't run yet.
 *                   The whole Laravel tree is sitting next to this
 *                   file. Bootstrap is __DIR__.'/bootstrap/app.php'.
 *                   The installer detects this and offers to convert
 *                   to layout 2.
 *
 * The same file works for all three so a customer can extract
 * anywhere and either way it boots — the installer / updater handle
 * the layout transitions.
 */

$base = null;
foreach (['/..', '/_app', ''] as $candidate) {
    $bootstrap = __DIR__ . $candidate . '/bootstrap/app.php';
    $autoload  = __DIR__ . $candidate . '/vendor/autoload.php';
    if (is_file($bootstrap) && is_file($autoload)) {
        $base = __DIR__ . $candidate;
        break;
    }
}

if ($base === null) {
    http_response_code(500);
    echo 'CapstoneNMS bootstrap failed: could not locate bootstrap/app.php. ';
    echo 'Did the dist extract correctly?';
    exit;
}

if (file_exists($maintenance = $base . '/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $base . '/vendor/autoload.php';

/** @var Application $app */
$app = require_once $base . '/bootstrap/app.php';

$app->handleRequest(Request::capture());
