<?php
declare(strict_types=1);

final class PaymentGateway
{
    private const API_BASE = 'https://api.razorpay.com/v1/';

    public static function isConfigured(): bool
    {
        return (string) config('razorpay_key_id', '') !== ''
            && (string) config('razorpay_key_secret', '') !== '';
    }

    public static function createOrder(int $userId, int $amountMinor): array
    {
        self::requireApiCredentials();
        if ($amountMinor < 100 || $amountMinor > 50000000) {
            throw new InvalidArgumentException('Deposit must be between ₹1 and ₹500,000.');
        }
        $db = Database::connection();
        $receipt = 'vy_' . bin2hex(random_bytes(12));
        $idempotency = 'deposit-order:' . $userId . ':' . bin2hex(random_bytes(12));
        $stmt = $db->prepare(
            'INSERT INTO payment_orders (user_id, receipt, amount_minor, currency, status, idempotency_key)
             VALUES (?,?,?,\'INR\',\'pending\',?)'
        );
        $stmt->execute([$userId, $receipt, $amountMinor, $idempotency]);
        $localId = (int) $db->lastInsertId();

        try {
            $response = self::apiRequest('POST', 'orders', [
                'amount' => $amountMinor,
                'currency' => 'INR',
                'receipt' => $receipt,
                'notes' => ['app' => 'Victory Yudh', 'local_order_id' => (string) $localId],
            ]);
            if (!isset($response['id']) || !is_string($response['id'])) {
                throw new RuntimeException('Razorpay did not return an order reference.');
            }
            $stmt = $db->prepare(
                "UPDATE payment_orders SET provider_order_id=?, status='created' WHERE id=? AND status='pending'"
            );
            $stmt->execute([$response['id'], $localId]);
            audit_log($userId, 'payment.order_created', 'payment_order', $localId, null, [
                'amount_minor' => $amountMinor, 'provider' => 'razorpay',
            ]);
            return [
                'id' => $localId,
                'provider_order_id' => $response['id'],
                'amount_minor' => $amountMinor,
                'key_id' => (string) config('razorpay_key_id'),
                'receipt' => $receipt,
            ];
        } catch (Throwable $e) {
            $db->prepare("UPDATE payment_orders SET status='failed' WHERE id=? AND status='pending'")
                ->execute([$localId]);
            throw $e;
        }
    }

    public static function verifyReturn(int $userId, int $localOrderId, string $providerOrderId, string $paymentId, string $signature): string
    {
        self::requireApiCredentials();
        if ($localOrderId < 1 || $providerOrderId === '' || $paymentId === '' || $signature === '') {
            throw new InvalidArgumentException('Payment verification details are incomplete.');
        }
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT * FROM payment_orders WHERE id=? AND user_id=? AND provider_order_id=? FOR UPDATE'
        );
        return WalletService::atomic($db, function (PDO $db) use ($stmt, $userId, $localOrderId, $providerOrderId, $paymentId, $signature): string {
            $stmt->execute([$localOrderId, $userId, $providerOrderId]);
            $order = $stmt->fetch();
            if (!$order) {
                throw new DomainException('Payment order not found for this account.');
            }
            $expected = hash_hmac('sha256', $providerOrderId . '|' . $paymentId, (string) config('razorpay_key_secret'));
            if (!hash_equals($expected, $signature)) {
                throw new DomainException('The payment signature could not be verified.');
            }
            $payment = self::apiRequest('GET', 'payments/' . rawurlencode($paymentId));
            if (($payment['order_id'] ?? null) !== $providerOrderId
                || (int) ($payment['amount'] ?? 0) !== (int) $order['amount_minor']
                || ($payment['currency'] ?? null) !== 'INR') {
                throw new DomainException('The payment details do not match the created order.');
            }
            $status = (string) ($payment['status'] ?? '');
            if (!in_array($status, ['authorized', 'captured'], true)) {
                throw new DomainException('The provider has not confirmed this payment.');
            }
            $newStatus = $status === 'captured' ? 'captured' : 'authorized';
            $stmt = $db->prepare(
                'UPDATE payment_orders SET provider_payment_id=?, status=?, captured_at=IF(?=\'captured\',NOW(),captured_at)
                 WHERE id=? AND status IN (\'created\',\'authorized\',\'captured\')'
            );
            $stmt->execute([$paymentId, $newStatus, $newStatus, $localOrderId]);
            if ($newStatus === 'captured') {
                self::creditCapturedOrder($db, $order, $paymentId);
            }
            audit_log($userId, 'payment.signature_verified', 'payment_order', $localOrderId, null, ['provider_status' => $newStatus]);
            return $newStatus;
        });
    }

    public static function processWebhook(string $rawBody, string $signature, string $eventId): string
    {
        $secret = (string) config('razorpay_webhook_secret', '');
        if ($secret === '' || $signature === '' || $eventId === '' || strlen($eventId) > 140) {
            throw new DomainException('Webhook verification is not configured.');
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new DomainException('Webhook signature is invalid.');
        }
        $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !is_string($payload['event'] ?? null)) {
            throw new DomainException('Webhook payload is invalid.');
        }
        $eventType = $payload['event'];
        $db = Database::connection();
        return WalletService::atomic($db, function (PDO $db) use ($eventId, $eventType, $rawBody, $payload): string {
            try {
                $stmt = $db->prepare(
                    'INSERT INTO payment_webhook_events (provider_event_id, event_type, payload_sha256, status)
                     VALUES (?,?,?,\'received\')'
                );
                $stmt->execute([$eventId, mb_substr($eventType, 0, 100), hash('sha256', $rawBody)]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    return 'duplicate';
                }
                throw $e;
            }
            $eventDbId = (int) $db->lastInsertId();
            $outcome = match ($eventType) {
                'payment.captured' => self::handleCaptured($db, $payload),
                'payment.authorized' => self::handleAuthorized($db, $payload),
                'payment.failed' => self::handleFailed($db, $payload),
                'refund.processed' => self::handleRefund($db, $payload, true),
                'refund.failed' => self::handleRefund($db, $payload, false),
                default => 'ignored',
            };
            $stmt = $db->prepare(
                'UPDATE payment_webhook_events SET status=?, payment_order_id=?, processed_at=NOW() WHERE id=?'
            );
            $stmt->execute([
                $outcome === 'ignored' ? 'ignored' : 'processed',
                $outcome === 'ignored' ? null : ($outcome['order_id'] ?? null),
                $eventDbId,
            ]);
            return $outcome === 'ignored' ? 'ignored' : 'processed';
        });
    }

    public static function requestRefund(int $paymentOrderId, int $amountMinor, int $adminId): int
    {
        self::requireApiCredentials();
        $db = Database::connection();
        $refundId = WalletService::atomic($db, function (PDO $db) use ($paymentOrderId, $amountMinor, $adminId): int {
            $stmt = $db->prepare('SELECT * FROM payment_orders WHERE id=? FOR UPDATE');
            $stmt->execute([$paymentOrderId]);
            $order = $stmt->fetch();
            if (!$order || $order['status'] !== 'captured' || !$order['provider_payment_id']) {
                throw new DomainException('Only captured payments can be refunded.');
            }
            $stmt = $db->prepare(
                "SELECT COALESCE(SUM(amount_minor),0) FROM payment_refunds
                 WHERE payment_order_id=? AND status IN ('pending','processed')"
            );
            $stmt->execute([$paymentOrderId]);
            $alreadyRequested = (int) $stmt->fetchColumn();
            if ($amountMinor < 1 || $alreadyRequested + $amountMinor > (int) $order['amount_minor']) {
                throw new DomainException('Refund amount exceeds the unrefunded payment amount.');
            }
            $stmt = $db->prepare(
                "SELECT id FROM payment_refunds WHERE payment_order_id=? AND status='pending' FOR UPDATE"
            );
            $stmt->execute([$paymentOrderId]);
            if ($stmt->fetchColumn()) {
                throw new DomainException('This payment already has a refund pending provider confirmation.');
            }
            $stmt = $db->prepare(
                'INSERT INTO payment_refunds (payment_order_id, requested_by, amount_minor, status, idempotency_key)
                 VALUES (?,?,?,\'pending\',?)'
            );
            $stmt->execute([$paymentOrderId, $adminId, $amountMinor, 'provider-refund:' . $paymentOrderId . ':' . $alreadyRequested . ':' . $amountMinor]);
            $id = (int) $db->lastInsertId();
            WalletService::post(
                $db, (int) $order['user_id'], 'admin_adjustment', $amountMinor, -$amountMinor, 0,
                'payment-refund-debit:' . $id, 'payment_refund', $id, 'Funds held for provider refund'
            );
            audit_log($adminId, 'payment.refund_requested', 'payment_order', $paymentOrderId, null, ['refund_id' => $id, 'amount_minor' => $amountMinor]);
            return $id;
        });

        $stmt = $db->prepare(
            'SELECT pr.*, po.provider_payment_id FROM payment_refunds pr
             JOIN payment_orders po ON po.id=pr.payment_order_id WHERE pr.id=?'
        );
        $stmt->execute([$refundId]);
        $refund = $stmt->fetch();
        try {
            $response = self::apiRequest('POST', 'payments/' . rawurlencode((string) $refund['provider_payment_id']) . '/refund', [
                'amount' => $amountMinor,
                'notes' => ['victory_yudh_refund_id' => (string) $refundId],
            ]);
            $db->prepare('UPDATE payment_refunds SET provider_refund_id=? WHERE id=? AND status=\'pending\'')
                ->execute([(string) ($response['id'] ?? ''), $refundId]);
            return $refundId;
        } catch (RazorpayApiException $e) {
            if ($e->status >= 400 && $e->status < 500) {
                self::releaseFailedRefund($refundId, $adminId, 'Provider rejected the refund request.');
                throw new DomainException('Razorpay rejected the refund. The held wallet funds were released.');
            }
            throw new DomainException('Refund request status is uncertain. Funds remain held while the provider is reconciled.');
        } catch (Throwable $e) {
            throw new DomainException('Refund request status is uncertain. Funds remain held while the provider is reconciled.');
        }
    }

    private static function handleCaptured(PDO $db, array $payload): array|string
    {
        $payment = $payload['payload']['payment']['entity'] ?? null;
        if (!is_array($payment) || !is_string($payment['order_id'] ?? null) || !is_string($payment['id'] ?? null)) {
            throw new DomainException('Captured payment event is malformed.');
        }
        $stmt = $db->prepare('SELECT * FROM payment_orders WHERE provider_order_id=? FOR UPDATE');
        $stmt->execute([$payment['order_id']]);
        $order = $stmt->fetch();
        if (!$order || (int) $payment['amount'] !== (int) $order['amount_minor'] || ($payment['currency'] ?? '') !== 'INR') {
            throw new DomainException('Captured payment does not match a local order.');
        }
        $db->prepare(
            "UPDATE payment_orders SET provider_payment_id=?, status='captured', captured_at=COALESCE(captured_at,NOW())
             WHERE id=? AND status IN ('created','authorized','captured')"
        )->execute([$payment['id'], $order['id']]);
        self::creditCapturedOrder($db, $order, $payment['id']);
        return ['order_id' => (int) $order['id']];
    }

    private static function handleAuthorized(PDO $db, array $payload): array|string
    {
        $payment = $payload['payload']['payment']['entity'] ?? null;
        if (!is_array($payment) || !is_string($payment['order_id'] ?? null)) {
            throw new DomainException('Authorized payment event is malformed.');
        }
        $stmt = $db->prepare('SELECT * FROM payment_orders WHERE provider_order_id=? FOR UPDATE');
        $stmt->execute([$payment['order_id']]);
        $order = $stmt->fetch();
        if (!$order || (int) ($payment['amount'] ?? -1) !== (int) $order['amount_minor'] || ($payment['currency'] ?? '') !== 'INR') {
            throw new DomainException('Authorized payment does not match a local order.');
        }
        if ($order['status'] !== 'captured') {
            $db->prepare("UPDATE payment_orders SET provider_payment_id=?, status='authorized' WHERE id=?")
                ->execute([(string) ($payment['id'] ?? ''), $order['id']]);
        }
        return ['order_id' => (int) $order['id']];
    }

    private static function handleFailed(PDO $db, array $payload): array|string
    {
        $payment = $payload['payload']['payment']['entity'] ?? null;
        if (!is_array($payment) || !is_string($payment['order_id'] ?? null)) {
            throw new DomainException('Failed payment event is malformed.');
        }
        $stmt = $db->prepare('SELECT id FROM payment_orders WHERE provider_order_id=? FOR UPDATE');
        $stmt->execute([$payment['order_id']]);
        $orderId = $stmt->fetchColumn();
        if (!$orderId) {
            return 'ignored';
        }
        $db->prepare("UPDATE payment_orders SET status='failed' WHERE id=? AND status IN ('pending','created','authorized')")
            ->execute([$orderId]);
        return ['order_id' => (int) $orderId];
    }

    private static function handleRefund(PDO $db, array $payload, bool $processed): array|string
    {
        $refund = $payload['payload']['refund']['entity'] ?? null;
        if (!is_array($refund) || !is_string($refund['payment_id'] ?? null)) {
            throw new DomainException('Refund webhook event is malformed.');
        }
        $stmt = $db->prepare('SELECT * FROM payment_orders WHERE provider_payment_id=? FOR UPDATE');
        $stmt->execute([$refund['payment_id']]);
        $order = $stmt->fetch();
        if (!$order) {
            return 'ignored';
        }
        $stmt = $db->prepare(
            "SELECT * FROM payment_refunds WHERE payment_order_id=? AND status='pending' ORDER BY id LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$order['id']]);
        $localRefund = $stmt->fetch();
        if (!$localRefund) {
            return ['order_id' => (int) $order['id']];
        }
        if ((int) ($refund['amount'] ?? -1) !== (int) $localRefund['amount_minor']) {
            throw new DomainException('Refund amount does not match the local refund request.');
        }
        if ($processed) {
            $db->prepare("UPDATE payment_refunds SET status='processed', provider_refund_id=? WHERE id=?")
                ->execute([(string) ($refund['id'] ?? ''), $localRefund['id']]);
            $stmt = $db->prepare(
                "SELECT COALESCE(SUM(amount_minor),0) FROM payment_refunds
                 WHERE payment_order_id=? AND status='processed'"
            );
            $stmt->execute([$order['id']]);
            if ((int) $stmt->fetchColumn() >= (int) $order['amount_minor']) {
                $db->prepare("UPDATE payment_orders SET status='refunded' WHERE id=?")->execute([$order['id']]);
            }
        } else {
            self::restoreRefundHold($db, $localRefund, 'Provider refund failed; held funds released.');
        }
        return ['order_id' => (int) $order['id']];
    }

    private static function creditCapturedOrder(PDO $db, array $order, string $paymentId): void
    {
        WalletService::post(
            $db,
            (int) $order['user_id'],
            'deposit',
            (int) $order['amount_minor'],
            (int) $order['amount_minor'],
            0,
            'deposit:' . $order['id'],
            'payment_order',
            (int) $order['id'],
            'Verified Razorpay capture ' . mb_substr($paymentId, 0, 50)
        );
        notify_user((int) $order['user_id'], 'deposit', 'Deposit received', 'Your verified ' . format_money((int) $order['amount_minor']) . ' wallet deposit is ready to use.', 'index.php?page=wallet');
    }

    private static function releaseFailedRefund(int $refundId, int $adminId, string $reason): void
    {
        $db = Database::connection();
        WalletService::atomic($db, function (PDO $db) use ($refundId, $adminId, $reason): void {
            $stmt = $db->prepare('SELECT * FROM payment_refunds WHERE id=? FOR UPDATE');
            $stmt->execute([$refundId]);
            $refund = $stmt->fetch();
            if (!$refund || $refund['status'] !== 'pending') {
                return;
            }
            self::restoreRefundHold($db, $refund, $reason);
            audit_log($adminId, 'payment.refund_failed', 'payment_refund', $refundId, ['status' => 'pending'], ['status' => 'failed']);
        });
    }

    private static function restoreRefundHold(PDO $db, array $refund, string $reason): void
    {
        $stmt = $db->prepare('SELECT user_id FROM payment_orders WHERE id=?');
        $stmt->execute([$refund['payment_order_id']]);
        $userId = (int) $stmt->fetchColumn();
        WalletService::post(
            $db, $userId, 'admin_adjustment', (int) $refund['amount_minor'], (int) $refund['amount_minor'], 0,
            'payment-refund-release:' . $refund['id'], 'payment_refund', (int) $refund['id'], $reason
        );
        $db->prepare("UPDATE payment_refunds SET status='failed' WHERE id=?")->execute([$refund['id']]);
    }

    private static function apiRequest(string $method, string $path, ?array $data = null): array
    {
        self::requireApiCredentials();
        $keyId = (string) config('razorpay_key_id');
        $keySecret = (string) config('razorpay_key_secret');
        $headers = [
            'Authorization: Basic ' . base64_encode($keyId . ':' . $keySecret),
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        $options = [
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers),
                'content' => $data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR),
                'timeout' => 15,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ];
        $context = stream_context_create($options);
        $response = @file_get_contents(self::API_BASE . $path, false, $context);
        $statusCode = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+([0-9]{3})/', $header, $match)) {
                $statusCode = (int) $match[1];
            }
        }
        if ($response === false || $statusCode === 0) {
            throw new RuntimeException('Could not reach the payment provider. The result may need reconciliation.');
        }
        $decoded = json_decode($response, true);
        if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
            throw new RazorpayApiException($statusCode, 'Payment provider request was not accepted.');
        }
        return $decoded;
    }

    private static function requireApiCredentials(): void
    {
        if (!self::isConfigured()) {
            throw new DomainException('Razorpay is not enabled. Add the Razorpay test or live key ID and key secret in environment variables.');
        }
    }
}

final class RazorpayApiException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
