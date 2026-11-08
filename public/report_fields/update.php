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
$labelValue = $_POST['label'] ?? null;
$typeValue = $_POST['field_type'] ?? null;
$orderValue = $_POST['sort_order'] ?? null;
if (!is_string($labelValue) || !is_string($typeValue) || !is_string($orderValue)) {
    http_response_code(400);
    exit('入力内容が正しくありません。');
}
$label = trim($labelValue);
$order = filter_var($orderValue, FILTER_VALIDATE_INT);
if ($label === '' || strlen($label) > 300) {
    http_response_code(400);
    exit('表示名は1〜300バイトで入力してください。');
}
if (!array_key_exists($typeValue, REPORT_FIELD_TYPES)) {
    http_response_code(400);
    exit('項目の種類が正しくありません。');
}
if ($order === false || $order < 0 || $order > 1000000) {
    http_response_code(400);
    exit('表示順は0〜1000000の整数で指定してください。');
}

$check = $pdo->prepare('SELECT field_type FROM report_fields WHERE id = :id');
$check->execute([':id' => $id]);
$current = $check->fetch(PDO::FETCH_ASSOC);
if ($current === false) {
    http_response_code(404);
    exit('指定された項目が見つかりません。');
}
if ($current['field_type'] !== $typeValue) {
    $valueCount = $pdo->prepare('SELECT COUNT(*) FROM report_field_values WHERE field_id = :id');
    $valueCount->execute([':id' => $id]);
    if ((int) $valueCount->fetchColumn() > 0) {
        http_response_code(409);
        exit('この項目には登録済みの値があるため、種類を変更できません。');
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
    ':field_type' => $typeValue,
    ':sort_order' => $order,
    ':is_required' => isset($_POST['is_required']) ? 1 : 0,
    ':updated_at' => date('Y-m-d H:i:s'),
    ':id' => $id,
]);
redirectReportFields('message', '項目を更新しました。');
