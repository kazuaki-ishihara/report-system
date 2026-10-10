<?php

require_once '../config/database.php';


// POST以外は編集画面へ戻す
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}


// ID
$id = filter_input(
    INPUT_POST,
    'id',
    FILTER_VALIDATE_INT
);


// 入力値
$reportDate = $_POST['report_date'] ?? '';
$name       = $_POST['name'] ?? '';
$subject    = $_POST['subject'] ?? '';
$content    = $_POST['content'] ?? '';
$issues     = $_POST['issues'] ?? '';
$remarks    = $_POST['remarks'] ?? '';


// 入力チェック
if (
    !$id ||
    $reportDate === '' ||
    $name === '' ||
    $subject === ''
) {
    exit('必須項目が入力されていません。');
}


// 更新日時
$now = date('Y-m-d H:i:s');


// UPDATE
$stmt = $pdo->prepare("
    UPDATE reports
    SET
        report_date = :report_date,
        name        = :name,
        subject     = :subject,
        content     = :content,
        issues      = :issues,
        remarks     = :remarks,
        updated_at  = :updated_at
    WHERE id = :id
");


$stmt->execute([
    ':report_date' => $reportDate,
    ':name'       => $name,
    ':subject'    => $subject,
    ':content'    => $content,
    ':issues'     => $issues,
    ':remarks'    => $remarks,
    ':updated_at' => $now,
    ':id'         => $id
]);


// 一覧へ戻る
header('Location: index.php');
exit;

