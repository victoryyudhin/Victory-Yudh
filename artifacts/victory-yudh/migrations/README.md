# Migrations

`schema.sql` is the entry point and imports `001_initial.sql`. The migration records version `001_initial` in `schema_migrations`, uses `CREATE TABLE IF NOT EXISTS` and `INSERT IGNORE` for initial settings, and does not drop, truncate, or overwrite existing rows.

Future schema changes should be numbered, additive migrations and recorded in `schema_migrations` in the same migration file. Apply them deliberately after a backup; do not edit an already-deployed migration to remove or rewrite user data.

Run from `artifacts/victory-yudh/` after creating the database and user:

```sh
mariadb --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" -p "$DB_NAME" < schema.sql
```

For later schema changes, add a new numbered SQL file and apply it after making a
database backup. Do not edit an already-applied migration on a live database.
