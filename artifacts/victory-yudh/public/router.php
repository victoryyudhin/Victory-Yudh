<?php
declare(strict_types=1);

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($uriPath) ? rawurldecode($uriPath) : '/';
if (str_contains($path, '/.')) {
    http_response_code(404);
    exit;
}
if ($path === '/health' || $path === '/health.php') {
    require __DIR__ . '/health.php';
    return true;
}
$candidate = realpath(__DIR__ . $path);
if ($candidate && str_starts_with($candidate, realpath(__DIR__) . DIRECTORY_SEPARATOR) && is_file($candidate)) {
    return false;
}
require __DIR__ . '/index.php';
