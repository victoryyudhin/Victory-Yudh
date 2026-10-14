<?php
declare(strict_types=1);

final class WithdrawalService
{
    public static function request(int $userId, int $amountMinor, string $upiId, string $idempotencyNonce): int
    {
        if ($amountMinor < 1 || $amountMinor > 50000000 || !preg_match('/^[a-f0-9]{32}$/', $idempotencyNonce)) {
            throw new InvalidArgumentException('Withdrawal request is invalid.');
        }
        $upiId = trim($upiId);
        if (mb_strlen($upiId) < 6 || mb_strlen($upiId) > 100 || !preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+$/', $upiId)) {
            throw new InvalidArgumentException('Enter a valid UPI ID.');
        }
        $settings = self::limits();
        if ($amountMinor < $settings['min'] || $amountMinor > $settings['max']) {
            throw new DomainException('Withdrawal amount must be between ' . format_money($settings['min']) . ' and ' . format_money($settings['max']) . '.');
        }
        $key = 'withdrawal:' . $userId . ':' . $idempotencyNonce;
        $existing = Database::connection()->prepare('SELECT id, amount_minor FROM withdrawal_requests WHERE idempotency_key=?');
        $existing->execute([$key]);
        $existingRow = $existing->fetch();
        if ($existingRow) {
            if ((int) $existingRow['amount_minor'] !== $amountMinor) {
                throw new DomainException('This withdrawal form has already been submitted with a different amount.');
            }
            return (int) $existingRow['id'];
        }

        $keyBytes = hash('sha256', app_key(), true);
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($upiId, 'aes-256-gcm', $keyBytes, OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($encrypted) || strlen($tag) !== 16) {
            throw new RuntimeException('Withdrawal destination could not be secured.');
        }

        $db = Database::connection();
        return WalletService::atomic($db, function (PDO $db) use ($userId, $amountMinor, $key, $encrypted, $iv, $tag): int {
            $stmt = $db->prepare(
                'INSERT INTO withdrawal_requests
                 (user_id, amount_minor, payout_method, payout_details_enc, payout_details_iv, payout_details_tag, idempotency_key)
                 VALUES (?, ?, \'upi\', ?, ?, ?, ?)'
            );
            $stmt->execute([$userId, $amountMinor, $encrypted, $iv, $tag, $key]);
            $requestId = (int) $db->lastInsertId();
            WalletService::post(
                $db, $userId, 'withdrawal_reserve', $amountMinor, -$amountMinor, $amountMinor,
                'withdrawal-reserve:' . $requestId, 'withdrawal', $requestId, 'Funds reserved for withdrawal review'
            );
            notify_user($userId, 'withdrawal', 'Withdrawal submitted', 'Your withdrawal is pending review. Funds are reserved, not marked as paid.', 'index.php?page=wallet');
            return $requestId;
        });
    }

    public static function review(int $requestId, int $adminId, string $decision, string $note = ''): void
    {
        if (!in_array($decision, ['approved', 'processing', 'paid', 'rejected'], true)) {
            throw new InvalidArgumentException('Choose a valid withdrawal action.');
        }
        $db = Database::connection();
        WalletService::atomic($db, function (PDO $db) use ($requestId, $adminId, $decision, $note): void {
            $stmt = $db->prepare('SELECT * FROM withdrawal_requests WHERE id=? FOR UPDATE');
            $stmt->execute([$requestId]);
            $request = $stmt->fetch();
            if (!$request) {
                throw new DomainException('Withdrawal request not found.');
            }
            $oldStatus = (string) $request['status'];
            $amount = (int) $request['amount_minor'];
            $userId = (int) $request['user_id'];

            if ($decision === 'approved' && $oldStatus === 'pending') {
                $db->prepare("UPDATE withdrawal_requests SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                    ->execute([$adminId, $requestId]);
            } elseif ($decision === 'processing' && $oldStatus === 'approved') {
                $db->prepare("UPDATE withdrawal_requests SET status='processing', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                    ->execute([$adminId, $requestId]);
            } elseif ($decision === 'paid' && $oldStatus === 'processing') {
                $reference = trim($note);
                if ($reference === '' || mb_strlen($reference) > 140) {
                    throw new InvalidArgumentException('Enter the confirmed payout reference before marking it paid.');
                }
                WalletService::post(
                    $db, $userId, 'withdrawal_paid', $amount, 0, -$amount,
                    'withdrawal-paid:' . $requestId, 'withdrawal', $requestId, 'Payout confirmed by administrator'
                );
                $db->prepare(
                    "UPDATE withdrawal_requests SET status='paid', payout_reference=?, paid_at=NOW(),
                     reviewed_by=?, reviewed_at=NOW() WHERE id=?"
                )->execute([$reference, $adminId, $requestId]);
                notify_user($userId, 'withdrawal', 'Withdrawal marked paid', 'The administrator recorded payout reference ' . $reference . '.', 'index.php?page=wallet');
            } elseif ($decision === 'rejected' && in_array($oldStatus, ['pending', 'approved'], true)) {
                $reason = trim($note);
                if ($reason === '') {
                    throw new InvalidArgumentException('Add a reason before rejecting this request.');
                }
                WalletService::post(
                    $db, $userId, 'withdrawal_release', $amount, $amount, -$amount,
                    'withdrawal-release:' . $requestId, 'withdrawal', $requestId, 'Rejected withdrawal funds returned'
                );
                $db->prepare(
                    "UPDATE withdrawal_requests SET status='rejected', rejection_reason=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?"
                )->execute([mb_substr($reason, 0, 255), $adminId, $requestId]);
                notify_user($userId, 'withdrawal', 'Withdrawal rejected', 'The reserved funds were returned to your available wallet balance.', 'index.php?page=wallet');
            } else {
                throw new DomainException('This withdrawal cannot move from ' . $oldStatus . ' to ' . $decision . '.');
            }
            audit_log($adminId, 'withdrawal.' . $decision, 'withdrawal', $requestId, ['status' => $oldStatus], ['status' => $decision]);
        });
    }

    public static function revealUpi(array $row): string
    {
        $key = hash('sha256', app_key(), true);
        $decrypted = openssl_decrypt(
            (string) $row['payout_details_enc'],
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            (string) $row['payout_details_iv'],
            (string) $row['payout_details_tag']
        );
        if (!is_string($decrypted)) {
            throw new RuntimeException('Withdrawal destination could not be decrypted.');
        }
        return $decrypted;
    }

    private static function limits(): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('min_withdrawal_minor','max_withdrawal_minor')"
        );
        $stmt->execute();
        $values = [];
        foreach ($stmt->fetchAll() as $row) {
            $values[$row['setting_key']] = (int) $row['setting_value'];
        }
        return [
            'min' => max(1, $values['min_withdrawal_minor'] ?? 10000),
            'max' => max($values['min_withdrawal_minor'] ?? 10000, $values['max_withdrawal_minor'] ?? 5000000),
        ];
    }
}
