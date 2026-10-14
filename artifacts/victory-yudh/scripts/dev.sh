#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

export APP_ENV="${APP_ENV:-development}"
export APP_KEY="${APP_KEY:-$(php -r 'echo bin2hex(random_bytes(32));')}"
export DB_HOST="${DB_HOST:-127.0.0.1}"
export DB_PORT="${DB_PORT:-3306}"
export DB_NAME="${DB_NAME:-victory_yudh}"
export DB_USER="${DB_USER:-victory_yudh}"
export DB_PASSWORD="${DB_PASSWORD:-victory_yudh_local_only}"
export DB_CHARSET="${DB_CHARSET:-utf8mb4}"
export UPLOAD_DIR="${UPLOAD_DIR:-$ROOT/var/uploads}"
export PORT="${PORT:-8080}"

mkdir -p var/mysql var/run var/uploads
chmod 700 var var/mysql var/run var/uploads

if [ ! -d var/mysql/mysql ]; then
  mariadb-install-db \
    --datadir="$ROOT/var/mysql" \
    --auth-root-authentication-method=normal \
    --skip-test-db \
    --user="$(id -un)" >/dev/null
fi

if ! mariadb-admin --socket="$ROOT/var/run/mysql.sock" --user=root ping >/dev/null 2>&1; then
  mariadbd \
    --datadir="$ROOT/var/mysql" \
    --socket="$ROOT/var/run/mysql.sock" \
    --pid-file="$ROOT/var/run/mysql.pid" \
    --bind-address=127.0.0.1 \
    --port="$DB_PORT" \
    --user="$(id -un)" \
    --skip-log-error \
    --log-error="$ROOT/var/run/mysql-error.log" &
  echo $! > "$ROOT/var/run/mysql-process.pid"
fi

for _ in $(seq 1 60); do
  if mariadb-admin --socket="$ROOT/var/run/mysql.sock" --user=root ping >/dev/null 2>&1; then break; fi
  sleep 1
done
mariadb-admin --socket="$ROOT/var/run/mysql.sock" --user=root ping >/dev/null

if ! [[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ && "$DB_USER" =~ ^[A-Za-z0-9_]+$ && "$DB_PASSWORD" =~ ^[A-Za-z0-9_-]{8,128}$ ]]; then
  echo "Development database name, user, or password contains unsupported characters." >&2
  exit 1
fi

if ! mariadb --socket="$ROOT/var/run/mysql.sock" --user=root -Nse "SELECT 1 FROM mysql.user WHERE User='${DB_USER}' AND Host='127.0.0.1' LIMIT 1" | grep -q 1; then
  mariadb --socket="$ROOT/var/run/mysql.sock" --user=root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
fi

mariadb --socket="$ROOT/var/run/mysql.sock" --user=root "$DB_NAME" < schema.sql
pnpm --filter @workspace/victory-yudh run css:build
php -S "0.0.0.0:$PORT" -t public public/router.php
