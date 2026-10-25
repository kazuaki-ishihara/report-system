<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';

// 管理者以外は登録処理を実行できない
requireAdmin($pdo);
startSession();

// POSTリクエスト以外は拒否
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users.php');
    exit;
}

// CSRFトークン検証
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

// 入力値取得
$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$roleTitle = (string) ($_POST['role_title'] ?? '');
$managerIdInput = $_POST['manager_id'] ?? '';
$isAdmin = isset($_POST['is_admin']) ? 1 : 0;

// 許可された役職
$allowedRoles = [
    '一般',
    '主任',
    '課長代理',
    '課長',
    '部長',
];

// ユーザー名チェック
if ($username === '') {
    header(
        'Location: user_create.php?error=' .
        rawurlencode('ユーザー名を入力してください。')
    );
    exit;
}

if (strlen($username) > 300) {
    header(
        'Location: user_create.php?error=' .
        rawurlencode('ユーザー名が長すぎます。')
    );
    exit;
}

// パスワードチェック
if (strlen($password) < 8) {
    header(
        'Location: user_create.php?error=' .
        rawurlencode('パスワードは8文字以上で入力してください。')
    );
    exit;
}

if (strlen($password) > 4096) {
    header(
        'Location: user_create.php?error=' .
        rawurlencode('パスワードが長すぎます。')
    );
    exit;
}

// 役職チェック
if (!in_array($roleTitle, $allowedRoles, true)) {
    header(
        'Location: user_create.php?error=' .
        rawurlencode('役職の指定が正しくありません。')
    );
    exit;
}

// 上司IDチェック
$managerId = null;

if ($managerIdInput !== '') {
    $managerIdString = (string) $managerIdInput;

    if (
        !ctype_digit($managerIdString)
        || (int) $managerIdString <= 0
    ) {
        header(
            'Location: user_create.php?error=' .
            rawurlencode('上司の指定が正しくありません。')
        );
        exit;
    }

    $managerId = (int) $managerIdString;

    $managerStmt = $pdo->prepare(
        'SELECT id FROM users WHERE id = :id'
    );

    $managerStmt->execute([
        ':id' => $managerId,
    ]);

    if ($managerStmt->fetch() === false) {
        header(
            'Location: user_create.php?error=' .
            rawurlencode('指定された上司が存在しません。')
        );
        exit;
    }
}

// ユーザー名の重複チェック
$checkStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM users WHERE username = :username'
);

$checkStmt->execute([
    ':username' => $username,
]);

if ((int) $checkStmt->fetchColumn() > 0) {
    header(
        'Location: user_create.php?error=' .
        rawurlencode('このユーザー名はすでに使用されています。')
    );
    exit;
}

// パスワードをハッシュ化
$passwordHash = password_hash(
    $password,
    PASSWORD_DEFAULT
);

if ($passwordHash === false) {
    http_response_code(500);
    exit('パスワードの処理に失敗しました。');
}

// ユーザー登録
try {
    $stmt = $pdo->prepare(
        'INSERT INTO users (
            username,
            password_hash,
            role_title,
            manager_id,
            is_admin,
            created_at
        ) VALUES (
            :username,
            :password_hash,
            :role_title,
            :manager_id,
            :is_admin,
            :created_at
        )'
    );

    $stmt->execute([
        ':username' => $username,
        ':password_hash' => $passwordHash,
        ':role_title' => $roleTitle,
        ':manager_id' => $managerId,
        ':is_admin' => $isAdmin,
        ':created_at' => date('Y-m-d H:i:s'),
    ]);

    header(
        'Location: users.php?message=' .
        rawurlencode('ユーザーを登録しました。')
    );
    exit;

} catch (PDOException $e) {
    // 詳細なDBエラーは画面に表示しない
    error_log('ユーザー登録エラー: ' . $e->getMessage());

    header(
        'Location: user_create.php?error=' .
        rawurlencode(
            'ユーザーを登録できませんでした。ユーザー名の重複などを確認してください。'
        )
    );
    exit;
}
