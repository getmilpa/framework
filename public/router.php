<?php

/**
 * This file is part of milpa/framework — the skeleton a Milpa app is created from.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/framework
 */

declare(strict_types=1);

// Router for PHP's built-in server (`coa serve`, or `php -S 127.0.0.1:8000 -t public public/router.php`):
// a real file under public/ is served as-is; everything else reaches the kernel through index.php.
// Routes served by controllers — the admin panel, the Desktop's assets — are not files on disk, and
// without this `php -S -t public` would answer 404 for them (greenhouse evidence/0487).
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);
$path = \is_string($path) ? $path : '/';

if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false; // let the built-in server serve the real file
}

require __DIR__ . '/index.php';
