SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(120) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  email_verified_at DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_status_role (status, role),
  KEY idx_users_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profiles (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  display_name VARCHAR(80) NOT NULL,
  free_fire_uid VARCHAR(32) NULL,
  avatar_path VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_profiles_ff_uid (free_fire_uid),
  CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teams (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  captain_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_teams_captain_name (captain_id, name),
  KEY idx_teams_captain (captain_id),
  CONSTRAINT fk_teams_captain FOREIGN KEY (captain_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS team_members (
  team_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  member_role ENUM('captain','member') NOT NULL DEFAULT 'member',
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (team_id, user_id),
  UNIQUE KEY uq_team_member_user (user_id, team_id),
  KEY idx_team_members_user (user_id),
  CONSTRAINT fk_team_members_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_team_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS team_invitations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  team_id BIGINT UNSIGNED NOT NULL,
  inviter_id BIGINT UNSIGNED NOT NULL,
  invitee_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','accepted','rejected','cancelled','expired') NOT NULL DEFAULT 'pending',
  invited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  UNIQUE KEY uq_team_invitation (team_id, invitee_id),
  KEY idx_invitations_inbox (invitee_id, status, expires_at),
  KEY idx_invitations_team (team_id, status),
  CONSTRAINT fk_invitations_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_invitations_inviter FOREIGN KEY (inviter_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_invitations_invitee FOREIGN KEY (invitee_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tournaments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(120) NOT NULL,
  title VARCHAR(140) NOT NULL,
  description TEXT NOT NULL,
  map_name VARCHAR(60) NOT NULL,
  mode ENUM('solo','duo','squad') NOT NULL,
  team_size TINYINT UNSIGNED NOT NULL,
  entry_fee_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
  prize_pool_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
  participant_limit INT UNSIGNED NOT NULL,
  registration_deadline DATETIME NOT NULL,
  match_time DATETIME NOT NULL,
  room_release_at DATETIME NOT NULL,
  rules TEXT NOT NULL,
  banner_path VARCHAR(255) NULL,
  room_id_enc VARBINARY(256) NULL,
  room_id_iv VARBINARY(12) NULL,
  room_id_tag VARBINARY(16) NULL,
  room_password_enc VARBINARY(256) NULL,
  room_password_iv VARBINARY(12) NULL,
  room_password_tag VARBINARY(16) NULL,
  status ENUM('draft','open','locked','live','completed','cancelled') NOT NULL DEFAULT 'draft',
  results_status ENUM('none','provisional','final') NOT NULL DEFAULT 'none',
  tie_breakers JSON NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  results_published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tournaments_slug (slug),
  KEY idx_tournaments_browse (status, match_time),
  KEY idx_tournaments_deadline (status, registration_deadline),
  KEY idx_tournaments_mode (mode, status, match_time),
  CONSTRAINT fk_tournaments_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_tournament_team_size CHECK (
    (mode = 'solo' AND team_size = 1) OR
    (mode = 'duo' AND team_size = 2) OR
    (mode = 'squad' AND team_size = 4)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scoring_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tournament_id BIGINT UNSIGNED NOT NULL,
  placement_rank INT UNSIGNED NOT NULL DEFAULT 0,
  placement_points INT NOT NULL DEFAULT 0,
  points_per_kill INT UNSIGNED NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  tie_breakers JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_scoring_tournament_rank (tournament_id, placement_rank),
  CONSTRAINT fk_scoring_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registrations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tournament_id BIGINT UNSIGNED NOT NULL,
  captain_id BIGINT UNSIGNED NOT NULL,
  team_id BIGINT UNSIGNED NULL,
  entry_fee_minor BIGINT UNSIGNED NOT NULL,
  status ENUM('confirmed','cancelled','refunded') NOT NULL DEFAULT 'confirmed',
  idempotency_key VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelled_at DATETIME NULL,
  UNIQUE KEY uq_registration_idem (idempotency_key),
  UNIQUE KEY uq_registration_team (tournament_id, team_id),
  KEY idx_registration_tournament_status (tournament_id, status),
  KEY idx_registration_captain (captain_id, created_at),
  CONSTRAINT fk_registrations_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_registrations_captain FOREIGN KEY (captain_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_registrations_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registration_members (
  registration_id BIGINT UNSIGNED NOT NULL,
  tournament_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (registration_id, user_id),
  UNIQUE KEY uq_tournament_registration_user (tournament_id, user_id),
  KEY idx_registration_members_user (user_id, registration_id),
  CONSTRAINT fk_registration_members_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
  CONSTRAINT fk_registration_members_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_registration_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS matches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tournament_id BIGINT UNSIGNED NOT NULL,
  match_number SMALLINT UNSIGNED NOT NULL,
  starts_at DATETIME NULL,
  status ENUM('scheduled','live','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tournament_match_number (tournament_id, match_number),
  CONSTRAINT fk_matches_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS results (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tournament_id BIGINT UNSIGNED NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  registration_id BIGINT UNSIGNED NOT NULL,
  placement_rank INT UNSIGNED NOT NULL,
  kills SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  bonus_points INT UNSIGNED NOT NULL DEFAULT 0,
  penalty_points INT UNSIGNED NOT NULL DEFAULT 0,
  placement_points INT NOT NULL DEFAULT 0,
  kill_points INT NOT NULL DEFAULT 0,
  total_points INT NOT NULL DEFAULT 0,
  entered_by BIGINT UNSIGNED NOT NULL,
  updated_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_match_registration_result (match_id, registration_id),
  KEY idx_results_leaderboard (tournament_id, registration_id),
  KEY idx_results_match_rank (match_id, placement_rank),
  CONSTRAINT fk_results_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_results_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_results_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_results_entered_by FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_results_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallet_accounts (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  balance_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
  reserved_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_wallet_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallet_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  transaction_type ENUM('deposit','entry_fee','entry_refund','prize','withdrawal_reserve','withdrawal_release','withdrawal_paid','admin_adjustment') NOT NULL,
  amount_minor BIGINT UNSIGNED NOT NULL,
  available_delta_minor BIGINT NOT NULL,
  reserved_delta_minor BIGINT NOT NULL DEFAULT 0,
  available_after_minor BIGINT UNSIGNED NOT NULL,
  reserved_after_minor BIGINT UNSIGNED NOT NULL,
  status ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
  idempotency_key VARCHAR(140) NOT NULL,
  reference_type VARCHAR(40) NULL,
  reference_id BIGINT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wallet_idempotency (idempotency_key),
  KEY idx_wallet_user_time (user_id, created_at),
  KEY idx_wallet_reference (reference_type, reference_id),
  CONSTRAINT fk_wallet_transactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  provider ENUM('razorpay') NOT NULL DEFAULT 'razorpay',
  provider_order_id VARCHAR(100) NULL,
  provider_payment_id VARCHAR(100) NULL,
  receipt VARCHAR(80) NOT NULL,
  amount_minor BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  status ENUM('pending','created','authorized','captured','failed','refunded') NOT NULL DEFAULT 'pending',
  idempotency_key VARCHAR(100) NOT NULL,
  captured_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payment_provider_order (provider_order_id),
  UNIQUE KEY uq_payment_provider_payment (provider_payment_id),
  UNIQUE KEY uq_payment_receipt (receipt),
  UNIQUE KEY uq_payment_idempotency (idempotency_key),
  KEY idx_payment_user_status (user_id, status, created_at),
  CONSTRAINT fk_payment_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_webhook_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider_event_id VARCHAR(140) NOT NULL,
  event_type VARCHAR(100) NOT NULL,
  payload_sha256 CHAR(64) NOT NULL,
  payment_order_id BIGINT UNSIGNED NULL,
  status ENUM('received','processed','ignored','failed') NOT NULL DEFAULT 'received',
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  UNIQUE KEY uq_payment_webhook_event (provider_event_id),
  KEY idx_webhook_status_time (status, received_at),
  CONSTRAINT fk_webhook_payment_order FOREIGN KEY (payment_order_id) REFERENCES payment_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_refunds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_order_id BIGINT UNSIGNED NOT NULL,
  requested_by BIGINT UNSIGNED NOT NULL,
  provider_refund_id VARCHAR(100) NULL,
  amount_minor BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','processed','failed') NOT NULL DEFAULT 'pending',
  idempotency_key VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_refund_idempotency (idempotency_key),
  UNIQUE KEY uq_provider_refund_id (provider_refund_id),
  KEY idx_refund_order (payment_order_id, status),
  CONSTRAINT fk_refunds_order FOREIGN KEY (payment_order_id) REFERENCES payment_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_refunds_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS withdrawal_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  amount_minor BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','approved','processing','paid','rejected') NOT NULL DEFAULT 'pending',
  payout_method ENUM('upi') NOT NULL DEFAULT 'upi',
  payout_details_enc VARBINARY(512) NOT NULL,
  payout_details_iv VARBINARY(12) NOT NULL,
  payout_details_tag VARBINARY(16) NOT NULL,
  payout_reference VARCHAR(140) NULL,
  idempotency_key VARCHAR(120) NOT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  paid_at DATETIME NULL,
  rejection_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_withdrawal_idempotency (idempotency_key),
  KEY idx_withdrawal_queue (status, created_at),
  KEY idx_withdrawal_user (user_id, created_at),
  CONSTRAINT fk_withdrawals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_withdrawals_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  notification_type VARCHAR(50) NOT NULL,
  title VARCHAR(120) NOT NULL,
  body VARCHAR(500) NOT NULL,
  target_url VARCHAR(255) NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notifications_user_unread (user_id, read_at, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(140) NOT NULL,
  body TEXT NOT NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  published_at DATETIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_announcements_public (status, published_at),
  CONSTRAINT fk_announcements_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  contact_email VARCHAR(254) NOT NULL,
  subject VARCHAR(140) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  assigned_to BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_support_queue (status, created_at),
  KEY idx_support_user (user_id, created_at),
  CONSTRAINT fk_support_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_support_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_settings_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  before_data JSON NULL,
  after_data JSON NULL,
  ip_address VARBINARY(16) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_actor_time (actor_id, created_at),
  KEY idx_audit_entity (entity_type, entity_id, created_at),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  scope_key CHAR(64) NOT NULL,
  window_started_at DATETIME NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_rate_limit_scope_window (scope_key, window_started_at),
  KEY idx_rate_limits_expiry (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('min_withdrawal_minor', '10000'),
  ('max_withdrawal_minor', '5000000');

INSERT IGNORE INTO schema_migrations (version) VALUES ('001_initial');
