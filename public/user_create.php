<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

requireAdmin($pdo);
startSession();

// CSRF対策用トークン
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

// 上司候補一覧
$stmt = $pdo->query(
    'SELECT id, username, role_title
     FROM users
     ORDER BY id ASC'
);
$managers = $stmt->fetchAll();

$error = trim($_GET['error'] ?? '');

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ユーザー登録</title>

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
    </style>
</head>

<body>

<div class="container">

    <h1>新規ユーザー登録</h1>

    <?php if ($error !== ''): ?>
        <div class="error-message">
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <form action="user_store.php" method="post">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= h($csrfToken) ?>"
        >

        <div class="form-group">
            <label for="username">ユーザー名 <span>必須</span></label>
            <input
                type="text"
                id="username"
                name="username"
                maxlength="100"
                required
                autocomplete="off"
            >
            <div class="help">
                他のユーザーと重複しない名前を入力してください。
            </div>
        </div>

        <div class="form-group">
            <label for="password">初期パスワード <span>必須</span></label>
            <input
                type="password"
                id="password"
                name="password"
                minlength="8"
                required
                autocomplete="new-password"
            >
            <div class="help">
                8文字以上で設定してください。
            </div>
        </div>

        <div class="form-group">
            <label for="role_title">役職 <span>必須</span></label>
            <select id="role_title" name="role_title" required>
                <?php foreach ($roleTitles as $roleTitle): ?>
                    <option value="<?= h($roleTitle) ?>">
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
                    <option value="<?= (int) $manager['id'] ?>">
                        <?= h($manager['username']) ?>
                        （<?= h($manager['role_title']) ?>）
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="checkbox-label" for="is_admin">
                <input
                    type="checkbox"
                    id="is_admin"
                    name="is_admin"
                    value="1"
                >
                管理者権限を付与する
            </label>

            <div class="help">
                管理者にはユーザー管理機能へのアクセス権が付与されます。
            </div>
        </div>

        <div class="buttons">
            <button type="submit" class="button submit-button">
                登録する
            </button>

            <a href="users.php" class="button back-button">
                キャンセル
            </a>
        </div>

    </form>

</div>

</body>
</html>
