<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

$dbError = null;
$initialState = [
    'user'           => null,
    'products'       => [],
    'movements'      => [],
    'receipts'       => [],
    'orders'         => [],
    'team'           => [],
    'supportTickets' => [],
];

try {
    $user = current_user();
    if ($user !== null) {
        $initialState = fetch_app_state($user);
    }
} catch (Throwable $e) {
    $dbError = 'Não foi possível conectar ao banco de dados MySQL. Verifique config/database.php e importe database/schema.sql.';
}

$stateJson = json_encode(
    $initialState,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SICE · Sistema Inteligente de Controle de Estoque</title>
  <meta name="description" content="Controle insumos, reduza perdas e acompanhe o CMV da sua operação em tempo real.">
  <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
  <script>
    (function () {
      try {
        var savedTheme = localStorage.getItem('sice_theme');
        if (savedTheme === 'dark' || savedTheme === 'light') {
          document.documentElement.dataset.theme = savedTheme;
        }
      } catch (e) {}
    })();
  </script>
  <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
  <?php if ($dbError !== null): ?>
    <div style="background:#a54e44;color:#fff;padding:10px 18px;font-size:12px;text-align:center;">
      <?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <div id="root" tabindex="-1"></div>

  <script id="sice-initial-state" type="application/json"><?= $stateJson ?></script>
  <script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.1/build/qrcode.min.js"></script>
  <script src="assets/js/app.js"></script>
</body>
</html>
