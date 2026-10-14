<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['error' => 'payload_too_large']);
    exit;
}
try {
    require dirname(__DIR__) . '/app/config.php';
    require dirname(__DIR__) . '/app/db.php';
    require dirname(__DIR__) . '/app/helpers.php';
    require dirname(__DIR__) . '/app/services/WalletService.php';
    require dirname(__DIR__) . '/app/services/PaymentGateway.php';
    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || $rawBody === '') {
        throw new DomainException('empty_body');
    }
    $signature = (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');
    $eventId = (string) ($_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? '');
    $result = PaymentGateway::processWebhook($rawBody, $signature, $eventId);
    http_response_code(200);
    echo json_encode(['status' => $result]);
} catch (DomainException $e) {
    http_response_code($e->getMessage() === 'Webhook signature is invalid.' ? 400 : 503);
    echo json_encode(['error' => 'webhook_rejected']);
} catch (Throwable $e) {
    error_log('Victory Yudh webhook failure: ' . get_class($e));
    http_response_code(500);
    echo json_encode(['error' => 'webhook_processing_failed']);
}
