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
$isRequired = isset($_POST['is_required']) ? 1 : 0;
$now = date('Y-m-d H:i:s');
$code = 'field_' . bin2hex(random_bytes(12));

$stmt = $pdo->prepare(
    'INSERT INTO report_fields (code, label, field_type, sort_order, is_active, is_required, created_at, updated_at)
     VALUES (:code, :label, :field_type, :sort_order, 1, :is_required, :created_at, :updated_at)'
);
$stmt->execute([
    ':code' => $code,
    ':label' => $label,
    ':field_type' => $typeValue,
    ':sort_order' => $order,
    ':is_required' => $isRequired,
    ':created_at' => $now,
    ':updated_at' => $now,
]);
redirectReportFields('message', '項目を追加しました。');
