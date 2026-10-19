<?php
declare(strict_types=1);

final class DepositService
{
    public const UPI_ID = '7877442284@fam';
    public const QR_IMAGE_URL = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=upi://pay?pa=7877442284@fam%26pn=Niyant';

    public static function request(int $userId, int $amountMinor, string $utr, string $idempotencyNonce): int
    {
        if ($userId < 1 || $amountMinor < 100 || $amountMinor > 50000000 ||
            !preg_match('/^[a-f0-9]{32}$/', $idempotencyNonce)) {
            throw new InvalidArgumentException('Deposit request is invalid.');
        }
        $utr = strtoupper(trim($utr));
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{5,119}$/', $utr)) {
            throw new InvalidArgumentException('Enter a valid transaction ID or UTR (6–120 letters, numbers, or hyphens).');
        }
        $key = 'manual-deposit:' . $userId . ':' . $idempotencyNonce;
        $db = Database::connection();
        return WalletService::atomic($db, function (PDO $db) use ($userId, $amountMinor, $utr, $key): int {
            $stmt = $db->prepare('SELECT * FROM deposit_requests WHERE idempotency_key=? FOR UPDATE');
            $stmt->execute([$key]);
            $existing = $stmt->fetch();
            if ($existing) {
                if ((int) $existing['user_id'] !== $userId ||
                    (int) $existing['amount_minor'] !== $amountMinor ||
                    $existing['utr_number'] !== $utr) {
                    throw new DomainException('This deposit form was already submitted with different details.');
                }
                return (int) $existing['id'];
            }
            $stmt = $db->prepare('SELECT id FROM deposit_requests WHERE utr_number=? FOR UPDATE');
            $stmt->execute([$utr]);
            if ($stmt->fetchColumn()) {
                throw new DomainException('This transaction ID or UTR has already been submitted.');
            }
            $stmt = $db->prepare(
                'INSERT INTO deposit_requests (user_id, amount_minor, utr_number, idempotency_key)
                 VALUES (?,?,?,?)'
            );
            $stmt->execute([$userId, $amountMinor, $utr, $key]);
            $requestId = (int) $db->lastInsertId();
            notify_user(
                $userId,
                'deposit',
                'Deposit submitted for review',
                'Your ' . format_money($amountMinor) . ' deposit request is pending admin verification.',
                'index.php?page=wallet'
            );
            return $requestId;
        });
    }

    public static function review(int $requestId, int $adminId, string $decision, string $note = ''): void
    {
        if ($requestId < 1 || !in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Choose approve or reject for a valid deposit request.');
        }
        $note = trim($note);
        if ($decision === 'rejected' && $note === '') {
            throw new InvalidArgumentException('Add a reason before rejecting this deposit request.');
        }
        if (mb_strlen($note) > 255) {
            throw new InvalidArgumentException('The review note is too long.');
        }
        $db = Database::connection();
        WalletService::atomic($db, function (PDO $db) use ($requestId, $adminId, $decision, $note): void {
            $stmt = $db->prepare('SELECT * FROM deposit_requests WHERE id=? FOR UPDATE');
            $stmt->execute([$requestId]);
            $request = $stmt->fetch();
            if (!$request) {
                throw new DomainException('Deposit request not found.');
            }
            if ($request['status'] !== 'pending') {
                throw new DomainException('This deposit request has already been reviewed.');
            }
            if ($decision === 'approved') {
                WalletService::post(
                    $db,
                    (int) $request['user_id'],
                    'deposit',
                    (int) $request['amount_minor'],
                    (int) $request['amount_minor'],
                    0,
                    'manual-deposit-credit:' . $requestId,
                    'deposit_request',
                    $requestId,
                    'Manually verified UTR ' . $request['utr_number']
                );
            }
            $db->prepare(
                'UPDATE deposit_requests SET status=?, review_note=?, reviewed_by=?, reviewed_at=NOW()
                 WHERE id=? AND status=\'pending\''
            )->execute([
                $decision,
                $note !== '' ? mb_substr($note, 0, 255) : null,
                $adminId,
                $requestId,
            ]);
            notify_user(
                (int) $request['user_id'],
                'deposit',
                $decision === 'approved' ? 'Deposit approved' : 'Deposit rejected',
                $decision === 'approved'
                    ? format_money((int) $request['amount_minor']) . ' was added to your wallet.'
                    : 'Your deposit request was rejected. ' . mb_substr($note, 0, 350),
                'index.php?page=wallet'
            );
            audit_log($adminId, 'deposit.' . $decision, 'deposit_request', $requestId, ['status' => 'pending'], [
                'status' => $decision,
                'amount_minor' => (int) $request['amount_minor'],
                'utr_number' => $request['utr_number'],
                'review_note' => $note,
            ]);
        });
    }
}
