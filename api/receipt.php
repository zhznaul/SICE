<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

$user = current_user();
if (!$user || !is_manager($user)) {
    http_response_code(403);
    exit('Acesso negado.');
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Comprovante inválido.');
}

$stmt = db()->prepare(
    'SELECT filename, original_name, mime_type
     FROM purchase_receipts
     WHERE id = :id
     LIMIT 1'
);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    exit('Comprovante não encontrado.');
}

$safeName = basename((string) $row['filename']);
$filePath = __DIR__ . '/../uploads/receipts/' . $safeName;
if (!is_file($filePath)) {
    http_response_code(404);
    exit('Arquivo não encontrado no servidor.');
}

$mime = (string) $row['mime_type'];
$orig = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $row['original_name']);

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $orig . '"');
header('Content-Length: ' . (string) filesize($filePath));
header('Cache-Control: private, no-store');
readfile($filePath);
exit;
