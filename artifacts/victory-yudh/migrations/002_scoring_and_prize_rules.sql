-- Add editable scoring multipliers and opt-in automatic prize settings.
-- Every column addition is guarded so the local schema entry point is safe to rerun.
SET @migration_schema = DATABASE();

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=@migration_schema AND table_name='scoring_rules' AND column_name='placement_multiplier')=0,
  'ALTER TABLE scoring_rules ADD COLUMN placement_multiplier SMALLINT UNSIGNED NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=@migration_schema AND table_name='scoring_rules' AND column_name='bonus_multiplier')=0,
  'ALTER TABLE scoring_rules ADD COLUMN bonus_multiplier SMALLINT UNSIGNED NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=@migration_schema AND table_name='scoring_rules' AND column_name='penalty_multiplier')=0,
  'ALTER TABLE scoring_rules ADD COLUMN penalty_multiplier SMALLINT UNSIGNED NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=@migration_schema AND table_name='results' AND column_name='bonus_input')=0,
  'ALTER TABLE results ADD COLUMN bonus_input INT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=@migration_schema AND table_name='results' AND column_name='penalty_input')=0,
  'ALTER TABLE results ADD COLUMN penalty_input INT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

-- Preserve existing raw bonus/penalty values before the score columns become weighted totals.
UPDATE results SET bonus_input=bonus_points WHERE bonus_input=0 AND bonus_points<>0;
UPDATE results SET penalty_input=penalty_points WHERE penalty_input=0 AND penalty_points<>0;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=@migration_schema AND table_name='tournaments' AND column_name='auto_prizes_enabled')=0,
  'ALTER TABLE tournaments ADD COLUMN auto_prizes_enabled TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=@migration_schema AND table_name='tournaments' AND column_name='rank_prize_percentages')=0,
  'ALTER TABLE tournaments ADD COLUMN rank_prize_percentages JSON NULL',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=@migration_schema AND table_name='tournaments' AND column_name='kill_reward_per_kill_minor')=0,
  'ALTER TABLE tournaments ADD COLUMN kill_reward_per_kill_minor BIGINT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=@migration_schema AND table_name='tournaments' AND column_name='payouts_locked_at')=0,
  'ALTER TABLE tournaments ADD COLUMN payouts_locked_at DATETIME NULL',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

INSERT IGNORE INTO schema_migrations (version) VALUES ('002_scoring_and_prize_rules');
