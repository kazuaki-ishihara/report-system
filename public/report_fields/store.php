<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/report_fields.php';

// ==================================================
// 項目管理：項目の追加処理（管理者のみ）
// ==================================================

$loginUserId = requireReportFieldAdmin($pdo);
requireReportFieldTables($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

verifyCsrfToken();

// ==================================================
// 入力チェック
// ==================================================

list($values, $error) = readReportFieldForm(null);

if ($error !== null) {
    // 入力した値を入力欄に戻す
    rememberFormInput('report_field_create', postedReportFieldForm());
    redirectWithMessage('create.php', 'error', $error);
}

// ==================================================
// 登録
// ==================================================

$now = date('Y-m-d H:i:s');

try {
    $pdo->beginTransaction();

    $insert = $pdo->prepare(
        'INSERT INTO report_fields (
            code, label, field_type, is_system, is_active, is_required,
            show_in_list, is_searchable, sort_order, max_length,
            min_value, max_value, allow_decimal,
            created_at, updated_at, updated_by
         ) VALUES (
            :code, :label, :field_type, 0, 1, :is_required,
            :show_in_list, :is_searchable, :sort_order, :max_length,
            :min_value, :max_value, :allow_decimal,
            :created_at, :updated_at, :updated_by
         )'
    );

    // 内部コードは登録後に ID から付けるため、いったん仮の値で登録する
    $insert->execute([
        ':code'          => 'tmp_' . bin2hex(random_bytes(8)),
        ':label'         => $values['label'],
        ':field_type'    => $values['field_type'],
        ':is_required'   => $values['is_required'],
        ':show_in_list'  => $values['show_in_list'],
        ':is_searchable' => $values['is_searchable'],
        ':sort_order'    => nextReportFieldSortOrder($pdo, 'report_fields', null),
        ':max_length'    => $values['max_length'],
        ':min_value'     => $values['min_value'],
        ':max_value'     => $values['max_value'],
        ':allow_decimal' => $values['allow_decimal'],
        ':created_at'    => $now,
        ':updated_at'    => $now,
        ':updated_by'    => $loginUserId,
    ]);

    $newId = (int) $pdo->lastInsertId();

    // 内部コード（例: f_7）。英小文字・数字・_ だけなので、列名などに使っても安全
    $updateCode = $pdo->prepare(
        'UPDATE report_fields
         SET code = :code
         WHERE id = :id'
    );
    $updateCode->execute([
        ':code' => 'f_' . $newId,
        ':id'   => $newId,
    ]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('項目の追加エラー: ' . $e->getMessage());

    rememberFormInput('report_field_create', postedReportFieldForm());
    redirectWithMessage('create.php', 'error', '項目を追加できませんでした。');
}

if (isChoiceFieldType($values['field_type'])) {
    redirectWithMessage(
        'options.php?field_id=' . $newId,
        'message',
        '項目を追加しました。続けて選択肢を登録してください。'
    );
}

redirectWithMessage('index.php', 'message', '項目を追加しました。');
