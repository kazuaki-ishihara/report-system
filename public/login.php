<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/functions.php';

// セッションを開始
startSession();

// ログイン済みの場合は一覧画面へ移動
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$errorMessage = '';
$username = '';

// POSTの場合はログイン処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {

        $errorMessage = 'ユーザー名とパスワードを入力してください。';

    } else {

        // ユーザー名からユーザー情報を取得
        $stmt = $pdo->prepare(
            'SELECT
                id,
                username,
                password_hash
             FROM users
             WHERE username = :username'
        );

        $stmt->execute([
            ':username' => $username,
        ]);

        $user = $stmt->fetch();

        // パスワードを照合
        if (
            $user !== false
            && password_verify($password, $user['password_hash'])
        ) {

            // セッションIDを再生成
            if (!session_regenerate_id(true)) {
                throw new RuntimeException(
                    'セッションIDの再生成に失敗しました。'
                );
            }

            // ログインユーザーIDをセッションに保存
            $_SESSION['user_id'] = (int) $user['id'];

            // レポート一覧へ移動
            header('Location: index.php');
            exit;

        } else {

            // ユーザー名とパスワードのどちらが違うかは表示しない
            $errorMessage = 'ユーザー名またはパスワードが正しくありません。';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>ログイン | レポート管理システム</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #f3f4f6;
            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
            color: #333;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            margin: 20px;
            padding: 35px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.08);
        }

        h1 {
            margin: 0 0 10px;
            text-align: center;
            font-size: 24px;
        }

        .description {
            margin-bottom: 28px;
            text-align: center;
            color: #666;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
            font-size: 14px;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 16px;
        }

        input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
        }

        .error-message {
            margin-bottom: 20px;
            padding: 12px;
            border: 1px solid #f5c2c7;
            border-radius: 5px;
            background: #f8d7da;
            color: #842029;
            font-size: 14px;
        }

        button {
            width: 100%;
            padding: 13px;
            border: none;
            border-radius: 5px;
            background: #2563eb;
            color: #fff;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #1d4ed8;
        }
    </style>
</head>

<body>

<div class="login-container">

    <h1>レポート管理システム</h1>

    <p class="description">
        ユーザー名とパスワードを入力してください。
    </p>

    <?php if ($errorMessage !== ''): ?>
        <div class="error-message" role="alert">
            <?= h($errorMessage) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="login.php">

        <div class="form-group">
            <label for="username">ユーザー名</label>

            <input
                type="text"
                id="username"
                name="username"
                value="<?= h($username) ?>"
                required
                maxlength="50"
                autocomplete="username"
                autofocus
            >
        </div>

        <div class="form-group">
            <label for="password">パスワード</label>

            <input
                type="password"
                id="password"
                name="password"
                required
                autocomplete="current-password"
            >
        </div>

        <button type="submit">ログイン</button>

    </form>

</div>

</body>
</html>
