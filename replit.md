# Victory Yudh

Mobile-first Free Fire tournament platform with a PHP/MariaDB application, player accounts, teams, match scoring, wallet ledger, and admin operations.

## Run & Operate

- Run `cd artifacts/victory-yudh && bash scripts/dev.sh` for the PHP development server and local MariaDB database.
- Run `pnpm --filter @workspace/victory-yudh install --frozen-lockfile` from the workspace root, then `cd artifacts/victory-yudh && pnpm run css:build` to build the Tailwind CSS asset.
- Run `cd artifacts/victory-yudh && php bin/create-admin.php` once after schema setup to create the first admin interactively. For Replit's local database, use `bash scripts/dev.sh --admin-setup` with the bootstrap values supplied securely; add `--promote-existing` only when the intended email already belongs to an active player. Never put the password in a seed file or command arguments.
- Run `cd artifacts/victory-yudh && bash tests/run.sh` to run the isolated PHP/MariaDB integration tests.
- See `artifacts/victory-yudh/README.md` for setup, payment sandbox, production hosting, migration, backup, and troubleshooting instructions.
- Runtime configuration is in `artifacts/victory-yudh/.env.example`; never commit `.env`.

## Stack

- App: PHP 8.1+, HTML5, Tailwind CSS, vanilla JavaScript, PDO
- Database: MariaDB/MySQL with InnoDB transactions
- Build tool only: pnpm + Tailwind CSS CLI
- Payments: server-side Razorpay Orders, signature checks, and signed webhooks; no fake capture

## Where things live

- `artifacts/victory-yudh/app/` — HTTP actions, database setup, session/security helpers, and domain services.
- `artifacts/victory-yudh/public/` — web document root, router, PWA files, static assets, uploads, health check, and Razorpay webhook.
- `artifacts/victory-yudh/views/` — public and admin server-rendered pages and shared layout.
- `artifacts/victory-yudh/schema.sql` / `migrations/` — additive database schema.
- `artifacts/victory-yudh/tests/` — isolated integration tests.

## Architecture decisions

- Use regular HTML form submissions. Do not replace the explicitly requested stack with a JavaScript framework or AJAX.
- Wallet and payment amounts are integer paise; each balance change has an idempotent ledger entry inside a database transaction.
- Room credentials and payout destinations are authenticated-encrypted using `APP_KEY`; keep that key stable across restarts and backups.
- Do not claim live Free Fire result access or mark withdrawals paid without a confirmed payout reference.

## Product

Players browse Solo/Duo/Squad tournaments, register individually or as teams, track wallet activity and match scores, view result tables, and request withdrawals. Administrators manage tournaments, teams, results, payments, withdrawals, announcements, and support.

## User preferences

- The product is Victory Yudh and the implementation must stay on HTML5, Tailwind CSS, vanilla JavaScript, PHP 8+, and MySQL/MariaDB.

## Gotchas

- `scripts/dev.sh` is for the local Replit development database only; it creates/reuses `var/mysql` and applies additive `CREATE TABLE IF NOT EXISTS` schema.
- Do not run optional demo seed data against a production database.
- PHP built-in server is for development only; production should serve `public/` using PHP-FPM/Apache with HTTPS.

## Pointers

- See `artifacts/victory-yudh/README.md` for full operation and recovery guidance.
