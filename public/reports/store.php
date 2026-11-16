<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/report_values.php';

// ========================================
// ログインチェック
// ========================================

// ログインしていない場合はログイン画面へ移動
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
// 入力チェック（項目管理の定義に従う。登録・更新で共通）
// ========================================

$fields = loadReportFields($pdo, true);

list($values, $errors) = readReportFormInput($fields);

if (count($errors) > 0) {
    http_response_code(400);
    exit(
        '入力内容を確認してください。<br>' .
        implode('<br>', array_map('h', $errors)) .
        '<br><br>ブラウザの「戻る」で入力画面に戻ってください。'
    );
}

$columns = buildReportColumnValues($fields, $values, null);


// ========================================
// 登録日時
// ========================================

$now = date('Y-m-d H:i:s');


// ========================================
// SQLiteへ登録
// ========================================

try {
    $pdo->beginTransaction();

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

    $stmt->execute([
        ':user_id'     => $userId,
        ':report_date' => $columns['report_date'],
        ':name'        => $columns['name'],
        ':subject'     => $columns['subject'],
        ':content'     => $columns['content'],
        ':issues'      => $columns['issues'],
        ':remarks'     => $columns['remarks'],
        ':created_at'  => $now,
        ':updated_at'  => $now,
    ]);

    $reportId = (int) $pdo->lastInsertId();

    // 項目管理で追加した項目の値
    saveAddedFieldValues($pdo, $reportId, $fields, $values, $now);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('レポート登録エラー: ' . $e->getMessage());

    http_response_code(500);
    exit('レポートを登録できませんでした。');
}


// ========================================
// 登録完了後、一覧へ戻る
// ========================================

header('Location: ../index.php');
exit;
