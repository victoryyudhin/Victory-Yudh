-- Restore defaultable authentication and account metadata columns on databases
-- created from older/incomplete versions of the users table.
SET @migration_schema = DATABASE();

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=@migration_schema AND table_name='users' AND column_name='role')=0,
  'ALTER TABLE users ADD COLUMN role ENUM(''user'',''admin'') NOT NULL DEFAULT ''user''',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=@migration_schema AND table_name='users' AND column_name='status')=0,
  'ALTER TABLE users ADD COLUMN status ENUM(''active'',''suspended'') NOT NULL DEFAULT ''active''',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=@migration_schema AND table_name='users' AND column_name='email_verified_at')=0,
  'ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=@migration_schema AND table_name='users' AND column_name='last_login_at')=0,
  'ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=@migration_schema AND table_name='users' AND column_name='created_at')=0,
  'ALTER TABLE users ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=@migration_schema AND table_name='users' AND column_name='updated_at')=0,
  'ALTER TABLE users ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

INSERT IGNORE INTO schema_migrations (version) VALUES ('004_user_auth_columns');
