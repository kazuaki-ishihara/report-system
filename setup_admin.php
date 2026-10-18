<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

// すでにユーザーが存在する場合は登録しない
$stmt = $pdo->query('SELECT COUNT(*) FROM users');
$userCount = (int) $stmt->fetchColumn();

if ($userCount > 0) {
    exit(
        'すでにユーザーが登録されています。' .
        '初期管理者の登録は実行できません。'
    );
}

// POST以外では登録処理を実行しない
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>初期管理者登録</title>
        <style>
            body {
                font-family: sans-serif;
                max-width: 500px;
                margin: 50px auto;
                padding: 20px;
                line-height: 1.8;
            }

            label {
                display: block;
                margin-top: 15px;
            }

            input {
                box-sizing: border-box;
                width: 100%;
                padding: 10px;
                margin-top: 5px;
            }

            button {
                margin-top: 20px;
                padding: 10px 20px;
                cursor: pointer;
            }
        </style>
    </head>
    <body>

    <h1>初期管理者登録</h1>

    <p>
        最初のシステム管理者アカウントを作成します。
    </p>

    <form method="post">
        <label for="username">ユーザー名</label>
        <input
            type="text"
            id="username"
            name="username"
            required
            maxlength="50"
            autocomplete="username"
        >

        <label for="password">パスワード</label>
        <input
            type="password"
            id="password"
            name="password"
            required
            minlength="12"
            autocomplete="new-password"
        >

        <label for="password_confirm">パスワード（確認用）</label>
        <input
            type="password"
            id="password_confirm"
            name="password_confirm"
            required
            minlength="12"
            autocomplete="new-password"
        >

        <button type="submit">管理者を登録する</button>
    </form>

    </body>
    </html>
    <?php
    exit;
}

// 入力値を取得
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$passwordConfirm = $_POST['password_confirm'] ?? '';

// 入力チェック
if ($username === '' || strlen($username) > 50) {
    exit('ユーザー名を1～50バイトで入力してください。');
}

if (preg_match('/[\x00-\x1F\x7F]/', $username)) {
    exit('ユーザー名に制御文字は使用できません。');
}

if (strlen($password) < 12) {
    exit('パスワードは12文字以上にしてください。');
}

if ($password !== $passwordConfirm) {
    exit('パスワードが一致しません。');
}

// パスワードをハッシュ化
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

try {
    $pdo->beginTransaction();

    // 登録直前にもユーザーが存在しないことを確認
    $stmt = $pdo->query('SELECT COUNT(*) FROM users');
    $userCount = (int) $stmt->fetchColumn();

    if ($userCount > 0) {
        $pdo->rollBack();
        exit('すでにユーザーが登録されています。');
    }

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
            NULL,
            1,
            :created_at
        )'
    );

    $stmt->execute([
        ':username' => $username,
        ':password_hash' => $passwordHash,
        ':role_title' => '部長',
        ':created_at' => date('Y-m-d H:i:s'),
    ]);

    $pdo->commit();

    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <title>管理者登録完了</title>
    </head>
    <body>
        <h1>管理者登録が完了しました</h1>

        <p>ユーザー名：<?= htmlspecialchars(
            $username,
            ENT_QUOTES,
            'UTF-8'
        ) ?></p>

        <p>
            システム管理者アカウントを作成しました。
        </p>

        <p>
            セキュリティのため、
            <strong>setup_admin.php を直ちに削除してください。</strong>
        </p>
    </body>
    </html>
    <?php

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());

    http_response_code(500);
    exit('管理者の登録に失敗しました。サーバーのエラーログを確認してください。');
}
