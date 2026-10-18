# Migrations

`schema.sql` is the entry point and imports the numbered migration files. `001_initial.sql` creates the initial tables; `002_scoring_and_prize_rules.sql` adds editable formula multipliers, raw score inputs, and opt-in automatic prize settings. The migrations are additive and safe to re-run; migration versions are recorded in `schema_migrations`.

Future schema changes should be numbered, additive migrations and recorded in `schema_migrations` in the same migration file. Apply them deliberately after a backup; do not edit an already-deployed migration to remove or rewrite user data.

Run from `artifacts/victory-yudh/` after creating the database and user:

```sh
mariadb --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" -p "$DB_NAME" < schema.sql
```

For later schema changes, add a new numbered SQL file and apply it after making a
database backup. Do not edit an already-applied migration on a live database.
