<?php
declare(strict_types=1);

/**
 * Configuração de conexão com o banco de dados MySQL via PDO.
 * Ajuste as credenciais abaixo ou defina variáveis de ambiente DB_*.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: 'db.fr-roub1.bengt.wasmernet.com';
    $port = getenv('DB_PORT') ?: '20184';
    $name = getenv('DB_NAME') ?: 'sice_db';
    $user = getenv('DB_USER') ?: 'user_bb3031b6';
    $pass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : 'pw_JpC93SUSloQH09tye1cwlVzlv8GQkDei';

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}
