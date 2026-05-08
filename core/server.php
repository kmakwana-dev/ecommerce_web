<?php

/**
 * Laravel - A PHP Framework For Web Artisans
 *
 * @package  Laravel
 * @author   Taylor Otwell <taylor@laravel.com>
 */

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)
);

// This file allows us to emulate Apache's "mod_rewrite" functionality from the
// built-in PHP web server. This provides a convenient way to test a Laravel
// application without having installed a "real" web server software here.
$filePath = __DIR__ . '/../' . ltrim($uri, '/');

if ($uri !== '/' && file_exists($filePath) && !is_dir($filePath)) {
    $mimes = [
        'css' => 'text/css',
        'js'  => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg'=> 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'woff'=> 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf' => 'font/ttf',
        'ico' => 'image/x-icon',
    ];
    $extension = pathinfo($filePath, PATHINFO_EXTENSION);
    if (isset($mimes[$extension])) {
        header('Content-Type: ' . $mimes[$extension]);
    }
    readfile($filePath);
    exit;
}

require_once __DIR__.'/../index.php';
