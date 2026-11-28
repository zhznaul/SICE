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
    $norm = strtolower(trim($unit));
    if ($norm === 'litros') {
        $norm = 'l';
    }
    $allowed = ['kg', 'g', 'un', 'l', 'ml', 'caixa', 'fardo'];
    return in_array($norm, $allowed, true) ? $norm : 'un';
}

function signed_qty(string $type, float $qty): float
{
    return ($type === 'entry' ? 1.0 : -1.0) * $qty;
}

function compatible_units(string $prodUnit): array
{
    $p = valid_unit($prodUnit);
    if ($p === 'kg' || $p === 'g') {
        return ['kg', 'g'];
    }
    if ($p === 'l' || $p === 'ml') {
        return ['l', 'ml'];
    }
    return [$p];
}

function are_units_compatible(string $movUnit, string $prodUnit): bool
{
    $m = valid_unit($movUnit);
    return in_array($m, compatible_units($prodUnit), true);
}

function convert_to_product_unit(float $qty, string $movUnit, string $prodUnit): float
{
    $m = $movUnit === 'litros' ? 'l' : $movUnit;
    $p = $prodUnit === 'litros' ? 'l' : $prodUnit;
    if ($m === 'g' && $p === 'kg') {
        return $qty / 1000.0;
    }
    if ($m === 'kg' && $p === 'g') {
        return $qty * 1000.0;
    }
    if ($m === 'ml' && $p === 'l') {
        return $qty / 1000.0;
    }
    if ($m === 'l' && $p === 'ml') {
        return $qty * 1000.0;
    }
    return $qty;
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
ensure_inventory_schema($pdo);

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
            // Revoga imediatamente sessões persistentes caso o usuário seja inativado ou tenha a senha redefinida
            if ($active === 0 || $password !== '') {
                try {
                    $pdo->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = :uid AND revoked_at IS NULL')
                        ->execute([':uid' => $empId]);
                } catch (Throwable $e) {
                    // Tabela pode não existir ainda
                }
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
            $requestedCode = strtoupper(post_text('code', 80));
            $codeAutoGenerated = post_text('codeAutoGenerated', 10) === '1';
            $category = post_text('category', 120);
            $location = post_text('location');
            $quantity = post_number('quantity');
            $minimumStock = post_number('minimumStock');
            $unitCost = post_number('unitCost') ?? 0.0;

            if ($name === '' || $quantity === null || $minimumStock === null || $quantity < 0 || $minimumStock < 0) {
                respond(['ok' => false, 'error' => 'Preencha os campos obrigatórios do insumo (nome, quantidade e estoque mínimo).']);
            }
            if (!is_valid_stock_location($location)) {
                respond(['ok' => false, 'error' => 'Selecione um estoque válido (Estoque Seco, Estoque de Bebidas, Adega Bar, Câmara Fria ou Freezer 1 a 5).']);
            }

            $ctrlResult = normalize_product_control_fields(
                $category !== '' ? $category : 'Hortifrutti',
                post_text('catalogType', 32),
                [
                    'purchasedAt'               => post_text('purchasedAt', 20),
                    'manufacturedAt'            => post_text('manufacturedAt', 20),
                    'handledAt'                 => post_text('handledAt', 20),
                    'expiryDate'                => post_text('expiryDate', 20),
                    'expiry'                    => post_text('expiry', 160),
                    'packageStatus'             => post_text('packageStatus', 20),
                    'shelfLifeAfterOpeningDays' => post_text('shelfLifeAfterOpeningDays', 20),
                ],
                true
            );
            if (!$ctrlResult['ok']) {
                respond(['ok' => false, 'error' => $ctrlResult['error']]);
            }
            $ctrl = $ctrlResult['data'];

            if ($requestedCode !== '') {
                $dupStmt = $pdo->prepare('SELECT id FROM inventory_products WHERE UPPER(code) = UPPER(:code) LIMIT 1');
                $dupStmt->execute([':code' => $requestedCode]);
                if ($dupStmt->fetch()) {
                    respond(['ok' => false, 'error' => 'Já existe um insumo cadastrado com o código ' . $requestedCode . '.'], 409);
                }
                $code = $requestedCode;
            } else {
                $code = generate_unique_product_code($pdo);
                $codeAutoGenerated = true;
            }

            $qtyRounded = round($quantity, 3);
            $minRounded = round($minimumStock, 3);
            $status = compute_inventory_product_status($qtyRounded, $minRounded, $ctrl);
            $accent = compute_product_accent($status);

            $insertSql =
                'INSERT INTO inventory_products
                 (name, category, catalog_type, code, location, quantity, unit, minimum_stock,
                  unit_cost, active, expiry, purchased_at, manufactured_at, handled_at,
                  expiry_date, expiry_indeterminate, package_status,
                  shelf_life_after_opening_days, opened_expiry_date, status, accent)
                 VALUES
                 (:name, :category, :catalog_type, :code, :location, :quantity, :unit, :minimum_stock,
                  :unit_cost, 1, :expiry, :purchased_at, :manufactured_at, :handled_at,
                  :expiry_date, :expiry_indeterminate, :package_status,
                  :shelf_life_after_opening_days, :opened_expiry_date, :status, :accent)';

            $maxInsertAttempts = $codeAutoGenerated ? 5 : 1;
            $newId = 0;

            for ($attempt = 1; $attempt <= $maxInsertAttempts; $attempt++) {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare($insertSql);
                    $stmt->execute([
                        ':name'                          => $name,
                        ':category'                      => $ctrl['category'],
                        ':catalog_type'                  => $ctrl['catalog_type'],
                        ':code'                          => $code,
                        ':location'                      => $location,
                        ':quantity'                      => $qtyRounded,
                        ':unit'                          => valid_unit(post_text('unit')),
                        ':minimum_stock'                 => $minRounded,
                        ':unit_cost'                     => round(max(0, $unitCost), 2),
                        ':expiry'                        => $ctrl['expiry'],
                        ':purchased_at'                  => $ctrl['purchased_at'],
                        ':manufactured_at'               => $ctrl['manufactured_at'],
                        ':handled_at'                    => $ctrl['handled_at'],
                        ':expiry_date'                   => $ctrl['expiry_date'],
                        ':expiry_indeterminate'          => $ctrl['expiry_indeterminate'],
                        ':package_status'                => $ctrl['package_status'],
                        ':shelf_life_after_opening_days' => $ctrl['shelf_life_after_opening_days'],
                        ':opened_expiry_date'            => $ctrl['opened_expiry_date'],
                        ':status'                        => $status,
                        ':accent'                        => $accent,
                    ]);
                    $newId = (int) $pdo->lastInsertId();

                    if ($ctrl['catalog_type'] === 'syrup' && $ctrl['package_status'] === 'opened') {
                        record_product_state_event($pdo, [
                            'product_id'                    => $newId,
                            'event_type'                    => 'opened',
                            'previous_state'                => null,
                            'new_state'                     => 'opened',
                            'manufactured_at'               => $ctrl['manufactured_at'],
                            'original_expiry_date'          => $ctrl['expiry_date'],
                            'handled_at'                    => $ctrl['handled_at'],
                            'shelf_life_after_opening_days' => $ctrl['shelf_life_after_opening_days'],
                            'opened_expiry_date'            => $ctrl['opened_expiry_date'],
                            'notes'                         => 'Cadastro inicial de xarope já aberto.',
                            'created_by'                    => $user['id'],
                        ]);
                    }

                    $pdo->commit();
                    break;
                } catch (PDOException $insertEx) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $isDuplicate = (($insertEx->errorInfo[1] ?? 0) === 1062);
                    if ($isDuplicate && $codeAutoGenerated && $attempt < $maxInsertAttempts) {
                        $code = generate_unique_product_code($pdo);
                        continue;
                    }
                    if ($isDuplicate) {
                        respond(['ok' => false, 'error' => 'Já existe um insumo cadastrado com o código ' . $code . '.'], 409);
                    }
                    throw $insertEx;
                }
            }

            respond_with_state($user, 'Insumo cadastrado com sucesso.', ['productId' => $newId, 'productCode' => $code]);
        }

        // -------------------------------------------------------------------
        // 3b. Gerar código aleatório exclusivo no servidor (owner / storekeeper)
        // -------------------------------------------------------------------
        case 'generateProductCode': {
            if (!is_manager($user)) {
                respond(['ok' => false, 'error' => 'Acesso restrito à gestão de estoque.'], 403);
            }
            respond(['ok' => true, 'code' => generate_unique_product_code($pdo)]);
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
            $category = post_text('category', 120);
            $location = post_text('location');
            $minimumStock = post_number('minimumStock');
            $unitCost = post_number('unitCost') ?? 0.0;

            if ($productId <= 0 || $name === '' || $minimumStock === null || $minimumStock < 0) {
                respond(['ok' => false, 'error' => 'Revise nome e estoque mínimo.']);
            }

            $curStmt = $pdo->prepare(
                'SELECT id, code, location, unit, quantity, catalog_type, purchased_at, manufactured_at, handled_at,
                        expiry_date, package_status, shelf_life_after_opening_days, opened_expiry_date
                 FROM inventory_products
                 WHERE id = :id
                 LIMIT 1'
            );
            $curStmt->execute([':id' => $productId]);
            $cur = $curStmt->fetch();
            if (!$cur) {
                respond(['ok' => false, 'error' => 'Insumo não encontrado.']);
            }

            // Código do produto é imutável após criação
            $code = (string) $cur['code'];

            $requestedUnit = valid_unit(post_text('unit'));
            $currentUnit = valid_unit((string) $cur['unit']);
            if ($requestedUnit !== $currentUnit) {
                $movCountStmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_movements WHERE product_id = :id');
                $movCountStmt->execute([':id' => $productId]);
                if ((int) $movCountStmt->fetchColumn() > 0) {
                    respond(['ok' => false, 'error' => 'Não é possível alterar a unidade de medida de um insumo que já possui movimentações registradas.']);
                }
            }

            if (!is_valid_stock_location($location) && $location !== (string) $cur['location']) {
                respond(['ok' => false, 'error' => 'Selecione um estoque válido (Estoque Seco, Estoque de Bebidas, Adega Bar, Câmara Fria ou Freezer 1 a 5).']);
            }

            $ctrlResult = normalize_product_control_fields(
                $category !== '' ? $category : 'Hortifrutti',
                post_text('catalogType', 32),
                [
                    'purchasedAt'               => post_text('purchasedAt', 20) !== '' ? post_text('purchasedAt', 20) : ($cur['purchased_at'] ?? ''),
                    'manufacturedAt'            => post_text('manufacturedAt', 20),
                    'handledAt'                 => post_text('handledAt', 20),
                    'expiryDate'                => post_text('expiryDate', 20),
                    'expiry'                    => post_text('expiry', 160),
                    'packageStatus'             => post_text('packageStatus', 20),
                    'shelfLifeAfterOpeningDays' => post_text('shelfLifeAfterOpeningDays', 20),
                ],
                false
            );
            if (!$ctrlResult['ok']) {
                respond(['ok' => false, 'error' => $ctrlResult['error']]);
            }
            $ctrl = $ctrlResult['data'];

            $minRounded = round($minimumStock, 3);
            $status = compute_inventory_product_status((float) $cur['quantity'], $minRounded, $ctrl);
            $accent = compute_product_accent($status);

            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'UPDATE inventory_products
                 SET name = :name, category = :category, catalog_type = :catalog_type,
                     location = :location, unit = :unit,
                     minimum_stock = :minimum_stock, unit_cost = :unit_cost,
                     expiry = :expiry, purchased_at = :purchased_at,
                     manufactured_at = :manufactured_at, handled_at = :handled_at,
                     expiry_date = :expiry_date, expiry_indeterminate = :expiry_indeterminate,
                     package_status = :package_status,
                     shelf_life_after_opening_days = :shelf_life_after_opening_days,
                     opened_expiry_date = :opened_expiry_date,
                     status = :status, accent = :accent
                 WHERE id = :id'
            );
            $stmt->execute([
                ':name'                          => $name,
                ':category'                      => $ctrl['category'],
                ':catalog_type'                  => $ctrl['catalog_type'],
                ':location'                      => $location,
                ':unit'                          => $requestedUnit,
                ':minimum_stock'                 => $minRounded,
                ':unit_cost'                     => round(max(0, $unitCost), 2),
                ':expiry'                        => $ctrl['expiry'],
                ':purchased_at'                  => $ctrl['purchased_at'],
                ':manufactured_at'               => $ctrl['manufactured_at'],
                ':handled_at'                    => $ctrl['handled_at'],
                ':expiry_date'                   => $ctrl['expiry_date'],
                ':expiry_indeterminate'          => $ctrl['expiry_indeterminate'],
                ':package_status'                => $ctrl['package_status'],
                ':shelf_life_after_opening_days' => $ctrl['shelf_life_after_opening_days'],
                ':opened_expiry_date'            => $ctrl['opened_expiry_date'],
                ':status'                        => $status,
                ':accent'                        => $accent,
                ':id'                            => $productId,
            ]);

            if ($ctrl['catalog_type'] === 'syrup') {
                $prevStatus = $cur['package_status'] ?: null;
                $newStatusVal = $ctrl['package_status'];
                $prevDays = $cur['shelf_life_after_opening_days'] !== null ? (int) $cur['shelf_life_after_opening_days'] : null;
                $newDays = $ctrl['shelf_life_after_opening_days'];
                $prevHandled = $cur['handled_at'] ?: null;
                if ($prevStatus !== $newStatusVal || $prevDays !== $newDays || $prevHandled !== $ctrl['handled_at']) {
                    $eventType = $newStatusVal !== $prevStatus
                        ? ($newStatusVal === 'opened' ? 'opened' : 'closed')
                        : 'shelf_life_updated';
                    record_product_state_event($pdo, [
                        'product_id'                    => $productId,
                        'event_type'                    => $eventType,
                        'previous_state'                => $prevStatus,
                        'new_state'                     => $newStatusVal,
                        'manufactured_at'               => $ctrl['manufactured_at'],
                        'original_expiry_date'          => $ctrl['expiry_date'],
                        'handled_at'                    => $ctrl['handled_at'],
                        'shelf_life_after_opening_days' => $ctrl['shelf_life_after_opening_days'],
                        'opened_expiry_date'            => $ctrl['opened_expiry_date'],
                        'notes'                         => 'Atualização pelo cadastro do insumo.',
                        'created_by'                    => $user['id'],
                    ]);
                }
            }

            $pdo->commit();
            respond_with_state($user, 'Insumo atualizado.');
        }

        // -------------------------------------------------------------------
        // 4b. Controle rastreável de abertura e estado de xaropes
        // -------------------------------------------------------------------
        case 'updateSyrupState': {
            $productId = (int) post_text('id', 20);
            $mode = post_text('mode', 32) ?: 'open';
            $notes = post_text('notes', 500);

            if ($productId <= 0 || !in_array($mode, ['open', 'close', 'configure_shelf_life'], true)) {
                respond(['ok' => false, 'error' => 'Operação inválida para controle de xarope.']);
            }

            $pdo->beginTransaction();
            $pStmt = $pdo->prepare(
                'SELECT id, name, category, catalog_type, quantity, minimum_stock, active,
                        manufactured_at, handled_at, expiry_date, expiry_indeterminate,
                        package_status, shelf_life_after_opening_days, opened_expiry_date
                 FROM inventory_products
                 WHERE id = :id
                 FOR UPDATE'
            );
            $pStmt->execute([':id' => $productId]);
            $prod = $pStmt->fetch();
            if (!$prod) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Insumo não encontrado.']);
            }
            if ((int) $prod['active'] === 0) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Este insumo está inativo.']);
            }
            $effectiveClass = resolve_product_classification((string) $prod['category'], $prod['catalog_type'] !== null ? (string) $prod['catalog_type'] : null);
            if ($effectiveClass !== 'syrup') {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'O controle de abertura aplica-se exclusivamente a insumos da classificação Xaropes.']);
            }

            $prevState = $prod['package_status'] ?: 'closed';
            $manufacturedAt = normalize_iso_date($prod['manufactured_at'] ?? null);
            $originalExpiry = normalize_iso_date($prod['expiry_date'] ?? null);
            $currentShelfDays = $prod['shelf_life_after_opening_days'] !== null ? (int) $prod['shelf_life_after_opening_days'] : null;

            $postedShelfRaw = post_text('shelfLifeAfterOpeningDays', 20);
            $nextShelfDays = $currentShelfDays;
            if ($postedShelfRaw !== '') {
                if (!ctype_digit($postedShelfRaw) || (int) $postedShelfRaw <= 0) {
                    $pdo->rollBack();
                    respond(['ok' => false, 'error' => 'O prazo após abertura deve ser um número inteiro de dias maior que zero.']);
                }
                $nextShelfDays = (int) $postedShelfRaw;
            }

            if ($mode === 'open') {
                $handledAt = post_date('handledAt');
                if ($handledAt === null) {
                    $pdo->rollBack();
                    respond(['ok' => false, 'error' => 'Informe a data de manipulação (data de abertura) do xarope.']);
                }
                if ($manufacturedAt !== null && $handledAt < $manufacturedAt) {
                    $pdo->rollBack();
                    respond(['ok' => false, 'error' => 'A data de manipulação não pode ser anterior à data de fabricação.']);
                }
                $nextState = 'opened';
                $nextHandledAt = $handledAt;
                $nextOpenedExpiry = calculate_opened_expiry_date($nextHandledAt, $nextShelfDays);
                $eventType = 'opened';
                $msg = $nextOpenedExpiry !== null
                    ? 'Abertura do xarope registrada e limite de utilização calculado.'
                    : 'Abertura do xarope registrada. Defina o prazo em dias após abertura para calcular o limite de utilização.';
            } elseif ($mode === 'configure_shelf_life') {
                if ($nextShelfDays === null || $nextShelfDays <= 0) {
                    $pdo->rollBack();
                    respond(['ok' => false, 'error' => 'Informe a quantidade de dias do prazo após abertura.']);
                }
                $nextState = $prevState;
                $nextHandledAt = normalize_iso_date($prod['handled_at'] ?? null);
                $nextOpenedExpiry = ($nextState === 'opened')
                    ? calculate_opened_expiry_date($nextHandledAt, $nextShelfDays)
                    : null;
                $eventType = 'shelf_life_updated';
                $msg = 'Prazo de utilização após abertura atualizado.';
            } else {
                // Novo frasco fechado (preservando todo o histórico anterior na tabela de histórico)
                $postedMfg = post_date('manufacturedAt');
                $postedExp = post_date('expiryDate');
                if ($postedMfg !== null) {
                    $manufacturedAt = $postedMfg;
                }
                if ($postedExp !== null) {
                    $originalExpiry = $postedExp;
                }
                if ($manufacturedAt !== null && $originalExpiry !== null && $originalExpiry < $manufacturedAt) {
                    $pdo->rollBack();
                    respond(['ok' => false, 'error' => 'A data de validade não pode ser anterior à data de fabricação.']);
                }
                $nextState = 'closed';
                $nextHandledAt = null;
                $nextOpenedExpiry = null;
                $eventType = 'closed';
                $msg = 'Estado atualizado para fechado preservando o histórico anterior.';
            }

            $nextCtx = [
                'catalog_type'                  => 'syrup',
                'expiry_indeterminate'          => 0,
                'package_status'                => $nextState,
                'expiry_date'                   => $originalExpiry,
                'handled_at'                    => $nextHandledAt,
                'shelf_life_after_opening_days' => $nextShelfDays,
                'opened_expiry_date'            => $nextOpenedExpiry,
            ];
            $newStatus = compute_inventory_product_status((float) $prod['quantity'], (float) $prod['minimum_stock'], $nextCtx);
            $newAccent = compute_product_accent($newStatus);

            $upd = $pdo->prepare(
                'UPDATE inventory_products
                 SET manufactured_at = :manufactured_at,
                     expiry_date = :expiry_date,
                     handled_at = :handled_at,
                     package_status = :package_status,
                     shelf_life_after_opening_days = :shelf_life_days,
                     opened_expiry_date = :opened_expiry_date,
                     status = :status,
                     accent = :accent
                 WHERE id = :id'
            );
            $upd->execute([
                ':manufactured_at'    => $manufacturedAt,
                ':expiry_date'        => $originalExpiry,
                ':handled_at'         => $nextHandledAt,
                ':package_status'     => $nextState,
                ':shelf_life_days'    => $nextShelfDays,
                ':opened_expiry_date' => $nextOpenedExpiry,
                ':status'             => $newStatus,
                ':accent'             => $newAccent,
                ':id'                 => $productId,
            ]);

            record_product_state_event($pdo, [
                'product_id'                    => $productId,
                'event_type'                    => $eventType,
                'previous_state'                => $prevState,
                'new_state'                     => $nextState,
                'manufactured_at'               => $manufacturedAt,
                'original_expiry_date'          => $originalExpiry,
                'handled_at'                    => $nextHandledAt,
                'shelf_life_after_opening_days' => $nextShelfDays,
                'opened_expiry_date'            => $nextOpenedExpiry,
                'notes'                         => $notes !== '' ? $notes : null,
                'created_by'                    => $user['id'],
            ]);

            $pdo->commit();
            respond_with_state($user, $msg);
        }

        // -------------------------------------------------------------------
        // 5. Ativar / Inativar insumo
        // -------------------------------------------------------------------
        case 'setProductActive': {
            if (!is_manager($user)) {
                respond(['ok' => false, 'error' => 'Acesso restrito à gestão de estoque.'], 403);
            }
            $productId = (int) post_text('id', 20);
            if ($productId <= 0) {
                respond(['ok' => false, 'error' => 'Insumo inválido.']);
            }
            $active = post_text('active') === 'true' ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE inventory_products SET active = :active WHERE id = :id');
            $stmt->execute([':active' => $active, ':id' => $productId]);
            if ($stmt->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT 1 FROM inventory_products WHERE id = :id LIMIT 1');
                $exists->execute([':id' => $productId]);
                if (!$exists->fetchColumn()) {
                    respond(['ok' => false, 'error' => 'Insumo não encontrado.']);
                }
            }
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
            if ($productId <= 0) {
                respond(['ok' => false, 'error' => 'Insumo inválido.']);
            }
            $checkMov = $pdo->prepare('SELECT COUNT(*) FROM inventory_movements WHERE product_id = :id');
            $checkMov->execute([':id' => $productId]);
            $hasHistory = (int) $checkMov->fetchColumn() > 0;

            if (!$hasHistory) {
                $checkLbl = $pdo->prepare('SELECT COUNT(*) FROM inventory_labels WHERE product_id = :id');
                $checkLbl->execute([':id' => $productId]);
                $hasHistory = (int) $checkLbl->fetchColumn() > 0;
            }
            if (!$hasHistory) {
                try {
                    $checkState = $pdo->prepare('SELECT COUNT(*) FROM inventory_product_state_history WHERE product_id = :id');
                    $checkState->execute([':id' => $productId]);
                    $hasHistory = (int) $checkState->fetchColumn() > 0;
                } catch (Throwable $e) {
                    // Ignora se tabela ainda não existir
                }
            }
            if ($hasHistory) {
                respond(['ok' => false, 'error' => 'Este insumo possui histórico (movimentações, etiquetas ou abertura). Inative-o para preservar a rastreabilidade.']);
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
                'SELECT id, name, category, catalog_type, unit, quantity, minimum_stock, unit_cost,
                        purchased_at, manufactured_at, handled_at, expiry_date, expiry_indeterminate,
                        package_status, shelf_life_after_opening_days, opened_expiry_date, active
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

            $prodUnit = valid_unit((string) $product['unit']);
            $movUnitRaw = post_text('unit', 32);
            $movUnit = $movUnitRaw !== '' ? valid_unit($movUnitRaw) : $prodUnit;
            if (!are_units_compatible($movUnit, $prodUnit)) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Unidade incompatível com ' . $product['name'] . ' (cadastrado em ' . $prodUnit . '). Use: ' . implode(' ou ', compatible_units($prodUnit)) . '.']);
            }
            $qtyRounded = round($quantity, 3);
            $qtyInProdUnit = convert_to_product_unit($qtyRounded, $movUnit, $prodUnit);

            $currentQty = (float) $product['quantity'];
            $newQty = round($currentQty + signed_qty($type, $qtyInProdUnit), 3);
            if ($newQty < 0) {
                $pdo->rollBack();
                $availFmt = rtrim(rtrim(number_format($currentQty, 3, ',', '.'), '0'), ',');
                respond(['ok' => false, 'error' => 'Saldo insuficiente para ' . $product['name'] . '. Disponível: ' . $availFmt . ' ' . $prodUnit . '.']);
            }

            $prodClass = resolve_product_classification((string) $product['category'], $product['catalog_type'] !== null ? (string) $product['catalog_type'] : null);
            $rules = classification_rules($prodClass);

            $entryCost = ($type === 'entry') ? post_number('unitCost') : null;
            $receivedDate = ($type === 'entry') ? post_date('receivedDate') : null;
            $entryManufacturedAt = ($type === 'entry' && $rules['usesManufacturedAt']) ? post_date('manufacturedAt') : null;
            $entryExpiryDate = ($type === 'entry' && $rules['hasExpiry']) ? post_date('expiryDate') : null;

            if ($entryManufacturedAt !== null && $entryExpiryDate !== null && $entryExpiryDate < $entryManufacturedAt) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'A data de validade não pode ser anterior à data de fabricação.']);
            }

            $nextUnitCost = ($type === 'entry' && $entryCost !== null && $entryCost >= 0)
                ? round($entryCost, 2)
                : (float) ($product['unit_cost'] ?? 0);
            $nextPurchasedAt = ($type === 'entry' && $receivedDate !== null)
                ? $receivedDate
                : ($product['purchased_at'] ?: null);
            $nextManufacturedAt = ($type === 'entry' && $entryManufacturedAt !== null)
                ? $entryManufacturedAt
                : ($product['manufactured_at'] ?: null);
            $nextExpiryDate = (!$rules['hasExpiry'])
                ? null
                : (($type === 'entry' && $entryExpiryDate !== null)
                    ? $entryExpiryDate
                    : ($product['expiry_date'] ?: null));

            $statusCtx = array_merge($product, [
                'catalog_type'    => $prodClass,
                'manufactured_at' => $nextManufacturedAt,
                'expiry_date'     => $nextExpiryDate,
            ]);
            $newStatus = compute_inventory_product_status($newQty, (float) $product['minimum_stock'], $statusCtx);
            $newAccent = compute_product_accent($newStatus);

            $upd = $pdo->prepare(
                'UPDATE inventory_products
                 SET quantity = :qty, unit_cost = :unit_cost, purchased_at = :purchased_at,
                     manufactured_at = :manufactured_at, expiry_date = :expiry_date,
                     status = :status, accent = :accent
                 WHERE id = :id'
            );
            $upd->execute([
                ':qty'             => $newQty,
                ':unit_cost'       => $nextUnitCost,
                ':purchased_at'    => $nextPurchasedAt,
                ':manufactured_at' => $nextManufacturedAt,
                ':expiry_date'     => $nextExpiryDate,
                ':status'          => $newStatus,
                ':accent'          => $newAccent,
                ':id'              => $productId,
            ]);

            $ins = $pdo->prepare(
                'INSERT INTO inventory_movements
                 (product_id, type, quantity, unit, reason, other_reason, supplier,
                  received_date, unit_cost, manufactured_at, expiry_date, operational_area, created_by)
                 VALUES
                 (:product_id, :type, :quantity, :unit, :reason, :other_reason, :supplier,
                  :received_date, :unit_cost, :manufactured_at, :expiry_date, :operational_area, :created_by)'
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
                ':manufactured_at'  => $entryManufacturedAt,
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
                'SELECT id, name, category, catalog_type, unit, quantity, minimum_stock,
                        expiry_date, expiry_indeterminate, package_status,
                        handled_at, shelf_life_after_opening_days, opened_expiry_date
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

            $prodUnit = valid_unit((string) $prod['unit']);
            $newUnitRaw = post_text('unit', 32);
            $newUnit = $newUnitRaw !== '' ? valid_unit($newUnitRaw) : valid_unit((string) $orig['unit']);
            if (!are_units_compatible($newUnit, $prodUnit)) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Unidade incompatível com ' . $prod['name'] . ' (cadastrado em ' . $prodUnit . '). Use: ' . implode(' ou ', compatible_units($prodUnit)) . '.']);
            }
            $qtyRounded = round($quantity, 3);

            $oldStockQty = convert_to_product_unit((float) $orig['quantity'], (string) $orig['unit'], $prodUnit);
            $newStockQty = convert_to_product_unit($qtyRounded, $newUnit, $prodUnit);
            $delta = signed_qty((string) $orig['type'], $newStockQty) - signed_qty((string) $orig['type'], $oldStockQty);
            $newQty = round((float) $prod['quantity'] + $delta, 3);
            if ($newQty < 0) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Saldo insuficiente para ' . $prod['name'] . '.']);
            }

            $prodClass = resolve_product_classification((string) $prod['category'], $prod['catalog_type'] !== null ? (string) $prod['catalog_type'] : null);
            $rules = classification_rules($prodClass);
            $newManufacturedAt = $rules['usesManufacturedAt'] ? post_date('manufacturedAt') : null;
            $newExpiry = $rules['hasExpiry'] ? post_date('expiryDate') : null;
            if ($newManufacturedAt !== null && $newExpiry !== null && $newExpiry < $newManufacturedAt) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'A data de validade não pode ser anterior à data de fabricação.']);
            }
            $effectiveCtx = array_merge($prod, [
                'catalog_type' => $prodClass,
                'expiry_date'  => $rules['hasExpiry'] ? ($newExpiry ?: ($prod['expiry_date'] ?: null)) : null,
            ]);
            $newStatus = compute_inventory_product_status($newQty, (float) $prod['minimum_stock'], $effectiveCtx);
            $newAccent = compute_product_accent($newStatus);

            $pdo->prepare('UPDATE inventory_products SET quantity = :qty, status = :status, accent = :accent WHERE id = :id')
                ->execute([':qty' => $newQty, ':status' => $newStatus, ':accent' => $newAccent, ':id' => (int) $prod['id']]);

            $pdo->prepare(
                'UPDATE inventory_movements
                 SET quantity = :qty, unit = :unit, supplier = :supplier,
                     received_date = :received_date, unit_cost = :unit_cost,
                     manufactured_at = :manufactured_at, expiry_date = :expiry_date
                 WHERE id = :id'
            )->execute([
                ':qty'             => $qtyRounded,
                ':unit'            => $newUnit,
                ':supplier'        => post_text('supplier') ?: null,
                ':received_date'   => post_date('receivedDate'),
                ':unit_cost'       => ($unitCost !== null && $unitCost >= 0) ? round($unitCost, 2) : null,
                ':manufactured_at' => $newManufacturedAt,
                ':expiry_date'     => $newExpiry,
                ':id'              => $movId,
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
                'SELECT id, name, category, catalog_type, unit, quantity, minimum_stock,
                        expiry_date, expiry_indeterminate, package_status,
                        handled_at, shelf_life_after_opening_days, opened_expiry_date
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

            $prodUnit = valid_unit((string) $prod['unit']);
            $newUnitRaw = post_text('unit', 32);
            $newUnit = $newUnitRaw !== '' ? valid_unit($newUnitRaw) : valid_unit((string) $orig['unit']);
            if (!are_units_compatible($newUnit, $prodUnit)) {
                $pdo->rollBack();
                respond(['ok' => false, 'error' => 'Unidade incompatível com ' . $prod['name'] . ' (cadastrado em ' . $prodUnit . '). Use: ' . implode(' ou ', compatible_units($prodUnit)) . '.']);
            }
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

            $newStatus = compute_inventory_product_status($newQty, (float) $prod['minimum_stock'], $prod);
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
                'SELECT id, category, catalog_type, unit, quantity, minimum_stock,
                        expiry_date, expiry_indeterminate, package_status,
                        handled_at, shelf_life_after_opening_days, opened_expiry_date
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
                $newStatus = compute_inventory_product_status($newQty, (float) $prod['minimum_stock'], $prod);
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
            $orderCost = post_number('unitCost');
            if ($orderCost !== null && $orderCost < 0) {
                respond(['ok' => false, 'error' => 'O custo unitário previsto não pode ser negativo.']);
            }
            if ($productId !== null && $productId > 0) {
                $chk = $pdo->prepare('SELECT 1 FROM inventory_products WHERE id = :id LIMIT 1');
                $chk->execute([':id' => $productId]);
                if (!$chk->fetchColumn()) {
                    respond(['ok' => false, 'error' => 'Insumo relacionado não encontrado.']);
                }
            }

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
                ':unit_cost'     => $orderCost !== null ? round($orderCost, 2) : null,
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
            $orderCost = post_number('unitCost');
            if ($orderCost !== null && $orderCost < 0) {
                respond(['ok' => false, 'error' => 'O custo unitário não pode ser negativo.']);
            }
            if ($productId !== null && $productId > 0) {
                $chk = $pdo->prepare('SELECT 1 FROM inventory_products WHERE id = :id LIMIT 1');
                $chk->execute([':id' => $productId]);
                if (!$chk->fetchColumn()) {
                    respond(['ok' => false, 'error' => 'Insumo relacionado não encontrado.']);
                }
            }

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
                ':unit_cost'     => $orderCost !== null ? round($orderCost, 2) : null,
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
        // 13. Salvar etiqueta (respeitando as regras de cada classificação)
        // -------------------------------------------------------------------
        case 'createLabel': {
            $productId = (int) post_text('product', 20);
            $quantity = post_number('quantity');
            $unit = strtoupper(post_text('unit', 16));
            $manufacturedAt = post_date('manufacturedAt');
            $handledAt = post_date('handledAt');
            $expiryDate = post_date('expiryDate');
            $lot = post_text('lot', 80);
            $responsibleName = post_text('responsibleName', 120);
            $requestedPkgStatus = strtolower(post_text('packageStatus', 20));

            if ($productId <= 0 || $quantity === null || $quantity <= 0 || !in_array($unit, ['UN', 'KG', 'G', 'L', 'ML', 'FARDO', 'CAIXA'], true) || $responsibleName === '') {
                respond(['ok' => false, 'error' => 'Preencha insumo, quantidade, unidade e responsável.']);
            }

            $pStmt = $pdo->prepare(
                'SELECT id, name, category, catalog_type, active, manufactured_at, handled_at,
                        expiry_date, expiry_indeterminate, package_status,
                        shelf_life_after_opening_days, opened_expiry_date
                 FROM inventory_products
                 WHERE id = :id
                 LIMIT 1'
            );
            $pStmt->execute([':id' => $productId]);
            $prod = $pStmt->fetch();
            if (!$prod || (int) $prod['active'] === 0) {
                respond(['ok' => false, 'error' => 'Selecione um insumo ativo.']);
            }

            $catalogType = resolve_product_classification(
                (string) $prod['category'],
                $prod['catalog_type'] !== null ? (string) $prod['catalog_type'] : null
            ) ?? 'perishable';

            $labelManufacturedAt = null;
            $labelHandledAt = null;
            $labelExpiryDate = null;
            $labelOpenedExpiryDate = null;
            $labelPackageStatus = null;
            $labelExpiryIndeterminate = 0;
            $labelLot = $lot !== '' ? $lot : null;

            switch ($catalogType) {
                case 'distilled':
                    $labelExpiryIndeterminate = 1;
                    break;

                case 'disposable':
                    // Exclusivamente identificação e controle de estoque (sem datas)
                    break;

                case 'soft':
                    if (!$manufacturedAt) {
                        respond(['ok' => false, 'error' => 'Informe a data de fabricação para etiqueta de produto Soft.']);
                    }
                    if ($expiryDate && $expiryDate < $manufacturedAt) {
                        respond(['ok' => false, 'error' => 'A validade não pode ser anterior à data de fabricação.']);
                    }
                    $labelManufacturedAt = $manufacturedAt;
                    $labelExpiryDate = $expiryDate;
                    break;

                case 'syrup':
                    $pkgStatus = in_array($requestedPkgStatus, ['closed', 'opened'], true)
                        ? $requestedPkgStatus
                        : ($prod['package_status'] ?: 'closed');
                    $labelPackageStatus = $pkgStatus;
                    if (!$manufacturedAt) {
                        respond(['ok' => false, 'error' => 'Informe a data de fabricação para a etiqueta do xarope.']);
                    }
                    $labelManufacturedAt = $manufacturedAt;
                    if ($pkgStatus === 'closed') {
                        if (!$expiryDate) {
                            respond(['ok' => false, 'error' => 'Informe a data de validade do xarope fechado.']);
                        }
                        if ($expiryDate < $manufacturedAt) {
                            respond(['ok' => false, 'error' => 'A validade não pode ser anterior à data de fabricação.']);
                        }
                        $labelExpiryDate = $expiryDate;
                    } else {
                        if (!$handledAt) {
                            respond(['ok' => false, 'error' => 'Informe a data de manipulação (abertura) do xarope aberto.']);
                        }
                        if ($handledAt < $manufacturedAt) {
                            respond(['ok' => false, 'error' => 'A data de manipulação não pode ser anterior à data de fabricação.']);
                        }
                        $shelfDays = $prod['shelf_life_after_opening_days'] !== null
                            ? (int) $prod['shelf_life_after_opening_days']
                            : null;
                        $labelHandledAt = $handledAt;
                        $labelExpiryDate = $expiryDate ?: ($prod['expiry_date'] ?: null);
                        $labelOpenedExpiryDate = calculate_opened_expiry_date($handledAt, $shelfDays);
                    }
                    break;

                case 'perishable':
                case 'non-perishable':
                default:
                    if (!$handledAt || !$expiryDate || $lot === '') {
                        respond(['ok' => false, 'error' => 'Preencha data de manipulação, data de validade e lote.']);
                    }
                    if ($expiryDate < $handledAt) {
                        respond(['ok' => false, 'error' => 'A validade não pode ser anterior à manipulação.']);
                    }
                    $labelHandledAt = $handledAt;
                    $labelExpiryDate = $expiryDate;
                    $labelLot = $lot;
                    break;
            }

            $ins = $pdo->prepare(
                'INSERT INTO inventory_labels
                 (name, product_id, catalog_type, quantity, unit, manufactured_at, handled_at,
                  expiry_date, opened_expiry_date, package_status, expiry_indeterminate,
                  lot, responsible_name, created_by)
                 VALUES
                 (:name, :product_id, :catalog_type, :quantity, :unit, :manufactured_at, :handled_at,
                  :expiry_date, :opened_expiry_date, :package_status, :expiry_indeterminate,
                  :lot, :responsible_name, :created_by)'
            );
            $ins->execute([
                ':name'                 => (string) $prod['name'],
                ':product_id'           => $productId,
                ':catalog_type'         => $catalogType,
                ':quantity'             => round($quantity, 3),
                ':unit'                 => $unit,
                ':manufactured_at'      => $labelManufacturedAt,
                ':handled_at'           => $labelHandledAt,
                ':expiry_date'          => $labelExpiryDate,
                ':opened_expiry_date'   => $labelOpenedExpiryDate,
                ':package_status'       => $labelPackageStatus,
                ':expiry_indeterminate' => $labelExpiryIndeterminate,
                ':lot'                  => $labelLot,
                ':responsible_name'     => $responsibleName,
                ':created_by'           => $user['id'],
            ]);
            $labelMsg = 'Etiqueta criada e guardada.';
            if ($catalogType === 'syrup' && $labelPackageStatus === 'opened' && $labelOpenedExpiryDate === null) {
                $labelMsg = 'Etiqueta criada. Atenção: defina o prazo após abertura deste xarope para calcular a data limite.';
            }
            respond_with_state($user, $labelMsg);
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
            $amount = post_number('amount');
            if ($title === '' || !$purchaseDate) {
                respond(['ok' => false, 'error' => 'Preencha o título e a data da compra.']);
            }
            if ($amount !== null && $amount < 0) {
                respond(['ok' => false, 'error' => 'O valor do comprovante não pode ser negativo.']);
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
                ':amount'        => $amount !== null ? round($amount, 2) : null,
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

                    // Validação estrita por MIME real (nunca confia apenas na extensão do nome do arquivo)
                    $safeExt = $allowedMimeToExt[$mime] ?? null;
                    if ($safeExt === null && $mime === 'application/zip' && in_array($ext, ['docx', 'xlsx'], true)) {
                        $safeExt = $ext;
                    }
                    if ($safeExt === null) {
                        respond(['ok' => false, 'error' => 'Formato de arquivo não permitido: ' . $origName . '. Envie PDF, imagens, TXT, CSV, DOC ou XLS válidos.']);
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
    respond(['ok' => false, 'error' => 'Não foi possível concluir a ação.'], 500);
}
