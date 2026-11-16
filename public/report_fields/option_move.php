<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/report_fields.php';

// ==================================================
// 項目管理：選択肢の並び順を 1 つ上・下へ移動（管理者のみ）
// ==================================================

$loginUserId = requireReportFieldAdmin($pdo);
requireReportFieldTables($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

verifyCsrfToken();

$id = postId('id');
$direction = postString('direction');

if (!in_array($direction, ['up', 'down'], true)) {
    http_response_code(400);
    exit('移動の向きが正しくありません。');
}

$option = findReportFieldOption($pdo, $id);

if ($option === null) {
    http_response_code(404);
    exit('指定された選択肢が見つかりません。');
}

$fieldId = (int) $option['field_id'];
$backTo = 'options.php?field_id=' . $fieldId;

try {
    $pdo->beginTransaction();

    moveReportFieldSortOrder(
        $pdo,
        'report_field_options',
        $id,
        $direction,
        $fieldId,
        $loginUserId,
        date('Y-m-d H:i:s')
    );

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('選択肢の並び替えエラー: ' . $e->getMessage());

    redirectWithMessage($backTo, 'error', '並び順を変更できませんでした。');
}

header('Location: ' . $backTo);
exit;
