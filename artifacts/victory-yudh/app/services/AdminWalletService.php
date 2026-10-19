<?php
declare(strict_types=1);

final class AdminWalletService
{
    public static function adjust(
        int $adminId,
        int $userId,
        string $direction,
        int $amountMinor,
        string $reason,
        string $idempotencyNonce
    ): bool {
        $reason = trim($reason);
        if ($adminId < 1 || $userId < 1 || !in_array($direction, ['credit', 'debit'], true) ||
            $amountMinor < 1 || $amountMinor > 50000000 ||
            !preg_match('/^[a-f0-9]{32}$/', $idempotencyNonce)) {
            throw new InvalidArgumentException('Choose a valid credit or debit amount.');
        }
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Enter a reason of 1 to 255 characters.');
        }

        $db = Database::connection();
        $key = 'admin-wallet-adjust:' . $adminId . ':' . $userId . ':' . $idempotencyNonce;
        return WalletService::atomic($db, function (PDO $db) use (
            $adminId, $userId, $direction, $amountMinor, $reason, $key
        ): bool {
            $stmt = $db->prepare('SELECT email, role, status FROM users WHERE id=? FOR UPDATE');
            $stmt->execute([$userId]);
            $target = $stmt->fetch();
            if (!$target || $target['role'] !== 'user') {
                throw new DomainException('Player wallet account not found.');
            }

            $delta = $direction === 'credit' ? $amountMinor : -$amountMinor;
            $entry = WalletService::post(
                $db,
                $userId,
                'admin_adjustment',
                $amountMinor,
                $delta,
                0,
                $key,
                'admin_adjustment',
                $userId,
                $reason
            );
            if ($entry['_duplicate']) {
                return false;
            }

            notify_user(
                $userId,
                'wallet',
                $direction === 'credit' ? 'Wallet credited by admin' : 'Wallet debit recorded',
                format_money($amountMinor) . ' was ' . ($direction === 'credit' ? 'added to' : 'deducted from') .
                    ' your wallet. Reason: ' . $reason,
                'index.php?page=wallet'
            );
            audit_log($adminId, 'wallet.admin_adjusted', 'user', $userId, null, [
                'direction' => $direction,
                'amount_minor' => $amountMinor,
                'available_delta_minor' => $delta,
                'reason' => $reason,
                'account_status' => $target['status'],
            ]);
            return true;
        });
    }
}
