#!/usr/bin/env bash
set -Eeuo pipefail

BASE_URL="${1:?Pass the local test server URL}"
ADMIN_EMAIL_FILE="${TEST_ADMIN_EMAIL_FILE:?Admin fixture email file is not set}"
TEMP_DIR="$(mktemp -d)"
COOKIE_JAR="$TEMP_DIR/cookies.txt"
PAGE="$TEMP_DIR/page.html"
EMAIL="http-smoke-$(date +%s)-$RANDOM@example.invalid"
PASSWORD="VictoryYudh-Test-Password-2026"
ADMIN_EMAIL="$(<"$ADMIN_EMAIL_FILE")"

fail() {
  echo "FAIL - HTTP auth smoke: $1" >&2
  exit 1
}

get_csrf() {
  sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' "$PAGE" | head -n 1
}

cleanup() {
  rm -rf "$TEMP_DIR"
}
trap cleanup EXIT

status="$(curl --silent --show-error --output "$PAGE" --cookie-jar "$COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=register")"
[[ "$status" == "200" ]] || fail "register page returned HTTP $status"
csrf="$(get_csrf)"
[[ -n "$csrf" ]] || fail "register form did not contain a CSRF token"

status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" --write-out '%{http_code}' \
  --request POST "$BASE_URL/index.php?action=register" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "display_name=HTTP Smoke Player" \
  --data-urlencode "email=$EMAIL" \
  --data-urlencode "free_fire_uid=" \
  --data-urlencode "password=$PASSWORD" \
  --data-urlencode "password_confirm=$PASSWORD")"
[[ "$status" == "303" ]] || fail "registration returned HTTP $status"

status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=dashboard")"
[[ "$status" == "200" ]] || fail "authenticated dashboard returned HTTP $status"
grep -q 'Player dashboard' "$PAGE" || fail "registered player dashboard did not render"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=wallet")"
[[ "$status" == "200" ]] || fail "player wallet returned HTTP $status"
grep -q 'api.qrserver.com' "$PAGE" || fail "manual deposit QR code did not render"
grep -q '7877442284@fam' "$PAGE" || fail "manual deposit UPI ID did not render"
grep -q 'name="utr_number"' "$PAGE" || fail "manual deposit transaction reference field is missing"
grep -q 'Note: 18% GST will be deducted from each withdrawal amount.' "$PAGE" || fail "withdrawal GST notice is missing"
grep -q 'name="upi_id"' "$PAGE" || fail "manual withdrawal UPI ID field is missing"
DEPOSIT_UTR="SMOKEDEP$(date +%s)$RANDOM"
csrf="$(get_csrf)"
deposit_nonce="$(sed -n 's/.*name="deposit_nonce" value="\([^"]*\)".*/\1/p' "$PAGE" | head -n 1)"
[[ -n "$deposit_nonce" ]] || fail "deposit form idempotency token is missing"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" --write-out '%{http_code}' \
  --request POST "$BASE_URL/index.php?action=deposit-request" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "deposit_nonce=$deposit_nonce" \
  --data-urlencode "amount_rupees=250" \
  --data-urlencode "utr_number=$DEPOSIT_UTR")"
[[ "$status" == "303" ]] || fail "manual deposit request returned HTTP $status"
if ! TEST_REGISTERED_EMAIL="$EMAIL" php -r '
  require "app/config.php";
  require "app/db.php";
  $db = Database::connection();
  $stmt = $db->prepare(
    "SELECT u.id, u.role, u.status, u.password_hash, p.display_name, p.free_fire_uid, w.user_id AS wallet_user_id
     FROM users u
     JOIN profiles p ON p.user_id=u.id
     LEFT JOIN wallet_accounts w ON w.user_id=u.id
     WHERE u.email=?"
  );
  $stmt->execute([getenv("TEST_REGISTERED_EMAIL")]);
  $account = $stmt->fetch();
  if (!$account || $account["role"] !== "user" || $account["status"] !== "active"
      || strlen((string) $account["password_hash"]) < 50
      || $account["display_name"] !== "HTTP Smoke Player"
      || $account["free_fire_uid"] !== null
      || (int) $account["wallet_user_id"] !== (int) $account["id"]) {
      fwrite(STDERR, "Registered account, profile, or wallet data was incomplete.\n");
      exit(1);
  }
'; then
  fail "registration POST did not persist complete account data"
fi

DUPLICATE_COOKIE_JAR="$TEMP_DIR/duplicate-cookies.txt"
status="$(curl --silent --show-error --output "$PAGE" --cookie-jar "$DUPLICATE_COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=register")"
[[ "$status" == "200" ]] || fail "duplicate-account register page returned HTTP $status"
csrf="$(get_csrf)"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$DUPLICATE_COOKIE_JAR" --cookie-jar "$DUPLICATE_COOKIE_JAR" --write-out '%{http_code}' \
  --request POST "$BASE_URL/index.php?action=register" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "display_name=Duplicate Smoke Player" \
  --data-urlencode "email=$EMAIL" \
  --data-urlencode 'free_fire_uid=' \
  --data-urlencode "password=$PASSWORD" \
  --data-urlencode "password_confirm=$PASSWORD")"
[[ "$status" == "303" ]] || fail "duplicate registration returned HTTP $status"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$DUPLICATE_COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=register")"
[[ "$status" == "200" ]] || fail "duplicate registration result page returned HTTP $status"
grep -q 'An account with that email already exists' "$PAGE" || fail "duplicate registration did not show a clear account-conflict message"

status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=admin")"
[[ "$status" == "303" ]] || fail "non-admin dashboard returned HTTP $status instead of redirecting to admin sign-in"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=admin-login")"
[[ "$status" == "200" ]] || fail "admin sign-in page returned HTTP $status"
grep -q 'Sign in to the admin panel' "$PAGE" || fail "admin sign-in prompt did not render for an active player session"
grep -q 'name="admin_email"' "$PAGE" || fail "admin email field is missing"
grep -q 'name="admin_password"' "$PAGE" || fail "admin password field is missing"
csrf="$(get_csrf)"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" --write-out '%{http_code}' \
  --request POST "$BASE_URL/index.php?action=admin-login" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "admin_email=$EMAIL" \
  --data-urlencode "admin_password=$PASSWORD")"
[[ "$status" == "303" ]] || fail "player credentials were not rejected by admin sign-in"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=admin-login")"
[[ "$status" == "200" ]] || fail "admin sign-in page after failed attempt returned HTTP $status"
grep -q 'Administrator email or password is incorrect' "$PAGE" || fail "admin sign-in did not show a generic invalid-credentials message"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=dashboard")"
[[ "$status" == "200" ]] || fail "failed admin sign-in unexpectedly removed the player session"
grep -q 'Player dashboard' "$PAGE" || fail "failed admin sign-in changed the player session"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=admin-login")"
csrf="$(get_csrf)"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" --write-out '%{http_code}' \
  --request POST "$BASE_URL/index.php?action=admin-login" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "admin_email=$ADMIN_EMAIL" \
  --data-urlencode 'admin_password=Fixture-Password-123456')"
[[ "$status" == "303" ]] || fail "valid admin credentials returned HTTP $status"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=admin")"
[[ "$status" == "200" ]] || fail "admin sign-in did not open the admin dashboard"
if grep -q 'Sign in to the admin panel' "$PAGE"; then fail "admin session was not switched after successful verification"; fi

status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=dashboard")"
csrf="$(get_csrf)"
[[ -n "$csrf" ]] || fail "authenticated page did not render CSRF token"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" --write-out '%{http_code}' \
  --request POST "$BASE_URL/index.php?action=logout" --data-urlencode "_csrf=$csrf")"
[[ "$status" == "303" ]] || fail "logout returned HTTP $status"

status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=login")"
[[ "$status" == "200" ]] || fail "login page returned HTTP $status"
csrf="$(get_csrf)"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" --write-out '%{http_code}' \
  --request POST "$BASE_URL/index.php?action=login" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "email=$EMAIL" --data-urlencode "password=$PASSWORD")"
[[ "$status" == "303" ]] || fail "login returned HTTP $status"

status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --write-out '%{http_code}' \
  --request POST "$BASE_URL/index.php?action=admin-user-toggle" \
  --data-urlencode "_csrf=invalid-token" --data-urlencode "user_id=1")"
[[ "$status" == "419" ]] || fail "invalid CSRF token returned HTTP $status instead of 419"

ADMIN_COOKIE_JAR="$TEMP_DIR/admin-cookies.txt"
status="$(curl --silent --show-error --output "$PAGE" --cookie-jar "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=login")"
[[ "$status" == "200" ]] || fail "admin login page returned HTTP $status"
csrf="$(get_csrf)"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --cookie-jar "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' \
  --request POST "$BASE_URL/index.php?action=login" \
  --data-urlencode "_csrf=$csrf" --data-urlencode "email=$ADMIN_EMAIL" --data-urlencode "password=Fixture-Password-123456")"
[[ "$status" == "303" ]] || fail "test admin login returned HTTP $status"

for route in admin admin-tournaments admin-tournament-edit admin-room admin-results admin-users admin-teams admin-wallet admin-withdrawals admin-announcements admin-support admin-audit admin-settings; do
  suffix=""
  case "$route" in
    admin-tournament-edit|admin-room) suffix="&id=1" ;;
  esac
  status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=$route$suffix")"
  [[ "$status" == "200" ]] || fail "admin route $route returned HTTP $status"
done
curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" "$BASE_URL/index.php?page=admin-wallet"
grep -q 'Add or deduct funds' "$PAGE" || fail "admin wallet adjustment controls did not render"
grep -q 'Deposit requests' "$PAGE" || fail "admin manual deposit review queue did not render"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=admin-wallet&deposit_status=pending")"
[[ "$status" == "200" ]] || fail "pending deposit queue returned HTTP $status"
grep -Fq "$DEPOSIT_UTR" "$PAGE" || fail "newly submitted pending deposit was missing from the admin queue"
grep -q 'name="decision" value="approved"' "$PAGE" || fail "deposit approval action did not render"
grep -q 'name="decision" value="rejected"' "$PAGE" || fail "deposit rejection action did not render"
DEPOSIT_ID="$(TEST_DEPOSIT_UTR="$DEPOSIT_UTR" php -r '
  require "app/config.php";
  require "app/db.php";
  $stmt = Database::connection()->prepare("SELECT id FROM deposit_requests WHERE utr_number=?");
  $stmt->execute([getenv("TEST_DEPOSIT_UTR")]);
  echo (int) $stmt->fetchColumn();
')"
[[ "$DEPOSIT_ID" -gt 0 ]] || fail "submitted deposit request could not be found in the database"
csrf="$(get_csrf)"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --cookie-jar "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' \
  --request POST "$BASE_URL/index.php?action=admin-deposit-review" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "deposit_request_id=$DEPOSIT_ID" \
  --data-urlencode 'decision=approved' \
  --data-urlencode 'note=HTTP smoke verified')"
[[ "$status" == "303" ]] || fail "admin deposit approval returned HTTP $status"
if ! TEST_DEPOSIT_ID="$DEPOSIT_ID" php -r '
  require "app/config.php";
  require "app/db.php";
  $db = Database::connection();
  $stmt = $db->prepare("SELECT status FROM deposit_requests WHERE id=?");
  $stmt->execute([(int) getenv("TEST_DEPOSIT_ID")]);
  if ($stmt->fetchColumn() !== "approved") {
    fwrite(STDERR, "Admin deposit approval was not persisted.\n");
    exit(1);
  }
  $stmt = $db->prepare("SELECT COUNT(*) FROM wallet_transactions WHERE transaction_type='deposit' AND reference_type='deposit_request' AND reference_id=?");
  $stmt->execute([(int) getenv("TEST_DEPOSIT_ID")]);
  if ((int) $stmt->fetchColumn() !== 1) {
    fwrite(STDERR, "Approved deposit did not create exactly one wallet credit.\n");
    exit(1);
  }
'; then fail "deposit approval did not update status and wallet ledger exactly once"; fi

REJECT_UTR="SMOKEREJ$(date +%s)$RANDOM"
if ! TEST_REGISTERED_EMAIL="$EMAIL" TEST_REJECT_UTR="$REJECT_UTR" php -r '
  require "app/bootstrap.php";
  $db = Database::connection();
  $stmt = $db->prepare("SELECT id FROM users WHERE email=?");
  $stmt->execute([getenv("TEST_REGISTERED_EMAIL")]);
  $userId = (int) $stmt->fetchColumn();
  DepositService::request($userId, 5000, getenv("TEST_REJECT_UTR"), bin2hex(random_bytes(16)));
'; then fail "could not create the isolated pending deposit for rejection testing"; fi
status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=admin-wallet&deposit_status=pending")"
[[ "$status" == "200" ]] || fail "pending deposit queue after fixture returned HTTP $status"
grep -Fq "$REJECT_UTR" "$PAGE" || fail "pending deposit rejection fixture was missing from the admin queue"
REJECT_DEPOSIT_ID="$(TEST_DEPOSIT_UTR="$REJECT_UTR" php -r '
  require "app/config.php";
  require "app/db.php";
  $stmt = Database::connection()->prepare("SELECT id FROM deposit_requests WHERE utr_number=?");
  $stmt->execute([getenv("TEST_DEPOSIT_UTR")]);
  echo (int) $stmt->fetchColumn();
')"
csrf="$(get_csrf)"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --cookie-jar "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' \
  --request POST "$BASE_URL/index.php?action=admin-deposit-review" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "deposit_request_id=$REJECT_DEPOSIT_ID" \
  --data-urlencode 'decision=rejected' \
  --data-urlencode 'note=UTR could not be verified')"
[[ "$status" == "303" ]] || fail "admin deposit rejection returned HTTP $status"
if ! TEST_DEPOSIT_ID="$REJECT_DEPOSIT_ID" php -r '
  require "app/config.php";
  require "app/db.php";
  $stmt = Database::connection()->prepare("SELECT status,review_note FROM deposit_requests WHERE id=?");
  $stmt->execute([(int) getenv("TEST_DEPOSIT_ID")]);
  $request = $stmt->fetch();
  if (!$request || $request["status"] !== "rejected" || $request["review_note"] !== "UTR could not be verified") {
    fwrite(STDERR, "Admin deposit rejection or its note was not persisted.\n");
    exit(1);
  }
'; then fail "deposit rejection did not persist its status and reason"; fi

status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=admin-wallet&deposit_status=approved")"
[[ "$status" == "200" ]] || fail "approved deposit filter returned HTTP $status"
grep -Fq "$DEPOSIT_UTR" "$PAGE" || fail "approved deposit was missing from the approved filter"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=admin-wallet&deposit_status=rejected")"
[[ "$status" == "200" ]] || fail "rejected deposit filter returned HTTP $status"
grep -Fq "$REJECT_UTR" "$PAGE" || fail "rejected deposit was missing from the rejected filter"

status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=admin-wallet&wallet_user_q=$EMAIL")"
[[ "$status" == "200" ]] || fail "wallet adjustment player search returned HTTP $status"
grep -q 'name="amount_rupees"' "$PAGE" || fail "wallet adjustment amount input did not render"
WALLET_USER_ID="$(TEST_REGISTERED_EMAIL="$EMAIL" php -r '
  require "app/config.php";
  require "app/db.php";
  $stmt = Database::connection()->prepare("SELECT id FROM users WHERE email=?");
  $stmt->execute([getenv("TEST_REGISTERED_EMAIL")]);
  echo (int) $stmt->fetchColumn();
')"
adjustment_nonce="$(sed -n 's/.*name="adjustment_nonce" value="\([^"]*\)".*/\1/p' "$PAGE" | head -n 1)"
[[ -n "$adjustment_nonce" ]] || fail "wallet adjustment idempotency token did not render"
csrf="$(get_csrf)"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --cookie-jar "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' \
  --request POST "$BASE_URL/index.php?action=admin-wallet-adjust" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "user_id=$WALLET_USER_ID" \
  --data-urlencode "adjustment_nonce=$adjustment_nonce" \
  --data-urlencode 'direction=credit' \
  --data-urlencode 'amount_rupees=1000' \
  --data-urlencode 'reason=HTTP smoke withdrawal test funding')"
[[ "$status" == "303" ]] || fail "admin wallet credit returned HTTP $status"

status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=wallet")"
[[ "$status" == "200" ]] || fail "player wallet for withdrawal submission returned HTTP $status"
csrf="$(get_csrf)"
withdraw_nonce="$(sed -n 's/.*name="withdraw_nonce" value="\([^"]*\)".*/\1/p' "$PAGE" | head -n 1)"
[[ -n "$withdraw_nonce" ]] || fail "withdrawal form idempotency token did not render"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$COOKIE_JAR" --cookie-jar "$COOKIE_JAR" --write-out '%{http_code}' \
  --request POST "$BASE_URL/index.php?action=withdraw-request" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "withdraw_nonce=$withdraw_nonce" \
  --data-urlencode 'amount_rupees=200' \
  --data-urlencode 'upi_id=smokepay@upi')"
[[ "$status" == "303" ]] || fail "player withdrawal request returned HTTP $status"
WITHDRAWAL_ID="$(TEST_REGISTERED_EMAIL="$EMAIL" php -r '
  require "app/config.php";
  require "app/db.php";
  $stmt = Database::connection()->prepare("SELECT id FROM withdrawal_requests WHERE user_id=(SELECT id FROM users WHERE email=?) ORDER BY id DESC LIMIT 1");
  $stmt->execute([getenv("TEST_REGISTERED_EMAIL")]);
  echo (int) $stmt->fetchColumn();
')"
[[ "$WITHDRAWAL_ID" -gt 0 ]] || fail "submitted withdrawal request could not be found"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=admin-withdrawals&status=pending")"
[[ "$status" == "200" ]] || fail "pending withdrawal queue returned HTTP $status"
grep -q 'Reason for rejection' "$PAGE" || fail "withdrawal rejection reason input did not render"
grep -q 'name="note" maxlength="255" required placeholder="Reason for rejection"' "$PAGE" || fail "withdrawal rejection input is missing its editable form field"
csrf="$(get_csrf)"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --cookie-jar "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' \
  --request POST "$BASE_URL/index.php?action=admin-withdraw-review" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "withdrawal_id=$WITHDRAWAL_ID" \
  --data-urlencode 'decision=rejected' \
  --data-urlencode 'note=HTTP smoke rejection reason')"
[[ "$status" == "303" ]] || fail "admin withdrawal rejection returned HTTP $status"
if ! TEST_WITHDRAWAL_ID="$WITHDRAWAL_ID" php -r '
  require "app/config.php";
  require "app/db.php";
  $stmt = Database::connection()->prepare("SELECT status,rejection_reason FROM withdrawal_requests WHERE id=?");
  $stmt->execute([(int) getenv("TEST_WITHDRAWAL_ID")]);
  $request = $stmt->fetch();
  if (!$request || $request["status"] !== "rejected" || $request["rejection_reason"] !== "HTTP smoke rejection reason") {
    fwrite(STDERR, "Withdrawal rejection or reason was not persisted.\n");
    exit(1);
  }
'; then fail "withdrawal rejection did not persist its reason"; fi

if ! TEST_REGISTERED_EMAIL="$EMAIL" php -r '
  require "app/bootstrap.php";
  $db = Database::connection();
  $stmt = $db->prepare("SELECT id FROM users WHERE email=?");
  $stmt->execute([getenv("TEST_REGISTERED_EMAIL")]);
  WithdrawalService::request((int) $stmt->fetchColumn(), 15000, "smokepay@upi", bin2hex(random_bytes(16)));
'; then fail "could not create the isolated withdrawal for approval and payout testing"; fi
PAYOUT_WITHDRAWAL_ID="$(TEST_REGISTERED_EMAIL="$EMAIL" php -r '
  require "app/config.php";
  require "app/db.php";
  $stmt = Database::connection()->prepare("SELECT id FROM withdrawal_requests WHERE user_id=(SELECT id FROM users WHERE email=?) AND status='pending' ORDER BY id DESC LIMIT 1");
  $stmt->execute([getenv("TEST_REGISTERED_EMAIL")]);
  echo (int) $stmt->fetchColumn();
')"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=admin-withdrawals&status=pending")"
[[ "$status" == "200" ]] || fail "second pending withdrawal queue returned HTTP $status"
csrf="$(get_csrf)"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --cookie-jar "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' \
  --request POST "$BASE_URL/index.php?action=admin-withdraw-review" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "withdrawal_id=$PAYOUT_WITHDRAWAL_ID" \
  --data-urlencode 'decision=approved')"
[[ "$status" == "303" ]] || fail "admin withdrawal approval returned HTTP $status"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=admin-withdrawals&status=approved")"
[[ "$status" == "200" ]] || fail "approved withdrawal filter returned HTTP $status"
grep -q 'Mark processing' "$PAGE" || fail "withdrawal processing action did not render"
csrf="$(get_csrf)"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --cookie-jar "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' \
  --request POST "$BASE_URL/index.php?action=admin-withdraw-review" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "withdrawal_id=$PAYOUT_WITHDRAWAL_ID" \
  --data-urlencode 'decision=processing')"
[[ "$status" == "303" ]] || fail "admin withdrawal processing action returned HTTP $status"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=admin-withdrawals&status=processing")"
[[ "$status" == "200" ]] || fail "processing withdrawal filter returned HTTP $status"
grep -q 'Confirmed payout reference' "$PAGE" || fail "confirmed payout reference input did not render"
grep -q 'name="note" maxlength="140" required placeholder="Confirmed payout reference"' "$PAGE" || fail "payout reference input is missing its editable form field"
csrf="$(get_csrf)"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --cookie-jar "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' \
  --request POST "$BASE_URL/index.php?action=admin-withdraw-review" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "withdrawal_id=$PAYOUT_WITHDRAWAL_ID" \
  --data-urlencode 'decision=paid' \
  --data-urlencode 'note=SMOKE-PAYOUT-REF-123')"
[[ "$status" == "303" ]] || fail "confirmed payout recording returned HTTP $status"
if ! TEST_WITHDRAWAL_ID="$PAYOUT_WITHDRAWAL_ID" php -r '
  require "app/config.php";
  require "app/db.php";
  $stmt = Database::connection()->prepare("SELECT status,payout_reference FROM withdrawal_requests WHERE id=?");
  $stmt->execute([(int) getenv("TEST_WITHDRAWAL_ID")]);
  $request = $stmt->fetch();
  if (!$request || $request["status"] !== "paid" || $request["payout_reference"] !== "SMOKE-PAYOUT-REF-123") {
    fwrite(STDERR, "Confirmed payout status or reference was not persisted.\n");
    exit(1);
  }
'; then fail "confirmed payout reference was not recorded"; fi

curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" "$BASE_URL/index.php?page=admin-withdrawals"
grep -q 'Transfer amount' "$PAGE" || fail "admin withdrawal net transfer amount did not render"
curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" "$BASE_URL/index.php?page=admin-notifications"
grep -q 'Send to All Users' "$PAGE" || fail "send-to-all notification option did not render"
grep -q 'Search & select specific users' "$PAGE" || fail "specific-user notification option did not render"
SCORE_TOURNAMENT_ID="$(php -r '
  require "app/config.php";
  require "app/db.php";
  echo (int) Database::connection()->query("SELECT id FROM tournaments WHERE slug LIKE \"test-score-%\" ORDER BY id DESC LIMIT 1")->fetchColumn();
')"
curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" "$BASE_URL/index.php?page=admin-results&id=$SCORE_TOURNAMENT_ID"
grep -q 'Match Note' "$PAGE" || fail "admin match note input did not render"
grep -q 'Username:' "$PAGE" || fail "joined player usernames and IGNs did not render"

status="$(curl --silent --show-error --output "$PAGE" --write-out '%{http_code}' "$BASE_URL/index.php?page=leaderboard&id=$SCORE_TOURNAMENT_ID")"
[[ "$status" == "200" ]] || fail "published match results returned HTTP $status"
grep -q 'Room opens ten minutes before match time.' "$PAGE" || fail "published custom match note did not render"

BULK_TOURNAMENT_ID="$(php -r '
  require "app/config.php";
  require "app/db.php";
  echo (int) Database::connection()->query("SELECT id FROM tournaments WHERE slug LIKE \"test-bulk-payout-%\" ORDER BY id DESC LIMIT 1")->fetchColumn();
')"
status="$(curl --silent --show-error --output "$PAGE" --write-out '%{http_code}' "$BASE_URL/index.php?page=leaderboard&id=$BULK_TOURNAMENT_ID")"
[[ "$status" == "200" ]] || fail "published results without a match note returned HTTP $status"
if grep -q 'Match notes' "$PAGE"; then fail "empty match note container should be hidden"; fi

status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=admin-tournament-edit")"
[[ "$status" == "200" ]] || fail "new tournament form returned HTTP $status"
csrf="$(get_csrf)"
CREATED_TITLE="HTTP Smoke Tournament $RANDOM"
registration_deadline="$(date -u -d '+1 day' +'%Y-%m-%dT%H:%M')"
room_release_at="$(date -u -d '+2 days' +'%Y-%m-%dT%H:%M')"
match_time="$(date -u -d '+3 days' +'%Y-%m-%dT%H:%M')"
status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --cookie-jar "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' \
  --request POST "$BASE_URL/index.php?action=admin-tournament-save" \
  --data-urlencode "_csrf=$csrf" \
  --data-urlencode "title=$CREATED_TITLE" \
  --data-urlencode "description=HTTP smoke tournament creation test" \
  --data-urlencode "map_name=Bermuda" \
  --data-urlencode "mode=solo" \
  --data-urlencode "entry_fee=0.00" \
  --data-urlencode "prize_pool=0.00" \
  --data-urlencode "participant_limit=10" \
  --data-urlencode "registration_deadline=$registration_deadline" \
  --data-urlencode "room_release_at=$room_release_at" \
  --data-urlencode "match_time=$match_time" \
  --data-urlencode "rules=HTTP smoke test rules" \
  --data-urlencode "status=draft" \
  --data-urlencode $'placement_points=1=12\n2=9' \
  --data-urlencode "points_per_kill=1" \
  --data-urlencode "placement_multiplier=1" \
  --data-urlencode "bonus_multiplier=1" \
  --data-urlencode "penalty_multiplier=1" \
  --data-urlencode $'rank_prize_percentages=1=50\n2=30\n3=20' \
  --data-urlencode "kill_reward_per_kill=0.00" \
  --data-urlencode "tie_breakers[]=total_points" \
  --data-urlencode "tie_breakers[]=total_kills")"
[[ "$status" == "303" ]] || fail "creating a tournament returned HTTP $status"
if grep -q 'Warning:' "$PAGE"; then fail "tournament creation emitted a PHP warning"; fi
status="$(curl --silent --show-error --output "$PAGE" --cookie "$ADMIN_COOKIE_JAR" --write-out '%{http_code}' "$BASE_URL/index.php?page=admin-tournaments")"
[[ "$status" == "200" ]] || fail "tournament list after creation returned HTTP $status"
grep -Fq "$CREATED_TITLE" "$PAGE" || fail "created tournament did not appear in the admin list"

echo "PASS - HTTP player wallet/deposit/withdrawal UI, admin wallet/withdrawal/notification/results UI, published match notes, auth, protected pages, CRUD screens, and CSRF rejection"
