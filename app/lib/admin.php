<?php

declare(strict_types=1);

// ====================================================================
// 管理画面の共通の処理
// ====================================================================

function require_admin(array $user): void
{
    if (!is_admin($user)) {
        error_page(403);
    }
}

/** 仮のパスワード（12 文字。紛らわしい文字を除く） */
function make_temp_password(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 12; $i++) {
        $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $s;
}

function departments_all(bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM departments' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id';
    return db_all($sql);
}

function admin_count(): int
{
    return (int) db_val('SELECT COUNT(*) FROM users WHERE is_admin = 1 AND is_active = 1');
}

/** 文字の項目の確認（1〜$max 文字、制御文字なし） */
function validate_text(string $value, string $label, int $max, bool $required, array &$errors, bool $allowNewline = false): void
{
    if ($required && $value === '') {
        $errors[] = $label . 'を入れてください';
        return;
    }
    if (str_len($value) > $max) {
        $errors[] = $label . 'は ' . $max . ' 文字以内にしてください';
    }
    if (has_control_chars($value, $allowNewline)) {
        $errors[] = $label . 'に使えない文字が含まれています';
    }
}
