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

$label = reportFieldValidatedLabel(reportFieldFormInput('label'));
$type = reportFieldValidatedType(reportFieldFormInput('field_type'));
$order = reportFieldValidatedOrder(reportFieldFormInput('sort_order'));
$isRequired = reportFieldRequiredFromPost();
$now = date('Y-m-d H:i:s');
$code = 'field_' . bin2hex(random_bytes(12));

try {
    $stmt = $pdo->prepare(
        'INSERT INTO report_fields (code, label, field_type, sort_order, is_active, is_required, created_at, updated_at)
         VALUES (:code, :label, :field_type, :sort_order, 1, :is_required, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':code' => $code,
        ':label' => $label,
        ':field_type' => $type,
        ':sort_order' => $order,
        ':is_required' => $isRequired,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
} catch (PDOException $e) {
    error_log('レポート項目登録エラー: ' . $e->getMessage());
    redirectReportFields('error', '項目を登録できませんでした。データベース設定を確認してください。');
}

redirectReportFields('message', '項目を追加しました。');
