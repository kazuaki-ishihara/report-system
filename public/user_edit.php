<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

requireAdmin($pdo);
startSession();

// ユーザーIDを検証
$idInput = $_GET['id'] ?? '';

if (
    !is_string($idInput)
    || !ctype_digit($idInput)
    || (int) $idInput <= 0
) {
    header('Location: users.php?error=' . rawurlencode('ユーザーIDが正しくありません。'));
    exit;
}

$userId = (int) $idInput;

// 編集対象ユーザーを取得
$stmt = $pdo->prepare(
    'SELECT id, username, role_title, manager_id, is_admin, created_at
     FROM users
     WHERE id = :id'
);

$stmt->execute([
    ':id' => $userId,
]);

$user = $stmt->fetch();

if ($user === false) {
    header('Location: users.php?error=' . rawurlencode('ユーザーが見つかりません。'));
    exit;
}

// CSRFトークン
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

// 役職一覧
$roleTitles = [
    '一般',
    '主任',
    '課長代理',
    '課長',
    '部長',
];

// 編集対象ユーザー本人と、その配下のユーザーを
// 上司候補から除外する
$managerSql = '
    WITH RECURSIVE descendants(id) AS (
        SELECT id
        FROM users
        WHERE manager_id = :user_id

        UNION

        SELECT u.id
        FROM users u
        INNER JOIN descendants d
            ON u.manager_id = d.id
    )
    SELECT id, username, role_title
    FROM users
    WHERE id <> :exclude_id
      AND id NOT IN (SELECT id FROM descendants)
    ORDER BY id ASC
';

$managerStmt = $pdo->prepare($managerSql);

$managerStmt->execute([
    ':user_id' => $userId,
    ':exclude_id' => $userId,
]);

$managers = $managerStmt->fetchAll();

$error = trim($_GET['error'] ?? '');

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>ユーザー編集</title>

    <style>
        body {
            margin: 0;
            padding: 20px;
            background: #f5f6f8;
            font-family: sans-serif;
            color: #333;
        }

        .container {
            max-width: 650px;
            margin: 30px auto;
            padding: 28px;
            background: #fff;
            border-radius: 8px;
            box-sizing: border-box;
        }

        h1 {
            margin-top: 0;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        input[type="text"],
        input[type="password"],
        select {
            width: 100%;
            padding: 10px;
            border: 1px solid #bbb;
            border-radius: 5px;
            box-sizing: border-box;
            font-size: 16px;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: normal;
        }

        .checkbox-label input {
            width: 18px;
            height: 18px;
        }

        .help {
            margin-top: 6px;
            color: #666;
            font-size: 13px;
            line-height: 1.6;
        }

        .error-message {
            margin-bottom: 20px;
            padding: 12px;
            color: #b71c1c;
            background: #ffebee;
            border-radius: 5px;
        }

        .buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 25px;
        }

        .button {
            display: inline-block;
            padding: 10px 18px;
            border: 0;
            border-radius: 5px;
            font-size: 15px;
            text-decoration: none;
            cursor: pointer;
        }

        .submit-button {
            background: #1976d2;
            color: #fff;
        }

        .back-button {
            background: #e0e0e0;
            color: #333;
        }

        .user-info {
            margin-bottom: 20px;
            padding: 12px;
            background: #f2f4f7;
            border-radius: 5px;
        }
    </style>
</head>

<body>

<div class="container">

    <h1>ユーザー編集</h1>

    <div class="user-info">
        ユーザーID：<?= (int) $user['id'] ?><br>
        作成日時：<?= h($user['created_at']) ?>
    </div>

    <?php if ($error !== ''): ?>
        <div class="error-message">
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <form action="user_update.php" method="post">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= h($csrfToken) ?>"
        >

        <input
            type="hidden"
            name="id"
            value="<?= (int) $user['id'] ?>"
        >

        <div class="form-group">
            <label for="username">ユーザー名</label>

            <input
                type="text"
                id="username"
                name="username"
                value="<?= h($user['username']) ?>"
                maxlength="100"
                required
                autocomplete="off"
            >
        </div>

        <div class="form-group">
            <label for="password">新しいパスワード</label>

            <input
                type="password"
                id="password"
                name="password"
                minlength="8"
                autocomplete="new-password"
            >

            <div class="help">
                パスワードを変更する場合のみ入力してください。<br>
                空欄の場合、現在のパスワードは変更されません。<br>
                変更する場合は8文字以上で入力してください。
            </div>
        </div>

        <div class="form-group">
            <label for="role_title">役職</label>

            <select
                id="role_title"
                name="role_title"
                required
            >
                <?php foreach ($roleTitles as $roleTitle): ?>
                    <option
                        value="<?= h($roleTitle) ?>"
                        <?= $user['role_title'] === $roleTitle ? 'selected' : '' ?>
                    >
                        <?= h($roleTitle) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="manager_id">上司</label>

            <select id="manager_id" name="manager_id">
                <option value="">上司を設定しない</option>

                <?php foreach ($managers as $manager): ?>
                    <option
                        value="<?= (int) $manager['id'] ?>"
                        <?= (int) ($user['manager_id'] ?? 0) === (int) $manager['id'] ? 'selected' : '' ?>
                    >
                        <?= h($manager['username']) ?>
                        （<?= h($manager['role_title']) ?>）
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="help">
                自分自身および自分の配下のユーザーは、上司に指定できません。
            </div>
        </div>

        <div class="form-group">
            <label class="checkbox-label" for="is_admin">
                <input
                    type="checkbox"
                    id="is_admin"
                    name="is_admin"
                    value="1"
                    <?= (int) $user['is_admin'] === 1 ? 'checked' : '' ?>
                >
                管理者権限を付与する
            </label>

            <div class="help">
                管理者権限を外すと、ユーザー管理機能を利用できなくなります。
                最後の管理者から権限を外すことはできません。
            </div>
        </div>

        <div class="buttons">

            <button
                type="submit"
                class="button submit-button"
            >
                更新する
            </button>

            <a
                href="users.php"
                class="button back-button"
            >
                キャンセル
            </a>

        </div>

    </form>

</div>

</body>
</html>
