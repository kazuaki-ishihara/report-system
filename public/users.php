<?php

declare(strict_types=1);

require_once '../config/database.php';
require_once '../lib/functions.php';
require_once '../lib/auth.php';

// 管理者以外は利用不可
requireAdmin($pdo);

// セッション開始
startSession();

// CSRF対策用トークン
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

// ログインユーザーID
$loginUserId = getLoginUserId();

// メッセージ
$message = trim($_GET['message'] ?? '');
$error = trim($_GET['error'] ?? '');

// 管理者数
$adminStmt = $pdo->query(
    'SELECT COUNT(*) FROM users WHERE is_admin = 1'
);
$adminCount = (int) $adminStmt->fetchColumn();

// ユーザー一覧
$sql = "
    SELECT
        u.id,
        u.username,
        u.role_title,
        u.manager_id,
        u.is_admin,
        u.created_at,
        m.username AS manager_name,
        (
            SELECT COUNT(*)
            FROM reports r
            WHERE r.user_id = u.id
        ) AS report_count,
        (
            SELECT COUNT(*)
            FROM comments c
            WHERE c.user_id = u.id
        ) AS comment_count
    FROM users u
    LEFT JOIN users m
        ON u.manager_id = m.id
    ORDER BY
        u.id ASC
";

$stmt = $pdo->query($sql);
$users = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>ユーザー管理</title>

    <link rel="stylesheet" href="css/style.css">

    <style>
        .user-management-container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 24px;
            background: #fff;
            border-radius: 8px;
            box-sizing: border-box;
        }

        .page-title {
            margin-bottom: 24px;
        }

        .toolbar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .action-button {
            display: inline-block;
            padding: 7px 14px;
            border: 1px solid transparent;
            border-radius: 5px;
            font-size: 14px;
            font-family: inherit;
            line-height: 1.4;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
            box-sizing: border-box;
        }

        .primary-button {
            background: #1976d2;
            color: #fff;
        }

        .primary-button:hover {
            background: #1565c0;
        }

        .edit-button {
            background: #2e7d32;
            color: #fff;
        }

        .edit-button:hover {
            background: #1b5e20;
        }

        .delete-button {
            background: #fff;
            color: #d32f2f;
            border-color: #d32f2f;
        }

        .delete-button:hover {
            background: #ffebee;
        }

        .back-button {
            display: inline-block;
            padding: 7px 14px;
            border: 1px solid #777;
            border-radius: 5px;
            color: #333;
            text-decoration: none;
            background: #fff;
        }

        .delete-form {
            display: inline;
            margin: 0;
        }

        .message {
            padding: 12px 16px;
            margin-bottom: 20px;
            border-radius: 5px;
            background: #e8f5e9;
            color: #1b5e20;
        }

        .error-message {
            padding: 12px 16px;
            margin-bottom: 20px;
            border-radius: 5px;
            background: #ffebee;
            color: #b71c1c;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .user-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .user-table th,
        .user-table td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
            vertical-align: middle;
        }

        .user-table th {
            background: #f2f4f7;
            white-space: nowrap;
        }

        .user-table td.actions {
            white-space: nowrap;
        }

        .admin-label {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            background: #e3f2fd;
            color: #0d47a1;
            font-size: 12px;
        }

        .normal-label {
            color: #666;
        }

        .cannot-delete {
            color: #888;
            font-size: 12px;
            white-space: normal;
        }

        .notice {
            margin-top: 20px;
            padding: 12px;
            background: #fff8e1;
            border-left: 4px solid #ffb300;
        }

        @media (max-width: 700px) {
            .user-management-container {
                margin: 10px;
                padding: 12px;
            }
        }
    </style>
</head>

<body>

<div class="user-management-container">

    <h1 class="page-title">ユーザー管理</h1>

    <?php if ($message !== ''): ?>
        <div class="message">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error-message">
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <div class="toolbar">

        <a
            href="user_create.php"
            class="action-button primary-button"
        >
            新規ユーザー登録
        </a>

        <a
            href="index.php"
            class="back-button"
        >
            レポート一覧へ戻る
        </a>

    </div>

    <p>
        登録ユーザー数：<?= count($users) ?>人
    </p>

    <div class="table-wrapper">

        <table class="user-table">

            <thead>
                <tr>
                    <th>ID</th>
                    <th>ユーザー名</th>
                    <th>役職</th>
                    <th>上司</th>
                    <th>管理者</th>
                    <th>報告書数</th>
                    <th>コメント数</th>
                    <th>作成日時</th>
                    <th>操作</th>
                </tr>
            </thead>

            <tbody>

            <?php if (count($users) === 0): ?>

                <tr>
                    <td colspan="9">
                        登録ユーザーはいません。
                    </td>
                </tr>

            <?php else: ?>

                <?php foreach ($users as $user): ?>

                    <?php
                    $userId = (int) $user['id'];
                    $isSelf = $userId === $loginUserId;
                    $isAdmin = (int) $user['is_admin'] === 1;

                    $reportCount = (int) $user['report_count'];
                    $commentCount = (int) $user['comment_count'];

                    $hasRelatedData =
                        $reportCount > 0 || $commentCount > 0;

                    $isLastAdmin =
                        $isAdmin && $adminCount <= 1;

                    $canDelete =
                        !$isSelf
                        && !$hasRelatedData
                        && !$isLastAdmin;
                    ?>

                    <tr>

                        <td>
                            <?= h($user['id']) ?>
                        </td>

                        <td>
                            <?= h($user['username']) ?>

                            <?php if ($isSelf): ?>
                                <br>
                                <small>（ログイン中）</small>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= h($user['role_title']) ?>
                        </td>

                        <td>
                            <?php if ($user['manager_name'] !== null): ?>
                                <?= h($user['manager_name']) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($isAdmin): ?>
                                <span class="admin-label">
                                    管理者
                                </span>
                            <?php else: ?>
                                <span class="normal-label">
                                    一般
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= $reportCount ?>
                        </td>

                        <td>
                            <?= $commentCount ?>
                        </td>

                        <td>
                            <?= h($user['created_at']) ?>
                        </td>

                        <td class="actions">

                            <a
                                href="user_edit.php?id=<?= $userId ?>"
                                class="action-button edit-button"
                            >
                                編集
                            </a>

                            <?php if ($canDelete): ?>

                                <form
                                    action="user_delete.php"
                                    method="post"
                                    class="delete-form"
                                    onsubmit="return confirm(
                                        'このユーザーを削除しますか？'
                                    );"
                                >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= $userId ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= h($csrfToken) ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="action-button delete-button"
                                    >
                                        削除
                                    </button>

                                </form>

                            <?php else: ?>

                                <br>

                                <span class="cannot-delete">

                                    <?php if ($isSelf): ?>

                                        自分自身は削除できません

                                    <?php elseif ($hasRelatedData): ?>

                                        関連データがあるため削除不可

                                    <?php elseif ($isLastAdmin): ?>

                                        最後の管理者のため削除不可

                                    <?php endif; ?>

                                </span>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

    <div class="notice">
        <strong>削除について</strong>
        <p>
            報告書またはコメントが登録されているユーザーは削除できません。
            また、ログイン中のユーザー自身と最後の管理者も削除できません。
        </p>
        <p>
            削除処理側でも同じ条件を再確認するため、
            URLやリクエストを直接操作しても制約を回避できないようにします。
        </p>
    </div>

</div>

</body>
</html>
