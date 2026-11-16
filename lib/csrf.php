<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * CSRF対策用のトークンを取得する
 *
 * ユーザー管理画面（public/users/）と同じセッションキー csrf_token を使う。
 * まだ発行していない場合は新しく作る。
 */
function getCsrfToken(): string
{
    startSession();

    if (
        empty($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
    ) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * POSTされたCSRFトークンを検証する
 *
 * 一致しない場合は 403 で処理を終了する。
 */
function verifyCsrfToken(): void
{
    startSession();

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $postToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($sessionToken)
        || !is_string($postToken)
        || $sessionToken === ''
        || !hash_equals($sessionToken, $postToken)
    ) {
        http_response_code(403);

        exit('不正なリクエストです。画面を開き直して再度お試しください。');
    }
}
