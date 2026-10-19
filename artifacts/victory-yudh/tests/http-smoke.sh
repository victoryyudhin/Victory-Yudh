#!/usr/bin/env bash
set -Eeuo pipefail

BASE_URL="${1:?Pass the local test server URL}"
ADMIN_EMAIL_FILE="${TEST_ADMIN_EMAIL_FILE:?Admin fixture email file is not set}"
TEMP_DIR="$(mktemp -d)"
COOKIE_JAR="$TEMP_DIR/cookies.txt"
PAGE="$TEMP_DIR/page.html"
EMAIL="http-smoke-$(date +%s)-$RANDOM@example.invalid"
PASSWORD="VictoryYudh-Test-Password-2026"

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
[[ "$status" == "403" ]] || fail "non-admin dashboard returned HTTP $status instead of 403"
grep -q 'permission' "$PAGE" || fail "forbidden page did not show an access-denied message"

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
ADMIN_EMAIL="$(<"$ADMIN_EMAIL_FILE")"
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

echo "PASS - HTTP player auth, admin auth, protected dashboard/pages, CRUD screens, and CSRF rejection"
