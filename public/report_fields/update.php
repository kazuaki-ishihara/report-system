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
$label = reportFieldValidatedLabel(reportFieldFormInput('label'));
$type = reportFieldValidatedType(reportFieldFormInput('field_type'));
$order = reportFieldValidatedOrder(reportFieldFormInput('sort_order'));
$isRequired = reportFieldRequiredFromPost();

try {
    // Lock writers before checking values to prevent a concurrent insert from
    // bypassing the field-type change restriction.
    $pdo->exec('BEGIN IMMEDIATE');
    $check = $pdo->prepare('SELECT field_type FROM report_fields WHERE id = :id');
    $check->execute([':id' => $id]);
    $current = $check->fetch(PDO::FETCH_ASSOC);
    if ($current === false) {
        $pdo->rollBack();
        http_response_code(404);
        exit('指定された項目が見つかりません。');
    }

    if ($current['field_type'] !== $type) {
        $valueCount = $pdo->prepare('SELECT COUNT(*) FROM report_field_values WHERE field_id = :id');
        $valueCount->execute([':id' => $id]);
        if ((int) $valueCount->fetchColumn() > 0) {
            $pdo->rollBack();
            redirectReportFields('error', 'この項目には登録済みの値があるため、種類を変更できません。');
        }
    }

    $stmt = $pdo->prepare(
        'UPDATE report_fields
         SET label = :label, field_type = :field_type, sort_order = :sort_order,
             is_required = :is_required, updated_at = :updated_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':label' => $label,
        ':field_type' => $type,
        ':sort_order' => $order,
        ':is_required' => $isRequired,
        ':updated_at' => date('Y-m-d H:i:s'),
        ':id' => $id,
    ]);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('レポート項目更新エラー: ' . $e->getMessage());
    redirectReportFields('error', '項目を更新できませんでした。データベース設定を確認してください。');
}

redirectReportFields('message', '項目を更新しました。');
