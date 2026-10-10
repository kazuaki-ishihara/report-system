<?php

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: create.php');
    exit;
}


// 入力値を取得
$reportDate = $_POST['report_date'] ?? '';
$name       = $_POST['name'] ?? '';
$subject    = $_POST['subject'] ?? '';
$content    = $_POST['content'] ?? '';
$issues     = $_POST['issues'] ?? '';
$remarks    = $_POST['remarks'] ?? '';


// 簡単な入力チェック
if ($reportDate === '' || $name === '' || $subject === '') {

    exit('必須項目が入力されていません。');

}


// 現在日時
$now = date('Y-m-d H:i:s');


// SQLiteへ登録
$stmt = $pdo->prepare("
    INSERT INTO reports (
        report_date,
        name,
        subject,
        content,
        issues,
        remarks,
        created_at,
        updated_at
    )
    VALUES (
        :report_date,
        :name,
        :subject,
        :content,
        :issues,
        :remarks,
        :created_at,
        :updated_at
    )
");


$stmt->execute([
    ':report_date' => $reportDate,
    ':name'       => $name,
    ':subject'    => $subject,
    ':content'    => $content,
    ':issues'     => $issues,
    ':remarks'    => $remarks,
    ':created_at' => $now,
    ':updated_at' => $now
]);


// 登録完了後、一覧へ戻る
header('Location: index.php');
exit;

