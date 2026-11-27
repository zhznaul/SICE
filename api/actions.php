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

function convert_to_product_unit(float $qty, string $movUnit, string $prodUnit): float
{
    if ($movUnit === 'g' && $prodUnit === 'kg') {
        return $qty / 1000.0;
    }
    if ($movUnit === 'kg' && $prodUnit === 'g') {
        return $qty * 1000.0;
    }
    return $qty;
}

function compute_product_status(float $qty, float $minStock, ?string $expiryDate = null): string
{
    if ($qty <= 0) {
        return 'critical';
    }
    $expiringVerySoon = false;
    if ($expiryDate !== null && $expiryDate !== '') {
        try {
            $exp = new DateTimeImmutable(substr($expiryDate, 0, 10) . ' 00:00:00', new DateTimeZone('UTC'));
            $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
            $diffDays = (int) $today->diff($exp)->format('%r%a');
            if ($diffDays <= 3) {
                $expiringVerySoon = true;
            }
        } catch (Throwable $e) {
            $expiringVerySoon = false;
        }
    }
    if ($qty <= $minStock && $expiringVerySoon) {
        return 'critical';
    }
    if ($qty <= $minStock) {
        return 'low';
    }
    return 'ok';
}

function compute_product_accent(string $status): string
{
    return match ($status) {
        'critical' => '#a54e44',
        'low'      => '#b67c33',
        default    => '#376759',
    };
}

function respond_with_state(array $user, string $message, array $extra = []): never
{
    $payload = array_merge([
        'ok'      => true,
        'message' => $message,
        'data'    => fetch_app_state($user),
    ], $extra);
    respond($payload);
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
            respond_with_state($user, 'Novo acesso criado para o funcionário.');
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
            respond_with_state($user, 'Acesso do funcionário atualizado.');
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
            $expiryDate = post_date('expiryDate');

            if ($name === '' || $code === '' || $quantity === null || $minimumStock === null || $quantity < 0 || $minimumStock < 0) {
                respond(['ok' => false, 'error' => 'Preencha os campos obrigatórios do insumo (nome, código, quantidade e estoque mínimo).']);
            }

            $dupStmt = $pdo->prepare('SELECT id FROM inventory_products WHERE UPPER(code) = UPPER(:code) LIMIT 1');
            $dupStmt->execute([':code' => $code]);
            if ($dupStmt->fetch()) {
                respond(['ok' => false, 'error' => 'Já existe um insumo cadastrado com o código ' . $code . '.']);
            }

            $qtyRounded = round($quantity, 3);
            $minRounded = round($minimumStock, 3);
            $status = compute_product_status($qtyRounded, $minRounded, $expiryDate);
            $accent = compute_product_accent($status);

            $stmt = $pdo->prepare(
                'INSERT INTO inventory_products
                 (name, category, catalog_type, code, location, quantity, unit, minimum_stock,
                  unit_cost, active, expiry, purchased_at, expiry_date, status, accent)
                 VALUES
                 (:name, :category, :catalog_type, :code, :location, :quantity, :unit, :minimum_stock,
                  :unit_cost, 1, :expiry, :purchased_at, :expiry_date, :status, :accent)'
            );
            $stmt->execute([
                ':name'          => $name,
                ':category'      => $category !== '' ? $category : 'Hortifrúti',
                ':catalog_type'  => valid_catalog_type(post_text('catalogType')),
                ':code'          => $code,
                ':location'      => $location !== '' ? $location : 'Estoque geral',
                ':quantity'      => $qtyRounded,
                ':unit'          => valid_unit(post_text('unit')),
                ':minimum_stock' => $minRounded,
                ':unit_cost'     => round(max(0, $unitCost), 2),
                ':expiry'        => post_text('expiry') ?: null,
                ':purchased_at'  => post_date('purchasedAt'),
                ':expiry_date'   => $expiryDate,
                ':status'        => $status,
                ':accent'        => $accent,
            ]);
            $newId = (int) $pdo->lastInsertId();
            respond_with_state($user, 'Insumo cadastrado com sucesso.', ['productId' => $newId]);
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
            $code = strtoupper(post_text('code', 80));
            $category = post_text('category', 120);
            $location = post_text('location');
            $minimumStock = post_number('minimumStock');
            $unitCost = post_number('unitCost') ?? 0.0;
            $expiryDate = post_date('expiryDate');

            if ($productId <= 0 || $name === '' || $code === '' || $minimumStock === null || $minimumStock < 0) {
                respond(['ok' => false, 'error' => 'Revise nome, código e estoque mínimo.']);
            }

            $curStmt = $pdo->prepare('SELECT quantity FROM inventory_products WHERE id = :id LIMIT 1');
            $curStmt->execute([':id' => $productId]);
            $cur = $curStmt->fetch();
            if (!$cur) {
                respond(['ok' => false, 'error' => 'Insumo não encontrado.']);
            }

            $dupStmt = $pdo->prepare('SELECT id FROM inventory_products WHERE UPPER(code) = UPPER(:code) AND id <> :id LIMIT 1');
            $dupStmt->execute([':code' => $code, ':id' => $productId]);
            if ($dupStmt->fetch()) {
                respond(['ok' => false, 'error' => 'Já existe outro insumo com o código ' . $code . '.']);
            }

            $minRounded = round($minimumStock, 3);
            $status = compute_product_status((float) $cur['quantity'], $minRounded, $expiryDate);
            $accent = compute_product_accent($status);

            $stmt = $pdo->prepare(
                'UPDATE inventory_products
                 SET name = :name, category = :category, catalog_type = :catalog_type,
                     code = :code, location = :location, unit = :unit,
                     minimum_stock = :minimum_stock, unit_cost = :unit_cost,
                     expiry = :expiry, expiry_date = :expiry_date, status = :status, accent = :accent
                 WHERE id = :id'
            );
            $stmt->execute([
                ':name'          => $name,
                ':category'      => $category !== '' ? $category : 'Hortifrúti',
                ':catalog_type'  => valid_catalog_type(post_text('catalogType')),
                ':code'          => $code,
                ':location'      => $location !== '' ? $location : 'Estoque geral',
                ':unit'          => valid_unit(post_text('unit')),
                ':minimum_stock' => $minRounded,
                ':unit_cost'     => round(max(0, $unitCost), 2),
                ':expiry'        => post_text('expiry') ?: null,
                ':expiry_date'   => $expiryDate,
                ':status'        => $status,
                ':accent'        => $accent,
                ':id'            => $productId,
            ]);
            respond_with_state($user, 'Insumo atualizado.');
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
            respond_with_state($user, 'Disponibilidade do insumo atualizada.');
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
            respond_with_state($user, 'Insumo sem histórico removido.');
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
                respond(['ok' => false, 'error' => 'Preencha produto, quantidade válida e motivo.']);
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
                'SELECT id, name, unit, quantity, minimum_stock, unit_cost, purchased_at, expiry_date, active
                 FROM inventory_products
                 WHERE id = :id
                 FOR UPDATE'
            );
            $prodStmt->execute([':id' => $productId]);
            $product = $prodStmt->fetch();
            if (!$product) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Insumo não encontrado.']);
            }
            if ((int) $product['active'] === 0) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Este insumo está inativo. Reative-o antes de movimentar.']);
            }

            $prodUnit = (string) $product['unit'];
            $movUnitRaw = post_text('unit', 32);
            $movUnit = $movUnitRaw !== '' ? valid_unit($movUnitRaw) : $prodUnit;
            $qtyRounded = round($quantity, 3);
            $qtyInProdUnit = convert_to_product_unit($qtyRounded, $movUnit, $prodUnit);

            $currentQty = (float) $product['quantity'];
            $newQty = round($currentQty + signed_qty($type, $qtyInProdUnit), 3);
            if ($newQty < 0) {
                $pdo->rollBack();
                $availFmt = rtrim(rtrim(number_format($currentQty, 3, ',', '.'), '0'), ',');
                respond(['ok' => false, 'error' => 'Saldo insuficiente para ' . $product['name'] . '. Disponível: ' . $availFmt . ' ' . $prodUnit . '.']);
            }

            $entryCost = ($type === 'entry') ? post_number('unitCost') : null;
            $receivedDate = ($type === 'entry') ? post_date('receivedDate') : null;
            $entryExpiryDate = ($type === 'entry') ? post_date('expiryDate') : null;

            $nextUnitCost = ($type === 'entry' && $entryCost !== null && $entryCost >= 0)
                ? round($entryCost, 2)
                : (float) ($product['unit_cost'] ?? 0);
            $nextPurchasedAt = ($type === 'entry' && $receivedDate !== null)
                ? $receivedDate
                : ($product['purchased_at'] ?: null);
            $nextExpiryDate = ($type === 'entry' && $entryExpiryDate !== null)
                ? $entryExpiryDate
                : ($product['expiry_date'] ?: null);

            $newStatus = compute_product_status($newQty, (float) $product['minimum_stock'], $nextExpiryDate);
            $newAccent = compute_product_accent($newStatus);

            $upd = $pdo->prepare(
                'UPDATE inventory_products
                 SET quantity = :qty, unit_cost = :unit_cost, purchased_at = :purchased_at,
                     expiry_date = :expiry_date, status = :status, accent = :accent
                 WHERE id = :id'
            );
            $upd->execute([
                ':qty'          => $newQty,
                ':unit_cost'    => $nextUnitCost,
                ':purchased_at' => $nextPurchasedAt,
                ':expiry_date'  => $nextExpiryDate,
                ':status'       => $newStatus,
                ':accent'       => $newAccent,
                ':id'           => $productId,
            ]);

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
                ':quantity'         => $qtyRounded,
                ':unit'             => $movUnit,
                ':reason'           => $reason,
                ':other_reason'     => $reason === 'Outros' ? $otherReason : null,
                ':supplier'         => $type === 'entry' ? (post_text('supplier') ?: null) : null,
                ':received_date'    => $receivedDate,
                ':unit_cost'        => ($entryCost !== null && $entryCost >= 0) ? round($entryCost, 2) : null,
                ':expiry_date'      => $entryExpiryDate,
                ':operational_area' => ($user['role'] === 'operational' && $type === 'exit') ? $operationalArea : null,
                ':created_by'       => $user['id'],
            ]);
            $movId = (int) $pdo->lastInsertId();
            $pdo->commit();

            respond_with_state(
                $user,
                $type === 'entry' ? 'Entrada registrada e saldo atualizado.' : 'Baixa registrada e saldo atualizado.',
                ['movementId' => $movId]
            );
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
            if (!$orig || (string) $orig['type'] !== 'entry') {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Entrada não encontrada.']);
            }

            $pStmt = $pdo->prepare(
                'SELECT id, name, unit, quantity, minimum_stock, expiry_date
                 FROM inventory_products
                 WHERE id = :id
                 FOR UPDATE'
            );
            $pStmt->execute([':id' => (int) $orig['product_id']]);
            $prod = $pStmt->fetch();
            if (!$prod) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Insumo não encontrado.']);
            }

            $prodUnit = (string) $prod['unit'];
            $newUnitRaw = post_text('unit', 32);
            $newUnit = $newUnitRaw !== '' ? valid_unit($newUnitRaw) : (string) $orig['unit'];
            $qtyRounded = round($quantity, 3);

            $oldStockQty = convert_to_product_unit((float) $orig['quantity'], (string) $orig['unit'], $prodUnit);
            $newStockQty = convert_to_product_unit($qtyRounded, $newUnit, $prodUnit);
            $delta = signed_qty((string) $orig['type'], $newStockQty) - signed_qty((string) $orig['type'], $oldStockQty);
            $newQty = round((float) $prod['quantity'] + $delta, 3);
            if ($newQty < 0) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Saldo insuficiente para ' . $prod['name'] . '.']);
            }

            $newExpiry = post_date('expiryDate');
            $effectiveExpiry = $newExpiry ?: ($prod['expiry_date'] ?: null);
            $newStatus = compute_product_status($newQty, (float) $prod['minimum_stock'], $effectiveExpiry);
            $newAccent = compute_product_accent($newStatus);

            $pdo->prepare('UPDATE inventory_products SET quantity = :qty, status = :status, accent = :accent WHERE id = :id')
                ->execute([':qty' => $newQty, ':status' => $newStatus, ':accent' => $newAccent, ':id' => (int) $prod['id']]);

            $pdo->prepare(
                'UPDATE inventory_movements
                 SET quantity = :qty, unit = :unit, supplier = :supplier,
                     received_date = :received_date, unit_cost = :unit_cost, expiry_date = :expiry_date
                 WHERE id = :id'
            )->execute([
                ':qty'           => $qtyRounded,
                ':unit'          => $newUnit,
                ':supplier'      => post_text('supplier') ?: null,
                ':received_date' => post_date('receivedDate'),
                ':unit_cost'     => ($unitCost !== null && $unitCost >= 0) ? round($unitCost, 2) : null,
                ':expiry_date'   => $newExpiry,
                ':id'            => $movId,
            ]);
            $pdo->commit();

            respond_with_state($user, 'Entrada atualizada e saldo recalculado.');
        }

        // -------------------------------------------------------------------
        // 8b. Atualizar baixa (saída) e recalcular saldo
        // -------------------------------------------------------------------
        case 'updateExit': {
            $movId = (int) post_text('id', 20);
            $quantity = post_number('quantity');
            $reason = post_text('reason');
            $otherReason = post_text('otherReason', 500);
            $operationalArea = post_text('operationalArea', 32);

            if ($movId <= 0 || $quantity === null || $quantity <= 0 || $reason === '') {
                respond(['ok' => false, 'error' => 'Informe quantidade válida e motivo da baixa.']);
            }
            if ($reason === 'Outros' && $otherReason === '') {
                respond(['ok' => false, 'error' => 'Descreva o motivo da baixa.']);
            }
            if ($user['role'] === 'operational' && !in_array($operationalArea, ['kitchen', 'bar'], true)) {
                respond(['ok' => false, 'error' => 'Selecione cozinha ou bar.']);
            }
            if ($operationalArea !== '' && !in_array($operationalArea, ['kitchen', 'bar', 'storekeeper'], true)) {
                $operationalArea = '';
            }

            $pdo->beginTransaction();
            $mStmt = $pdo->prepare('SELECT * FROM inventory_movements WHERE id = :id FOR UPDATE');
            $mStmt->execute([':id' => $movId]);
            $orig = $mStmt->fetch();
            if (!$orig || (string) $orig['type'] !== 'exit') {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Registro de baixa não encontrado.']);
            }
            if (!is_manager($user) && (int) $orig['created_by'] !== (int) $user['id']) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Você só pode editar baixas registradas por você.'], 403);
            }

            $pStmt = $pdo->prepare(
                'SELECT id, name, unit, quantity, minimum_stock, expiry_date
                 FROM inventory_products
                 WHERE id = :id
                 FOR UPDATE'
            );
            $pStmt->execute([':id' => (int) $orig['product_id']]);
            $prod = $pStmt->fetch();
            if (!$prod) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Insumo não encontrado.']);
            }

            $prodUnit = (string) $prod['unit'];
            $newUnitRaw = post_text('unit', 32);
            $newUnit = $newUnitRaw !== '' ? valid_unit($newUnitRaw) : (string) $orig['unit'];
            $qtyRounded = round($quantity, 3);

            $oldStockQty = convert_to_product_unit((float) $orig['quantity'], (string) $orig['unit'], $prodUnit);
            $newStockQty = convert_to_product_unit($qtyRounded, $newUnit, $prodUnit);
            $delta = signed_qty('exit', $newStockQty) - signed_qty('exit', $oldStockQty);
            $newQty = round((float) $prod['quantity'] + $delta, 3);

            if ($newQty < 0) {
                $pdo->rollBack();
                $maxAvail = round((float) $prod['quantity'] + $oldStockQty, 3);
                $availFmt = rtrim(rtrim(number_format($maxAvail, 3, ',', '.'), '0'), ',');
                respond(['ok' => false, 'error' => 'Saldo insuficiente para ' . $prod['name'] . '. Máximo disponível para esta baixa: ' . $availFmt . ' ' . $prodUnit . '.']);
            }

            $newStatus = compute_product_status($newQty, (float) $prod['minimum_stock'], $prod['expiry_date'] ?: null);
            $newAccent = compute_product_accent($newStatus);

            $pdo->prepare('UPDATE inventory_products SET quantity = :qty, status = :status, accent = :accent WHERE id = :id')
                ->execute([
                    ':qty'    => $newQty,
                    ':status' => $newStatus,
                    ':accent' => $newAccent,
                    ':id'     => (int) $prod['id'],
                ]);

            $pdo->prepare(
                'UPDATE inventory_movements
                 SET quantity = :qty, unit = :unit, reason = :reason,
                     other_reason = :other_reason, operational_area = :operational_area
                 WHERE id = :id'
            )->execute([
                ':qty'              => $qtyRounded,
                ':unit'             => $newUnit,
                ':reason'           => $reason,
                ':other_reason'     => $reason === 'Outros' ? $otherReason : null,
                ':operational_area' => $operationalArea !== '' ? $operationalArea : null,
                ':id'               => $movId,
            ]);
            $pdo->commit();

            respond_with_state($user, 'Baixa atualizada e saldo recalculado.');
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

            $pStmt = $pdo->prepare(
                'SELECT id, unit, quantity, minimum_stock, expiry_date
                 FROM inventory_products
                 WHERE id = :id
                 FOR UPDATE'
            );
            $pStmt->execute([':id' => (int) $orig['product_id']]);
            $prod = $pStmt->fetch();
            if ($prod) {
                $origStockQty = convert_to_product_unit((float) $orig['quantity'], (string) $orig['unit'], (string) $prod['unit']);
                $newQty = round((float) $prod['quantity'] - signed_qty((string) $orig['type'], $origStockQty), 3);
                if ($newQty < 0) {
                    $pdo->rollBack();
                    respond(['ok' => false, 'error' => 'A exclusão deixaria o estoque com saldo negativo.']);
                }
                $newStatus = compute_product_status($newQty, (float) $prod['minimum_stock'], $prod['expiry_date'] ?: null);
                $newAccent = compute_product_accent($newStatus);
                $pdo->prepare('UPDATE inventory_products SET quantity = :qty, status = :status, accent = :accent WHERE id = :id')
                    ->execute([':qty' => $newQty, ':status' => $newStatus, ':accent' => $newAccent, ':id' => (int) $prod['id']]);
            }

            $pdo->prepare('DELETE FROM inventory_movements WHERE id = :id')->execute([':id' => $movId]);
            $pdo->commit();

            respond_with_state($user, 'Registro removido e saldo recalculado.');
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
                "INSERT INTO purchase_orders
                 (title, product_id, supplier, quantity, unit, unit_cost, expected_date, status, notes, created_by)
                 VALUES
                 (:title, :product_id, :supplier, :quantity, :unit, :unit_cost, :expected_date, 'planned', :notes, :created_by)"
            );
            $stmt->execute([
                ':title'         => $title,
                ':product_id'    => $productId ?: null,
                ':supplier'      => post_text('supplier') ?: null,
                ':quantity'      => round($quantity, 3),
                ':unit'          => valid_unit(post_text('unit')),
                ':unit_cost'     => post_number('unitCost'),
                ':expected_date' => $expectedDate,
                ':notes'         => post_text('notes', 500) ?: null,
                ':created_by'    => $user['id'],
            ]);
            respond_with_state($user, 'Compra futura planejada.');
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
                ':quantity'      => round($quantity, 3),
                ':unit'          => valid_unit(post_text('unit')),
                ':unit_cost'     => post_number('unitCost'),
                ':expected_date' => $expectedDate,
                ':status'        => $status,
                ':notes'         => post_text('notes', 500) ?: null,
                ':id'            => $ordId,
            ]);
            respond_with_state($user, 'Pedido de compra atualizado.');
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
            respond_with_state($user, 'Planejamento de compra removido.');
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
                ':quantity'         => round($quantity, 3),
                ':unit'             => $unit,
                ':handled_at'       => $handledAt,
                ':expiry_date'      => $expiryDate,
                ':lot'              => $lot,
                ':responsible_name' => $responsibleName,
                ':created_by'       => $user['id'],
            ]);
            respond_with_state($user, 'Etiqueta criada e guardada.');
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
            respond_with_state($user, 'Comprovante salvo na pasta de compras.');
        }

        // -------------------------------------------------------------------
        // 15. Enviar mensagem para Ajuda e Suporte
        // -------------------------------------------------------------------
        case 'createSupportTicket': {
            $subject = post_text('subject', 180);
            $description = post_text('description', 4000);

            if ($subject === '' || $description === '') {
                respond(['ok' => false, 'error' => 'Preencha o assunto e a descrição do problema.']);
            }

            $savedAttachments = [];
            if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'] ?? null)) {
                $names = $_FILES['attachments']['name'];
                $tmpNames = $_FILES['attachments']['tmp_name'];
                $errors = $_FILES['attachments']['error'];
                $sizes = $_FILES['attachments']['size'];
                $count = count($names);

                $allowedMimeToExt = [
                    'application/pdf'                                                         => 'pdf',
                    'image/jpeg'                                                              => 'jpg',
                    'image/png'                                                               => 'png',
                    'image/webp'                                                              => 'webp',
                    'text/plain'                                                              => 'txt',
                    'text/csv'                                                                => 'csv',
                    'application/vnd.ms-excel'                                                => 'xls',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'xlsx',
                    'application/msword'                                                      => 'doc',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                ];
                $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'txt', 'csv', 'xls', 'xlsx', 'doc', 'docx'];

                $dir = __DIR__ . '/../uploads/support';
                $finfo = new finfo(FILEINFO_MIME_TYPE);

                $validFilesCount = 0;
                for ($i = 0; $i < $count; $i++) {
                    $err = (int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE);
                    if ($err === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    if ($err !== UPLOAD_ERR_OK) {
                        respond(['ok' => false, 'error' => 'Falha ao enviar um dos arquivos anexados.']);
                    }
                    $validFilesCount++;
                    if ($validFilesCount > 5) {
                        respond(['ok' => false, 'error' => 'Anexe no máximo 5 arquivos por chamado.']);
                    }
                    $size = (int) ($sizes[$i] ?? 0);
                    if ($size <= 0 || $size > 10 * 1024 * 1024) {
                        respond(['ok' => false, 'error' => 'Cada arquivo anexado deve ter até 10 MB.']);
                    }

                    $tmpPath = (string) ($tmpNames[$i] ?? '');
                    $origName = mb_substr((string) ($names[$i] ?? 'anexo'), 0, 255);
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $mime = (string) $finfo->file($tmpPath);

                    $safeExt = $allowedMimeToExt[$mime] ?? (in_array($ext, $allowedExts, true) ? $ext : null);
                    if ($safeExt === null) {
                        respond(['ok' => false, 'error' => 'Formato de arquivo não permitido: ' . $origName . '. Envie PDF, imagens, TXT, CSV, DOC ou XLS.']);
                    }

                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    $storedName = bin2hex(random_bytes(16)) . '.' . $safeExt;
                    if (!move_uploaded_file($tmpPath, $dir . '/' . $storedName)) {
                        respond(['ok' => false, 'error' => 'Não foi possível salvar o anexo enviado.'], 500);
                    }

                    $savedAttachments[] = [
                        'storedName'   => $storedName,
                        'originalName' => $origName,
                        'mimeType'     => $mime,
                        'fileSize'     => $size,
                    ];
                }
            }

            ensure_support_tickets_table($pdo);

            $ins = $pdo->prepare(
                'INSERT INTO support_tickets (user_id, subject, description, attachments_json, status)
                 VALUES (:user_id, :subject, :description, :attachments_json, \'open\')'
            );
            $ins->execute([
                ':user_id'          => $user['id'],
                ':subject'          => $subject,
                ':description'      => $description,
                ':attachments_json' => !empty($savedAttachments)
                    ? json_encode($savedAttachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
            ]);
            $ticketId = (int) $pdo->lastInsertId();

            respond_with_state(
                $user,
                'Sua mensagem foi enviada ao suporte do SICE e será analisada e respondida em até 48 horas.',
                ['ticketId' => $ticketId]
            );
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
