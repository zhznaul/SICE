-- ============================================================================
-- SICE - Migração Segura: Atualização de Unidades de Medida (U.M.)
-- 1. Etiquetas: adiciona FARDO e CAIXA
-- 2. Insumos / Movimentações / Compras: adiciona fardo e ml; altera litros -> l
-- Preserva todos os registros existentes sem erro de truncamento.
-- ============================================================================

USE `sice_db`;

-- 1. inventory_products (migração em 3 passos para preservar registros com 'litros')
ALTER TABLE `inventory_products`
  MODIFY COLUMN `unit` ENUM('kg', 'g', 'un', 'litros', 'l', 'ml', 'caixa', 'fardo') NOT NULL DEFAULT 'un';

UPDATE `inventory_products`
SET `unit` = 'l'
WHERE `unit` = 'litros';

ALTER TABLE `inventory_products`
  MODIFY COLUMN `unit` ENUM('kg', 'g', 'un', 'l', 'ml', 'caixa', 'fardo') NOT NULL DEFAULT 'un';

-- 2. inventory_movements
UPDATE `inventory_movements`
SET `unit` = 'l'
WHERE `unit` = 'litros';

-- 3. purchase_orders (mantém consistência com o cadastro de insumos)
ALTER TABLE `purchase_orders`
  MODIFY COLUMN `unit` ENUM('kg', 'g', 'un', 'litros', 'l', 'ml', 'caixa', 'fardo') NOT NULL DEFAULT 'un';

UPDATE `purchase_orders`
SET `unit` = 'l'
WHERE `unit` = 'litros';

ALTER TABLE `purchase_orders`
  MODIFY COLUMN `unit` ENUM('kg', 'g', 'un', 'l', 'ml', 'caixa', 'fardo') NOT NULL DEFAULT 'un';

-- 4. inventory_labels (adiciona FARDO e CAIXA)
ALTER TABLE `inventory_labels`
  MODIFY COLUMN `unit` ENUM('UN', 'KG', 'G', 'L', 'ML', 'FARDO', 'CAIXA') NOT NULL DEFAULT 'UN';
