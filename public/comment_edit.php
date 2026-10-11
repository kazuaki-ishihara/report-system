<?php

require_once '../config/database.php';
require_once '../lib/functions.php';

// コメントIDを取得
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id <= 0) {
    http_response_code(400);
    exit('コメントIDが正しくありません。');
}

// コメントを取得
$stmt = $pdo->prepare("
    SELECT
        c.id,
        c.report_id,
        c.field_name,
        c.comment,
        c.created_at,
        c.updated_at
    FROM comments c
    INNER JOIN reports r ON r.id = c.report_id
    WHERE c.id = :id
");

$stmt->execute([
    ':id' => $id
]);

$comment = $stmt->fetch();

if (!$comment) {
    http_response_code(404);
    exit('指定されたコメントが見つかりません。');
}

// 項目名
$fields = [
    'report_date' => '日付',
    'name'        => '氏名',
    'subject'     => '件名',
    'content'     => '内容',
    'issues'      => '課題',
    'remarks'     => '備考'
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
