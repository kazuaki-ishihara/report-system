<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/report_fields.php';

// ==================================================
// 項目管理：選択肢の名前の変更（管理者のみ）
// ==================================================

$loginUserId = requireReportFieldAdmin($pdo);
requireReportFieldTables($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

verifyCsrfToken();

$id = postId('id');
$option = findReportFieldOption($pdo, $id);

if ($option === null) {
    http_response_code(404);
    exit('指定された選択肢が見つかりません。');
}

$fieldId = (int) $option['field_id'];
$backTo = 'options.php?field_id=' . $fieldId;

// ==================================================
// 入力チェック
// ==================================================

$label = trim(postString('label'));
$error = validateReportFieldLabel($label, '選択肢の名前');

if ($error !== null) {
    redirectWithMessage($backTo, 'error', $error);
}

if ($label === $option['label']) {
    redirectWithMessage($backTo, 'message', '変更はありませんでした。');
}

// ==================================================
// 更新（同じ項目の中で同じ名前があれば変更しない）
// ==================================================

try {
    $pdo->beginTransaction();

    $check = $pdo->prepare(
        'SELECT COUNT(*)
         FROM report_field_options
         WHERE field_id = :field_id AND label = :label AND id <> :id'
    );
    $check->execute([':field_id' => $fieldId, ':label' => $label, ':id' => $id]);

    if ((int) $check->fetchColumn() > 0) {
        $pdo->rollBack();

        redirectWithMessage($backTo, 'error', '同じ名前の選択肢がすでにあります（非表示のものを含む）。');
    }

    $update = $pdo->prepare(
        'UPDATE report_field_options
         SET label = :label, updated_at = :updated_at, updated_by = :updated_by
         WHERE id = :id'
    );
    $update->execute([
        ':label'      => $label,
        ':updated_at' => date('Y-m-d H:i:s'),
        ':updated_by' => $loginUserId,
        ':id'         => $id,
    ]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('選択肢の名前変更エラー: ' . $e->getMessage());

    redirectWithMessage($backTo, 'error', '選択肢の名前を変更できませんでした。');
}

redirectWithMessage($backTo, 'message', '選択肢の名前を「' . $label . '」に変更しました。');
