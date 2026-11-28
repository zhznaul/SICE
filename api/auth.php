<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'login';
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isJson = str_contains($contentType, 'application/json');

if ($action === 'logout') {
    logout_user();
    header('Cache-Control: no-store, max-age=0');
    header('Location: ../index.php?logout=1', true, 303);
    exit;
}

if ($action === 'renew') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    $renewed = renew_current_session();
    if ($renewed === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Sua sessão expirou. Entre novamente.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'ok'         => true,
        'expiresIn'  => $renewed['expiresIn'],
        'serverTime' => $renewed['serverTime'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = $isJson
    ? (json_decode((string) file_get_contents('php://input'), true) ?: [])
    : $_POST;

$email = strtolower(trim((string) ($data['email'] ?? '')));
$password = (string) ($data['password'] ?? '');
$remember = ($data['remember'] ?? false) === true || ($data['remember'] ?? '') === 'on' || ($data['remember'] ?? '') === '1';

if ($email === '' || $password === '') {
    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Informe e-mail e senha.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Cache-Control: no-store');
    header('Location: ../index.php?loginError=1', true, 303);
    exit;
}

try {
    login_user($email, $password, $remember);
    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Cache-Control: no-store');
    header('Location: ../index.php?login=1', true, 303);
    exit;
} catch (Throwable $e) {
    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'E-mail ou senha incorretos.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Cache-Control: no-store');
    header('Location: ../index.php?loginError=1', true, 303);
    exit;
}
