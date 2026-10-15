-- Optional, clearly labelled demo content only. No users, credentials, payments, or wallet entries are created.
-- Requires one administrator created with: php bin/create-admin.php
SET time_zone = '+00:00';
SET @demo_admin_id := (SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1);

INSERT IGNORE INTO tournaments
  (slug, title, description, map_name, mode, team_size, entry_fee_minor, prize_pool_minor,
   participant_limit, registration_deadline, match_time, room_release_at, rules, status, tie_breakers, created_by)
SELECT
  'demo-victory-yudh-solo',
  '[DEMO] Victory Yudh Solo Scrim',
  'Clearly labelled example tournament for checking the public listing and details page.',
  'Bermuda', 'solo', 1, 0, 100000, 48,
  UTC_TIMESTAMP() + INTERVAL 7 DAY,
  UTC_TIMESTAMP() + INTERVAL 8 DAY,
  UTC_TIMESTAMP() + INTERVAL 8 DAY,
  'Demo content only. No prize is funded or guaranteed.',
  'open',
  JSON_ARRAY('total_points','total_kills','placement_points','best_rank','registration_time'),
  @demo_admin_id
WHERE @demo_admin_id IS NOT NULL;

SET @demo_tournament_id := (SELECT id FROM tournaments WHERE slug='demo-victory-yudh-solo');
INSERT IGNORE INTO scoring_rules (tournament_id, placement_rank, placement_points, points_per_kill, active, tie_breakers)
SELECT @demo_tournament_id, 0, 0, 1, 1,
       JSON_ARRAY('total_points','total_kills','placement_points','best_rank','registration_time')
WHERE @demo_tournament_id IS NOT NULL;
INSERT IGNORE INTO scoring_rules (tournament_id, placement_rank, placement_points, points_per_kill, active, tie_breakers)
SELECT @demo_tournament_id, placements.rank_no, placements.points, 1, 1,
       JSON_ARRAY('total_points','total_kills','placement_points','best_rank','registration_time')
FROM (
  SELECT 1 AS rank_no, 12 AS points UNION ALL SELECT 2,9 UNION ALL SELECT 3,8
  UNION ALL SELECT 4,7 UNION ALL SELECT 5,6 UNION ALL SELECT 6,5
  UNION ALL SELECT 7,4 UNION ALL SELECT 8,3 UNION ALL SELECT 9,2 UNION ALL SELECT 10,1
) AS placements
WHERE @demo_tournament_id IS NOT NULL;
