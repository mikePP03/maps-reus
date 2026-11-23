<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Igual que api.jumechi-soft.com: la app Laravel vive junto a public_html, fuera de la parte pública.
// domains/backend.jumechi-soft.com/{public_html, maps-reus/backend}
$app_dir = __DIR__.'/../maps-reus/backend';

if (file_exists($maintenance = $app_dir.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $app_dir.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $app_dir.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
