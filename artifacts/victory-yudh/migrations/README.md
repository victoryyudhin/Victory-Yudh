# Migrations

`001_initial.sql` creates the initial Victory Yudh schema. It is safe to run again:
it uses `CREATE TABLE IF NOT EXISTS` and does not delete or truncate existing data.

Run from `artifacts/victory-yudh/` after creating the database and user:

```sh
mariadb --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" -p "$DB_NAME" < schema.sql
```

For later schema changes, add a new numbered SQL file and apply it after making a
database backup. Do not edit an already-applied migration on a live database.
