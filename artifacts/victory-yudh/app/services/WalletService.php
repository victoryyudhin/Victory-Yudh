<?php
declare(strict_types=1);

final class WalletService
{
    public static function atomic(PDO $db, callable $callback): mixed
    {
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }
        try {
            $result = $callback($db);
            if ($ownsTransaction) {
                $db->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Posts one immutable, idempotent ledger entry. The caller must be in a
     * database transaction when combining it with another domain operation.
     */
    public static function post(
        PDO $db,
        int $userId,
        string $type,
        int $amountMinor,
        int $availableDelta,
        int $reservedDelta,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $note = null
    ): array {
        if (!$db->inTransaction()) {
            throw new LogicException('Wallet entries must be posted within a transaction.');
        }
        if ($amountMinor < 0 || $userId < 1 || strlen($idempotencyKey) > 140 || $idempotencyKey === '') {
            throw new InvalidArgumentException('Invalid wallet transaction.');
        }

        $stmt = $db->prepare('SELECT * FROM wallet_transactions WHERE idempotency_key=? LIMIT 1');
        $stmt->execute([$idempotencyKey]);
        $existing = $stmt->fetch();
        if ($existing) {
            if ((int) $existing['user_id'] !== $userId
                || $existing['transaction_type'] !== $type
                || (int) $existing['amount_minor'] !== $amountMinor
                || (int) $existing['available_delta_minor'] !== $availableDelta
                || (int) $existing['reserved_delta_minor'] !== $reservedDelta) {
                throw new DomainException('This wallet transaction key was already used for a different transaction.');
            }
            return $existing + ['_duplicate' => true];
        }

        $stmt = $db->prepare('INSERT IGNORE INTO wallet_accounts (user_id) VALUES (?)');
        $stmt->execute([$userId]);
        $stmt = $db->prepare('SELECT balance_minor, reserved_minor FROM wallet_accounts WHERE user_id=? FOR UPDATE');
        $stmt->execute([$userId]);
        $account = $stmt->fetch();
        if (!$account) {
            throw new RuntimeException('Wallet account could not be loaded.');
        }
        // Re-read after the account lock with a locking read: another request
        // may have posted this same idempotency key while this request waited.
        $stmt = $db->prepare('SELECT * FROM wallet_transactions WHERE idempotency_key=? FOR UPDATE');
        $stmt->execute([$idempotencyKey]);
        $existing = $stmt->fetch();
        if ($existing) {
            if ((int) $existing['user_id'] !== $userId
                || $existing['transaction_type'] !== $type
                || (int) $existing['amount_minor'] !== $amountMinor
                || (int) $existing['available_delta_minor'] !== $availableDelta
                || (int) $existing['reserved_delta_minor'] !== $reservedDelta) {
                throw new DomainException('This wallet transaction key was already used for a different transaction.');
            }
            return $existing + ['_duplicate' => true];
        }

        $availableBefore = (int) $account['balance_minor'];
        $reservedBefore = (int) $account['reserved_minor'];
        $availableAfter = $availableBefore + $availableDelta;
        $reservedAfter = $reservedBefore + $reservedDelta;
        if ($availableAfter < 0) {
            throw new DomainException('Insufficient available wallet balance.');
        }
        if ($reservedAfter < 0) {
            throw new DomainException('The reserved wallet balance is inconsistent.');
        }
        if ($availableAfter > 9000000000000000 || $reservedAfter > 9000000000000000) {
            throw new DomainException('Wallet balance limit exceeded.');
        }

        $stmt = $db->prepare(
            'UPDATE wallet_accounts SET balance_minor=?, reserved_minor=? WHERE user_id=?'
        );
        $stmt->execute([$availableAfter, $reservedAfter, $userId]);
        $stmt = $db->prepare(
            'INSERT INTO wallet_transactions
              (user_id, transaction_type, amount_minor, available_delta_minor, reserved_delta_minor,
               available_after_minor, reserved_after_minor, idempotency_key, reference_type, reference_id, note)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $userId, $type, $amountMinor, $availableDelta, $reservedDelta,
            $availableAfter, $reservedAfter, $idempotencyKey, $referenceType, $referenceId,
            $note === null ? null : mb_substr($note, 0, 255),
        ]);
        return [
            'id' => (int) $db->lastInsertId(),
            'user_id' => $userId,
            'transaction_type' => $type,
            'amount_minor' => $amountMinor,
            'available_after_minor' => $availableAfter,
            'reserved_after_minor' => $reservedAfter,
            'idempotency_key' => $idempotencyKey,
            '_duplicate' => false,
        ];
    }

    public static function balance(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT balance_minor, reserved_minor, currency FROM wallet_accounts WHERE user_id=?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: ['balance_minor' => 0, 'reserved_minor' => 0, 'currency' => 'INR'];
    }
}
