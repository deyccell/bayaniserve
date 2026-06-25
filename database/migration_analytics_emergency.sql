-- ============================================================
-- BayaniServe Migration: Predictive Analytics + Emergency Mode
-- Run this against your bayaniserve database
-- ============================================================

-- ── 1. Emergency Mode Settings table ────────────────────────────
CREATE TABLE IF NOT EXISTS `emergency_mode` (
  `id`              int(11)      NOT NULL AUTO_INCREMENT,
  `is_active`       tinyint(1)   NOT NULL DEFAULT 0,
  `label`           varchar(150) NOT NULL DEFAULT 'Emergency Mode',
  `description`     text         DEFAULT NULL,
  `activated_by`    int(11)      DEFAULT NULL,
  `activated_at`    datetime     DEFAULT NULL,
  `deactivated_by`  int(11)      DEFAULT NULL,
  `deactivated_at`  datetime     DEFAULT NULL,
  `per_hh_limit`    int(11)      NOT NULL DEFAULT 5 COMMENT 'Max units per household during emergency',
  `bypass_approval` tinyint(1)   NOT NULL DEFAULT 1 COMMENT 'Skip normal requisition approval queue',
  PRIMARY KEY (`id`),
  KEY `activated_by` (`activated_by`),
  KEY `deactivated_by` (`deactivated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed one row (the single source of truth for emergency state)
INSERT IGNORE INTO `emergency_mode` (`id`, `is_active`, `label`) VALUES (1, 0, 'Emergency Mode');

-- ── 2. Emergency Distributions log ──────────────────────────────
CREATE TABLE IF NOT EXISTS `emergency_distributions` (
  `id`              int(11)      NOT NULL AUTO_INCREMENT,
  `station_id`      int(11)      NOT NULL,
  `medicine_id`     int(11)      NOT NULL,
  `household_rep`   varchar(150) NOT NULL COMMENT 'Name of household representative',
  `mobile_number`   varchar(20)  DEFAULT NULL,
  `address`         varchar(255) DEFAULT NULL,
  `quantity`        int(11)      NOT NULL DEFAULT 1,
  `distributed_by`  int(11)      NOT NULL,
  `notes`           text         DEFAULT NULL,
  `created_at`      timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `station_id`     (`station_id`),
  KEY `medicine_id`    (`medicine_id`),
  KEY `distributed_by` (`distributed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── 3. Foreign Keys for new tables ──────────────────────────────
ALTER TABLE `emergency_mode`
  ADD CONSTRAINT `em_activated_by`   FOREIGN KEY (`activated_by`)   REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `em_deactivated_by` FOREIGN KEY (`deactivated_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

ALTER TABLE `emergency_distributions`
  ADD CONSTRAINT `ed_station`  FOREIGN KEY (`station_id`)     REFERENCES `health_stations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ed_medicine` FOREIGN KEY (`medicine_id`)    REFERENCES `medicines`       (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ed_by`       FOREIGN KEY (`distributed_by`) REFERENCES `admins`          (`id`) ON DELETE CASCADE;

-- ── 4. Sample snapshot data for analytics demo ─────────────────
-- (Month-by-month synthetic history so charts aren't empty)
-- Hilamonan (station 1), Paracetamol (medicine 1)
INSERT IGNORE INTO `monthly_inventory_snapshots`
  (snapshot_month, station_id, medicine_id, opening_quantity, closing_quantity, quantity_received, quantity_distributed)
VALUES
  ('2025-08', 1, 1, 200, 140, 100,  160),
  ('2025-09', 1, 1, 140, 100, 120,  160),
  ('2025-10', 1, 1, 100, 170, 200,  130),
  ('2025-11', 1, 1, 170, 210, 180,  140),
  ('2025-12', 1, 1, 210, 130, 100,  180),
  ('2026-01', 1, 1, 130, 180, 200,  150),
  ('2026-02', 1, 1, 180, 150, 100,  130),
  ('2026-03', 1, 1, 150, 110, 120,  160),
  ('2026-04', 1, 1, 110,  90, 200,  220),
  ('2026-05', 1, 1,  90, 300, 350,  140);

-- Camugao (station 2), Paracetamol
INSERT IGNORE INTO `monthly_inventory_snapshots`
  (snapshot_month, station_id, medicine_id, opening_quantity, closing_quantity, quantity_received, quantity_distributed)
VALUES
  ('2025-08', 2, 1, 150,  90, 80,  140),
  ('2025-09', 2, 1,  90,  70, 100, 120),
  ('2025-10', 2, 1,  70, 100, 150, 120),
  ('2025-11', 2, 1, 100, 130, 150, 120),
  ('2025-12', 2, 1, 130,  80,  80, 130),
  ('2026-01', 2, 1,  80, 100, 150, 130),
  ('2026-02', 2, 1, 100,  80,  80, 100),
  ('2026-03', 2, 1,  80,  60, 100, 120),
  ('2026-04', 2, 1,  60,  80, 150, 130),
  ('2026-05', 2, 1,  80,  80, 150, 150);

-- Hilamonan, Cetirizine (medicine 4)
INSERT IGNORE INTO `monthly_inventory_snapshots`
  (snapshot_month, station_id, medicine_id, opening_quantity, closing_quantity, quantity_received, quantity_distributed)
VALUES
  ('2025-08', 1, 4, 150, 120, 100,  130),
  ('2025-09', 1, 4, 120, 100, 100,  120),
  ('2025-10', 1, 4, 100,  80, 100,  120),
  ('2025-11', 1, 4,  80, 100, 120,  100),
  ('2025-12', 1, 4, 100, 140, 150,  110),
  ('2026-01', 1, 4, 140, 160, 150,  130),
  ('2026-02', 1, 4, 160, 130, 100,  130),
  ('2026-03', 1, 4, 130, 180, 200,  150),
  ('2026-04', 1, 4, 180, 200, 200,  180),
  ('2026-05', 1, 4, 200, 200, 200,  200);

