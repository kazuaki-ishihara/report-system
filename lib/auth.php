<?php

declare(strict_types=1);

/**
 * セッションを開始する
 */
function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * ログインしているか確認する
 */
function isLoggedIn(): bool
{
    startSession();

    return isset($_SESSION['user_id']);
}

/**
 * ログイン必須の画面で使用する
 *
 * 未ログインの場合はログイン画面へ移動する
 */
function requireLogin(): void
{
    startSession();

    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * ログイン中のユーザーIDを取得する
 */
function getLoginUserId(): ?int
{
    startSession();

    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    return (int) $_SESSION['user_id'];
}

/**
 * ログイン中のユーザー情報を取得する
 *
 * @param PDO $pdo データベース接続
 * @return array|null ユーザー情報。取得できない場合はnull
 */
function getLoginUser(PDO $pdo): ?array
{
    $userId = getLoginUserId();

    if ($userId === null) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT
            id,
            username,
            role_title,
            manager_id,
            is_admin
         FROM users
         WHERE id = :id'
    );

    $stmt->execute([
        ':id' => $userId,
    ]);

    $user = $stmt->fetch();

    if ($user === false) {
        // 存在しないユーザーのセッションを破棄する
        $_SESSION = [];
        session_regenerate_id(true);

        return null;
    }

    return $user;
}

/**
 * システム管理者か確認する
 */
function isAdmin(PDO $pdo): bool
{
    $user = getLoginUser($pdo);

    return $user !== null
        && (int) $user['is_admin'] === 1;
}

/**
 * システム管理者専用の画面で使用する
 */
function requireAdmin(PDO $pdo): void
{
    requireLogin();

    if (!isAdmin($pdo)) {
        http_response_code(403);

        exit('この操作を実行する権限がありません。');
    }
}
