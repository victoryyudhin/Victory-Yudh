-- Manual deposit verification, withdrawal tax breakdown, and per-match admin notes.
SET @migration_schema = DATABASE();

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=@migration_schema AND table_name='matches' AND column_name='note')=0,
  'ALTER TABLE matches ADD COLUMN note VARCHAR(500) NULL',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=@migration_schema AND table_name='withdrawal_requests' AND column_name='gst_minor')=0,
  'ALTER TABLE withdrawal_requests ADD COLUMN gst_minor BIGINT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema=@migration_schema AND table_name='withdrawal_requests' AND column_name='net_amount_minor')=0,
  'ALTER TABLE withdrawal_requests ADD COLUMN net_amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_sql;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

UPDATE withdrawal_requests
SET gst_minor=ROUND(amount_minor * 18 / 100),
    net_amount_minor=amount_minor - ROUND(amount_minor * 18 / 100)
WHERE net_amount_minor=0 AND amount_minor>0;

CREATE TABLE IF NOT EXISTS deposit_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  amount_minor BIGINT UNSIGNED NOT NULL,
  utr_number VARCHAR(120) NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  idempotency_key VARCHAR(140) NOT NULL,
  review_note VARCHAR(255) NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_deposit_requests_utr (utr_number),
  UNIQUE KEY uq_deposit_requests_idempotency (idempotency_key),
  KEY idx_deposit_requests_queue (status, created_at),
  KEY idx_deposit_requests_user (user_id, created_at),
  CONSTRAINT fk_deposit_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_deposit_requests_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('003_manual_wallet_flows');
