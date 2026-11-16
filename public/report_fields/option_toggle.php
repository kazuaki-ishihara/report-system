<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/report_fields.php';

// ==================================================
// 項目管理：選択肢の表示・非表示の切り替え（管理者のみ）
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

$backTo = 'options.php?field_id=' . (int) $option['field_id'];
$newState = (int) $option['is_active'] === 1 ? 0 : 1;

try {
    $stmt = $pdo->prepare(
        'UPDATE report_field_options
         SET is_active = :is_active, updated_at = :updated_at, updated_by = :updated_by
         WHERE id = :id'
    );
    $stmt->execute([
        ':is_active'  => $newState,
        ':updated_at' => date('Y-m-d H:i:s'),
        ':updated_by' => $loginUserId,
        ':id'         => $id,
    ]);
} catch (PDOException $e) {
    error_log('選択肢の表示切り替えエラー: ' . $e->getMessage());

    redirectWithMessage($backTo, 'error', '表示状態を変更できませんでした。');
}

redirectWithMessage(
    $backTo,
    'message',
    '選択肢「' . $option['label'] . '」を' . ($newState === 1 ? '再表示しました。' : '非表示にしました。')
);
