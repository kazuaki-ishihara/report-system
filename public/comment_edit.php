<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

// ==================================================
// 1. ログイン確認
// ==================================================

requireLogin();

$loginUser = getLoginUser($pdo);

if ($loginUser === null) {
    header('Location: login.php');
    exit;
}

$loginUserId = (int) $loginUser['id'];
$isAdmin = (int) $loginUser['is_admin'] === 1;

// ==================================================
// 2. コメントIDの取得・チェック
// ==================================================

$commentId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (
    $commentId === false
    || $commentId === null
    || $commentId <= 0
) {
    http_response_code(400);
    exit('コメントIDが正しくありません。');
}

$commentId = (int) $commentId;

// ==================================================
// 3. コメントと対象レポートを取得
// ==================================================

$sql = '
    SELECT
        c.id,
        c.report_id,
        c.user_id AS comment_user_id,
        c.field_name,
        c.comment,
        c.created_at,
        c.updated_at,
        r.user_id AS report_user_id
    FROM comments c
    INNER JOIN reports r
        ON r.id = c.report_id
    WHERE c.id = :id
';

$stmt = $pdo->prepare($sql);

$stmt->execute([
    ':id' => $commentId,
]);

$comment = $stmt->fetch(PDO::FETCH_ASSOC);

if ($comment === false) {
    http_response_code(404);
    exit('指定されたコメントが見つかりません。');
}

// ==================================================
// 4. レポートの閲覧権限を確認
//
// システム管理者：すべてのレポート
// 一般ユーザー：自分と配下のユーザーのレポート
// ==================================================

if (!$isAdmin) {
    $sql = '
        WITH RECURSIVE subordinate_users(id) AS (
            SELECT id
            FROM users
            WHERE id = :user_id

            UNION

            SELECT u.id
            FROM users u
            INNER JOIN subordinate_users s
                ON u.manager_id = s.id
        )
        SELECT id
        FROM subordinate_users
    ';

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':user_id' => $loginUserId,
    ]);

    $allowedUserIds = array_map(
        'intval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );

    $reportUserId = (int) $comment['report_user_id'];

    if (!in_array($reportUserId, $allowedUserIds, true)) {
        http_response_code(403);
        exit('このレポートのコメントを編集する権限がありません。');
    }
}

// ==================================================
// 5. コメント編集権限を確認
//
// 自分が登録したコメントのみ編集可能。
// システム管理者はすべてのコメントを編集可能。
// ==================================================

$commentUserId = (int) $comment['comment_user_id'];

if (!$isAdmin && $commentUserId !== $loginUserId) {
    http_response_code(403);
    exit('このコメントを編集する権限がありません。');
}

// ==================================================
// 6. コメント対象の項目名
// ==================================================

$fields = [
    'report_date' => '日付',
    'name'        => '氏名',
    'subject'     => '件名',
    'content'     => '内容',
    'issues'      => '課題',
    'remarks'     => '備考',
];

$fieldName = $comment['field_name'];

if (!isset($fields[$fieldName])) {
    http_response_code(400);
    exit('コメント対象の項目が正しくありません。');
}

$label = $fields[$fieldName];

?>

<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>コメント編集</title>

    <link rel="stylesheet" href="css/style.css">

    <style>
        .comment-edit-info {
            margin-bottom: 20px;
            color: #555;
        }

        .comment-edit-textarea {
            display: block;
            width: 100%;
            min-height: 180px;
            padding: 10px;
            box-sizing: border-box;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 15px;
            font-family: inherit;
            resize: vertical;
        }

        .comment-edit-textarea:focus {
            outline: none;
            border-color: #1976d2;
            box-shadow: 0 0 0 2px rgba(25, 118, 210, 0.12);
        }

        .comment-edit-actions {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }

        .comment-save-button {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            background-color: #1976d2;
            color: #fff;
            cursor: pointer;
        }

        .comment-save-button:hover {
            background-color: #1565c0;
        }
    </style>
</head>

<body>

<div class="container">

    <h1>コメント編集</h1>

    <div class="form-container">

        <p class="comment-edit-info">
            対象項目：<?= h($label) ?>
        </p>

        <form action="comment_update.php" method="post">

            <input
                type="hidden"
                name="comment_id"
                value="<?= h($comment['id']) ?>"
            >

            <label for="comment">
                コメント
            </label>

            <textarea
                id="comment"
                name="comment"
                class="comment-edit-textarea"
                required
                maxlength="5000"
            ><?= h($comment['comment']) ?></textarea>

            <div class="comment-edit-actions">

                <button
                    type="submit"
                    class="comment-save-button"
                >
                    保存
                </button>

                <a
                    href="detail.php?id=<?= h($comment['report_id']) ?>#comments-<?= h($fieldName) ?>"
                    class="back-button"
                >
                    キャンセル
                </a>

            </div>

        </form>

    </div>

</div>

</body>
</html>
