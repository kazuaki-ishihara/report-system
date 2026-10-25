<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';

// ==================================================
// 1. POSTリクエストの確認
// ==================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

// ==================================================
// 2. ログイン確認
// ==================================================

requireLogin();

$loginUser = getLoginUser($pdo);

if ($loginUser === null) {
    header('Location: ../login.php');
    exit;
}

$loginUserId = (int) $loginUser['id'];
$isAdmin = (int) $loginUser['is_admin'] === 1;

// ==================================================
// 3. レポートIDの取得・チェック
// ==================================================

$id = filter_input(
    INPUT_POST,
    'id',
    FILTER_VALIDATE_INT
);

if (
    $id === false
    || $id === null
    || $id <= 0
) {
    http_response_code(400);
    exit('レポートIDが正しくありません。');
}

$id = (int) $id;

// ==================================================
// 4. 更新対象のレポートを取得
// ==================================================

$stmt = $pdo->prepare(
    'SELECT *
     FROM reports
     WHERE id = :id'
);

$stmt->execute([
    ':id' => $id,
]);

$report = $stmt->fetch(PDO::FETCH_ASSOC);

// レポートが存在しない場合
if ($report === false) {
    http_response_code(404);
    exit('指定されたレポートが見つかりません。');
}

// ==================================================
// 5. レポートの更新権限を確認
//
// システム管理者：すべてのレポートを更新可能
// 一般ユーザー：自分のレポートのみ更新可能
// ==================================================

$reportUserId = (int) $report['user_id'];

if (!$isAdmin && $reportUserId !== $loginUserId) {
    http_response_code(403);
    exit('このレポートを更新する権限がありません。');
}

// ==================================================
// 6. POSTされた入力値を取得
// ==================================================

$reportDate = $_POST['report_date'] ?? null;
$name       = $_POST['name'] ?? null;
$subject    = $_POST['subject'] ?? null;
$content    = $_POST['content'] ?? null;
$issues     = $_POST['issues'] ?? null;
$remarks    = $_POST['remarks'] ?? null;

// ==================================================
// 7. 入力値の形式を確認
// ==================================================

// 文字列以外の値が送信された場合は拒否
$inputValues = [
    $reportDate,
    $name,
    $subject,
    $content,
    $issues,
    $remarks,
];

foreach ($inputValues as $value) {
    if (!is_string($value)) {
        http_response_code(400);
        exit('入力値が正しくありません。');
    }
}

// 必須項目の確認
$name = trim($name);
$subject = trim($subject);

if (
    $reportDate === ''
    || $name === ''
    || $subject === ''
) {
    http_response_code(400);
    exit('必須項目が入力されていません。');
}

// 日付の形式を確認（YYYY-MM-DD）
$date = DateTimeImmutable::createFromFormat(
    '!Y-m-d',
    $reportDate
);

$dateErrors = DateTimeImmutable::getLastErrors();

if (
    $date === false
    || (
        $dateErrors !== false
        && (
            $dateErrors['warning_count'] > 0
            || $dateErrors['error_count'] > 0
        )
    )
    || $date->format('Y-m-d') !== $reportDate
) {
    http_response_code(400);
    exit('日付の形式が正しくありません。');
}

// 氏名・件名の最大バイト数を確認
// 日本語の文字数ではなく、UTF-8のバイト数で判定する
if (strlen($name) > 300) {
    http_response_code(400);
    exit('氏名が長すぎます。');
}

if (strlen($subject) > 600) {
    http_response_code(400);
    exit('件名が長すぎます。');
}

// ==================================================
// 8. レポートを更新
// ==================================================

$now = date('Y-m-d H:i:s');

$stmt = $pdo->prepare(
    'UPDATE reports
     SET
        report_date = :report_date,
        name = :name,
        subject = :subject,
        content = :content,
        issues = :issues,
        remarks = :remarks,
        updated_at = :updated_at
     WHERE id = :id'
);

$stmt->execute([
    ':report_date' => $reportDate,
    ':name'        => $name,
    ':subject'     => $subject,
    ':content'     => $content,
    ':issues'      => $issues,
    ':remarks'     => $remarks,
    ':updated_at'  => $now,
    ':id'           => $id,
]);

// ==================================================
// 9. 更新完了後、一覧画面へ戻る
// ==================================================

header('Location: ../index.php');
exit;
