<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/report_fields.php';

// ==================================================
// 項目管理：項目の更新処理（管理者のみ）
// ==================================================

$loginUserId = requireReportFieldAdmin($pdo);
requireReportFieldTables($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

verifyCsrfToken();

$id = postId('id');
$field = findReportField($pdo, $id);

if ($field === null) {
    http_response_code(404);
    exit('指定された項目が見つかりません。');
}

// ==================================================
// 入力チェック（固定項目は表示名だけを受け付ける）
// ==================================================

list($values, $error) = readReportFieldForm($field);

if ($error !== null) {
    // 入力した値を入力欄に戻す
    rememberFormInput('report_field_edit_' . $id, postedReportFieldForm());
    redirectWithMessage('edit.php?id=' . $id, 'error', $error);
}

// ==================================================
// 更新（種類・内部コード・固定の区分・表示状態・並び順は変更しない）
// ==================================================

try {
    $stmt = $pdo->prepare(
        'UPDATE report_fields
         SET
            label = :label,
            is_required = :is_required,
            show_in_list = :show_in_list,
            is_searchable = :is_searchable,
            max_length = :max_length,
            min_value = :min_value,
            max_value = :max_value,
            allow_decimal = :allow_decimal,
            updated_at = :updated_at,
            updated_by = :updated_by
         WHERE id = :id'
    );

    $stmt->execute([
        ':label'         => $values['label'],
        ':is_required'   => $values['is_required'],
        ':show_in_list'  => $values['show_in_list'],
        ':is_searchable' => $values['is_searchable'],
        ':max_length'    => $values['max_length'],
        ':min_value'     => $values['min_value'],
        ':max_value'     => $values['max_value'],
        ':allow_decimal' => $values['allow_decimal'],
        ':updated_at'    => date('Y-m-d H:i:s'),
        ':updated_by'    => $loginUserId,
        ':id'            => $id,
    ]);
} catch (PDOException $e) {
    error_log('項目の更新エラー: ' . $e->getMessage());

    rememberFormInput('report_field_edit_' . $id, postedReportFieldForm());
    redirectWithMessage('edit.php?id=' . $id, 'error', '項目を更新できませんでした。');
}

redirectWithMessage('index.php', 'message', '項目「' . $values['label'] . '」を更新しました。');
