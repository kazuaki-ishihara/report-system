<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/report_fields.php';

// ==================================================
// 項目管理：項目の表示・非表示の切り替え（管理者のみ）
//
// 項目は物理削除せず、非表示にする。固定の項目は切り替えられない。
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

if ((int) $field['is_system'] === 1) {
    redirectWithMessage('index.php', 'error', '固定の項目は非表示にできません。');
}

$newState = (int) $field['is_active'] === 1 ? 0 : 1;

try {
    $stmt = $pdo->prepare(
        'UPDATE report_fields
         SET is_active = :is_active, updated_at = :updated_at, updated_by = :updated_by
         WHERE id = :id AND is_system = 0'
    );
    $stmt->execute([
        ':is_active'  => $newState,
        ':updated_at' => date('Y-m-d H:i:s'),
        ':updated_by' => $loginUserId,
        ':id'         => $id,
    ]);
} catch (PDOException $e) {
    error_log('項目の表示切り替えエラー: ' . $e->getMessage());

    redirectWithMessage('index.php', 'error', '表示状態を変更できませんでした。');
}

redirectWithMessage(
    'index.php',
    'message',
    '項目「' . $field['label'] . '」を' . ($newState === 1 ? '再表示しました。' : '非表示にしました。')
);
