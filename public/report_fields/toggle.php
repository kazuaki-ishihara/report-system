<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
requireAdmin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('許可されていないアクセスです。');
}
verifyReportFieldCsrf();
$id = reportFieldIdFromRequest('id');

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT is_active FROM report_fields WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $field = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($field === false) {
        $pdo->rollBack();
        http_response_code(404);
        exit('指定された項目が見つかりません。');
    }

    $newState = (int) $field['is_active'] === 1 ? 0 : 1;
    $update = $pdo->prepare(
        'UPDATE report_fields
         SET is_active = :is_active, updated_at = :updated_at
         WHERE id = :id'
    );
    $update->execute([
        ':is_active' => $newState,
        ':updated_at' => date('Y-m-d H:i:s'),
        ':id' => $id,
    ]);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('レポート項目表示切替エラー: ' . $e->getMessage());
    redirectReportFields('error', '表示状態を変更できませんでした。');
}

redirectReportFields('message', $newState === 1 ? '項目を再表示しました。' : '項目を非表示にしました。');
