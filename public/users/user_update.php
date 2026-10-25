<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';

requireAdmin($pdo);
startSession();

/**
 * 編集画面へエラーを返す
 */
function redirectEditError(int $id, string $message): void
{
    header(
        'Location: user_edit.php?id=' . $id
        . '&error=' . rawurlencode($message)
    );
    exit;
}

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

// ユーザーID検証
$idInput = $_POST['id'] ?? '';

if (
    !is_string($idInput)
    || !ctype_digit($idInput)
    || (int) $idInput <= 0
) {
    header(
        'Location: users.php?error='
        . rawurlencode('ユーザーIDが正しくありません。')
    );
    exit;
}

$userId = (int) $idInput;

// 入力値取得
$usernameInput = $_POST['username'] ?? '';
$passwordInput = $_POST['password'] ?? '';
$roleTitleInput = $_POST['role_title'] ?? '';
$managerIdInput = $_POST['manager_id'] ?? '';

if (
    !is_string($usernameInput)
    || !is_string($passwordInput)
    || !is_string($roleTitleInput)
    || !is_string($managerIdInput)
) {
    redirectEditError($userId, '入力内容が正しくありません。');
}

$username = trim($usernameInput);
$password = $passwordInput;
$roleTitle = $roleTitleInput;
$isAdminInput = $_POST['is_admin'] ?? '';

$isAdmin = ($isAdminInput === '1') ? 1 : 0;

// 許可された役職
$allowedRoles = [
    '一般',
    '主任',
    '課長代理',
    '課長',
    '部長',
];

// 対象ユーザーの存在確認
$userStmt = $pdo->prepare(
    'SELECT id, username, role_title, manager_id, is_admin
     FROM users
     WHERE id = :id'
);

$userStmt->execute([
    ':id' => $userId,
]);

$targetUser = $userStmt->fetch();

if ($targetUser === false) {
    header(
        'Location: users.php?error='
        . rawurlencode('対象ユーザーが見つかりません。')
    );
    exit;
}

// ユーザー名チェック
if ($username === '') {
    redirectEditError($userId, 'ユーザー名を入力してください。');
}

if (strlen($username) > 300) {
    redirectEditError($userId, 'ユーザー名が長すぎます。');
}

// パスワードチェック
// 空欄の場合は変更しない
if ($password !== '') {
    if (strlen($password) < 8) {
        redirectEditError(
            $userId,
            '新しいパスワードは8文字以上で入力してください。'
        );
    }

    if (strlen($password) > 4096) {
        redirectEditError($userId, 'パスワードが長すぎます。');
    }
}

// 役職チェック
if (!in_array($roleTitle, $allowedRoles, true)) {
    redirectEditError($userId, '役職の指定が正しくありません。');
}

// ユーザー名重複チェック
$duplicateStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM users
     WHERE username = :username
       AND id <> :id'
);

$duplicateStmt->execute([
    ':username' => $username,
    ':id' => $userId,
]);

if ((int) $duplicateStmt->fetchColumn() > 0) {
    redirectEditError(
        $userId,
        'このユーザー名はすでに使用されています。'
    );
}

// 上司IDチェック
$managerId = null;

if ($managerIdInput !== '') {
    if (
        !ctype_digit($managerIdInput)
        || (int) $managerIdInput <= 0
    ) {
        redirectEditError(
            $userId,
            '上司の指定が正しくありません。'
        );
    }

    $managerId = (int) $managerIdInput;

    // 自分自身を上司に指定できない
    if ($managerId === $userId) {
        redirectEditError(
            $userId,
            '自分自身を上司に指定することはできません。'
        );
    }

    // 指定された上司が存在するか確認
    $managerStmt = $pdo->prepare(
        'SELECT id
         FROM users
         WHERE id = :id'
    );

    $managerStmt->execute([
        ':id' => $managerId,
    ]);

    if ($managerStmt->fetch() === false) {
        redirectEditError(
            $userId,
            '指定された上司が存在しません。'
        );
    }

    // 配下のユーザーを上司に指定すると循環するため拒否
    $descendantStmt = $pdo->prepare(
        'WITH RECURSIVE descendants(id) AS (
            SELECT id
            FROM users
            WHERE manager_id = :user_id

            UNION

            SELECT u.id
            FROM users u
            INNER JOIN descendants d
                ON u.manager_id = d.id
        )
        SELECT COUNT(*)
        FROM descendants
        WHERE id = :manager_id'
    );

    $descendantStmt->execute([
        ':user_id' => $userId,
        ':manager_id' => $managerId,
    ]);

    if ((int) $descendantStmt->fetchColumn() > 0) {
        redirectEditError(
            $userId,
            '配下のユーザーを上司に指定することはできません。'
        );
    }
}

// パスワードが入力された場合だけハッシュ化
$passwordHash = null;

if ($password !== '') {
    $passwordHash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    if ($passwordHash === false) {
        http_response_code(500);
        exit('パスワードの処理に失敗しました。');
    }
}

try {
    $pdo->beginTransaction();

    // 最後の管理者から管理者権限を外すことを禁止
    if (
        (int) $targetUser['is_admin'] === 1
        && $isAdmin === 0
    ) {
        $adminCountStmt = $pdo->query(
            'SELECT COUNT(*)
             FROM users
             WHERE is_admin = 1'
        );

        $adminCount = (int) $adminCountStmt->fetchColumn();

        if ($adminCount <= 1) {
            $pdo->rollBack();

            redirectEditError(
                $userId,
                '最後の管理者から権限を外すことはできません。'
            );
        }
    }

    // パスワードを変更する場合
    if ($passwordHash !== null) {
        $updateStmt = $pdo->prepare(
            'UPDATE users
             SET username = :username,
                 password_hash = :password_hash,
                 role_title = :role_title,
                 manager_id = :manager_id,
                 is_admin = :is_admin
             WHERE id = :id'
        );

        $updateStmt->execute([
            ':username' => $username,
            ':password_hash' => $passwordHash,
            ':role_title' => $roleTitle,
            ':manager_id' => $managerId,
            ':is_admin' => $isAdmin,
            ':id' => $userId,
        ]);
    } else {
        // パスワードを変更しない場合
        $updateStmt = $pdo->prepare(
            'UPDATE users
             SET username = :username,
                 role_title = :role_title,
                 manager_id = :manager_id,
                 is_admin = :is_admin
             WHERE id = :id'
        );

        $updateStmt->execute([
            ':username' => $username,
            ':role_title' => $roleTitle,
            ':manager_id' => $managerId,
            ':is_admin' => $isAdmin,
            ':id' => $userId,
        ]);
    }

    $pdo->commit();

    header(
        'Location: users.php?message='
        . rawurlencode('ユーザー情報を更新しました。')
    );
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('ユーザー更新エラー: ' . $e->getMessage());

    redirectEditError(
        $userId,
        'ユーザー情報を更新できませんでした。入力内容を確認してください。'
    );
}
