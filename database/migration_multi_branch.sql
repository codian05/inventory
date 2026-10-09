-- =====================================================================
-- MIGRATION: Multi-Branch Support
-- =====================================================================
-- Non-destructive. Adds branches + branch_id relationships to the
-- existing single-database inventory_system schema. Existing data is
-- preserved and migrated to a default "Main Branch" (branch_id = 1).
--
-- Run this once against the existing `inventory_system` database
-- (e.g. `mysql -u root inventory_system < migration_multi_branch.sql`
-- or import via phpMyAdmin). Safe to run on a fresh install too — the
-- "no existing rows" UPDATE statements simply affect 0 rows.
-- =====================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- ---------------------------------------------------------------------
-- 1. branches
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `branches` (
  `branch_id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_code` varchar(20) NOT NULL,
  `branch_name` varchar(150) NOT NULL,
  `address` text DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`branch_id`),
  UNIQUE KEY `branch_code` (`branch_code`),
  KEY `idx_status` (`status`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed the default branch that all pre-existing data migrates into.
-- INSERT IGNORE + explicit id so this is safe to re-run.
INSERT IGNORE INTO `branches`
  (`branch_id`, `branch_code`, `branch_name`, `address`, `contact_number`, `email`, `status`)
VALUES
  (1, 'MAIN', 'Main Branch', NULL, NULL, NULL, 'active');

ALTER TABLE `branches` AUTO_INCREMENT = 2;

-- ---------------------------------------------------------------------
-- 2. users.branch_id
--    NULL = system-wide admin (not tied to a branch).
--    Staff MUST have a branch_id (enforced in application code on
--    create/edit, not at the DB level, so an admin can still exist
--    with NULL while staff always gets a value).
-- ---------------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `branch_id` int(11) DEFAULT NULL AFTER `role`;

ALTER TABLE `users`
  ADD KEY IF NOT EXISTS `idx_branch_id` (`branch_id`);

-- Migrate existing staff accounts to Main Branch. Existing admin
-- accounts stay NULL (system-wide), preserving current behavior.
UPDATE `users` SET `branch_id` = 1 WHERE `role` = 'staff' AND `branch_id` IS NULL;

-- ---------------------------------------------------------------------
-- 3. inventory_batches.branch_id
--    Stock is now tracked independently per branch. Every batch
--    belongs to exactly one branch.
-- ---------------------------------------------------------------------
ALTER TABLE `inventory_batches`
  ADD COLUMN IF NOT EXISTS `branch_id` int(11) NOT NULL DEFAULT 1 AFTER `product_id`;

UPDATE `inventory_batches` SET `branch_id` = 1 WHERE `branch_id` = 0;

ALTER TABLE `inventory_batches`
  ADD KEY IF NOT EXISTS `idx_branch_id` (`branch_id`),
  ADD KEY IF NOT EXISTS `idx_branch_product` (`branch_id`, `product_id`);

-- ---------------------------------------------------------------------
-- 4. stock_transactions.branch_id (+ transfer support)
-- ---------------------------------------------------------------------
ALTER TABLE `stock_transactions`
  ADD COLUMN IF NOT EXISTS `branch_id` int(11) NOT NULL DEFAULT 1 AFTER `product_id`,
  ADD COLUMN IF NOT EXISTS `transfer_id` int(11) DEFAULT NULL AFTER `batch_id`;

UPDATE `stock_transactions` SET `branch_id` = 1 WHERE `branch_id` = 0;

-- Widen the transaction type enum to include transfers. MySQL/MariaDB
-- ENUM columns are altered via MODIFY; existing values are preserved.
ALTER TABLE `stock_transactions`
  MODIFY `type` enum('Stock In','Stock Out','Adjustment','Transfer Out','Transfer In') NOT NULL;

ALTER TABLE `stock_transactions`
  ADD KEY IF NOT EXISTS `idx_branch_id` (`branch_id`),
  ADD KEY IF NOT EXISTS `idx_transfer_id` (`transfer_id`);

-- ---------------------------------------------------------------------
-- 5. stock_transfers — one row per transfer, links the paired
--    "Transfer Out" (source) / "Transfer In" (destination)
--    stock_transactions rows via stock_transactions.transfer_id.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stock_transfers` (
  `transfer_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `from_branch_id` int(11) NOT NULL,
  `to_branch_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`transfer_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_from_branch` (`from_branch_id`),
  KEY `idx_to_branch` (`to_branch_id`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 6. Foreign keys (added last, after data is backfilled, so they
--    never fail against pre-existing rows)
-- ---------------------------------------------------------------------

-- users.branch_id -> branches.branch_id (SET NULL: deleting/deactivating
-- a branch never destroys a user account)
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'users_branch_fk');
SET @sql := IF(@fk = 0,
  'ALTER TABLE `users` ADD CONSTRAINT `users_branch_fk` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- inventory_batches.branch_id -> branches.branch_id (RESTRICT: a
-- branch with existing stock history can't be hard-deleted out from
-- under it — soft delete is used instead, see branch_delete.php)
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'inventory_batches_branch_fk');
SET @sql := IF(@fk = 0,
  'ALTER TABLE `inventory_batches` ADD CONSTRAINT `inventory_batches_branch_fk` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- stock_transactions.branch_id -> branches.branch_id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'stock_transactions_branch_fk');
SET @sql := IF(@fk = 0,
  'ALTER TABLE `stock_transactions` ADD CONSTRAINT `stock_transactions_branch_fk` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- stock_transactions.transfer_id -> stock_transfers.transfer_id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'stock_transactions_transfer_fk');
SET @sql := IF(@fk = 0,
  'ALTER TABLE `stock_transactions` ADD CONSTRAINT `stock_transactions_transfer_fk` FOREIGN KEY (`transfer_id`) REFERENCES `stock_transfers` (`transfer_id`) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- stock_transfers.product_id / from_branch_id / to_branch_id
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'stock_transfers_product_fk');
SET @sql := IF(@fk = 0,
  'ALTER TABLE `stock_transfers` ADD CONSTRAINT `stock_transfers_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'stock_transfers_from_branch_fk');
SET @sql := IF(@fk = 0,
  'ALTER TABLE `stock_transfers` ADD CONSTRAINT `stock_transfers_from_branch_fk` FOREIGN KEY (`from_branch_id`) REFERENCES `branches` (`branch_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'stock_transfers_to_branch_fk');
SET @sql := IF(@fk = 0,
  'ALTER TABLE `stock_transfers` ADD CONSTRAINT `stock_transfers_to_branch_fk` FOREIGN KEY (`to_branch_id`) REFERENCES `branches` (`branch_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'stock_transfers_user_fk');
SET @sql := IF(@fk = 0,
  'ALTER TABLE `stock_transfers` ADD CONSTRAINT `stock_transfers_user_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

-- =====================================================================
-- NOTE: MariaDB 10.4 (this project's target, per the dump header)
-- supports `ADD COLUMN IF NOT EXISTS` / `ADD KEY IF NOT EXISTS`
-- natively, so this whole file is safe to run more than once. If you
-- are running this against a MySQL server instead (no IF NOT EXISTS
-- support on ALTER ... ADD COLUMN before 8.0.29), run it only once
-- against a database matching database/inventory_system_client_clean.sql.
-- =====================================================================
