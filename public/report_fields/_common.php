<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';

const REPORT_FIELD_TYPES = [
    'single_text' => '1行テキスト',
    'multi_text' => '複数行テキスト',
    'date' => '日付',
];

function reportFieldCsrfToken(): string
{
    startSession();
    return csrfToken();
}

function verifyReportFieldCsrf(): void
{
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('不正なリクエストです。画面を開き直して再度お試しください。');
    }
}

function reportFieldIdFromRequest(string $key): int
{
    $value = $_POST[$key] ?? $_GET[$key] ?? null;
    if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
        http_response_code(400);
        exit('項目IDが正しくありません。');
    }
    return (int) $value;
}

function redirectReportFields(string $kind, string $message): never
{
    header('Location: index.php?' . rawurlencode($kind) . '=' . rawurlencode($message));
    exit;
}

function reportFieldLabel(array $field): string
{
    return REPORT_FIELD_TYPES[$field['field_type']] ?? '不明な型';
}
