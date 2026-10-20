<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
date_default_timezone_set('Europe/Vienna');

if (PHP_SAPI !== 'cli' && is_file(APP_ROOT . '/.private/maintenance.json')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Retry-After: 60');
    exit('<!doctype html><html lang="de"><meta charset="utf-8"><title>Literatur – Wartung</title><p>Die Literaturverwaltung wird gerade aktualisiert. Bitte in Kürze erneut aufrufen.</p></html>');
}

function env_config(): array
{
    static $config;
    if ($config === null) {
        $config = @parse_ini_file(APP_ROOT . '/.env', false, INI_SCANNER_RAW);
        if (!is_array($config)) throw new RuntimeException('Die Datenbankverbindung ist nicht eingerichtet.');
    }
    return $config;
}

function db(): PDO
{
    static $db;
    if (!$db) {
        $c = env_config();
        $db = new PDO('mysql:host=' . $c['APIARY_DB_HOST'] . ';dbname=' . $c['APIARY_DB_NAME'] . ';charset=utf8mb4', $c['APIARY_DB_USER'], $c['APIARY_DB_PASS'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }
    return $db;
}

function e(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function verify_csrf(): void
{
    if (!isset($_POST['csrf']) || !hash_equals(csrf_token(), (string)$_POST['csrf'])) {
        http_response_code(403);
        throw new RuntimeException('Die Sitzung ist abgelaufen. Bitte die Seite neu laden.');
    }
}
function redirect(string $url = 'index.php'): never { header('Location: ' . $url, true, 303); exit; }
function signed_id(mixed $value): ?int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => -2147483648, 'max_range' => 2147483647]]);
    return $id === false ? null : $id;
}

if (PHP_SAPI !== 'cli') {
    if(PHP_SAPI!=='cli-server' && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off')) {
        header('Location: https://scalas.at'.str_replace(["\r","\n"],'',$_SERVER['REQUEST_URI']??'/literatur/'),true,308);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('literatur_session');
    session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax', 'path' => '/literatur/']);
    session_start();
    if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > 3600) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = time();
}
