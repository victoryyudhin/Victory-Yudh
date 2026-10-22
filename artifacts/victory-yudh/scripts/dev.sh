#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

ADMIN_SETUP=0
ADMIN_SETUP_ARGS=()
if [[ $# -eq 1 && "$1" == "--admin-setup" ]]; then
  ADMIN_SETUP=1
  ADMIN_SETUP_ARGS=(--non-interactive)
elif [[ $# -eq 2 && "$1" == "--admin-setup" && "$2" == "--promote-existing" ]]; then
  ADMIN_SETUP=1
  ADMIN_SETUP_ARGS=(--non-interactive --promote-existing)
elif [[ $# -gt 0 ]]; then
  echo "Usage: bash scripts/dev.sh [--admin-setup [--promote-existing]]" >&2
  exit 2
fi

mkdir -p var/mysql var/run var/uploads
chmod 700 var var/mysql var/run var/uploads

export APP_ENV="${APP_ENV:-development}"
USE_POSTGRES=0
if [[ "${DB_DRIVER:-}" == "pgsql" || -n "${DATABASE_URL:-}" || -n "${PGHOST:-}" ]]; then
  USE_POSTGRES=1
  export DB_DRIVER=pgsql
else
  export DB_DRIVER=mysql
fi
if [[ "$USE_POSTGRES" == "0" ]]; then
if [[ -z "${APP_KEY:-}" && -f "$ROOT/.env" ]]; then
  APP_KEY="$(php -r '
    foreach (file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
      if (preg_match("/^\\s*APP_KEY\\s*=\\s*(.*?)\\s*$/", $line, $match)) {
        $value = trim($match[1]);
        if (strlen($value) >= 2 && (($value[0] === "\"" && str_ends_with($value, "\"")) || ($value[0] === "\047" && str_ends_with($value, "\047")))) $value = substr($value, 1, -1);
        echo $value;
        exit;
      }
    }' "$ROOT/.env")"
fi
if [[ "${APP_KEY:-}" == replace-with-* || "${APP_KEY:-}" == change-me-* || "${APP_KEY:-}" == example-* ]]; then
  APP_KEY=""
fi
if [[ -z "${APP_KEY:-}" ]]; then
  if [[ -s "$ROOT/var/dev-app-key" ]]; then
    APP_KEY="$(<"$ROOT/var/dev-app-key")"
  else
    APP_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
    (umask 077; printf '%s' "$APP_KEY" > "$ROOT/var/dev-app-key")
    chmod 600 "$ROOT/var/dev-app-key"
  fi
fi
export APP_KEY
export DB_HOST="${DB_HOST:-127.0.0.1}"
export DB_PORT="${DB_PORT:-3306}"
export DB_NAME="${DB_NAME:-victory_yudh}"
export DB_USER="${DB_USER:-victory_yudh}"
export DB_PASSWORD="${DB_PASSWORD:-victory_yudh_local_only}"
export DB_CHARSET="${DB_CHARSET:-utf8mb4}"
fi
export UPLOAD_DIR="${UPLOAD_DIR:-$ROOT/var/uploads}"
export PORT="${PORT:-8099}"

if [[ "$USE_POSTGRES" == "1" ]]; then
  pnpm --filter @workspace/victory-yudh run css:build
  chmod 644 "$ROOT/public/assets/app.css"
  unset INITIAL_ADMIN_EMAIL INITIAL_ADMIN_NAME INITIAL_ADMIN_PASSWORD
  exec php -S "0.0.0.0:$PORT" -t public public/router.php
fi

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

  mariadb --socket="$ROOT/var/run/mysql.sock" --user=root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

mariadb --socket="$ROOT/var/run/mysql.sock" --user=root "$DB_NAME" < schema.sql
if [[ "$ADMIN_SETUP" == "1" ]]; then
  DB_HOST="localhost;unix_socket=$ROOT/var/run/mysql.sock" \
  DB_USER=root \
  DB_PASSWORD= \
  php bin/create-admin.php "${ADMIN_SETUP_ARGS[@]}"
  exit 0
fi

pnpm --filter @workspace/victory-yudh run css:build
chmod 644 "$ROOT/public/assets/app.css"
unset INITIAL_ADMIN_EMAIL INITIAL_ADMIN_NAME INITIAL_ADMIN_PASSWORD
php -S "0.0.0.0:$PORT" -t public public/router.php
