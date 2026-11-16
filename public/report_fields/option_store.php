<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/report_fields.php';

// ==================================================
// 項目管理：選択肢の追加処理（管理者のみ）
// ==================================================

$loginUserId = requireReportFieldAdmin($pdo);
requireReportFieldTables($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

verifyCsrfToken();

$fieldId = postId('field_id');
$field = findReportField($pdo, $fieldId);

if ($field === null) {
    http_response_code(404);
    exit('指定された項目が見つかりません。');
}

if (!isChoiceFieldType((string) $field['field_type'])) {
    redirectWithMessage('index.php', 'error', 'この項目は選択肢の種類ではありません。');
}

$backTo = 'options.php?field_id=' . $fieldId;

// ==================================================
// 入力チェック
// ==================================================

$label = trim(postString('label'));
$error = validateReportFieldLabel($label, '選択肢の名前');

if ($error !== null) {
    redirectWithMessage($backTo, 'error', $error);
}

// ==================================================
// 登録（同じ項目の中で同じ名前があれば登録しない）
// ==================================================

$now = date('Y-m-d H:i:s');

try {
    $pdo->beginTransaction();

    $check = $pdo->prepare(
        'SELECT is_active
         FROM report_field_options
         WHERE field_id = :field_id AND label = :label'
    );
    $check->execute([':field_id' => $fieldId, ':label' => $label]);
    $existing = $check->fetchColumn();

    if ($existing !== false) {
        $pdo->rollBack();

        redirectWithMessage(
            $backTo,
            'error',
            (int) $existing === 1
                ? '同じ名前の選択肢がすでにあります。'
                : '同じ名前の選択肢が非表示になっています。再表示してください。'
        );
    }

    $insert = $pdo->prepare(
        'INSERT INTO report_field_options (
            field_id, label, sort_order, is_active, created_at, updated_at, updated_by
         ) VALUES (
            :field_id, :label, :sort_order, 1, :created_at, :updated_at, :updated_by
         )'
    );
    $insert->execute([
        ':field_id'   => $fieldId,
        ':label'      => $label,
        ':sort_order' => nextReportFieldSortOrder($pdo, 'report_field_options', $fieldId),
        ':created_at' => $now,
        ':updated_at' => $now,
        ':updated_by' => $loginUserId,
    ]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('選択肢の追加エラー: ' . $e->getMessage());

    redirectWithMessage($backTo, 'error', '選択肢を追加できませんでした。');
}

redirectWithMessage($backTo, 'message', '選択肢「' . $label . '」を追加しました。');
