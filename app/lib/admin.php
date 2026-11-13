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

/** システム全体の管理の画面（管理者と補佐）。補佐は画面ごとに、個々の情報を出さない */
function require_system_manager(array $user): void
{
    if (!can_manage_system($user)) {
        error_page(403);
    }
}

/** システム管理者補佐の上限（設計製造開発者とそのサポート役。G-14 §6.9） */
const ADMIN_DEPUTY_MAX_COUNT = 3;

/** 有効な補佐の人数（$exceptUserId の人は数えない） */
function admin_deputy_count(?int $exceptUserId = null): int
{
    return (int) db_val('SELECT COUNT(*) FROM users WHERE is_admin_deputy = 1 AND is_active = 1 AND id IS NOT ?', [$exceptUserId]);
}

/** 管理者か補佐の利用者か（補佐は、管理者と補佐の利用者を変えられない） */
function user_row_is_system_manager(array $userRow): bool
{
    return (int) $userRow['is_admin'] === 1 || (int) ($userRow['is_admin_deputy'] ?? 0) === 1;
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
/** システムの管理担当の課の ID（メッセージを変えられるのはこの課の課長だけ。K-006）。未設定なら null */
function management_department_id(): ?int
{
    $storedValue = db_val("SELECT value FROM app_state WHERE name = 'management_department_id'");
    return $storedValue === null || $storedValue === '' ? null : (int) $storedValue;
}

/**
 * 課の班（「班ごと」で管理する課。G-14 §6.7）。
 *
 * @return array<int, array{id: int|string, name: string, is_active: int|string}> 並び順
 */
function department_teams_of(int $departmentId, bool $isActiveOnly = true): array
{
    return db_all('SELECT id, name, is_active FROM department_teams WHERE department_id = ?' . ($isActiveOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, id', [$departmentId]);
}

/**
 * 課の管理（G-23）の課員と確認者の行。この課の有効な課員と、この課の確認者（ほかの課の人を含む）。
 * 並びは 課長 → 確認者 → ほかの課員（名前の順）。
 *
 * @return array<int, array<string, mixed>> id・login_id・display_name・role_title・department_id・team_id・
 *         is_reviewer・is_final・reviewer_team_id・does_check・is_field_admin
 */
function department_manage_member_rows(int $departmentId): array
{
    return db_all(
        'SELECT u.id, u.login_id, u.display_name, u.role_title, u.department_id, u.team_id,
                (r.user_id IS NOT NULL) AS is_reviewer, COALESCE(r.is_final, 0) AS is_final, r.team_id AS reviewer_team_id,
                r.does_check, (a.user_id IS NOT NULL) AS is_field_admin
           FROM users u
           LEFT JOIN department_reviewers r ON r.user_id = u.id AND r.department_id = ?
           LEFT JOIN department_admins a ON a.user_id = u.id AND a.department_id = ?
          WHERE u.is_active = 1 AND (u.department_id = ? OR r.user_id IS NOT NULL)
          ORDER BY COALESCE(r.is_final, 0) DESC, (r.user_id IS NOT NULL) DESC, u.display_name',
        [$departmentId, $departmentId, $departmentId]
    );
}

function validate_text(string $value, string $label, int $max, bool $required, array &$errors, bool $allowNewline = false): void
{
    if ($required && $value === '') {
        $errors[] = msg('{label}を入れてください', ['label' => $label]);
        return;
    }
    if (str_len($value) > $max) {
        $errors[] = msg('{label}は {max} 文字以内にしてください', ['label' => $label, 'max' => $max]);
    }
    if (has_control_chars($value, $allowNewline)) {
        $errors[] = msg('{label}に使えない文字が含まれています', ['label' => $label]);
    }
}
