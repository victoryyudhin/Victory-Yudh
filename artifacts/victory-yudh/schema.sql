-- Victory Yudh schema entry point. Run from artifacts/victory-yudh:
--   mariadb --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" -p "$DB_NAME" < schema.sql
-- DDL uses CREATE TABLE IF NOT EXISTS; this does not drop or truncate existing data.
SOURCE migrations/001_initial.sql;
SOURCE migrations/002_scoring_and_prize_rules.sql;
SOURCE migrations/003_manual_wallet_flows.sql;
SOURCE migrations/004_user_auth_columns.sql;
