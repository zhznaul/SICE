-- ============================================================================
-- SICE - Migração Segura: Nomenclatura Hortifrutti + Classificações por Categoria
-- Compatível com MySQL 8.0+ / MariaDB 10.4+
-- Preserva integralmente os registros já existentes.
-- ============================================================================

USE `sice_db`;

-- 1. Ampliar catalog_type para VARCHAR(32) preservando registros existentes
ALTER TABLE `inventory_products`
  MODIFY COLUMN `catalog_type` VARCHAR(32) DEFAULT NULL;

-- 2. Adicionar colunas de fabricação, manipulação, estado aberto/fechado,
--    prazo após abertura e validade indeterminada em inventory_products
SET @db := DATABASE();

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inventory_products' AND COLUMN_NAME = 'manufactured_at'),
  'SELECT 1',
  'ALTER TABLE `inventory_products` ADD COLUMN `manufactured_at` DATE DEFAULT NULL AFTER `purchased_at`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inventory_products' AND COLUMN_NAME = 'handled_at'),
  'SELECT 1',
  'ALTER TABLE `inventory_products` ADD COLUMN `handled_at` DATE DEFAULT NULL AFTER `manufactured_at`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inventory_products' AND COLUMN_NAME = 'expiry_indeterminate'),
  'SELECT 1',
  'ALTER TABLE `inventory_products` ADD COLUMN `expiry_indeterminate` TINYINT(1) NOT NULL DEFAULT 0 AFTER `expiry_date`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inventory_products' AND COLUMN_NAME = 'package_status'),
  'SELECT 1',
  'ALTER TABLE `inventory_products` ADD COLUMN `package_status` ENUM(\'closed\', \'opened\') DEFAULT NULL AFTER `expiry_indeterminate`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inventory_products' AND COLUMN_NAME = 'shelf_life_after_opening_days'),
  'SELECT 1',
  'ALTER TABLE `inventory_products` ADD COLUMN `shelf_life_after_opening_days` INT UNSIGNED DEFAULT NULL AFTER `package_status`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inventory_products' AND COLUMN_NAME = 'opened_expiry_date'),
  'SELECT 1',
  'ALTER TABLE `inventory_products` ADD COLUMN `opened_expiry_date` DATE DEFAULT NULL AFTER `shelf_life_after_opening_days`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Tabela de histórico rastreável de abertura e mudanças de estado (xaropes)
CREATE TABLE IF NOT EXISTS `inventory_product_state_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `event_type` VARCHAR(40) NOT NULL DEFAULT 'opened',
  `previous_state` VARCHAR(24) DEFAULT NULL,
  `new_state` VARCHAR(24) DEFAULT NULL,
  `manufactured_at` DATE DEFAULT NULL,
  `original_expiry_date` DATE DEFAULT NULL,
  `handled_at` DATE DEFAULT NULL,
  `shelf_life_after_opening_days` INT UNSIGNED DEFAULT NULL,
  `opened_expiry_date` DATE DEFAULT NULL,
  `notes` VARCHAR(500) DEFAULT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_prod_state_hist_product` (`product_id`),
  KEY `idx_prod_state_hist_created_at` (`created_at`),
  CONSTRAINT `fk_prod_state_hist_product`
    FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prod_state_hist_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Suporte a data de fabricação em inventory_movements (entradas)
SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inventory_movements' AND COLUMN_NAME = 'manufactured_at'),
  'SELECT 1',
  'ALTER TABLE `inventory_movements` ADD COLUMN `manufactured_at` DATE DEFAULT NULL AFTER `unit_cost`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Adequar inventory_labels para suportar valores nulos reais e novas classificações
ALTER TABLE `inventory_labels`
  MODIFY COLUMN `handled_at` DATE DEFAULT NULL,
  MODIFY COLUMN `expiry_date` DATE DEFAULT NULL,
  MODIFY COLUMN `lot` VARCHAR(80) DEFAULT NULL;

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inventory_labels' AND COLUMN_NAME = 'catalog_type'),
  'SELECT 1',
  'ALTER TABLE `inventory_labels` ADD COLUMN `catalog_type` VARCHAR(32) DEFAULT NULL AFTER `product_id`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inventory_labels' AND COLUMN_NAME = 'manufactured_at'),
  'SELECT 1',
  'ALTER TABLE `inventory_labels` ADD COLUMN `manufactured_at` DATE DEFAULT NULL AFTER `unit`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inventory_labels' AND COLUMN_NAME = 'opened_expiry_date'),
  'SELECT 1',
  'ALTER TABLE `inventory_labels` ADD COLUMN `opened_expiry_date` DATE DEFAULT NULL AFTER `expiry_date`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inventory_labels' AND COLUMN_NAME = 'package_status'),
  'SELECT 1',
  'ALTER TABLE `inventory_labels` ADD COLUMN `package_status` ENUM(\'closed\', \'opened\') DEFAULT NULL AFTER `opened_expiry_date`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (SELECT IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'inventory_labels' AND COLUMN_NAME = 'expiry_indeterminate'),
  'SELECT 1',
  'ALTER TABLE `inventory_labels` ADD COLUMN `expiry_indeterminate` TINYINT(1) NOT NULL DEFAULT 0 AFTER `package_status`'
));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. Migração segura de nomenclatura: Hortifrúti -> Hortifrutti
UPDATE `inventory_products`
SET `category` = 'Hortifrutti'
WHERE LOWER(TRIM(`category`)) IN ('hortifrúti', 'hortifruti');

UPDATE `inventory_products`
SET `location` = REPLACE(REPLACE(REPLACE(REPLACE(`location`, 'Hortifrúti', 'Hortifrutti'), 'hortifrúti', 'hortifrutti'), 'Hortifruti', 'Hortifrutti'), 'hortifruti', 'hortifrutti')
WHERE `location` LIKE '%Hortifrúti%' OR `location` LIKE '%hortifrúti%' OR `location` LIKE '%Hortifruti%' OR `location` LIKE '%hortifruti%';

UPDATE `purchase_orders`
SET `title` = REPLACE(REPLACE(REPLACE(REPLACE(`title`, 'Hortifrúti', 'Hortifrutti'), 'hortifrúti', 'hortifrutti'), 'Hortifruti', 'Hortifrutti'), 'hortifruti', 'hortifrutti')
WHERE `title` LIKE '%Hortifrúti%' OR `title` LIKE '%hortifrúti%' OR `title` LIKE '%Hortifruti%' OR `title` LIKE '%hortifruti%';

-- 7. Migração segura das classificações automáticas e bebidas legadas
UPDATE `inventory_products`
SET `catalog_type` = 'perishable', `expiry_indeterminate` = 0
WHERE `category` IN ('Carnes e pescados', 'Laticínios e frios', 'Hortifrutti');

UPDATE `inventory_products`
SET `catalog_type` = 'disposable',
    `expiry_indeterminate` = 0,
    `expiry_date` = NULL,
    `manufactured_at` = NULL,
    `handled_at` = NULL,
    `package_status` = NULL,
    `shelf_life_after_opening_days` = NULL,
    `opened_expiry_date` = NULL
WHERE `category` = 'Materiais descartáveis';

-- Bebidas legadas com catalog_type = 'beverage' ou NULL
UPDATE `inventory_products`
SET `catalog_type` = 'distilled',
    `expiry_indeterminate` = 1,
    `expiry_date` = NULL
WHERE `category` = 'Bebidas'
  AND (`catalog_type` IS NULL OR `catalog_type` IN ('beverage', 'non-perishable'))
  AND LOWER(`name`) REGEXP 'gin|vodka|whisky|whiskey|rum|cachaça|cachaca|tequila|conhaque|cognac|licor|destilado|aperol|campari|vermute|bourbon|mezcal|sake|saquê';

UPDATE `inventory_products`
SET `catalog_type` = 'syrup',
    `package_status` = COALESCE(`package_status`, 'closed'),
    `expiry_indeterminate` = 0
WHERE `category` = 'Bebidas'
  AND (`catalog_type` IS NULL OR `catalog_type` IN ('beverage', 'non-perishable'))
  AND LOWER(`name`) REGEXP 'xarope|syrup|monin|grenadine|groselha';

UPDATE `inventory_products`
SET `catalog_type` = 'soft',
    `expiry_indeterminate` = 0
WHERE `category` = 'Bebidas'
  AND (`catalog_type` IS NULL OR `catalog_type` IN ('beverage', 'non-perishable'))
  AND LOWER(`name`) REGEXP 'refrigerante|coca|guaraná|guarana|tônica|tonica|água|agua|suco|energético|energetico|soda|soft|chá|cha|mate|ginger';

UPDATE `inventory_products`
SET `expiry_indeterminate` = 1,
    `expiry_date` = NULL
WHERE `catalog_type` = 'distilled';
