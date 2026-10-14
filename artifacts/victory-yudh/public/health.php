<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    require dirname(__DIR__) . '/app/config.php';
    require dirname(__DIR__) . '/app/db.php';
    Database::connection()->query('SELECT 1');
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['status' => 'unavailable']);
}
