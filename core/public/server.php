<?php

/**
 * Laravel Development Server Router
 *
 * php artisan serve runs: php -S host:port -t public public/server.php
 * __DIR__ here = Files/core/public/
 * Assets live in   Files/assets/   (two levels up)
 * Entry point is   Files/index.php (two levels up)
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Two levels up from core/public/ → Files/
$filesRoot = dirname(__DIR__, 2);

$filePath = $filesRoot . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $uri), DIRECTORY_SEPARATOR);

if ($uri !== '/' && file_exists($filePath) && !is_dir($filePath)) {
    $mimes = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'mjs'   => 'application/javascript',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'webp'  => 'image/webp',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'eot'   => 'application/vnd.ms-fontobject',
        'otf'   => 'font/otf',
        'txt'   => 'text/plain',
        'json'  => 'application/json',
        'xml'   => 'application/xml',
        'map'   => 'application/json',
    ];

    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if (isset($mimes[$ext])) {
        header('Content-Type: ' . $mimes[$ext]);
    }

    readfile($filePath);
    exit;
}

// Fall through to the app entry point (Files/index.php)
require_once $filesRoot . DIRECTORY_SEPARATOR . 'index.php';
