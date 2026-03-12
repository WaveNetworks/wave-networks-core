-- Development seed data - Docker only, never run on production
-- Creates one admin user per shard assignment pattern

-- Main DB: two test users, one per shard
INSERT INTO `user` (email, password, shard_id, is_admin, is_confirmed)
VALUES
  ('admin@test.com', 'HASHED_IN_CODE', 'shard1', 1, 1),
  ('user2@test.com', 'HASHED_IN_CODE', 'shard2', 0, 1)
ON DUPLICATE KEY UPDATE user_id = user_id;
