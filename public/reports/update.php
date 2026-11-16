<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/report_values.php';

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
// 6. 入力チェック（項目管理の定義に従う。登録・更新で共通）
// ==================================================

$fields = loadReportFields($pdo, true);

// 今の値（非表示になった選択肢を選んだままにできるようにするため）
$currentValues = loadReportValues($pdo, $report, $fields);

list($values, $errors) = readReportFormInput($fields, $currentValues);

if (count($errors) > 0) {
    http_response_code(400);
    exit(
        '入力内容を確認してください。<br>' .
        implode('<br>', array_map('h', $errors)) .
        '<br><br>ブラウザの「戻る」で入力画面に戻ってください。'
    );
}

// 非表示の項目は今の値を残す
$columns = buildReportColumnValues($fields, $values, $report);

// ==================================================
// 7. レポートを更新
// ==================================================

$now = date('Y-m-d H:i:s');

try {
    $pdo->beginTransaction();

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
        ':report_date' => $columns['report_date'],
        ':name'        => $columns['name'],
        ':subject'     => $columns['subject'],
        ':content'     => $columns['content'],
        ':issues'      => $columns['issues'],
        ':remarks'     => $columns['remarks'],
        ':updated_at'  => $now,
        ':id'          => $id,
    ]);

    // 項目管理で追加した項目の値（非表示の項目の値は残す）
    saveAddedFieldValues($pdo, $id, $fields, $values, $now);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('レポート更新エラー: ' . $e->getMessage());

    http_response_code(500);
    exit('レポートを更新できませんでした。');
}

// ==================================================
// 8. 更新完了後、一覧画面へ戻る
// ==================================================

header('Location: ../index.php');
exit;
