<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/functions.php';

// POSTリクエスト以外は受け付けない
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// 入力値を取得
$reportId = filter_input(INPUT_POST, 'report_id', FILTER_VALIDATE_INT);
$fieldName = $_POST['field_name'] ?? '';
$commentText = trim($_POST['comment'] ?? '');

// コメントを登録できる項目
$allowedFields = [
    'report_date',
    'name',
    'subject',
    'content',
    'issues',
    'remarks'
];

// 入力チェック
if (
    !$reportId ||
    $reportId <= 0 ||
    !in_array($fieldName, $allowedFields, true) ||
    $commentText === ''
) {
    exit('入力内容が正しくありません。コメントを入力してください。');
}

// 対象のレポートが存在するか確認
$checkStmt = $pdo->prepare(
    'SELECT id FROM reports WHERE id = :id'
);
$checkStmt->execute([':id' => $reportId]);

if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
    http_response_code(404);
    exit('対象のレポートが見つかりません。');
}

// 登録日時
$now = date('Y-m-d H:i:s');

// コメントを登録
$sql = "
    INSERT INTO comments (
        report_id,
        field_name,
        comment,
        created_at,
        updated_at
    ) VALUES (
        :report_id,
        :field_name,
        :comment,
        :created_at,
        :updated_at
    )
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':report_id' => $reportId,
    ':field_name' => $fieldName,
    ':comment' => $commentText,
    ':created_at' => $now,
    ':updated_at' => $now
]);

// 登録後、詳細画面の該当項目へ戻る
header(
    'Location: detail.php?id=' . $reportId
    . '#comments-' . rawurlencode($fieldName)
);
exit;
