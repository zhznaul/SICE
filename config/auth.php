<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/inventory_rules.php';

/**
 * Inicia a sessão PHP com cookies seguros.
 */
function start_app_session(int $lifetime = 0): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443);

    session_name('sice_session');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Realiza login validando e-mail, senha e status ativo do usuário.
 */
function login_user(string $email, string $password, bool $remember = false): array
{
    $stmt = db()->prepare(
        'SELECT id, name, email, password_hash, role, function_title, active
         FROM users
         WHERE LOWER(email) = LOWER(:email)
         LIMIT 1'
    );
    $stmt->execute([':email' => trim($email)]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, (string) $row['password_hash'])) {
        throw new RuntimeException('Credenciais inválidas.');
    }

    if ((int) $row['active'] === 0) {
        throw new RuntimeException('Este acesso está inativo.');
    }

    $lifetime = $remember ? 60 * 60 * 24 * 30 : 60 * 60 * 8;
    start_app_session($lifetime);
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $row['id'];
    $_SESSION['login_at'] = time();

    if ($remember) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $lifetime,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    return format_user($row);
}

/**
 * Encerra a sessão atual.
 */
function logout_user(): void
{
    start_app_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'] ?: '/',
            'domain'   => $params['domain'] ?: '',
            'secure'   => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?: 'Lax',
        ]);
    }

    session_destroy();
}

/**
 * Retorna o usuário autenticado atual ou null.
 */
function current_user(): ?array
{
    start_app_session();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, name, email, role, function_title, active
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => (int) $userId]);
        $row = $stmt->fetch();

        if (!$row || (int) $row['active'] === 0) {
            return null;
        }

        return format_user($row);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Formata o registro do usuário no formato esperado pelo frontend.
 */
function format_user(array $row): array
{
    return [
        'id'       => (int) $row['id'],
        'name'     => (string) $row['name'],
        'email'    => (string) $row['email'],
        'role'     => (string) $row['role'],
        'function' => $row['function_title'] !== null ? (string) $row['function_title'] : null,
        'active'   => (bool) ((int) $row['active']),
    ];
}

function is_manager(array $user): bool
{
    return in_array($user['role'] ?? '', ['owner', 'storekeeper'], true);
}

/**
 * Garante que a tabela de chamados de suporte exista mesmo em bancos já importados.
 */
function ensure_support_tickets_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `support_tickets` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id` INT UNSIGNED NOT NULL,
          `subject` VARCHAR(180) NOT NULL,
          `description` TEXT NOT NULL,
          `attachments_json` TEXT DEFAULT NULL,
          `status` ENUM(\'open\', \'in_progress\', \'resolved\') NOT NULL DEFAULT \'open\',
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_support_tickets_user` (`user_id`),
          KEY `idx_support_tickets_created_at` (`created_at`),
          CONSTRAINT `fk_support_tickets_user`
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
            ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * Carrega todo o estado da aplicação de acordo com as permissões do perfil.
 */
function fetch_app_state(array $user): array
{
    $pdo = db();
    ensure_inventory_schema($pdo);
    $canPurchase = ($user['role'] !== 'operational');
    $canManageTeam = ($user['role'] === 'owner');

    // 1. Produtos
    $productsStmt = $pdo->query(
        'SELECT id, name, category, catalog_type, code, location, quantity, unit,
                minimum_stock, expiry, purchased_at, manufactured_at, handled_at,
                expiry_date, expiry_indeterminate, package_status,
                shelf_life_after_opening_days, opened_expiry_date,
                unit_cost, active, status, accent, created_at, updated_at
         FROM inventory_products
         ORDER BY name ASC
         LIMIT 500'
    );
    $products = [];
    $productsById = [];
    foreach ($productsStmt->fetchAll() as $row) {
        $normCategory = normalize_inventory_category((string) $row['category']);
        $resolvedCatalogType = resolve_product_classification($normCategory, $row['catalog_type'] !== null ? (string) $row['catalog_type'] : null)
            ?? ($row['catalog_type'] !== null ? (string) $row['catalog_type'] : null);
        $isDistilled = ($resolvedCatalogType === 'distilled');
        $isDisposable = ($resolvedCatalogType === 'disposable');
        $shelfLifeDays = $row['shelf_life_after_opening_days'] !== null ? (int) $row['shelf_life_after_opening_days'] : null;
        $openedExpiry = $row['opened_expiry_date'];
        if ($resolvedCatalogType === 'syrup' && $row['package_status'] === 'opened' && $openedExpiry === null && $row['handled_at'] !== null && $shelfLifeDays !== null) {
            $openedExpiry = calculate_opened_expiry_date((string) $row['handled_at'], $shelfLifeDays);
        }

        $item = [
            'id'                        => (int) $row['id'],
            'name'                      => (string) $row['name'],
            'category'                  => $normCategory,
            'catalogType'               => $resolvedCatalogType,
            'code'                      => (string) $row['code'],
            'location'                  => (string) $row['location'],
            'quantity'                  => (float) $row['quantity'],
            'unit'                      => (string) $row['unit'],
            'minimumStock'              => (float) $row['minimum_stock'],
            'expiry'                    => $isDisposable ? null : $row['expiry'],
            'purchasedAt'               => $row['purchased_at'],
            'manufacturedAt'            => $isDisposable ? null : $row['manufactured_at'],
            'handledAt'                 => $isDisposable ? null : $row['handled_at'],
            'expiryDate'                => ($isDistilled || $isDisposable) ? null : $row['expiry_date'],
            'expiryIndeterminate'       => $isDistilled || (bool) ((int) ($row['expiry_indeterminate'] ?? 0)),
            'packageStatus'             => $resolvedCatalogType === 'syrup' ? ($row['package_status'] ?: 'closed') : null,
            'shelfLifeAfterOpeningDays' => $resolvedCatalogType === 'syrup' ? $shelfLifeDays : null,
            'openedExpiryDate'          => $resolvedCatalogType === 'syrup' ? $openedExpiry : null,
            'unitCost'                  => $row['unit_cost'] !== null ? (float) $row['unit_cost'] : 0.0,
            'active'                    => (bool) ((int) $row['active']),
            'status'                    => (string) $row['status'],
            'accent'                    => $row['accent'] ?: '#376759',
            'createdAt'                 => str_replace(' ', 'T', (string) $row['created_at']),
            'updatedAt'                 => str_replace(' ', 'T', (string) $row['updated_at']),
        ];
        $products[] = $item;
        $productsById[$item['id']] = $item;
    }

    // 2. Movimentações
    $movStmt = $pdo->query(
        'SELECT m.id, m.product_id, m.type, m.quantity, m.unit, m.reason, m.other_reason,
                m.supplier, m.received_date, m.unit_cost, m.manufactured_at, m.expiry_date,
                m.operational_area, m.created_by, m.created_at,
                u.name AS user_name, u.email AS user_email, u.role AS user_role
         FROM inventory_movements m
         LEFT JOIN users u ON u.id = m.created_by
         ORDER BY m.created_at DESC, m.id DESC
         LIMIT 500'
    );
    $movements = [];
    foreach ($movStmt->fetchAll() as $row) {
        $pid = (int) $row['product_id'];
        $movements[] = [
            'id'              => (int) $row['id'],
            'type'            => (string) $row['type'],
            'quantity'        => (float) $row['quantity'],
            'unit'            => (string) $row['unit'],
            'reason'          => (string) $row['reason'],
            'otherReason'     => $row['other_reason'],
            'operationalArea' => $row['operational_area'],
            'supplier'        => $row['supplier'],
            'receivedDate'    => $row['received_date'],
            'unitCost'        => $row['unit_cost'] !== null ? (float) $row['unit_cost'] : null,
            'manufacturedAt'  => $row['manufactured_at'],
            'expiryDate'      => $row['expiry_date'],
            'createdAt'       => str_replace(' ', 'T', (string) $row['created_at']),
            'product'         => $productsById[$pid] ?? $pid,
            'createdBy'       => [
                'id'    => (int) $row['created_by'],
                'name'  => (string) ($row['user_name'] ?? 'Responsável'),
                'email' => (string) ($row['user_email'] ?? ''),
                'role'  => (string) ($row['user_role'] ?? 'operational'),
            ],
        ];
    }

    // 3. Comprovantes (apenas proprietário e almoxarife)
    $receipts = [];
    if ($canPurchase) {
        $recStmt = $pdo->query(
            'SELECT id, title, supplier, purchase_date, amount, filename, original_name
             FROM purchase_receipts
             ORDER BY purchase_date DESC, id DESC
             LIMIT 100'
        );
        foreach ($recStmt->fetchAll() as $row) {
            $receipts[] = [
                'id'           => (int) $row['id'],
                'title'        => (string) $row['title'],
                'supplier'     => $row['supplier'],
                'purchaseDate' => (string) $row['purchase_date'],
                'amount'       => $row['amount'] !== null ? (float) $row['amount'] : null,
                'filename'     => (string) $row['original_name'],
                'url'          => 'api/receipt.php?id=' . (int) $row['id'],
            ];
        }
    }

    // 4. Pedidos de compra (apenas proprietário e almoxarife)
    $orders = [];
    if ($canPurchase) {
        $ordStmt = $pdo->query(
            'SELECT id, title, product_id, supplier, quantity, unit, unit_cost,
                    expected_date, status, notes
             FROM purchase_orders
             ORDER BY expected_date ASC, id DESC
             LIMIT 100'
        );
        foreach ($ordStmt->fetchAll() as $row) {
            $pid = $row['product_id'] !== null ? (int) $row['product_id'] : null;
            $orders[] = [
                'id'           => (int) $row['id'],
                'title'        => (string) $row['title'],
                'product'      => $pid !== null ? ($productsById[$pid] ?? $pid) : null,
                'supplier'     => $row['supplier'],
                'quantity'     => (float) $row['quantity'],
                'unit'         => (string) $row['unit'],
                'unitCost'     => $row['unit_cost'] !== null ? (float) $row['unit_cost'] : null,
                'expectedDate' => (string) $row['expected_date'],
                'status'       => (string) $row['status'],
                'notes'        => $row['notes'],
            ];
        }
    }

    // 5. Equipe (apenas proprietário)
    $team = [];
    if ($canManageTeam) {
        $teamStmt = $pdo->query(
            'SELECT id, name, email, role, function_title, active
             FROM users
             ORDER BY name ASC
             LIMIT 100'
        );
        foreach ($teamStmt->fetchAll() as $row) {
            $team[] = format_user($row);
        }
    }

    // 6. Chamados de suporte recentes do usuário
    $supportTickets = [];
    try {
        $ticketStmt = $pdo->prepare(
            'SELECT id, subject, description, attachments_json, status, created_at
             FROM support_tickets
             WHERE user_id = :uid
             ORDER BY created_at DESC, id DESC
             LIMIT 20'
        );
        $ticketStmt->execute([':uid' => (int) $user['id']]);
        foreach ($ticketStmt->fetchAll() as $row) {
            $atts = [];
            if (!empty($row['attachments_json'])) {
                $decoded = json_decode((string) $row['attachments_json'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $att) {
                        if (is_array($att) && isset($att['originalName'])) {
                            $atts[] = [
                                'originalName' => (string) $att['originalName'],
                                'fileSize'     => (int) ($att['fileSize'] ?? 0),
                            ];
                        }
                    }
                }
            }
            $supportTickets[] = [
                'id'          => (int) $row['id'],
                'subject'     => (string) $row['subject'],
                'description' => (string) $row['description'],
                'attachments' => $atts,
                'status'      => (string) $row['status'],
                'createdAt'   => str_replace(' ', 'T', (string) $row['created_at']),
            ];
        }
    } catch (Throwable $e) {
        $supportTickets = [];
    }

    // 7. Histórico de abertura e alterações de estado (xaropes)
    $stateHistory = [];
    try {
        $histStmt = $pdo->query(
            'SELECT h.id, h.product_id, h.event_type, h.previous_state, h.new_state,
                    h.manufactured_at, h.original_expiry_date, h.handled_at,
                    h.shelf_life_after_opening_days, h.opened_expiry_date, h.notes,
                    h.created_by, h.created_at,
                    u.name AS user_name
             FROM inventory_product_state_history h
             LEFT JOIN users u ON u.id = h.created_by
             ORDER BY h.created_at DESC, h.id DESC
             LIMIT 300'
        );
        foreach ($histStmt->fetchAll() as $row) {
            $stateHistory[] = [
                'id'                        => (int) $row['id'],
                'productId'                 => (int) $row['product_id'],
                'eventType'                 => (string) $row['event_type'],
                'previousState'             => $row['previous_state'],
                'newState'                  => $row['new_state'],
                'manufacturedAt'            => $row['manufactured_at'],
                'originalExpiryDate'        => $row['original_expiry_date'],
                'handledAt'                 => $row['handled_at'],
                'shelfLifeAfterOpeningDays' => $row['shelf_life_after_opening_days'] !== null ? (int) $row['shelf_life_after_opening_days'] : null,
                'openedExpiryDate'          => $row['opened_expiry_date'],
                'notes'                     => $row['notes'],
                'createdBy'                 => [
                    'id'   => (int) $row['created_by'],
                    'name' => (string) ($row['user_name'] ?? 'Responsável'),
                ],
                'createdAt'                 => str_replace(' ', 'T', (string) $row['created_at']),
            ];
        }
    } catch (Throwable $e) {
        $stateHistory = [];
    }

    return [
        'user'           => $user,
        'products'       => $products,
        'movements'      => $movements,
        'receipts'       => $receipts,
        'orders'         => $orders,
        'team'           => $team,
        'supportTickets' => $supportTickets,
        'stateHistory'   => $stateHistory,
    ];
}
