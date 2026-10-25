<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';

// ========================================
// ログインチェック
// ========================================

// 未ログインの場合はログイン画面へ移動
requireLogin();


// ========================================
// POSTチェック
// ========================================

// POST以外でアクセスされた場合は登録画面へ戻る
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: create.php');
    exit;
}


// ========================================
// ログインユーザーID取得
// ========================================

$userId = getLoginUserId();

// ログインユーザーIDを取得できない場合
if ($userId === null) {
    header('Location: ../login.php');
    exit;
}


// ========================================
// 入力値を取得
// ========================================

// 配列などの不正な値が送信された場合に備え、
// 文字列以外は空文字として扱う
$getPostString = static function (string $key): string {
    $value = $_POST[$key] ?? '';

    return is_string($value) ? $value : '';
};

$reportDate = $getPostString('report_date');
$name       = $getPostString('name');
$subject    = $getPostString('subject');
$content    = $getPostString('content');
$issues     = $getPostString('issues');
$remarks    = $getPostString('remarks');


// ========================================
// 入力チェック
// ========================================

// 必須項目のチェック
if (
    trim($reportDate) === ''
    || trim($name) === ''
    || trim($subject) === ''
) {
    http_response_code(400);
    exit('必須項目が入力されていません。');
}


// ========================================
// 日付チェック
// ========================================

// YYYY-MM-DD形式であることを確認
$date = DateTimeImmutable::createFromFormat(
    '!Y-m-d',
    $reportDate
);

// 存在しない日付や不正な形式を拒否
if (
    $date === false
    || $date->format('Y-m-d') !== $reportDate
) {
    http_response_code(400);
    exit('日付の形式が正しくありません。');
}


// ========================================
// 登録日時
// ========================================

$now = date('Y-m-d H:i:s');


// ========================================
// SQLiteへ登録
// ========================================

// ログインユーザーIDを user_id に保存する
$stmt = $pdo->prepare("
    INSERT INTO reports (
        user_id,
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
        :user_id,
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


// SQL実行
$stmt->execute([
    ':user_id'     => $userId,
    ':report_date' => $reportDate,
    ':name'        => $name,
    ':subject'     => $subject,
    ':content'     => $content,
    ':issues'      => $issues,
    ':remarks'     => $remarks,
    ':created_at'  => $now,
    ':updated_at'  => $now,
]);


// ========================================
// 登録完了後、一覧へ戻る
// ========================================

header('Location: ../index.php');
exit;
