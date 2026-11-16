<?php

declare(strict_types=1);

/*
 * Router for the local demo (F48): php -S 127.0.0.1:8124 -t public scripts/demo-server.php
 * Loads .env.demo (APP_ENV=demo, built by `php artisan dochamster:demo`) and serves public/ like the web server would.
 */

putenv('APP_ENV=demo');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'demo';

$public = dirname(__DIR__).'/public';
$path = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($path !== '/' && is_file($public.$path)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $public.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
chdir($public);

require $public.'/index.php';
