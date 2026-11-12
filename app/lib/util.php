<?php

declare(strict_types=1);

// ====================================================================
// 共通の小さな関数
// ====================================================================

function cfg(string $key)
{
    $c = $GLOBALS['APP_CONFIG'];
    if (!array_key_exists($key, $c)) {
        throw new RuntimeException('unknown config: ' . $key);
    }
    return $c[$key];
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** 公開側の URL（base_path を付ける） */
function url(string $path): string
{
    return cfg('base_path') . $path;
}

/** 自サイトの中だけに転送する */
function redirect(string $path): void
{
    if (!is_safe_local_path($path)) {
        $path = '/index.php';
    }
    header('Location: ' . url($path), true, 303);
    exit;
}

/** 転送先として使ってよいパスか（外のサイトへの転送を防ぐ） */
function is_safe_local_path(string $path): bool
{
    if ($path === '' || $path[0] !== '/') {
        return false;
    }
    if (strpos($path, '//') === 0 || strpos($path, '\\') !== false) {
        return false;
    }
    return preg_match('/[\x00-\x1F\x7F]/', $path) !== 1;
}

function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/** GET・POST の整数の ID（1 以上でなければ 400） */
function input_id(string $name, string $method = 'GET'): int
{
    $src = $method === 'POST' ? $_POST : $_GET;
    $v = $src[$name] ?? null;
    if (!is_string($v) || preg_match('/^[1-9][0-9]{0,9}$/', $v) !== 1) {
        error_page(400);
    }
    return (int) $v;
}

/** POST の文字列（配列などは 400） */
function post_str(string $name, bool $trim = true): string
{
    $v = $_POST[$name] ?? '';
    if (!is_string($v)) {
        error_page(400);
    }
    if (preg_match('//u', $v) !== 1) {
        error_page(400);
    }
    return $trim ? trim($v) : $v;
}

function is_valid_period(string $p): bool
{
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $p) === 1;
}

function str_len(string $s): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($s, 'UTF-8');
    }
    return (int) preg_match_all('/./us', $s);
}

function has_control_chars(string $s, bool $allowNewline = false): bool
{
    $pattern = $allowNewline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
    return preg_match($pattern, $s) === 1;
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function app_log(string $message): void
{
    $dir = cfg('log_dir');
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    @file_put_contents($dir . '/app.log', now() . ' ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

function app_init_error_handling(): void
{
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    set_error_handler(function (int $no, string $str, string $file, int $line): bool {
        if ((error_reporting() & $no) === 0) {
            return false; // @ で抑止した警告
        }
        throw new ErrorException($str, 0, $no, $file, $line);
    });
    set_exception_handler(function (Throwable $e): void {
        app_log('ERROR ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
        error_page(500);
    });
}
