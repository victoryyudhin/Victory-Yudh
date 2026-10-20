#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
MYSQL_SOCKET="${MYSQL_SOCKET:-$ROOT/var/run/mysql.sock}"
if [[ ! -S "$MYSQL_SOCKET" ]]; then
  echo "MariaDB is not running at the development socket. Start scripts/dev.sh first." >&2
  exit 1
fi

SUFFIX="$(date +%s)_$RANDOM"
TEST_DB="vy_test_${SUFFIX}"
TEST_USER="vy_test_${SUFFIX}"
TEST_PASSWORD="$(php -r 'echo bin2hex(random_bytes(18));')"
TEST_APP_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
PORT="$((20000 + ($$ % 20000)))"
TEMP_DIR="$(mktemp -d)"
SERVER_PID=""
DB_CREATED=0

cleanup() {
  status="$1"
  trap - EXIT INT TERM
  if [[ -n "$SERVER_PID" ]]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
    wait "$SERVER_PID" >/dev/null 2>&1 || true
  fi
  if [[ "$DB_CREATED" == "1" ]]; then
    mariadb --socket="$MYSQL_SOCKET" --user=root <<SQL >/dev/null 2>&1 || true
DROP DATABASE IF EXISTS \`$TEST_DB\`;
DROP USER IF EXISTS '$TEST_USER'@'127.0.0.1';
SQL
  fi
  rm -rf "$TEMP_DIR"
  exit "$status"
}
trap 'cleanup $?' EXIT INT TERM

if mariadb --socket="$MYSQL_SOCKET" --user=root -Nse "SHOW DATABASES LIKE '$TEST_DB'" | grep -q .; then
  echo "Generated test database name already exists; refusing to use it." >&2
  exit 1
fi

mariadb --socket="$MYSQL_SOCKET" --user=root <<SQL
CREATE DATABASE \`$TEST_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$TEST_USER'@'127.0.0.1' IDENTIFIED BY '$TEST_PASSWORD';
GRANT ALL PRIVILEGES ON \`$TEST_DB\`.* TO '$TEST_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
DB_CREATED=1
mariadb --socket="$MYSQL_SOCKET" --user=root "$TEST_DB" < schema.sql
pnpm --filter @workspace/victory-yudh run css:build

export APP_ENV=testing
export APP_KEY="$TEST_APP_KEY"
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_NAME="$TEST_DB"
export DB_USER="$TEST_USER"
export DB_PASSWORD="$TEST_PASSWORD"
export TEST_ADMIN_EMAIL_FILE="$TEMP_DIR/admin-email"

BOOTSTRAP_EMAIL="bootstrap-${SUFFIX}@example.invalid"
BOOTSTRAP_PASSWORD="$(php -r 'echo bin2hex(random_bytes(24));')"
if ! INITIAL_ADMIN_EMAIL="$BOOTSTRAP_EMAIL" \
  INITIAL_ADMIN_NAME="Bootstrap Test" \
  INITIAL_ADMIN_PASSWORD="$BOOTSTRAP_PASSWORD" \
  php bin/create-admin.php --non-interactive >"$TEMP_DIR/admin-bootstrap.log" 2>&1; then
  cat "$TEMP_DIR/admin-bootstrap.log" >&2
  echo "Non-interactive admin setup failed." >&2
  exit 1
fi

if ! TEST_BOOTSTRAP_EMAIL="$BOOTSTRAP_EMAIL" \
  TEST_BOOTSTRAP_PASSWORD="$BOOTSTRAP_PASSWORD" \
  php -r '
    require "app/config.php";
    require "app/db.php";
    $db = Database::connection();
    $stmt = $db->prepare(
        "SELECT u.password_hash, u.role, p.display_name
         FROM users u JOIN profiles p ON p.user_id = u.id
         WHERE u.email = ?"
    );
    $stmt->execute([getenv("TEST_BOOTSTRAP_EMAIL")]);
    $admin = $stmt->fetch();
    if (!$admin || $admin["role"] !== "admin" || $admin["display_name"] !== "Bootstrap Test"
        || !password_verify((string) getenv("TEST_BOOTSTRAP_PASSWORD"), $admin["password_hash"])) {
        fwrite(STDERR, "Non-interactive admin setup created invalid account data.\n");
        exit(1);
    }
  '; then
  echo "Non-interactive admin setup verification failed." >&2
  exit 1
fi

if INITIAL_ADMIN_EMAIL="$BOOTSTRAP_EMAIL" \
  INITIAL_ADMIN_NAME="Bootstrap Test" \
  INITIAL_ADMIN_PASSWORD="$BOOTSTRAP_PASSWORD" \
  php bin/create-admin.php --non-interactive >"$TEMP_DIR/admin-bootstrap-second-run.log" 2>&1; then
  echo "Non-interactive admin setup should refuse a second administrator." >&2
  exit 1
fi
unset BOOTSTRAP_PASSWORD

PROMOTION_EMAIL="promotion-${SUFFIX}@example.invalid"
PROMOTION_OLD_PASSWORD="$(php -r 'echo bin2hex(random_bytes(24));')"
PROMOTION_PASSWORD="$(php -r 'echo bin2hex(random_bytes(24));')"
if ! TEST_PROMOTION_EMAIL="$PROMOTION_EMAIL" \
  TEST_PROMOTION_PASSWORD="$PROMOTION_OLD_PASSWORD" \
  php -r '
    require "app/config.php";
    require "app/db.php";
    $db = Database::connection();
    $stmt = $db->prepare("INSERT INTO users (email, password_hash, role) VALUES (?,?, \"user\")");
    $stmt->execute([getenv("TEST_PROMOTION_EMAIL"), password_hash((string) getenv("TEST_PROMOTION_PASSWORD"), PASSWORD_DEFAULT)]);
    $userId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO profiles (user_id,display_name,free_fire_uid,avatar_path) VALUES (?,?,?,?)")
        ->execute([$userId, "Player Before Promotion", "T" . bin2hex(random_bytes(8)), "uploads/preserved-avatar.png"]);
    $db->prepare("INSERT INTO wallet_accounts (user_id) VALUES (?)")->execute([$userId]);
  '; then
  echo "Could not create the isolated player fixture for promotion." >&2
  exit 1
fi

if ! INITIAL_ADMIN_EMAIL="$PROMOTION_EMAIL" \
  INITIAL_ADMIN_NAME="Promoted Test" \
  INITIAL_ADMIN_PASSWORD="$PROMOTION_PASSWORD" \
  php bin/create-admin.php --non-interactive --promote-existing >"$TEMP_DIR/admin-promotion.log" 2>&1; then
  cat "$TEMP_DIR/admin-promotion.log" >&2
  echo "Non-interactive promotion failed." >&2
  exit 1
fi

if ! TEST_PROMOTION_EMAIL="$PROMOTION_EMAIL" \
  TEST_PROMOTION_PASSWORD="$PROMOTION_PASSWORD" \
  php -r '
    require "app/config.php";
    require "app/db.php";
    $db = Database::connection();
    $stmt = $db->prepare(
        "SELECT u.id, u.role, u.status, u.password_hash, p.display_name, p.free_fire_uid, p.avatar_path,
                (SELECT COUNT(*) FROM wallet_accounts w WHERE w.user_id = u.id) AS wallet_count,
                (SELECT COUNT(*) FROM audit_logs a WHERE a.entity_id = u.id AND a.action = \"admin.account_promoted\") AS promotion_count
         FROM users u JOIN profiles p ON p.user_id = u.id
         WHERE u.email = ?"
    );
    $stmt->execute([getenv("TEST_PROMOTION_EMAIL")]);
    $account = $stmt->fetch();
    if (!$account || $account["role"] !== "admin" || $account["status"] !== "active"
        || $account["display_name"] !== "Promoted Test"
        || $account["free_fire_uid"] === null || $account["avatar_path"] !== "uploads/preserved-avatar.png"
        || (int) $account["wallet_count"] !== 1 || (int) $account["promotion_count"] !== 1
        || !password_verify((string) getenv("TEST_PROMOTION_PASSWORD"), $account["password_hash"])) {
        fwrite(STDERR, "Promotion did not preserve account data or secure the password.\n");
        exit(1);
    }
  '; then
  echo "Promotion verification failed." >&2
  exit 1
fi
echo "PASS - active player promotion updates admin access while preserving account data"
unset PROMOTION_OLD_PASSWORD PROMOTION_PASSWORD

php tests/run.php
node tests/admin-confirm.test.js

php -S "127.0.0.1:$PORT" -t public public/router.php >"$TEMP_DIR/php-server.log" 2>&1 &
SERVER_PID=$!
READY=0
for _ in $(seq 1 40); do
  if curl --silent --fail "http://127.0.0.1:$PORT/health.php" >"$TEMP_DIR/health.json"; then
    READY=1
    break
  fi
  sleep 0.25
done
if [[ "$READY" != "1" ]]; then
  cat "$TEMP_DIR/php-server.log" >&2
  echo "Test PHP server failed to start." >&2
  exit 1
fi
bash tests/http-smoke.sh "http://127.0.0.1:$PORT"
