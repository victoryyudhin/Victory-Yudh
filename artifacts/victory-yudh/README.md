# Victory Yudh

Mobile-first Free Fire tournament platform built with PHP 8.1+, MariaDB/MySQL, PDO, Tailwind CSS, and vanilla JavaScript. Forms submit normally; the application does not use a JavaScript framework or AJAX.

## Features

- Player registration, login/logout, profiles, password changes, dashboard, notifications, support form, and match history.
- Solo, Duo, and Squad tournament listings, detail pages, capacity checks, entry fees, deadlines, registration, cancellation refunds, and timed room access.
- Team creation, invitations, accept/decline, captain controls, member removal, and exact Duo/Squad roster checks.
- Admin tournament controls, room credential management, result entry and CSV imports, configurable placement/kill scoring, provisional/final leaderboards, corrections, and audit trail.
- Integer-paise INR wallet with available/reserved balances, immutable ledger entries, locking transactions, idempotency keys, prize credits, deposit tracking, and withdrawal review.
- Razorpay order creation, server signature checks, event-signed webhook processing, duplicate-event protection, and provider-confirmed refunds. Payments are disabled until provider credentials are configured.
- Installable PWA manifest, icons, and static-asset-only service worker. It is not an Android APK.

## Runtime requirements

- PHP 8.1 or newer (tested in this workspace with PHP 8.4), with `pdo_mysql`, `openssl`, `mbstring`, `fileinfo`, `session`, and `json`.
- MariaDB 10.5+ or MySQL 8.0+ with InnoDB, JSON, and transactions.
- Node.js and pnpm are needed only to compile Tailwind CSS.
- HTTPS in production, especially for sessions, Razorpay checkout, and webhooks.

No Laravel, React, Vue, Bootstrap, jQuery, or AJAX is used. The existing Node/PostgreSQL project services are unrelated and are not used by this app.

## Run in this Replit workspace

From the workspace root:

```sh
cd artifacts/victory-yudh
pnpm --filter @workspace/victory-yudh install --frozen-lockfile
bash scripts/dev.sh
```

The development script starts/reuses a local MariaDB instance under `var/mysql`, creates the local `victory_yudh` database/user if needed, applies the non-destructive schema, builds CSS, and starts the PHP development server on `$PORT` (8099 by default in this workspace). Its local database credentials are development-only; do not use them on a public server. Local encryption key is persisted in ignored `var/dev-app-key`, so encrypted room/payout data stays readable between development restarts.

The script is for local development only. It imports additive schema on start and never drops or truncates application data. To stop it, stop the configured workflow. The local database remains in `var/mysql`.

Build CSS manually:

```sh
pnpm run css:build
```

## Database setup

For a standalone PHP host, create an empty database and a least-privilege user, then configure environment variables from `.env.example`. Copy the example to `.env` for local non-Replit use; keep `.env` out of Git and the public document root.

```sh
cp .env.example .env
```

Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` to the database you created. Configure a stable random `APP_KEY` of at least 32 characters. Set `APP_ENV=production` and the public HTTPS `APP_URL`. Do not change `APP_KEY` after room credentials or withdrawal destinations have been encrypted unless you have a planned key-rotation procedure.

From this directory, apply the schema:

```sh
mariadb --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" -p "$DB_NAME" < schema.sql
```

`schema.sql` imports `migrations/001_initial.sql`; run it from this directory so the relative `SOURCE` path resolves. The migration is additive and tracked in `schema_migrations`. Future migrations should be numbered and applied after a tested backup. Do not run this against a production database without first taking and verifying a backup.

Optional, clearly labelled demo event:

```sh
mariadb --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" -p "$DB_NAME" < demo_seed.sql
```

Demo SQL requires an admin account, creates only a `[DEMO]` tournament and scoring rules, and creates no player, payment, wallet, or credential records. Do not apply it to production.

## Secure initial admin setup

There is no public admin registration and no default admin password. After database setup, run this command on the server:

```sh
php bin/create-admin.php
```

Enter the first administrator's email, display name, and a unique password of at least 16 characters at the prompts. The password is hidden on an interactive terminal. The script uses a database lock and refuses to create another admin once one exists. Sign in at `index.php?page=login`, then open `index.php?page=admin`.

For automated setup, the same command supports a one-time environment-based mode:

```sh
php bin/create-admin.php --non-interactive
```

Before running it, provide `INITIAL_ADMIN_EMAIL`, `INITIAL_ADMIN_NAME`, and `INITIAL_ADMIN_PASSWORD` through the process environment. The password must be at least 16 characters and must not be passed as a command-line argument, committed to a seed file, or written to shell history. In Replit, provide the password through Tools > Secrets; remove that secret after successful setup. The development server launcher clears all three bootstrap variables before starting the web server. The command removes the variables from its own environment, stores only a password hash, and preserves the same one-admin-only check and database lock as interactive mode. In either mode, the command refuses to create an admin if one already exists.

In this Replit workspace, run the development setup helper so the local database settings are initialized for the CLI:

```sh
bash scripts/dev.sh --admin-setup
```

If the configured email already belongs to an active player account, explicitly promote that account instead of trying to insert a duplicate email:

```sh
bash scripts/dev.sh --admin-setup --promote-existing
```

Promotion changes the existing account's role to admin, replaces its password hash, and updates its display name. It preserves the account ID, wallet, tournament history, Free Fire UID, and avatar, and writes an audit entry. Suspended accounts cannot be promoted. Use this only after confirming the existing account is the intended administrator.

Do not expose CLI output, passwords, or database credentials in support requests or logs. To grant additional admin access, use a controlled database maintenance procedure, not a public form.

## Razorpay sandbox setup

1. Create or use a Razorpay account that is eligible for the intended use; provider approval and account settings are outside this application.
2. In the provider dashboard, create **test-mode** API credentials (the key ID starts with `rzp_test_`). Add `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, and `RAZORPAY_WEBHOOK_SECRET` to Replit Secrets or the hosting platform's environment-secret facility. Do not commit credentials or put them in client-side JavaScript. Development/testing reject live-mode keys; production requires a live-mode key ID starting with `rzp_live_`.
3. Set `APP_URL` to the public HTTPS URL. Register a Razorpay webhook at `APP_URL/webhook/razorpay`, using the matching webhook secret and the events `payment.authorized`, `payment.captured`, `payment.failed`, `refund.processed`, and `refund.failed`. Replit development URLs are private and are not suitable for Razorpay webhooks; this workspace has no published production URL yet.
4. In the app, create a deposit order and complete the provider's test-mode checkout. Wallet credit is posted only after server-side signature verification and provider capture, or a correctly signed captured webhook.
5. Confirm the provider sends webhook events and inspect payment/ledger status in the admin panel before considering a live switch.

Without the three Razorpay values, deposits and provider refunds remain disabled. The app does not fake successful payments, test captures, provider responses, or refunds. Test webhooks in `tests/run.php` are generated from a temporary test-only HMAC secret and are not proof of a real transaction.

Refunds are submitted to Razorpay and remain held/pending until a verified provider event confirms success or failure. If a network timeout makes the provider's response uncertain, held funds stay reserved for reconciliation rather than being released as if the refund failed.

### Withdrawals and payouts

The app reserves wallet funds and supports Pending → Approved → Processing → Paid or Rejected. Rejection releases reserved money exactly once. This version does **not** call a payout API. An administrator must arrange a payout through an eligible provider/bank process and enter a confirmed payout reference before marking it Paid. A status update alone is never represented as payment.

## Tests

```sh
bash tests/run.sh
```

The test script creates a uniquely named isolated database and user, applies the schema, runs PHP integration tests, then removes only that generated test database/user. It requires a local MariaDB root socket (the development workflow provides one). It never targets the app's development database or any configured production database.

Tests cover password hashing, team sizes and invitation flow, Solo/Duo/Squad registration, duplicate joins, capacity overbooking, wallet integer/idempotent operations, signed duplicate webhooks, scoring formula and multiple matches, result corrections/recalculation, cancellation refunds, withdrawal reservations/status transitions, admin permission and CSRF helpers, migration tables, and responsive/PWA markup checks.

No live Razorpay payment, bank payout, external Free Fire result feed, email delivery, or real Android-device install is simulated or claimed by these tests.

## Production hosting guide

Use a PHP host or Linux server with PHP-FPM/Apache, MariaDB/MySQL, HTTPS, and a persistent filesystem for the built CSS and tournament banners. This Replit development workflow verifies the PHP/MariaDB application locally; production hosting, provider account eligibility, and outbound network/webhook delivery must be set up and verified separately.

- Point the web server document root at `public/` only. Keep `app/`, `bin/`, `migrations/`, SQL files, `.env`, and backups outside the public document root.
- Route normal requests to `public/index.php`; route `/health` or `/health.php` to the safe health check and `/webhook/razorpay` to the webhook handler. The included router is for PHP's development server.
- Use a private database network/user with access only to this database. Configure environment secrets and a stable `APP_KEY`; do not use development defaults.
- Set HTTPS, disable `display_errors`, keep PHP and MariaDB patched, set upload/session limits, and configure log rotation. Ensure PHP workers can write only to the banner upload directory and logs.
- Configure the web server so PHP files in `public/uploads/` cannot execute; the included `.htaccess` protects Apache installations. For Nginx, deny PHP execution beneath `/uploads/`.
- Use a process manager/PHP-FPM and web server for production, not `php -S`. Rebuild the CSS with pnpm during release and deploy `public/assets/app.css` and both PWA icons.
- Register the Razorpay HTTPS webhook and verify a provider test transaction end to end before enabling live credentials.
- Keep the `APP_KEY` and database backup separately secured. Losing the key prevents decrypting room and payout details; rotating it requires deliberate re-encryption.
- This workspace has a running PHP development preview; a production deployment target has not been configured or tested. Before publishing from Replit or another host, verify persistent PHP and MariaDB support, HTTPS, storage persistence, and webhook delivery; otherwise use the PHP-compatible hosting path above.

### Backup and restore

Take an encrypted backup of the database and the private encryption key. Keep backups outside the web root and test restores periodically.

```sh
mariadb-dump --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" -p \
  --single-transaction --routines --triggers "$DB_NAME" > victory-yudh.sql
```

Restore into a **new or empty** database first:

```sh
mariadb --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" -p "$RESTORE_DB" < victory-yudh.sql
```

Restore banner files from the corresponding backup as well. Never overwrite a live database during a restore without explicit review and a fresh backup.

## Health check and PWA

`/health` returns only `{"status":"ok"}` or `{"status":"unavailable"}` and an appropriate HTTP status; it does not reveal database names, credentials, paths, or exception details.

The manifest, PNG icons, and service worker support adding the website to an Android home screen over HTTPS. The service worker caches only static assets and never caches account, wallet, payment, or admin pages. This is a website/PWA, not an APK. An Android APK requires a separate packaging/review step such as a Trusted Web Activity and Google Play compliance work.

## Troubleshooting

- **Database connection fails:** check `DB_*`, MariaDB status, host access, and that PDO's `pdo_mysql` extension is loaded. Do not paste passwords into logs or tickets.
- **`SOURCE` cannot find migration:** run the `mariadb < schema.sql` command from `artifacts/victory-yudh`, or apply `migrations/001_initial.sql` directly to the selected DB.
- **Admin setup refuses:** an admin already exists. Sign in with that account; never enable a public admin creation route.
- **Room/UPI data will not decrypt:** check that the exact `APP_KEY` used when the data was encrypted is configured. Restore that key from the secrets backup; do not replace it with a new key.
- **Deposit order is unavailable:** confirm Razorpay test credentials and outbound HTTPS access. An authorization does not credit the wallet until captured. Check the provider dashboard and webhook before retrying.
- **Refund is pending after a timeout:** check the Razorpay refund state and signed webhook delivery. Funds remain debited/held until confirmed; do not create a second refund manually without reconciling the first.
- **CSS is missing:** run `pnpm install && pnpm run css:build`, then check the `public/assets/app.css` deployment file.
- **PWA install is not offered:** verify HTTPS, the manifest and PNG icon requests, a successful service-worker registration, and browser install criteria. Verify separately on a real Android device.
