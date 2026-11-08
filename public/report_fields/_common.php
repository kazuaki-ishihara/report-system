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
    if (
        !isset($_SESSION['report_field_csrf_token'])
        || !is_string($_SESSION['report_field_csrf_token'])
        || $_SESSION['report_field_csrf_token'] === ''
    ) {
        $_SESSION['report_field_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['report_field_csrf_token'];
}

function verifyReportFieldCsrf(): void
{
    startSession();
    $sessionToken = $_SESSION['report_field_csrf_token'] ?? null;
    $submittedToken = $_POST['csrf_token'] ?? null;

    if (
        !is_string($sessionToken)
        || $sessionToken === ''
        || !is_string($submittedToken)
        || !hash_equals($sessionToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('不正なリクエストです。画面を開き直して再度お試しください。');
    }
}

function reportFieldIdFromRequest(string $key): int
{
    $value = $_POST[$key] ?? $_GET[$key] ?? null;
    if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/', $value)) {
        http_response_code(400);
        exit('項目IDが正しくありません。');
    }

    $id = filter_var($value, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        http_response_code(400);
        exit('項目IDが正しくありません。');
    }

    return $id;
}

function reportFieldFormInput(string $key): string
{
    $value = $_POST[$key] ?? null;
    if (!is_string($value)) {
        http_response_code(400);
        exit('入力内容が正しくありません。');
    }

    return $value;
}

function reportFieldValidatedLabel(string $value): string
{
    $label = trim($value);
    if (
        $label === ''
        || strlen($label) > 300
        || preg_match('//u', $label) !== 1
        || preg_match('/[\x00-\x1F\x7F]/', $label) === 1
    ) {
        http_response_code(400);
        exit('表示名は制御文字を含めず、UTF-8で1〜300バイトの範囲で入力してください。');
    }

    return $label;
}

function reportFieldValidatedType(string $value): string
{
    if (!array_key_exists($value, REPORT_FIELD_TYPES)) {
        http_response_code(400);
        exit('項目の種類が正しくありません。');
    }

    return $value;
}

function reportFieldValidatedOrder(string $value): int
{
    if (!preg_match('/^(0|[1-9][0-9]*)$/', $value)) {
        http_response_code(400);
        exit('表示順は0〜1000000の整数で指定してください。');
    }

    $order = filter_var($value, FILTER_VALIDATE_INT);
    if ($order === false || $order > 1000000) {
        http_response_code(400);
        exit('表示順は0〜1000000の整数で指定してください。');
    }

    return $order;
}

function reportFieldRequiredFromPost(): int
{
    if (!array_key_exists('is_required', $_POST)) {
        return 0;
    }

    if (!is_string($_POST['is_required']) || $_POST['is_required'] !== '1') {
        http_response_code(400);
        exit('必須設定の値が正しくありません。');
    }

    return 1;
}

function redirectReportFields(string $kind, string $message): void
{
    header('Location: index.php?' . rawurlencode($kind) . '=' . rawurlencode($message));
    exit;
}

function reportFieldLabel(array $field): string
{
    return REPORT_FIELD_TYPES[$field['field_type']] ?? '不明な型';
}
