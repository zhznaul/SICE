-- SICE - Sistema Inteligente de Controle de Estoque
-- Banco de Dados MySQL 8.0+ / MariaDB 10.4+
-- Charset: utf8mb4

CREATE DATABASE IF NOT EXISTS `sice_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `sice_db`;

-- ---------------------------------------------------------------------------
-- 1. Usuários e perfis de acesso
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(160) NOT NULL,
  `email` VARCHAR(254) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('owner', 'storekeeper', 'operational') NOT NULL DEFAULT 'operational',
  `function_title` VARCHAR(160) DEFAULT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. Produtos / Insumos do estoque
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventory_products` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(160) NOT NULL,
  `category` VARCHAR(120) NOT NULL,
  `catalog_type` VARCHAR(32) DEFAULT NULL,
  `code` VARCHAR(80) NOT NULL,
  `location` VARCHAR(160) NOT NULL,
  `quantity` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `unit` ENUM('kg', 'g', 'un', 'l', 'ml', 'caixa', 'fardo') NOT NULL DEFAULT 'un',
  `minimum_stock` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `expiry` VARCHAR(160) DEFAULT NULL,
  `purchased_at` DATE DEFAULT NULL,
  `manufactured_at` DATE DEFAULT NULL,
  `handled_at` DATE DEFAULT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `expiry_indeterminate` TINYINT(1) NOT NULL DEFAULT 0,
  `package_status` ENUM('closed', 'opened') DEFAULT NULL,
  `shelf_life_after_opening_days` INT UNSIGNED DEFAULT NULL,
  `opened_expiry_date` DATE DEFAULT NULL,
  `unit_cost` DECIMAL(12,2) DEFAULT 0.00,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `status` ENUM('ok', 'low', 'critical') NOT NULL DEFAULT 'ok',
  `accent` VARCHAR(24) DEFAULT '#376759',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_inventory_products_code` (`code`),
  KEY `idx_inventory_products_name` (`name`),
  KEY `idx_inventory_products_category` (`category`),
  KEY `idx_inventory_products_catalog_type` (`catalog_type`),
  KEY `idx_inventory_products_status` (`status`),
  KEY `idx_inventory_products_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2b. Histórico de abertura e alterações de estado (rastreabilidade de xaropes)
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- 3. Movimentações de estoque (Entradas e Baixas)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventory_movements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `type` ENUM('entry', 'exit') NOT NULL,
  `quantity` DECIMAL(12,3) NOT NULL,
  `unit` VARCHAR(32) NOT NULL,
  `reason` VARCHAR(160) NOT NULL,
  `other_reason` TEXT DEFAULT NULL,
  `supplier` VARCHAR(160) DEFAULT NULL,
  `received_date` DATE DEFAULT NULL,
  `unit_cost` DECIMAL(12,2) DEFAULT NULL,
  `manufactured_at` DATE DEFAULT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `operational_area` ENUM('kitchen', 'bar', 'storekeeper') DEFAULT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_movements_product` (`product_id`),
  KEY `idx_movements_created_by` (`created_by`),
  KEY `idx_movements_created_at` (`created_at`),
  KEY `idx_movements_type` (`type`),
  CONSTRAINT `fk_movements_product`
    FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_movements_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Etiquetas de identificação (QR Code)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inventory_labels` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(160) NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `catalog_type` VARCHAR(32) DEFAULT NULL,
  `quantity` DECIMAL(12,3) NOT NULL,
  `unit` ENUM('UN', 'KG', 'G', 'L', 'ML', 'FARDO', 'CAIXA') NOT NULL DEFAULT 'UN',
  `manufactured_at` DATE DEFAULT NULL,
  `handled_at` DATE DEFAULT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `opened_expiry_date` DATE DEFAULT NULL,
  `package_status` ENUM('closed', 'opened') DEFAULT NULL,
  `expiry_indeterminate` TINYINT(1) NOT NULL DEFAULT 0,
  `lot` VARCHAR(80) DEFAULT NULL,
  `responsible_name` VARCHAR(120) NOT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_labels_product` (`product_id`),
  KEY `idx_labels_created_by` (`created_by`),
  CONSTRAINT `fk_labels_product`
    FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_labels_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. Planejamento de compras futuras
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(160) NOT NULL,
  `product_id` INT UNSIGNED DEFAULT NULL,
  `supplier` VARCHAR(160) DEFAULT NULL,
  `quantity` DECIMAL(12,3) NOT NULL,
  `unit` ENUM('kg', 'g', 'un', 'l', 'ml', 'caixa', 'fardo') NOT NULL DEFAULT 'un',
  `unit_cost` DECIMAL(12,2) DEFAULT NULL,
  `expected_date` DATE NOT NULL,
  `status` ENUM('planned', 'ordered', 'received', 'cancelled') NOT NULL DEFAULT 'planned',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_orders_product` (`product_id`),
  KEY `idx_orders_expected_date` (`expected_date`),
  KEY `idx_orders_status` (`status`),
  CONSTRAINT `fk_orders_product`
    FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. Comprovantes e notas fiscais de compra
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `purchase_receipts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(160) NOT NULL,
  `supplier` VARCHAR(160) DEFAULT NULL,
  `purchase_date` DATE NOT NULL,
  `amount` DECIMAL(12,2) DEFAULT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_receipts_purchase_date` (`purchase_date`),
  KEY `idx_receipts_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_receipts_user`
    FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 7. Chamados de ajuda e suporte
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `subject` VARCHAR(180) NOT NULL,
  `description` TEXT NOT NULL,
  `attachments_json` TEXT DEFAULT NULL,
  `status` ENUM('open', 'in_progress', 'resolved') NOT NULL DEFAULT 'open',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_support_tickets_user` (`user_id`),
  KEY `idx_support_tickets_created_at` (`created_at`),
  CONSTRAINT `fk_support_tickets_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Dados iniciais (Seed) - Senha padrão de todos os usuários de teste: password
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `users` (`id`, `name`, `email`, `password_hash`, `role`, `function_title`, `active`) VALUES
(1, 'Luan Oliveira', 'admin@alltoalmar.com.br', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'owner', 'Proprietário', 1),
(2, 'Camila Ferreira', 'almoxarife@alltoalmar.com.br', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'storekeeper', 'Almoxarife', 1),
(3, 'Rafael Santos', 'cozinha@alltoalmar.com.br', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'operational', 'Cozinheiro', 1);

INSERT IGNORE INTO `inventory_products`
(`id`, `name`, `category`, `catalog_type`, `code`, `location`, `quantity`, `unit`, `minimum_stock`, `expiry`, `purchased_at`, `manufactured_at`, `handled_at`, `expiry_date`, `expiry_indeterminate`, `package_status`, `shelf_life_after_opening_days`, `opened_expiry_date`, `unit_cost`, `active`, `status`, `accent`)
VALUES
(1, 'Salmão fresco inteiro', 'Carnes e pescados', 'perishable', 'INS-0101', 'Câmara Fria 01', 8.500, 'kg', 10.000, 'Consumir em até 3 dias', CURDATE() - INTERVAL 2 DAY, NULL, NULL, CURDATE() + INTERVAL 2 DAY, 0, NULL, NULL, NULL, 84.90, 1, 'critical', '#a54e44'),
(2, 'Camarão rosa limpo', 'Carnes e pescados', 'perishable', 'INS-0102', 'Freezer Pescados', 14.000, 'kg', 8.000, 'Manter congelado a -18°C', CURDATE() - INTERVAL 5 DAY, NULL, NULL, CURDATE() + INTERVAL 25 DAY, 0, NULL, NULL, NULL, 118.00, 1, 'ok', '#376759'),
(3, 'Limão siciliano', 'Hortifrutti', 'perishable', 'INS-0201', 'Hortifrutti B2', 4.000, 'kg', 6.000, 'Revisar seleção diária', CURDATE() - INTERVAL 3 DAY, NULL, NULL, CURDATE() + INTERVAL 4 DAY, 0, NULL, NULL, NULL, 14.50, 1, 'low', '#b67c33'),
(4, 'Queijo burrata artesanal', 'Laticínios e frios', 'perishable', 'INS-0301', 'Câmara Fria 02', 6.000, 'un', 8.000, 'Validade curta', CURDATE() - INTERVAL 2 DAY, NULL, NULL, CURDATE() + INTERVAL 5 DAY, 0, NULL, NULL, NULL, 26.00, 1, 'low', '#b9995b'),
(5, 'Gin London Dry 750ml', 'Bebidas', 'distilled', 'INS-0401', 'Adega Bar Rooftop', 18.000, 'un', 6.000, NULL, CURDATE() - INTERVAL 15 DAY, NULL, NULL, NULL, 1, NULL, NULL, NULL, 139.90, 1, 'ok', '#234a3e'),
(6, 'Guardanapo folha dupla', 'Materiais descartáveis', 'disposable', 'INS-0501', 'Depósito Seco A1', 12.000, 'caixa', 4.000, NULL, CURDATE() - INTERVAL 20 DAY, NULL, NULL, NULL, 0, NULL, NULL, NULL, 48.00, 1, 'ok', '#376759');

INSERT IGNORE INTO `inventory_movements`
(`id`, `product_id`, `type`, `quantity`, `unit`, `reason`, `supplier`, `received_date`, `unit_cost`, `manufactured_at`, `expiry_date`, `operational_area`, `created_by`, `created_at`)
VALUES
(1, 1, 'entry', 12.000, 'kg', 'Recebimento de fornecedor', 'Pescados Litoral Sul', CURDATE() - INTERVAL 2 DAY, 84.90, NULL, CURDATE() + INTERVAL 2 DAY, NULL, 2, NOW() - INTERVAL 2 DAY),
(2, 1, 'exit', 3.500, 'kg', 'Consumo', NULL, NULL, NULL, NULL, NULL, 'kitchen', 3, NOW() - INTERVAL 1 DAY),
(3, 3, 'exit', 2.000, 'kg', 'Validade', NULL, NULL, NULL, NULL, NULL, 'bar', 3, NOW() - INTERVAL 6 HOUR),
(4, 5, 'entry', 18.000, 'un', 'Recebimento de fornecedor', 'Distribuidora Costa Verde', CURDATE() - INTERVAL 15 DAY, 139.90, NULL, NULL, NULL, 2, NOW() - INTERVAL 15 DAY);

INSERT IGNORE INTO `purchase_orders`
(`id`, `title`, `product_id`, `supplier`, `quantity`, `unit`, `unit_cost`, `expected_date`, `status`, `notes`, `created_by`)
VALUES
(1, 'Reposição de salmão fresco', 1, 'Pescados Litoral Sul', 15.000, 'kg', 84.90, CURDATE() + INTERVAL 2 DAY, 'ordered', 'Entrega antes das 10h para pré-preparo.', 1),
(2, 'Hortifrutti semanal', 3, 'Ceasa Santos', 12.000, 'kg', 14.50, CURDATE() + INTERVAL 3 DAY, 'planned', 'Priorizar limão siciliano e ervas frescas.', 2);
