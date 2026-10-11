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

// レポートIDを取得
$reportId = filter_input(
    INPUT_POST,
    'report_id',
    FILTER_VALIDATE_INT
);

// 項目名を取得
$fieldName = $_POST['field_name'] ?? '';

// 入力チェック
if (!$commentId || $commentId <= 0) {
    http_response_code(400);
    exit('コメントIDが正しくありません。');
}

if (!$reportId || $reportId <= 0) {
    http_response_code(400);
    exit('レポートIDが正しくありません。');
}

// 許可する項目名
$allowedFields = [
    'report_date',
    'name',
    'subject',
    'content',
    'issues',
    'remarks'
];

if (!in_array($fieldName, $allowedFields, true)) {
    http_response_code(400);
    exit('コメント対象の項目が正しくありません。');
}

// 指定したレポート・項目に属するコメントか確認
$stmt = $pdo->prepare("
    SELECT id
    FROM comments
    WHERE id = :comment_id
      AND report_id = :report_id
      AND field_name = :field_name
");

$stmt->execute([
    ':comment_id' => $commentId,
    ':report_id'  => $reportId,
    ':field_name' => $fieldName
]);

$comment = $stmt->fetch();

if (!$comment) {
    http_response_code(404);
    exit('指定されたコメントが見つかりません。');
}

// コメントを削除
$stmt = $pdo->prepare("
    DELETE FROM comments
    WHERE id = :comment_id
      AND report_id = :report_id
      AND field_name = :field_name
");

$stmt->execute([
    ':comment_id' => $commentId,
    ':report_id'  => $reportId,
    ':field_name' => $fieldName
]);

// 詳細画面に戻り、該当項目の吹き出しを開く
header(
    'Location: detail.php?id=' .
    $reportId .
    '#comments-' .
    $fieldName
);

exit;
