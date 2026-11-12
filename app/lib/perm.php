<?php

declare(strict_types=1);

// ====================================================================
// 権限の判定（詳細設計書 共通編 §10）
// ====================================================================

function is_admin(array $user): bool
{
    return (int) $user['is_admin'] === 1;
}

/** 自分が確認者の課（課ID => is_final） */
function reviewer_departments(int $userId): array
{
    $out = [];
    foreach (db_all('SELECT department_id, is_final FROM department_reviewers WHERE user_id = ?', [$userId]) as $r) {
        $out[(int) $r['department_id']] = (int) $r['is_final'] === 1;
    }
    return $out;
}

function is_reviewer_of(int $userId, int $departmentId): bool
{
    return db_val('SELECT 1 FROM department_reviewers WHERE user_id = ? AND department_id = ?', [$userId, $departmentId]) !== null;
}

function is_final_reviewer_of(int $userId, int $departmentId): bool
{
    return db_val('SELECT 1 FROM department_reviewers WHERE user_id = ? AND department_id = ? AND is_final = 1', [$userId, $departmentId]) !== null;
}

/** 課別項目を管理できるか（課の項目管理者、課長、管理者） */
function can_manage_fields_of(array $user, int $departmentId): bool
{
    if (is_admin($user)) {
        return true;
    }
    $uid = (int) $user['id'];
    if (is_final_reviewer_of($uid, $departmentId)) {
        return true;
    }
    return db_val('SELECT 1 FROM department_admins WHERE user_id = ? AND department_id = ?', [$uid, $departmentId]) !== null;
}

/**
 * 報告を見られるか（共通編 §10.2）。
 * 下書きは本人と管理者だけ。削除済みは見られない。
 */
function can_view_report(array $user, array $report): bool
{
    if ($report['deleted_at'] !== null) {
        return false;
    }
    $uid = (int) $user['id'];
    if ((int) $report['user_id'] === $uid || is_admin($user)) {
        return true;
    }
    if ($report['status'] === 'draft') {
        return false;
    }
    return $report['department_id'] !== null && is_reviewer_of($uid, (int) $report['department_id']);
}

/** 「限定」の項目を見られるか（本人・課長・管理者） */
function can_view_restricted(array $user, array $report): bool
{
    $uid = (int) $user['id'];
    if ((int) $report['user_id'] === $uid || is_admin($user)) {
        return true;
    }
    return $report['department_id'] !== null && is_final_reviewer_of($uid, (int) $report['department_id']);
}

/** 提出状況などを見られる課（課ID => 課の行）。確認者の課、管理者は全課 */
function reviewable_departments(array $user): array
{
    $out = [];
    if (is_admin($user)) {
        foreach (db_all('SELECT * FROM departments WHERE is_active = 1 ORDER BY sort_order, id') as $d) {
            $out[(int) $d['id']] = $d;
        }
        return $out;
    }
    foreach (db_all(
        'SELECT d.* FROM departments d JOIN department_reviewers r ON r.department_id = d.id
          WHERE r.user_id = ? AND d.is_active = 1 ORDER BY d.sort_order, d.id',
        [(int) $user['id']]
    ) as $d) {
        $out[(int) $d['id']] = $d;
    }
    return $out;
}

/** 課長として扱う課（課ID => 課の行）。管理者は全課 */
function final_departments(array $user): array
{
    $out = [];
    foreach (reviewable_departments($user) as $id => $d) {
        if (is_admin($user) || is_final_reviewer_of((int) $user['id'], $id)) {
            $out[$id] = $d;
        }
    }
    return $out;
}

/** メニューに出す項目 */
function menu_items(array $user): array
{
    $uid = (int) $user['id'];
    $items = [
        ['/index.php', 'ホーム'],
        ['/reports/mine.php', '自分の報告'],
    ];
    $rev = reviewer_departments($uid);
    if ($rev || is_admin($user)) {
        $items[] = ['/status/index.php', '提出状況'];
    }
    if (in_array(true, $rev, true) || is_admin($user)) {
        $items[] = ['/surveys/manage.php', '臨時の調査'];
        $items[] = ['/export/index.php', '出力'];
    }
    if (is_admin($user) || in_array(true, $rev, true) || db_val('SELECT 1 FROM department_admins WHERE user_id = ?', [$uid]) !== null) {
        $items[] = ['/admin/fields.php', '項目マスタ'];
    }
    if (is_admin($user)) {
        $items[] = ['/admin/projects.php', '案件の管理'];
        $items[] = ['/admin/org.php', '組織・ユーザー'];
        $items[] = ['/admin/logs.php', '記録'];
    }
    $items[] = ['/account.php', 'アカウント設定'];
    return $items;
}
