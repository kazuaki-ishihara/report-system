<?php

require_once '../config/database.php';

// POST以外のアクセスを拒否
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('許可されていないアクセスです。');
}

// コメントIDを取得
$commentId = filter_input(
    INPUT_POST,
    'comment_id',
    FILTER_VALIDATE_INT
);

// コメント本文を取得
$commentText = trim($_POST['comment'] ?? '');

// 入力チェック
if (!$commentId || $commentId <= 0) {
    http_response_code(400);
    exit('コメントIDが正しくありません。');
}

// コメントが未入力の場合
if ($commentText === '') {
    http_response_code(400);
    exit('コメントを入力してください。');
}

// コメントの長さをチェック（15,000バイト以内）
if (strlen($commentText) > 15000) {
    http_response_code(400);
    exit('コメントが長すぎます。15,000バイト以内で入力してください。');
}

// 編集対象のコメントを取得
$stmt = $pdo->prepare("
    SELECT id, report_id, field_name
    FROM comments
    WHERE id = :id
");

$stmt->execute([
    ':id' => $commentId
]);

$comment = $stmt->fetch();

// コメントが存在しない場合
if (!$comment) {
    http_response_code(404);
    exit('指定されたコメントが見つかりません。');
}

// 更新日時
$now = date('Y-m-d H:i:s');

// コメントを更新
$stmt = $pdo->prepare("
    UPDATE comments
    SET
        comment = :comment,
        updated_at = :updated_at
    WHERE id = :id
");

$stmt->execute([
    ':comment'    => $commentText,
    ':updated_at' => $now,
    ':id'         => $commentId
]);

// 詳細画面に戻り、該当項目の吹き出しを開く
header(
    'Location: detail.php?id=' .
    $comment['report_id'] .
    '#comments-' .
    $comment['field_name']
);

exit;
