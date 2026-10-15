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
TEST_WEBHOOK_SECRET="$(php -r 'echo bin2hex(random_bytes(24));')"
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
export RAZORPAY_WEBHOOK_SECRET="$TEST_WEBHOOK_SECRET"
export TEST_ADMIN_EMAIL_FILE="$TEMP_DIR/admin-email"

php tests/run.php

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
