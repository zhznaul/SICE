-- ============================================================================
-- SICE - Migração Segura: Sessões Persistentes + Unicidade de Código de Produto
-- Compatível com MySQL 8.0+ / MariaDB 10.4+
-- Preserva integralmente todos os registros já existentes.
-- ============================================================================

USE `sice_db`;

-- 1. Tabela de sessões autenticadas persistentes (evita perda de sessão por GC
--    do PHP em /tmp ou reinício de worker durante preenchimento demorado)
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_hash` CHAR(64) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `remember` TINYINT(1) NOT NULL DEFAULT 0,
  `user_agent_hash` CHAR(64) DEFAULT NULL,
  `last_activity_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_sessions_token_hash` (`token_hash`),
  KEY `idx_user_sessions_user` (`user_id`),
  KEY `idx_user_sessions_expires` (`expires_at`),
  CONSTRAINT `fk_user_sessions_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Garantir índice UNIQUE no código do produto (evita duplicidade em concorrência)
SET @db := DATABASE();

SET @sql := (SELECT IF(
  EXISTS(
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'inventory_products'
      AND COLUMN_NAME = 'code'
      AND NON_UNIQUE = 0
  ),
  'SELECT 1',
  'ALTER TABLE `inventory_products` ADD UNIQUE KEY `uk_inventory_products_code` (`code`)'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Índice de localização para otimizar filtros por estoque pré-definido
SET @sql := (SELECT IF(
  EXISTS(
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'inventory_products'
      AND INDEX_NAME = 'idx_inventory_products_location'
  ),
  'SELECT 1',
  'ALTER TABLE `inventory_products` ADD KEY `idx_inventory_products_location` (`location`)'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
