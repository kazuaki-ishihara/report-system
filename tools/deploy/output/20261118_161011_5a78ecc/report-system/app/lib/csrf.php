<?php

declare(strict_types=1);

// ====================================================================
// CSRF（詳細設計書 共通編 §6）
// ====================================================================

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = random_token(32);
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(): void
{
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $token = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sent) || !is_string($token) || $token === '' || !hash_equals($token, $sent)) {
        error_page(403, msg('画面を開き直してから、もう一度お試しください。'));
    }
}
