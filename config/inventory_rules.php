<?php
declare(strict_types=1);

/**
 * Regras centralizadas de categorias, classificações, validades, abertura de xaropes
 * e migração segura de esquema do SICE.
 */

const SICE_CATEGORIES = [
    'Hortifrutti',
    'Carnes e pescados',
    'Laticínios e frios',
    'Insumos Secos',
    'Bebidas',
    'Materiais descartáveis',
];

const SICE_AUTO_CLASSIFICATIONS = [
    'Carnes e pescados'      => 'perishable',
    'Laticínios e frios'     => 'perishable',
    'Hortifrutti'            => 'perishable',
    'Insumos Secos'          => 'non-perishable',
    'Materiais descartáveis' => 'disposable',
];

const SICE_BEVERAGE_CLASSIFICATIONS = ['distilled', 'soft', 'syrup'];

const SICE_CLASSIFICATION_LABELS = [
    'perishable'     => 'Perecível',
    'non-perishable' => 'Não Perecível',
    'distilled'      => 'Destilados',
    'soft'           => 'Soft',
    'syrup'          => 'Xaropes',
    'disposable'     => 'Descartáveis',
];

const SICE_STOCK_LOCATIONS = [
    'Estoque Seco',
    'Adega Bar',
    'Câmara Fria',
    'Freezer 1',
    'Freezer 2',
    'Freezer 3',
    'Freezer 4',
    'Freezer 5',
];

/**
 * Valida se o estoque/localização informado está na lista oficial pré-definida.
 */
function is_valid_stock_location(string $location): bool
{
    return in_array(trim($location), SICE_STOCK_LOCATIONS, true);
}

/**
 * Gera um código aleatório exclusivo no formato INS-XXXXXX verificando unicidade no banco.
 */
function generate_unique_product_code(PDO $pdo, int $maxAttempts = 25): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $maxIdx = strlen($alphabet) - 1;
    $check = $pdo->prepare('SELECT 1 FROM inventory_products WHERE UPPER(code) = UPPER(:code) LIMIT 1');

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $suffix = '';
        for ($i = 0; $i < 6; $i++) {
            $suffix .= $alphabet[random_int(0, $maxIdx)];
        }
        $candidate = 'INS-' . $suffix;
        $check->execute([':code' => $candidate]);
        if (!$check->fetchColumn()) {
            return $candidate;
        }
    }

    return 'INS-' . strtoupper(bin2hex(random_bytes(4)));
}

/**
 * Normaliza a nomenclatura de categoria garantindo compatibilidade com registros
 * legados (ex.: "Hortifrúti" -> "Hortifrutti").
 */
function normalize_inventory_category(?string $raw): string
{
    $trimmed = trim((string) ($raw ?? ''));
    if ($trimmed === '') {
        return 'Hortifrutti';
    }

    $lower = mb_strtolower($trimmed, 'UTF-8');

    if (in_array($lower, ['hortifrúti', 'hortifruti', 'hortifrutti'], true)) {
        return 'Hortifrutti';
    }
    if (in_array($lower, ['carnes e pescados', 'carnes', 'pescados'], true)) {
        return 'Carnes e pescados';
    }
    if (in_array($lower, ['laticínios e frios', 'laticinios e frios', 'laticínios', 'laticinios'], true)) {
        return 'Laticínios e frios';
    }
    if (in_array($lower, ['insumos secos', 'secos', 'mercearia'], true)) {
        return 'Insumos Secos';
    }
    if ($lower === 'bebidas') {
        return 'Bebidas';
    }
    if (in_array($lower, ['materiais descartáveis', 'materiais descartaveis', 'descartáveis', 'descartaveis'], true)) {
        return 'Materiais descartáveis';
    }

    foreach (SICE_CATEGORIES as $cat) {
        if (mb_strtolower($cat, 'UTF-8') === $lower) {
            return $cat;
        }
    }

    return $trimmed;
}

/**
 * Resolve a classificação efetiva a partir da categoria e da seleção (quando Bebidas).
 * Para categorias de classificação única, ignora qualquer valor divergente e atribui automaticamente.
 */
function resolve_product_classification(string $category, ?string $requested = null): ?string
{
    $normCategory = normalize_inventory_category($category);

    if (isset(SICE_AUTO_CLASSIFICATIONS[$normCategory])) {
        return SICE_AUTO_CLASSIFICATIONS[$normCategory];
    }

    if ($normCategory === 'Bebidas') {
        $req = strtolower(trim((string) ($requested ?? '')));
        return in_array($req, SICE_BEVERAGE_CLASSIFICATIONS, true) ? $req : null;
    }

    $req = strtolower(trim((string) ($requested ?? '')));
    if (isset(SICE_CLASSIFICATION_LABELS[$req])) {
        return $req;
    }

    return 'perishable';
}

/**
 * Retorna a matriz de regras da classificação informada.
 */
function classification_rules(?string $catalogType): array
{
    return match ($catalogType) {
        'distilled' => [
            'code'                 => 'distilled',
            'label'                => 'Destilados',
            'hasExpiry'            => false,
            'expiryIndeterminate'  => true,
            'usesManufacturedAt'   => false,
            'usesHandledAt'        => false,
            'supportsOpenClose'    => false,
            'expiryAlertEligible'  => false,
            'disposableControl'    => false,
        ],
        'soft' => [
            'code'                 => 'soft',
            'label'                => 'Soft',
            'hasExpiry'            => true,
            'expiryIndeterminate'  => false,
            'usesManufacturedAt'   => true,
            'usesHandledAt'        => false,
            'supportsOpenClose'    => false,
            'expiryAlertEligible'  => true,
            'disposableControl'    => false,
        ],
        'syrup' => [
            'code'                 => 'syrup',
            'label'                => 'Xaropes',
            'hasExpiry'            => true,
            'expiryIndeterminate'  => false,
            'usesManufacturedAt'   => true,
            'usesHandledAt'        => true,
            'supportsOpenClose'    => true,
            'expiryAlertEligible'  => true,
            'disposableControl'    => false,
        ],
        'disposable' => [
            'code'                 => 'disposable',
            'label'                => 'Descartáveis',
            'hasExpiry'            => false,
            'expiryIndeterminate'  => false,
            'usesManufacturedAt'   => false,
            'usesHandledAt'        => false,
            'supportsOpenClose'    => false,
            'expiryAlertEligible'  => false,
            'disposableControl'    => true,
        ],
        'non-perishable' => [
            'code'                 => 'non-perishable',
            'label'                => 'Não Perecível',
            'hasExpiry'            => true,
            'expiryIndeterminate'  => false,
            'usesManufacturedAt'   => false,
            'usesHandledAt'        => true,
            'supportsOpenClose'    => false,
            'expiryAlertEligible'  => true,
            'disposableControl'    => false,
        ],
        default => [
            'code'                 => 'perishable',
            'label'                => 'Perecível',
            'hasExpiry'            => true,
            'expiryIndeterminate'  => false,
            'usesManufacturedAt'   => false,
            'usesHandledAt'        => true,
            'supportsOpenClose'    => false,
            'expiryAlertEligible'  => true,
            'disposableControl'    => false,
        ],
    };
}

/**
 * Valida uma data YYYY-MM-DD retornando formato normalizado ou null.
 */
function normalize_iso_date(?string $raw): ?string
{
    $val = trim((string) ($raw ?? ''));
    if ($val === '') {
        return null;
    }
    $slice = substr($val, 0, 10);
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $slice, new DateTimeZone('UTC'));
    if (!$dt || $dt->format('Y-m-d') !== $slice) {
        return null;
    }
    return $slice;
}

/**
 * Calcula a data limite de utilização após abertura (handled_at + dias).
 * Nunca inventa prazo genérico: retorna null se não houver data ou prazo configurado.
 */
function calculate_opened_expiry_date(?string $handledAt, ?int $shelfLifeDays): ?string
{
    $normHandled = normalize_iso_date($handledAt);
    if ($normHandled === null || $shelfLifeDays === null || $shelfLifeDays <= 0) {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($normHandled . ' 00:00:00', new DateTimeZone('UTC'));
        return $dt->modify('+' . $shelfLifeDays . ' days')->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Valida e normaliza todos os campos de classificação e datas de um produto.
 */
function normalize_product_control_fields(
    string $categoryRaw,
    ?string $requestedCatalogType,
    array $input,
    bool $isCreate = true
): array {
    $category = normalize_inventory_category($categoryRaw);
    $catalogType = resolve_product_classification($category, $requestedCatalogType);

    if ($catalogType === null) {
        return [
            'ok'    => false,
            'error' => 'Para a categoria Bebidas, selecione a classificação: Destilados, Soft ou Xaropes.',
        ];
    }

    $purchasedAt = normalize_iso_date($input['purchasedAt'] ?? null);
    $manufacturedAt = normalize_iso_date($input['manufacturedAt'] ?? null);
    $handledAt = normalize_iso_date($input['handledAt'] ?? null);
    $expiryDate = normalize_iso_date($input['expiryDate'] ?? null);
    $expiryNotes = trim((string) ($input['expiry'] ?? ''));
    $expiryNotes = $expiryNotes !== '' ? mb_substr($expiryNotes, 0, 160) : null;

    $shelfLifeRaw = $input['shelfLifeAfterOpeningDays'] ?? null;
    $shelfLifeDays = null;
    if ($shelfLifeRaw !== null && trim((string) $shelfLifeRaw) !== '') {
        $shelfStr = trim((string) $shelfLifeRaw);
        if (!ctype_digit($shelfStr) || (int) $shelfStr <= 0) {
            return [
                'ok'    => false,
                'error' => 'O prazo de utilização após abertura deve ser um número inteiro de dias maior que zero.',
            ];
        }
        $shelfLifeDays = (int) $shelfStr;
    }

    $packageStatusRaw = strtolower(trim((string) ($input['packageStatus'] ?? '')));

    switch ($catalogType) {
        case 'distilled':
            return [
                'ok'   => true,
                'data' => [
                    'category'                      => $category,
                    'catalog_type'                  => 'distilled',
                    'purchased_at'                  => $purchasedAt,
                    'manufactured_at'               => null,
                    'handled_at'                    => null,
                    'expiry_date'                   => null,
                    'expiry_indeterminate'          => 1,
                    'package_status'                => null,
                    'shelf_life_after_opening_days' => null,
                    'opened_expiry_date'            => null,
                    'expiry'                        => $expiryNotes,
                ],
            ];

        case 'disposable':
            return [
                'ok'   => true,
                'data' => [
                    'category'                      => $category,
                    'catalog_type'                  => 'disposable',
                    'purchased_at'                  => $purchasedAt,
                    'manufactured_at'               => null,
                    'handled_at'                    => null,
                    'expiry_date'                   => null,
                    'expiry_indeterminate'          => 0,
                    'package_status'                => null,
                    'shelf_life_after_opening_days' => null,
                    'opened_expiry_date'            => null,
                    'expiry'                        => null,
                ],
            ];

        case 'soft':
            if ($isCreate && $manufacturedAt === null) {
                return [
                    'ok'    => false,
                    'error' => 'Informe a data de fabricação para produtos da classificação Soft.',
                ];
            }
            if ($manufacturedAt !== null && $expiryDate !== null && $expiryDate < $manufacturedAt) {
                return [
                    'ok'    => false,
                    'error' => 'A data de validade não pode ser anterior à data de fabricação.',
                ];
            }
            return [
                'ok'   => true,
                'data' => [
                    'category'                      => $category,
                    'catalog_type'                  => 'soft',
                    'purchased_at'                  => $purchasedAt,
                    'manufactured_at'               => $manufacturedAt,
                    'handled_at'                    => null,
                    'expiry_date'                   => $expiryDate,
                    'expiry_indeterminate'          => 0,
                    'package_status'                => null,
                    'shelf_life_after_opening_days' => null,
                    'opened_expiry_date'            => null,
                    'expiry'                        => $expiryNotes,
                ],
            ];

        case 'syrup':
            $packageStatus = in_array($packageStatusRaw, ['closed', 'opened'], true)
                ? $packageStatusRaw
                : 'closed';

            if ($manufacturedAt === null) {
                return [
                    'ok'    => false,
                    'error' => 'Informe a data de fabricação do xarope.',
                ];
            }
            if ($packageStatus === 'closed' && $expiryDate === null) {
                return [
                    'ok'    => false,
                    'error' => 'Informe a data de validade do xarope fechado.',
                ];
            }
            if ($expiryDate !== null && $expiryDate < $manufacturedAt) {
                return [
                    'ok'    => false,
                    'error' => 'A data de validade não pode ser anterior à data de fabricação.',
                ];
            }
            if ($packageStatus === 'opened') {
                if ($handledAt === null) {
                    return [
                        'ok'    => false,
                        'error' => 'Informe a data de manipulação (data de abertura) do xarope aberto.',
                    ];
                }
                if ($handledAt < $manufacturedAt) {
                    return [
                        'ok'    => false,
                        'error' => 'A data de manipulação não pode ser anterior à data de fabricação.',
                    ];
                }
                $openedExpiryDate = calculate_opened_expiry_date($handledAt, $shelfLifeDays);
            } else {
                $handledAt = null;
                $openedExpiryDate = null;
            }

            return [
                'ok'   => true,
                'data' => [
                    'category'                      => $category,
                    'catalog_type'                  => 'syrup',
                    'purchased_at'                  => $purchasedAt,
                    'manufactured_at'               => $manufacturedAt,
                    'handled_at'                    => $handledAt,
                    'expiry_date'                   => $expiryDate,
                    'expiry_indeterminate'          => 0,
                    'package_status'                => $packageStatus,
                    'shelf_life_after_opening_days' => $shelfLifeDays,
                    'opened_expiry_date'            => $openedExpiryDate,
                    'expiry'                        => $expiryNotes,
                ],
            ];

        case 'non-perishable':
            return [
                'ok'   => true,
                'data' => [
                    'category'                      => $category,
                    'catalog_type'                  => 'non-perishable',
                    'purchased_at'                  => $purchasedAt,
                    'manufactured_at'               => null,
                    'handled_at'                    => null,
                    'expiry_date'                   => $expiryDate,
                    'expiry_indeterminate'          => 0,
                    'package_status'                => null,
                    'shelf_life_after_opening_days' => null,
                    'opened_expiry_date'            => null,
                    'expiry'                        => $expiryNotes,
                ],
            ];

        case 'perishable':
        default:
            return [
                'ok'   => true,
                'data' => [
                    'category'                      => $category,
                    'catalog_type'                  => 'perishable',
                    'purchased_at'                  => $purchasedAt,
                    'manufactured_at'               => null,
                    'handled_at'                    => null,
                    'expiry_date'                   => $expiryDate,
                    'expiry_indeterminate'          => 0,
                    'package_status'                => null,
                    'shelf_life_after_opening_days' => null,
                    'opened_expiry_date'            => null,
                    'expiry'                        => $expiryNotes,
                ],
            ];
    }
}

/**
 * Retorna a data de validade efetiva para alertas e status do estoque.
 * Nunca retorna data para destilados (validade indeterminada) nem descartáveis.
 * Para xaropes abertos, considera o limite após abertura (e também a validade original se for anterior).
 */
function get_effective_expiry_date(array $product): ?string
{
    $catalogType = $product['catalog_type'] ?? $product['catalogType'] ?? null;
    $indeterminate = (bool) ($product['expiry_indeterminate'] ?? $product['expiryIndeterminate'] ?? false);

    if ($catalogType === 'distilled' || $catalogType === 'disposable' || $indeterminate) {
        return null;
    }

    $origExpiry = normalize_iso_date($product['expiry_date'] ?? $product['expiryDate'] ?? null);

    if ($catalogType === 'syrup') {
        $pkgStatus = $product['package_status'] ?? $product['packageStatus'] ?? 'closed';
        if ($pkgStatus === 'opened') {
            $openedExpiry = normalize_iso_date($product['opened_expiry_date'] ?? $product['openedExpiryDate'] ?? null);
            if ($openedExpiry === null) {
                $handled = normalize_iso_date($product['handled_at'] ?? $product['handledAt'] ?? null);
                $days = $product['shelf_life_after_opening_days'] ?? $product['shelfLifeAfterOpeningDays'] ?? null;
                $openedExpiry = calculate_opened_expiry_date($handled, $days !== null ? (int) $days : null);
            }
            if ($openedExpiry !== null && $origExpiry !== null) {
                return $openedExpiry <= $origExpiry ? $openedExpiry : $origExpiry;
            }
            if ($openedExpiry !== null) {
                return $openedExpiry;
            }
            // Se prazo após abertura ainda não foi cadastrado, não inventa data fictícia
            return $origExpiry;
        }
    }

    return $origExpiry;
}

/**
 * Calcula o status do produto considerando saldo mínimo e validade efetiva
 * (sem penalizar destilados com validade indeterminada ou descartáveis).
 */
function compute_inventory_product_status(float $qty, float $minStock, array $productContext = []): string
{
    if ($qty <= 0) {
        return 'critical';
    }

    $effectiveExpiry = get_effective_expiry_date($productContext);
    $expiringVerySoon = false;
    if ($effectiveExpiry !== null) {
        try {
            $exp = new DateTimeImmutable($effectiveExpiry . ' 00:00:00', new DateTimeZone('UTC'));
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

/**
 * Registra um evento rastreável no histórico de estado do produto (ex.: abertura de xarope).
 */
function record_product_state_event(PDO $pdo, array $event): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO inventory_product_state_history
         (product_id, event_type, previous_state, new_state, manufactured_at,
          original_expiry_date, handled_at, shelf_life_after_opening_days,
          opened_expiry_date, notes, created_by)
         VALUES
         (:product_id, :event_type, :previous_state, :new_state, :manufactured_at,
          :original_expiry_date, :handled_at, :shelf_life_after_opening_days,
          :opened_expiry_date, :notes, :created_by)'
    );
    $stmt->execute([
        ':product_id'                    => (int) $event['product_id'],
        ':event_type'                    => (string) ($event['event_type'] ?? 'opened'),
        ':previous_state'                => $event['previous_state'] ?? null,
        ':new_state'                     => $event['new_state'] ?? null,
        ':manufactured_at'               => $event['manufactured_at'] ?? null,
        ':original_expiry_date'          => $event['original_expiry_date'] ?? null,
        ':handled_at'                    => $event['handled_at'] ?? null,
        ':shelf_life_after_opening_days' => $event['shelf_life_after_opening_days'] ?? null,
        ':opened_expiry_date'            => $event['opened_expiry_date'] ?? null,
        ':notes'                         => isset($event['notes']) && $event['notes'] !== '' ? mb_substr((string) $event['notes'], 0, 500) : null,
        ':created_by'                    => (int) $event['created_by'],
    ]);
}

/**
 * Garante de forma segura e idempotente que o banco existente tenha todas as colunas,
 * tabelas e ajustes de nomenclatura ("Hortifrúti" -> "Hortifrutti") sem perder dados.
 */
function ensure_inventory_schema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName === '') {
            return;
        }

        $colStmt = $pdo->prepare(
            'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db
               AND TABLE_NAME IN (\'inventory_products\', \'inventory_movements\', \'inventory_labels\', \'purchase_orders\')'
        );
        $colStmt->execute([':db' => $dbName]);
        $columns = [];
        foreach ($colStmt->fetchAll() as $row) {
            $columns[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row;
        }

        // 1. inventory_products
        $prodCols = $columns['inventory_products'] ?? [];
        if (isset($prodCols['catalog_type']) && strtolower((string) $prodCols['catalog_type']['DATA_TYPE']) === 'enum') {
            $pdo->exec('ALTER TABLE `inventory_products` MODIFY COLUMN `catalog_type` VARCHAR(32) DEFAULT NULL');
        }
        if (!isset($prodCols['manufactured_at'])) {
            $pdo->exec('ALTER TABLE `inventory_products` ADD COLUMN `manufactured_at` DATE DEFAULT NULL AFTER `purchased_at`');
        }
        if (!isset($prodCols['handled_at'])) {
            $pdo->exec('ALTER TABLE `inventory_products` ADD COLUMN `handled_at` DATE DEFAULT NULL AFTER `manufactured_at`');
        }
        if (!isset($prodCols['expiry_indeterminate'])) {
            $pdo->exec('ALTER TABLE `inventory_products` ADD COLUMN `expiry_indeterminate` TINYINT(1) NOT NULL DEFAULT 0 AFTER `expiry_date`');
        }
        if (!isset($prodCols['package_status'])) {
            $pdo->exec("ALTER TABLE `inventory_products` ADD COLUMN `package_status` ENUM('closed', 'opened') DEFAULT NULL AFTER `expiry_indeterminate`");
        }
        if (!isset($prodCols['shelf_life_after_opening_days'])) {
            $pdo->exec('ALTER TABLE `inventory_products` ADD COLUMN `shelf_life_after_opening_days` INT UNSIGNED DEFAULT NULL AFTER `package_status`');
        }
        if (!isset($prodCols['opened_expiry_date'])) {
            $pdo->exec('ALTER TABLE `inventory_products` ADD COLUMN `opened_expiry_date` DATE DEFAULT NULL AFTER `shelf_life_after_opening_days`');
        }

        // 2. inventory_product_state_history
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `inventory_product_state_history` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // 3. inventory_movements
        $movCols = $columns['inventory_movements'] ?? [];
        if (!isset($movCols['manufactured_at'])) {
            $pdo->exec('ALTER TABLE `inventory_movements` ADD COLUMN `manufactured_at` DATE DEFAULT NULL AFTER `unit_cost`');
        }

        // 4. inventory_labels
        $lblCols = $columns['inventory_labels'] ?? [];
        if (
            (isset($lblCols['handled_at']) && $lblCols['handled_at']['IS_NULLABLE'] === 'NO') ||
            (isset($lblCols['expiry_date']) && $lblCols['expiry_date']['IS_NULLABLE'] === 'NO') ||
            (isset($lblCols['lot']) && $lblCols['lot']['IS_NULLABLE'] === 'NO')
        ) {
            $pdo->exec(
                'ALTER TABLE `inventory_labels`
                 MODIFY COLUMN `handled_at` DATE DEFAULT NULL,
                 MODIFY COLUMN `expiry_date` DATE DEFAULT NULL,
                 MODIFY COLUMN `lot` VARCHAR(80) DEFAULT NULL'
            );
        }
        if (!isset($lblCols['catalog_type'])) {
            $pdo->exec('ALTER TABLE `inventory_labels` ADD COLUMN `catalog_type` VARCHAR(32) DEFAULT NULL AFTER `product_id`');
        }
        if (!isset($lblCols['manufactured_at'])) {
            $pdo->exec('ALTER TABLE `inventory_labels` ADD COLUMN `manufactured_at` DATE DEFAULT NULL AFTER `unit`');
        }
        if (!isset($lblCols['opened_expiry_date'])) {
            $pdo->exec('ALTER TABLE `inventory_labels` ADD COLUMN `opened_expiry_date` DATE DEFAULT NULL AFTER `expiry_date`');
        }
        if (!isset($lblCols['package_status'])) {
            $pdo->exec("ALTER TABLE `inventory_labels` ADD COLUMN `package_status` ENUM('closed', 'opened') DEFAULT NULL AFTER `opened_expiry_date`");
        }
        if (!isset($lblCols['expiry_indeterminate'])) {
            $pdo->exec('ALTER TABLE `inventory_labels` ADD COLUMN `expiry_indeterminate` TINYINT(1) NOT NULL DEFAULT 0 AFTER `package_status`');
        }

        // 4b. Atualização segura de unidades de medida (litros -> l, + ml, + fardo; etiquetas + FARDO, + CAIXA)
        $prodUnitType = strtolower((string) ($prodCols['unit']['COLUMN_TYPE'] ?? ''));
        if ($prodUnitType !== '' && (!str_contains($prodUnitType, "'fardo'") || !str_contains($prodUnitType, "'ml'") || str_contains($prodUnitType, "'litros'"))) {
            $pdo->exec("ALTER TABLE `inventory_products` MODIFY COLUMN `unit` ENUM('kg', 'g', 'un', 'litros', 'l', 'ml', 'caixa', 'fardo') NOT NULL DEFAULT 'un'");
            $pdo->exec("UPDATE `inventory_products` SET `unit` = 'l' WHERE `unit` = 'litros'");
            $pdo->exec("ALTER TABLE `inventory_products` MODIFY COLUMN `unit` ENUM('kg', 'g', 'un', 'l', 'ml', 'caixa', 'fardo') NOT NULL DEFAULT 'un'");
        }
        $pdo->exec("UPDATE `inventory_movements` SET `unit` = 'l' WHERE `unit` = 'litros'");

        $ordCols = $columns['purchase_orders'] ?? [];
        $ordUnitType = strtolower((string) ($ordCols['unit']['COLUMN_TYPE'] ?? ''));
        if ($ordUnitType !== '' && (!str_contains($ordUnitType, "'fardo'") || !str_contains($ordUnitType, "'ml'") || str_contains($ordUnitType, "'litros'"))) {
            $pdo->exec("ALTER TABLE `purchase_orders` MODIFY COLUMN `unit` ENUM('kg', 'g', 'un', 'litros', 'l', 'ml', 'caixa', 'fardo') NOT NULL DEFAULT 'un'");
            $pdo->exec("UPDATE `purchase_orders` SET `unit` = 'l' WHERE `unit` = 'litros'");
            $pdo->exec("ALTER TABLE `purchase_orders` MODIFY COLUMN `unit` ENUM('kg', 'g', 'un', 'l', 'ml', 'caixa', 'fardo') NOT NULL DEFAULT 'un'");
        }

        $lblUnitType = strtoupper((string) ($lblCols['unit']['COLUMN_TYPE'] ?? ''));
        if ($lblUnitType !== '' && (!str_contains($lblUnitType, "'FARDO'") || !str_contains($lblUnitType, "'CAIXA'"))) {
            $pdo->exec("ALTER TABLE `inventory_labels` MODIFY COLUMN `unit` ENUM('UN', 'KG', 'G', 'L', 'ML', 'FARDO', 'CAIXA') NOT NULL DEFAULT 'UN'");
        }

        // 5. Migração idempotente de dados legados (Hortifrúti -> Hortifrutti e classificações)
        $pdo->exec(
            "UPDATE `inventory_products`
             SET `category` = 'Hortifrutti'
             WHERE LOWER(TRIM(`category`)) IN ('hortifrúti', 'hortifruti')"
        );
        $pdo->exec(
            "UPDATE `inventory_products`
             SET `location` = REPLACE(REPLACE(REPLACE(REPLACE(`location`, 'Hortifrúti', 'Hortifrutti'), 'hortifrúti', 'hortifrutti'), 'Hortifruti', 'Hortifrutti'), 'hortifruti', 'hortifrutti')
             WHERE `location` LIKE '%Hortifrúti%' OR `location` LIKE '%hortifrúti%' OR `location` LIKE '%Hortifruti%' OR `location` LIKE '%hortifruti%'"
        );
        $pdo->exec(
            "UPDATE `purchase_orders`
             SET `title` = REPLACE(REPLACE(REPLACE(REPLACE(`title`, 'Hortifrúti', 'Hortifrutti'), 'hortifrúti', 'hortifrutti'), 'Hortifruti', 'Hortifrutti'), 'hortifruti', 'hortifrutti')
             WHERE `title` LIKE '%Hortifrúti%' OR `title` LIKE '%hortifrúti%' OR `title` LIKE '%Hortifruti%' OR `title` LIKE '%hortifruti%'"
        );
        $pdo->exec(
            "UPDATE `inventory_products`
             SET `catalog_type` = 'perishable', `expiry_indeterminate` = 0
             WHERE `category` IN ('Carnes e pescados', 'Laticínios e frios', 'Hortifrutti')
               AND (`catalog_type` IS NULL OR `catalog_type` <> 'perishable' OR `expiry_indeterminate` <> 0)"
        );
        $pdo->exec(
            "UPDATE `inventory_products`
             SET `category` = 'Insumos Secos', `catalog_type` = 'non-perishable', `expiry_indeterminate` = 0
             WHERE LOWER(TRIM(`category`)) = 'insumos secos'
               AND (`catalog_type` IS NULL OR `catalog_type` <> 'non-perishable' OR `expiry_indeterminate` <> 0)"
        );
        $pdo->exec(
            "UPDATE `inventory_products`
             SET `catalog_type` = 'disposable',
                 `expiry_indeterminate` = 0,
                 `expiry_date` = NULL,
                 `manufactured_at` = NULL,
                 `handled_at` = NULL,
                 `package_status` = NULL,
                 `shelf_life_after_opening_days` = NULL,
                 `opened_expiry_date` = NULL
             WHERE `category` = 'Materiais descartáveis'
               AND (`catalog_type` IS NULL OR `catalog_type` <> 'disposable' OR `expiry_date` IS NOT NULL)"
        );
        $pdo->exec(
            "UPDATE `inventory_products`
             SET `catalog_type` = 'distilled',
                 `expiry_indeterminate` = 1,
                 `expiry_date` = NULL
             WHERE `category` = 'Bebidas'
               AND (`catalog_type` IS NULL OR `catalog_type` IN ('beverage', 'non-perishable'))
               AND LOWER(`name`) REGEXP 'gin|vodka|whisky|whiskey|rum|cachaça|cachaca|tequila|conhaque|cognac|licor|destilado|aperol|campari|vermute|bourbon|mezcal|sake|saquê'"
        );
        $pdo->exec(
            "UPDATE `inventory_products`
             SET `catalog_type` = 'syrup',
                 `package_status` = COALESCE(`package_status`, 'closed'),
                 `expiry_indeterminate` = 0
             WHERE `category` = 'Bebidas'
               AND (`catalog_type` IS NULL OR `catalog_type` IN ('beverage', 'non-perishable'))
               AND LOWER(`name`) REGEXP 'xarope|syrup|monin|grenadine|groselha'"
        );
        $pdo->exec(
            "UPDATE `inventory_products`
             SET `catalog_type` = 'soft',
                 `expiry_indeterminate` = 0
             WHERE `category` = 'Bebidas'
               AND (`catalog_type` IS NULL OR `catalog_type` IN ('beverage', 'non-perishable'))
               AND LOWER(`name`) REGEXP 'refrigerante|coca|guaraná|guarana|tônica|tonica|água|agua|suco|energético|energetico|soda|soft|chá|cha|mate|ginger'"
        );
        $pdo->exec(
            "UPDATE `inventory_products`
             SET `expiry_indeterminate` = 1,
                 `expiry_date` = NULL
             WHERE `catalog_type` = 'distilled'
               AND (`expiry_indeterminate` <> 1 OR `expiry_date` IS NOT NULL)"
        );
        $pdo->exec(
            "UPDATE `inventory_products`
             SET `status` = CASE
                   WHEN `quantity` <= 0 THEN 'critical'
                   WHEN `quantity` <= `minimum_stock` THEN 'low'
                   ELSE 'ok'
                 END,
                 `accent` = CASE
                   WHEN `quantity` <= 0 THEN '#a54e44'
                   WHEN `quantity` <= `minimum_stock` THEN '#b67c33'
                   ELSE '#376759'
                 END
             WHERE `catalog_type` IN ('distilled', 'disposable')"
        );

        // 6. Garantir índice de localização e tabela de sessões persistentes
        $idxStmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = :db
               AND TABLE_NAME = 'inventory_products'
               AND INDEX_NAME = 'idx_inventory_products_location'
             LIMIT 1"
        );
        $idxStmt->execute([':db' => $dbName]);
        if (!$idxStmt->fetchColumn()) {
            $pdo->exec('ALTER TABLE `inventory_products` ADD KEY `idx_inventory_products_location` (`location`)');
        }
    } catch (Throwable $e) {
        // Não bloqueia a aplicação caso o usuário do banco não tenha privilégios de DDL
    }
}
