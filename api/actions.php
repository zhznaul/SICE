<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function post_text(string $key, int $max = 160): string
{
    $val = $_POST[$key] ?? '';
    if (!is_string($val)) {
        return '';
    }
    $val = trim($val);
    return mb_substr($val, 0, $max);
}

function post_number(string $key): ?float
{
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        return null;
    }
    $raw = str_replace(',', '.', trim((string) $_POST[$key]));
    if (!is_numeric($raw)) {
        return null;
    }
    $val = (float) $raw;
    return is_finite($val) ? $val : null;
}

function post_date(string $key): ?string
{
    $val = post_text($key, 20);
    if ($val === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', substr($val, 0, 10));
    return $dt ? $dt->format('Y-m-d') : null;
}

function valid_unit(string $unit): string
{
    $allowed = ['kg', 'g', 'un', 'litros', 'caixa'];
    return in_array($unit, $allowed, true) ? $unit : 'un';
}

function valid_catalog_type(string $val): ?string
{
    $allowed = ['perishable', 'non-perishable', 'beverage', 'disposable'];
    return in_array($val, $allowed, true) ? $val : null;
}

function signed_qty(string $type, float $qty): float
{
    return ($type === 'entry' ? 1.0 : -1.0) * $qty;
}

$user = current_user();
if (!$user) {
    respond(['ok' => false, 'error' => 'Sua sessão expirou. Entre novamente.'], 401);
}

$action = post_text('action', 64);
$pdo = db();

try {
    switch ($action) {
        // -------------------------------------------------------------------
        // 1. Criar funcionário (owner)
        // -------------------------------------------------------------------
        case 'createEmployee': {
            if ($user['role'] !== 'owner') {
                respond(['ok' => false, 'error' => 'Apenas o proprietário gerencia a equipe.'], 403);
            }
            $name = post_text('name');
            $email = strtolower(post_text('email', 254));
            $passwordRaw = $_POST['password'] ?? '';
            $password = is_string($passwordRaw) ? mb_substr($passwordRaw, 0, 128) : '';
            $role = post_text('role');
            $fn = post_text('function');

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen(trim($password)) < 4 || !in_array($role, ['storekeeper', 'operational'], true)) {
                respond(['ok' => false, 'error' => 'Revise nome, e-mail, função, perfil e senha.']);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, role, function_title, active)
                 VALUES (:name, :email, :hash, :role, :fn, 1)'
            );
            $stmt->execute([
                ':name'  => $name,
                ':email' => $email,
                ':hash'  => password_hash($password, PASSWORD_DEFAULT),
                ':role'  => $role,
                ':fn'    => $fn !== '' ? $fn : null,
            ]);
            respond(['ok' => true, 'message' => 'Novo acesso criado para o funcionário.']);
        }

        // -------------------------------------------------------------------
        // 2. Atualizar funcionário (owner)
        // -------------------------------------------------------------------
        case 'updateEmployee': {
            if ($user['role'] !== 'owner') {
                respond(['ok' => false, 'error' => 'Apenas o proprietário gerencia a equipe.'], 403);
            }
            $empId = (int) post_text('id', 20);
            $role = post_text('role');
            $name = post_text('name');
            $email = strtolower(post_text('email', 254));
            $fn = post_text('function');
            $active = in_array(post_text('active'), ['true', '1', 'on'], true) ? 1 : 0;
            $passwordRaw = $_POST['password'] ?? '';
            $password = is_string($passwordRaw) ? mb_substr($passwordRaw, 0, 128) : '';

            if ($empId <= 0 || !in_array($role, ['storekeeper', 'operational'], true) || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                respond(['ok' => false, 'error' => 'Revise nome, e-mail e perfil do funcionário.']);
            }

            $targetStmt = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
            $targetStmt->execute([':id' => $empId]);
            $targetUser = $targetStmt->fetch();
            if (!$targetUser) {
                respond(['ok' => false, 'error' => 'Funcionário não encontrado.']);
            }
            if ((string) $targetUser['role'] === 'owner') {
                respond(['ok' => false, 'error' => 'Não é possível alterar o perfil do proprietário nesta tela.'], 403);
            }

            if ($password !== '') {
                if (mb_strlen($password) < 4) {
                    respond(['ok' => false, 'error' => 'A nova senha deve ter pelo menos 4 caracteres.']);
                }
                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET name = :name, email = :email, role = :role, function_title = :fn,
                         active = :active, password_hash = :hash
                     WHERE id = :id AND role <> 'owner'"
                );
                $stmt->execute([
                    ':name'   => $name,
                    ':email'  => $email,
                    ':role'   => $role,
                    ':fn'     => $fn !== '' ? $fn : null,
                    ':active' => $active,
                    ':hash'   => password_hash($password, PASSWORD_DEFAULT),
                    ':id'     => $empId,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET name = :name, email = :email, role = :role, function_title = :fn, active = :active
                     WHERE id = :id AND role <> 'owner'"
                );
                $stmt->execute([
                    ':name'   => $name,
                    ':email'  => $email,
                    ':role'   => $role,
                    ':fn'     => $fn !== '' ? $fn : null,
                    ':active' => $active,
                    ':id'     => $empId,
                ]);
            }
            respond(['ok' => true, 'message' => 'Acesso do funcionário atualizado.']);
        }

        // -------------------------------------------------------------------
        // 3. Cadastrar insumo (owner / storekeeper)
        // -------------------------------------------------------------------
        case 'createProduct': {
            if (!is_manager($user)) {
                respond(['ok' => false, 'error' => 'Seu perfil apenas seleciona insumos existentes.'], 403);
            }
            $name = post_text('name');
            $code = strtoupper(post_text('code', 80));
            $category = post_text('category', 120);
            $location = post_text('location');
            $quantity = post_number('quantity');
            $minimumStock = post_number('minimumStock');
            $unitCost = post_number('unitCost') ?? 0.0;

            if ($name === '' || $code === '' || $quantity === null || $minimumStock === null || $quantity < 0 || $minimumStock < 0) {
                respond(['ok' => false, 'error' => 'Preencha os campos obrigatórios do insumo.']);
            }

            $status = ($quantity <= $minimumStock) ? 'low' : 'ok';

            $stmt = $pdo->prepare(
                'INSERT INTO inventory_products
                 (name, category, catalog_type, code, location, quantity, unit, minimum_stock,
                  unit_cost, active, expiry, purchased_at, expiry_date, status, accent)
                 VALUES
                 (:name, :category, :catalog_type, :code, :location, :quantity, :unit, :minimum_stock,
                  :unit_cost, 1, :expiry, :purchased_at, :expiry_date, :status, "#376759")'
            );
            $stmt->execute([
                ':name'          => $name,
                ':category'      => $category !== '' ? $category : 'Hortifrúti',
                ':catalog_type'  => valid_catalog_type(post_text('catalogType')),
                ':code'          => $code,
                ':location'      => $location !== '' ? $location : 'Estoque geral',
                ':quantity'      => $quantity,
                ':unit'          => valid_unit(post_text('unit')),
                ':minimum_stock' => $minimumStock,
                ':unit_cost'     => max(0, $unitCost),
                ':expiry'        => post_text('expiry') ?: null,
                ':purchased_at'  => post_date('purchasedAt'),
                ':expiry_date'   => post_date('expiryDate'),
                ':status'        => $status,
            ]);
            respond(['ok' => true, 'message' => 'Insumo cadastrado com sucesso.']);
        }

        // -------------------------------------------------------------------
        // 4. Atualizar insumo (owner / storekeeper)
        // -------------------------------------------------------------------
        case 'updateProduct': {
            if (!is_manager($user)) {
                respond(['ok' => false, 'error' => 'Acesso restrito à gestão de estoque.'], 403);
            }
            $productId = (int) post_text('id', 20);
            $name = post_text('name');
            $minimumStock = post_number('minimumStock');
            $unitCost = post_number('unitCost') ?? 0.0;

            if ($productId <= 0 || $name === '' || $minimumStock === null || $minimumStock < 0) {
                respond(['ok' => false, 'error' => 'Revise nome e estoque mínimo.']);
            }

            $curStmt = $pdo->prepare('SELECT quantity FROM inventory_products WHERE id = :id LIMIT 1');
            $curStmt->execute([':id' => $productId]);
            $cur = $curStmt->fetch();
            if (!$cur) {
                respond(['ok' => false, 'error' => 'Insumo não encontrado.']);
            }
            $status = ((float) $cur['quantity'] <= $minimumStock) ? 'low' : 'ok';

            $stmt = $pdo->prepare(
                'UPDATE inventory_products
                 SET name = :name, category = :category, catalog_type = :catalog_type,
                     code = :code, location = :location, unit = :unit,
                     minimum_stock = :minimum_stock, unit_cost = :unit_cost,
                     expiry = :expiry, expiry_date = :expiry_date, status = :status
                 WHERE id = :id'
            );
            $stmt->execute([
                ':name'          => $name,
                ':category'      => post_text('category', 120),
                ':catalog_type'  => valid_catalog_type(post_text('catalogType')),
                ':code'          => strtoupper(post_text('code', 80)),
                ':location'      => post_text('location'),
                ':unit'          => valid_unit(post_text('unit')),
                ':minimum_stock' => $minimumStock,
                ':unit_cost'     => max(0, $unitCost),
                ':expiry'        => post_text('expiry') ?: null,
                ':expiry_date'   => post_date('expiryDate'),
                ':status'        => $status,
                ':id'            => $productId,
            ]);
            respond(['ok' => true, 'message' => 'Insumo atualizado.']);
        }

        // -------------------------------------------------------------------
        // 5. Ativar / Inativar insumo
        // -------------------------------------------------------------------
        case 'setProductActive': {
            if (!is_manager($user)) {
                respond(['ok' => false, 'error' => 'Acesso restrito à gestão de estoque.'], 403);
            }
            $productId = (int) post_text('id', 20);
            $active = post_text('active') === 'true' ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE inventory_products SET active = :active WHERE id = :id');
            $stmt->execute([':active' => $active, ':id' => $productId]);
            respond(['ok' => true, 'message' => 'Disponibilidade do insumo atualizada.']);
        }

        // -------------------------------------------------------------------
        // 6. Remover insumo sem histórico
        // -------------------------------------------------------------------
        case 'deleteProduct': {
            if (!is_manager($user)) {
                respond(['ok' => false, 'error' => 'Acesso restrito à gestão de estoque.'], 403);
            }
            $productId = (int) post_text('id', 20);
            $check = $pdo->prepare('SELECT COUNT(*) FROM inventory_movements WHERE product_id = :id');
            $check->execute([':id' => $productId]);
            if ((int) $check->fetchColumn() > 0) {
                respond(['ok' => false, 'error' => 'Este insumo possui histórico. Inative-o para preservar a rastreabilidade.']);
            }
            $del = $pdo->prepare('DELETE FROM inventory_products WHERE id = :id');
            $del->execute([':id' => $productId]);
            respond(['ok' => true, 'message' => 'Insumo sem histórico removido.']);
        }

        // -------------------------------------------------------------------
        // 7. Criar movimentação (Entrada / Baixa) com sincronização de saldo
        // -------------------------------------------------------------------
        case 'createMovement': {
            $type = post_text('type');
            $productId = (int) post_text('product', 20);
            $quantity = post_number('quantity');
            $reason = post_text('reason');
            $otherReason = post_text('otherReason', 500);
            $operationalArea = post_text('operationalArea');

            if ($productId <= 0 || !in_array($type, ['entry', 'exit'], true) || $quantity === null || $quantity <= 0 || $reason === '') {
                respond(['ok' => false, 'error' => 'Preencha produto, quantidade e motivo.']);
            }
            if ($type === 'entry' && !is_manager($user)) {
                respond(['ok' => false, 'error' => 'Apenas a gestão de estoque registra entradas.'], 403);
            }
            if ($reason === 'Outros' && $otherReason === '') {
                respond(['ok' => false, 'error' => 'Descreva o motivo da baixa.']);
            }
            if ($user['role'] === 'operational' && $type === 'exit' && !in_array($operationalArea, ['kitchen', 'bar'], true)) {
                respond(['ok' => false, 'error' => 'Selecione cozinha ou bar.']);
            }

            $pdo->beginTransaction();
            $prodStmt = $pdo->prepare(
                'SELECT id, name, quantity, minimum_stock FROM inventory_products WHERE id = :id FOR UPDATE'
            );
            $prodStmt->execute([':id' => $productId]);
            $product = $prodStmt->fetch();
            if (!$product) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Insumo não encontrado.']);
            }

            $newQty = (float) $product['quantity'] + signed_qty($type, $quantity);
            if ($newQty < 0) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Saldo insuficiente para ' . $product['name'] . '.']);
            }

            $newStatus = ($newQty <= (float) $product['minimum_stock']) ? 'low' : 'ok';
            $upd = $pdo->prepare('UPDATE inventory_products SET quantity = :qty, status = :status WHERE id = :id');
            $upd->execute([':qty' => $newQty, ':status' => $newStatus, ':id' => $productId]);

            $entryCost = ($type === 'entry') ? post_number('unitCost') : null;
            $ins = $pdo->prepare(
                'INSERT INTO inventory_movements
                 (product_id, type, quantity, unit, reason, other_reason, supplier,
                  received_date, unit_cost, expiry_date, operational_area, created_by)
                 VALUES
                 (:product_id, :type, :quantity, :unit, :reason, :other_reason, :supplier,
                  :received_date, :unit_cost, :expiry_date, :operational_area, :created_by)'
            );
            $ins->execute([
                ':product_id'       => $productId,
                ':type'             => $type,
                ':quantity'         => $quantity,
                ':unit'             => post_text('unit', 32) ?: 'un',
                ':reason'           => $reason,
                ':other_reason'     => $reason === 'Outros' ? $otherReason : null,
                ':supplier'         => $type === 'entry' ? (post_text('supplier') ?: null) : null,
                ':received_date'    => $type === 'entry' ? post_date('receivedDate') : null,
                ':unit_cost'        => $entryCost,
                ':expiry_date'      => $type === 'entry' ? post_date('expiryDate') : null,
                ':operational_area' => ($user['role'] === 'operational' && $type === 'exit') ? $operationalArea : null,
                ':created_by'       => $user['id'],
            ]);
            $pdo->commit();

            respond(['ok' => true, 'message' => $type === 'entry' ? 'Entrada registrada.' : 'Baixa registrada.']);
        }

        // -------------------------------------------------------------------
        // 8. Atualizar entrada e recalcular saldo
        // -------------------------------------------------------------------
        case 'updateEntry': {
            if (!is_manager($user)) {
                respond(['ok' => false, 'error' => 'Acesso restrito à gestão de estoque.'], 403);
            }
            $movId = (int) post_text('id', 20);
            $quantity = post_number('quantity');
            $unitCost = post_number('unitCost');
            if ($movId <= 0 || $quantity === null || $quantity <= 0) {
                respond(['ok' => false, 'error' => 'Informe uma quantidade válida.']);
            }

            $pdo->beginTransaction();
            $mStmt = $pdo->prepare('SELECT * FROM inventory_movements WHERE id = :id FOR UPDATE');
            $mStmt->execute([':id' => $movId]);
            $orig = $mStmt->fetch();
            if (!$orig) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Movimentação não encontrada.']);
            }

            $pStmt = $pdo->prepare('SELECT id, name, quantity, minimum_stock FROM inventory_products WHERE id = :id FOR UPDATE');
            $pStmt->execute([':id' => (int) $orig['product_id']]);
            $prod = $pStmt->fetch();
            if (!$prod) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Insumo não encontrado.']);
            }

            $delta = signed_qty((string) $orig['type'], $quantity) - signed_qty((string) $orig['type'], (float) $orig['quantity']);
            $newQty = (float) $prod['quantity'] + $delta;
            if ($newQty < 0) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Saldo insuficiente para ' . $prod['name'] . '.']);
            }

            $newStatus = ($newQty <= (float) $prod['minimum_stock']) ? 'low' : 'ok';
            $pdo->prepare('UPDATE inventory_products SET quantity = :qty, status = :status WHERE id = :id')
                ->execute([':qty' => $newQty, ':status' => $newStatus, ':id' => (int) $prod['id']]);

            $pdo->prepare(
                'UPDATE inventory_movements
                 SET quantity = :qty, unit = :unit, supplier = :supplier,
                     received_date = :received_date, unit_cost = :unit_cost, expiry_date = :expiry_date
                 WHERE id = :id'
            )->execute([
                ':qty'           => $quantity,
                ':unit'          => post_text('unit', 32) ?: (string) $orig['unit'],
                ':supplier'      => post_text('supplier') ?: null,
                ':received_date' => post_date('receivedDate'),
                ':unit_cost'     => $unitCost,
                ':expiry_date'   => post_date('expiryDate'),
                ':id'            => $movId,
            ]);
            $pdo->commit();

            respond(['ok' => true, 'message' => 'Entrada atualizada e saldo recalculado.']);
        }

        // -------------------------------------------------------------------
        // 9. Remover movimentação e recalcular saldo
        // -------------------------------------------------------------------
        case 'deleteMovement': {
            if (!is_manager($user)) {
                respond(['ok' => false, 'error' => 'Acesso restrito à gestão de estoque.'], 403);
            }
            $movId = (int) post_text('id', 20);

            $pdo->beginTransaction();
            $mStmt = $pdo->prepare('SELECT * FROM inventory_movements WHERE id = :id FOR UPDATE');
            $mStmt->execute([':id' => $movId]);
            $orig = $mStmt->fetch();
            if (!$orig) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Registro não encontrado.']);
            }

            $pStmt = $pdo->prepare('SELECT id, quantity, minimum_stock FROM inventory_products WHERE id = :id FOR UPDATE');
            $pStmt->execute([':id' => (int) $orig['product_id']]);
            $prod = $pStmt->fetch();
            if ($prod) {
                $newQty = (float) $prod['quantity'] - signed_qty((string) $orig['type'], (float) $orig['quantity']);
                if ($newQty < 0) {
                    $pdo->rollBack();
                    respond(['ok' => false, 'error' => 'A exclusão deixaria o estoque com saldo negativo.']);
                }
                $newStatus = ($newQty <= (float) $prod['minimum_stock']) ? 'low' : 'ok';
                $pdo->prepare('UPDATE inventory_products SET quantity = :qty, status = :status WHERE id = :id')
                    ->execute([':qty' => $newQty, ':status' => $newStatus, ':id' => (int) $prod['id']]);
            }

            $pdo->prepare('DELETE FROM inventory_movements WHERE id = :id')->execute([':id' => $movId]);
            $pdo->commit();

            respond(['ok' => true, 'message' => 'Registro removido e saldo recalculado.']);
        }

        // -------------------------------------------------------------------
        // 10. Criar pedido de compra
        // -------------------------------------------------------------------
        case 'createPurchaseOrder': {
            if (!is_manager($user)) {
                respond(['ok' => false, 'error' => 'Seu perfil não acessa compras.'], 403);
            }
            $title = post_text('title');
            $quantity = post_number('quantity');
            $expectedDate = post_date('expectedDate');
            if ($title === '' || $quantity === null || $quantity <= 0 || !$expectedDate) {
                respond(['ok' => false, 'error' => 'Revise item, quantidade e data prevista.']);
            }
            $prodRaw = post_text('product', 20);
            $productId = $prodRaw !== '' ? (int) $prodRaw : null;

            $stmt = $pdo->prepare(
                'INSERT INTO purchase_orders
                 (title, product_id, supplier, quantity, unit, unit_cost, expected_date, status, notes, created_by)
                 VALUES
                 (:title, :product_id, :supplier, :quantity, :unit, :unit_cost, :expected_date, "planned", :notes, :created_by)'
            );
            $stmt->execute([
                ':title'         => $title,
                ':product_id'    => $productId ?: null,
                ':supplier'      => post_text('supplier') ?: null,
                ':quantity'      => $quantity,
                ':unit'          => valid_unit(post_text('unit')),
                ':unit_cost'     => post_number('unitCost'),
                ':expected_date' => $expectedDate,
                ':notes'         => post_text('notes', 500) ?: null,
                ':created_by'    => $user['id'],
            ]);
            respond(['ok' => true, 'message' => 'Compra futura planejada.']);
        }

        // -------------------------------------------------------------------
        // 11. Atualizar pedido de compra
        // -------------------------------------------------------------------
        case 'updatePurchaseOrder': {
            if (!is_manager($user)) {
                respond(['ok' => false, 'error' => 'Seu perfil não acessa compras.'], 403);
            }
            $ordId = (int) post_text('id', 20);
            $title = post_text('title');
            $quantity = post_number('quantity');
            $expectedDate = post_date('expectedDate');
            $status = post_text('status');

            if ($ordId <= 0 || $title === '' || $quantity === null || $quantity <= 0 || !$expectedDate || !in_array($status, ['planned', 'ordered', 'received', 'cancelled'], true)) {
                respond(['ok' => false, 'error' => 'Revise item, quantidade, data e status.']);
            }
            $prodRaw = post_text('product', 20);
            $productId = $prodRaw !== '' ? (int) $prodRaw : null;

            $stmt = $pdo->prepare(
                'UPDATE purchase_orders
                 SET title = :title, product_id = :product_id, supplier = :supplier,
                     quantity = :quantity, unit = :unit, unit_cost = :unit_cost,
                     expected_date = :expected_date, status = :status, notes = :notes
                 WHERE id = :id'
            );
            $stmt->execute([
                ':title'         => $title,
                ':product_id'    => $productId ?: null,
                ':supplier'      => post_text('supplier') ?: null,
                ':quantity'      => $quantity,
                ':unit'          => valid_unit(post_text('unit')),
                ':unit_cost'     => post_number('unitCost'),
                ':expected_date' => $expectedDate,
                ':status'        => $status,
                ':notes'         => post_text('notes', 500) ?: null,
                ':id'            => $ordId,
            ]);
            respond(['ok' => true, 'message' => 'Pedido de compra atualizado.']);
        }

        // -------------------------------------------------------------------
        // 12. Remover pedido de compra
        // -------------------------------------------------------------------
        case 'deletePurchaseOrder': {
            if (!is_manager($user)) {
                respond(['ok' => false, 'error' => 'Seu perfil não acessa compras.'], 403);
            }
            $ordId = (int) post_text('id', 20);
            $pdo->prepare('DELETE FROM purchase_orders WHERE id = :id')->execute([':id' => $ordId]);
            respond(['ok' => true, 'message' => 'Planejamento de compra removido.']);
        }

        // -------------------------------------------------------------------
        // 13. Salvar etiqueta
        // -------------------------------------------------------------------
        case 'createLabel': {
            $productId = (int) post_text('product', 20);
            $quantity = post_number('quantity');
            $unit = post_text('unit', 10);
            $handledAt = post_date('handledAt');
            $expiryDate = post_date('expiryDate');
            $lot = post_text('lot', 80);
            $responsibleName = post_text('responsibleName', 120);

            if ($productId <= 0 || $quantity === null || $quantity <= 0 || !in_array($unit, ['UN', 'KG', 'G', 'L', 'ML'], true) || !$handledAt || !$expiryDate || $lot === '' || $responsibleName === '') {
                respond(['ok' => false, 'error' => 'Preencha todos os campos da etiqueta.']);
            }
            if ($expiryDate < $handledAt) {
                respond(['ok' => false, 'error' => 'A validade não pode ser anterior à manipulação.']);
            }

            $pStmt = $pdo->prepare('SELECT id, name, active FROM inventory_products WHERE id = :id LIMIT 1');
            $pStmt->execute([':id' => $productId]);
            $prod = $pStmt->fetch();
            if (!$prod || (int) $prod['active'] === 0) {
                respond(['ok' => false, 'error' => 'Selecione um insumo ativo.']);
            }

            $ins = $pdo->prepare(
                'INSERT INTO inventory_labels
                 (name, product_id, quantity, unit, handled_at, expiry_date, lot, responsible_name, created_by)
                 VALUES
                 (:name, :product_id, :quantity, :unit, :handled_at, :expiry_date, :lot, :responsible_name, :created_by)'
            );
            $ins->execute([
                ':name'             => (string) $prod['name'],
                ':product_id'       => $productId,
                ':quantity'         => $quantity,
                ':unit'             => $unit,
                ':handled_at'       => $handledAt,
                ':expiry_date'      => $expiryDate,
                ':lot'              => $lot,
                ':responsible_name' => $responsibleName,
                ':created_by'       => $user['id'],
            ]);
            respond(['ok' => true, 'message' => 'Etiqueta criada e guardada.']);
        }

        // -------------------------------------------------------------------
        // 14. Upload de comprovante de compra
        // -------------------------------------------------------------------
        case 'uploadReceipt': {
            if (!is_manager($user)) {
                respond(['ok' => false, 'error' => 'Seu perfil não acessa compras.'], 403);
            }
            $file = $_FILES['file'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) === 0) {
                respond(['ok' => false, 'error' => 'Selecione uma foto ou PDF do comprovante.']);
            }
            if ((int) $file['size'] > 10 * 1024 * 1024) {
                respond(['ok' => false, 'error' => 'O arquivo deve ter até 10 MB.']);
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file($file['tmp_name']);
            $allowed = [
                'application/pdf' => 'pdf',
                'image/jpeg'      => 'jpg',
                'image/png'       => 'png',
                'image/webp'      => 'webp',
            ];
            if (!isset($allowed[$mime])) {
                respond(['ok' => false, 'error' => 'Envie PDF, JPG, PNG ou WEBP.']);
            }

            $title = post_text('title');
            $purchaseDate = post_date('purchaseDate');
            if ($title === '' || !$purchaseDate) {
                respond(['ok' => false, 'error' => 'Preencha o título e a data da compra.']);
            }

            $dir = __DIR__ . '/../uploads/receipts';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $storedName = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
            if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $storedName)) {
                respond(['ok' => false, 'error' => 'Não foi possível salvar o arquivo enviado.'], 500);
            }

            $ins = $pdo->prepare(
                'INSERT INTO purchase_receipts
                 (title, supplier, purchase_date, amount, filename, original_name, mime_type, file_size, uploaded_by)
                 VALUES
                 (:title, :supplier, :purchase_date, :amount, :filename, :original_name, :mime_type, :file_size, :uploaded_by)'
            );
            $ins->execute([
                ':title'         => $title,
                ':supplier'      => post_text('supplier') ?: null,
                ':purchase_date' => $purchaseDate,
                ':amount'        => post_number('amount'),
                ':filename'      => $storedName,
                ':original_name' => mb_substr((string) $file['name'], 0, 255),
                ':mime_type'     => $mime,
                ':file_size'     => (int) $file['size'],
                ':uploaded_by'   => $user['id'],
            ]);
            respond(['ok' => true, 'message' => 'Comprovante salvo na pasta de compras.']);
        }

        default:
            respond(['ok' => false, 'error' => 'Ação desconhecida.'], 400);
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (($e->errorInfo[1] ?? 0) === 1062) {
        respond(['ok' => false, 'error' => 'Já existe um registro com este código ou e-mail.']);
    }
    respond(['ok' => false, 'error' => 'Erro ao salvar no banco de dados.'], 500);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(['ok' => false, 'error' => $e->getMessage() ?: 'Não foi possível concluir a ação.'], 500);
}
