<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sua sessão expirou. Entre novamente.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    echo json_encode(
        ['ok' => true, 'data' => fetch_app_state($user)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro ao carregar dados do estoque.'], JSON_UNESCAPED_UNICODE);
}
