<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/inventory_rules.php';

const SICE_AUTH_COOKIE = 'sice_auth_token';
const SICE_SESSION_IDLE_TTL = 7200;         // 2 horas de inatividade sem renovação (login padrão)
const SICE_SESSION_MAX_TTL = 28800;         // 8 horas de janela máxima (login padrão)
const SICE_SESSION_REMEMBER_TTL = 2592000;  // 30 dias quando "Manter conectado" está ativo

function is_https_request(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? null) == 443) {
        return true;
    }
    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwardedProto === 'https') {
        return true;
    }
    $forwardedSsl = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
    return $forwardedSsl === 'on';
}

function set_auth_cookie(string $name, string $value, int $expires): void
{
    setcookie($name, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function ensure_user_sessions_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `user_sessions` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        // Caso o usuário do banco não tenha permissão CREATE TABLE, segue com sessão PHP padrão
    }
}

/**
 * Inicia a sessão PHP com cookies seguros e tempo de vida compatível no servidor.
 */
function start_app_session(int $lifetime = 0): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $gcLifetime = max(SICE_SESSION_MAX_TTL, $lifetime);
    @ini_set('session.gc_maxlifetime', (string) $gcLifetime);
    @ini_set('session.use_strict_mode', '1');

    session_name('sice_session');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Realiza login validando e-mail, senha e status ativo do usuário.
 * Cria sessão PHP + registro persistente revogável em user_sessions.
 */
function login_user(string $email, string $password, bool $remember = false): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
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

    $now = time();
    $lifetime = $remember ? SICE_SESSION_REMEMBER_TTL : SICE_SESSION_MAX_TTL;
    start_app_session($lifetime);
    session_regenerate_id(true);

    $expiresAtTs = $now + $lifetime;
    $_SESSION['user_id'] = (int) $row['id'];
    $_SESSION['login_at'] = $now;
    $_SESSION['last_activity'] = $now;
    $_SESSION['remember'] = $remember ? 1 : 0;
    $_SESSION['expires_at'] = $expiresAtTs;

    set_auth_cookie(session_name(), session_id(), $remember ? $expiresAtTs : 0);

    // Persiste token opaco com hash SHA-256 no banco para sobreviver a GC de /tmp ou reinício de worker
    try {
        ensure_user_sessions_table($pdo);
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $uaHash = $ua !== '' ? hash('sha256', $ua) : null;

        $ins = $pdo->prepare(
            'INSERT INTO user_sessions (token_hash, user_id, remember, user_agent_hash, last_activity_at, expires_at)
             VALUES (:token_hash, :user_id, :remember, :ua_hash, NOW(), DATE_ADD(NOW(), INTERVAL :ttl SECOND))'
        );
        $ins->execute([
            ':token_hash' => $tokenHash,
            ':user_id'    => (int) $row['id'],
            ':remember'   => $remember ? 1 : 0,
            ':ua_hash'    => $uaHash,
            ':ttl'        => $lifetime,
        ]);

        $_SESSION['auth_token_hash'] = $tokenHash;
        set_auth_cookie(SICE_AUTH_COOKIE, $rawToken, $remember ? $expiresAtTs : 0);

        // Limpeza oportunista de sessões já expiradas
        $pdo->exec('DELETE FROM user_sessions WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 100');
    } catch (Throwable $e) {
        // Mantém sessão PHP caso a tabela user_sessions ainda não exista
    }

    return format_user($row);
}

/**
 * Encerra a sessão atual e revoga o token persistente no banco.
 */
function logout_user(): void
{
    start_app_session();

    $tokenHash = null;
    $cookieToken = (string) ($_COOKIE[SICE_AUTH_COOKIE] ?? '');
    if ($cookieToken !== '') {
        $tokenHash = hash('sha256', $cookieToken);
    } elseif (!empty($_SESSION['auth_token_hash'])) {
        $tokenHash = (string) $_SESSION['auth_token_hash'];
    }

    if ($tokenHash !== null) {
        try {
            $pdo = db();
            $rev = $pdo->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE token_hash = :hash');
            $rev->execute([':hash' => $tokenHash]);
        } catch (Throwable $e) {
            // Ignora falha de revogação se tabela não existir
        }
    }

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
    set_auth_cookie(SICE_AUTH_COOKIE, '', time() - 42000);

    session_destroy();
}

/**
 * Tenta recuperar sessão válida a partir do cookie persistente (user_sessions)
 * caso a sessão PHP em /tmp tenha sido coletada pelo GC ou perdida entre workers.
 */
function restore_session_from_db_token(PDO $pdo): ?int
{
    $rawToken = (string) ($_COOKIE[SICE_AUTH_COOKIE] ?? '');
    if ($rawToken === '' || strlen($rawToken) < 32) {
        return null;
    }

    try {
        ensure_user_sessions_table($pdo);
        $tokenHash = hash('sha256', $rawToken);
        $stmt = $pdo->prepare(
            'SELECT id, user_id, remember, UNIX_TIMESTAMP(last_activity_at) AS last_act_ts,
                    UNIX_TIMESTAMP(expires_at) AS exp_ts
             FROM user_sessions
             WHERE token_hash = :hash
               AND revoked_at IS NULL
               AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':hash' => $tokenHash]);
        $sess = $stmt->fetch();
        if (!$sess) {
            return null;
        }

        $now = time();
        $isRemember = (int) $sess['remember'] === 1;
        $lastActTs = (int) ($sess['last_act_ts'] ?? 0);
        if (!$isRemember && $lastActTs > 0 && ($now - $lastActTs) > SICE_SESSION_IDLE_TTL) {
            $pdo->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE id = :id')
                ->execute([':id' => (int) $sess['id']]);
            return null;
        }

        $userId = (int) $sess['user_id'];
        $_SESSION['user_id'] = $userId;
        $_SESSION['last_activity'] = $now;
        $_SESSION['remember'] = $isRemember ? 1 : 0;
        $_SESSION['expires_at'] = (int) ($sess['exp_ts'] ?? ($now + SICE_SESSION_MAX_TTL));
        $_SESSION['auth_token_hash'] = $tokenHash;

        $pdo->prepare('UPDATE user_sessions SET last_activity_at = NOW() WHERE id = :id')
            ->execute([':id' => (int) $sess['id']]);

        return $userId;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Retorna o usuário autenticado atual ou null, respeitando janela de inatividade
 * e expiração absoluta, com recuperação transparente via user_sessions.
 */
function current_user(): ?array
{
    start_app_session();
    $pdo = null;
    try {
        $pdo = db();
    } catch (Throwable $e) {
        return null;
    }

    $now = time();
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

    if ($userId) {
        $isRemember = !empty($_SESSION['remember']);
        $lastAct = (int) ($_SESSION['last_activity'] ?? $now);
        $expTs = (int) ($_SESSION['expires_at'] ?? ($now + SICE_SESSION_MAX_TTL));
        $idleExpired = !$isRemember && ($now - $lastAct) > SICE_SESSION_IDLE_TTL;
        $absExpired = $now > $expTs;

        if ($idleExpired || $absExpired) {
            $_SESSION = [];
            $userId = null;
        }
    }

    if (!$userId) {
        $userId = restore_session_from_db_token($pdo);
        if (!$userId) {
            return null;
        }
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT id, name, email, role, function_title, active
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        if (!$row || (int) $row['active'] === 0) {
            return null;
        }

        // Atualiza timestamp de atividade (com throttle de 60s no banco)
        $prevAct = (int) ($_SESSION['last_activity'] ?? 0);
        $_SESSION['last_activity'] = $now;
        if (($now - $prevAct) >= 60 && !empty($_SESSION['auth_token_hash'])) {
            try {
                $pdo->prepare('UPDATE user_sessions SET last_activity_at = NOW() WHERE token_hash = :hash AND revoked_at IS NULL')
                    ->execute([':hash' => (string) $_SESSION['auth_token_hash']]);
            } catch (Throwable $e) {
                // Ignora erro secundário de atualização de atividade
            }
        }

        return format_user($row);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Renova com segurança a sessão ativa (sliding expiration dentro da janela máxima permitida).
 */
function renew_current_session(): ?array
{
    $user = current_user();
    if (!$user) {
        return null;
    }

    $now = time();
    $isRemember = !empty($_SESSION['remember']);
    $loginAt = (int) ($_SESSION['login_at'] ?? $now);
    $maxWindow = $isRemember ? SICE_SESSION_REMEMBER_TTL : (SICE_SESSION_MAX_TTL * 2); // até 16h ativo contínuo ou 30d remember
    $targetExp = min($loginAt + $maxWindow, $now + ($isRemember ? SICE_SESSION_REMEMBER_TTL : SICE_SESSION_IDLE_TTL));
    $targetExp = max($targetExp, $now + 1800);

    $_SESSION['last_activity'] = $now;
    $_SESSION['expires_at'] = $targetExp;

    if (!empty($_SESSION['auth_token_hash'])) {
        try {
            $pdo = db();
            $ttl = max(60, $targetExp - $now);
            $upd = $pdo->prepare(
                'UPDATE user_sessions
                 SET last_activity_at = NOW(),
                     expires_at = DATE_ADD(NOW(), INTERVAL :ttl SECOND)
                 WHERE token_hash = :hash
                   AND revoked_at IS NULL'
            );
            $upd->execute([
                ':ttl'  => $ttl,
                ':hash' => (string) $_SESSION['auth_token_hash'],
            ]);
        } catch (Throwable $e) {
            // Segue com renovação da sessão PHP
        }
    }

    if ($isRemember) {
        set_auth_cookie(session_name(), session_id(), $targetExp);
        $rawToken = (string) ($_COOKIE[SICE_AUTH_COOKIE] ?? '');
        if ($rawToken !== '') {
            set_auth_cookie(SICE_AUTH_COOKIE, $rawToken, $targetExp);
        }
    }

    return [
        'expiresIn'  => max(0, $targetExp - $now),
        'serverTime' => $now,
    ];
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
            'unit'                      => ((string) $row['unit'] === 'litros') ? 'l' : (string) $row['unit'],
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
            'unit'            => ((string) $row['unit'] === 'litros') ? 'l' : (string) $row['unit'],
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
                'unit'         => ((string) $row['unit'] === 'litros') ? 'l' : (string) $row['unit'],
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
