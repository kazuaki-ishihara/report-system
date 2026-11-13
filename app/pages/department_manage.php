<?php

declare(strict_types=1);

// ====================================================================
// G-23 課の管理（課長が自分の課を管理する。詳細設計書 G23_department_manage.md。K-042）
//   基本: 締め日・管理の仕方（課全体／班ごと）
//   確認者と課員: 課代・リーダー（確認者）・担当の班・チェックする／しない（K-039）・項目管理者・課員の班
//   班: 班の追加・名前・有効
// システム管理者は個々の課を管理しない（課の追加・名前・課長・引き継ぎは G-14）。
// ====================================================================

$user = page_start();
$userId = (int) $user['id'];

// 自分が課長（最終確認者）の課だけ
$manageableDepartmentById = [];
foreach (db_all(
    'SELECT d.* FROM departments d JOIN department_reviewers r ON r.department_id = d.id
      WHERE r.user_id = ? AND r.is_final = 1 AND d.is_active = 1 ORDER BY d.sort_order, d.id',
    [$userId]
) as $departmentRow) {
    $manageableDepartmentById[(int) $departmentRow['id']] = $departmentRow;
}
if (!$manageableDepartmentById) {
    error_page(403);
}
$departmentId = isset($_GET['dept']) ? input_id('dept') : (int) array_key_first($manageableDepartmentById);
if (!isset($manageableDepartmentById[$departmentId])) {
    error_page(403);
}
$department = $manageableDepartmentById[$departmentId];
$selfPath = '/admin/department.php?dept=' . $departmentId;
$errorTextList = [];

if (is_post()) {
    $action = post_str('action');
    if (!in_array($action, ['settings_save', 'members_save', 'team_add', 'team_update'], true)) {
        error_page(400);
    }
    rk_begin('department.' . $action, $userId);

    if ($action === 'settings_save') {
        // 締め日・管理の仕方
        $closeDayText = post_str('close_day');
        $closeDay = null;
        if ($closeDayText !== '') {
            if (preg_match('/^\d{1,2}$/', $closeDayText) !== 1 || (int) $closeDayText < 1 || (int) $closeDayText > 31) {
                $errorTextList[] = msg('締め日は 1〜31（31 は月末）にしてください');
            } else {
                $closeDay = (int) $closeDayText;
            }
        }
        $reviewScope = post_str('review_scope') === 'team' ? 'team' : 'department';
        if (!$errorTextList) {
            db_exec('UPDATE departments SET close_day = ?, review_scope = ?, updated_at = ? WHERE id = ?', [$closeDay, $reviewScope, now(), $departmentId]);
            audit_log('admin', 'department:' . $departmentId . ':settings');
            flash('success', msg('保存しました'));
            rk_done($selfPath . '#dept-basic');
        }

    } elseif ($action === 'members_save') {
        // 課代・リーダー（確認者）・担当の班・チェックする・項目管理者・課員の班をまとめて保存する（課長の行は変えない）
        $memberRowList = department_manage_member_rows($departmentId);
        $memberById = [];
        foreach ($memberRowList as $memberRow) {
            $memberById[(int) $memberRow['id']] = $memberRow;
        }
        $teamIdSet = array_fill_keys(array_map('intval', array_column(department_teams_of($departmentId, true), 'id')), true);
        $postedArrayByName = [];
        foreach (['reviewer', 'reviewer_team', 'does_check', 'field_admin', 'member_team'] as $postedName) {
            $postedArrayByName[$postedName] = $_POST[$postedName] ?? [];
            if (!is_array($postedArrayByName[$postedName])) {
                error_page(400);
            }
            foreach ($postedArrayByName[$postedName] as $postedUserIdText => $postedValue) {
                if (preg_match('/^\d{1,10}$/', (string) $postedUserIdText) !== 1 || !is_string($postedValue) || !isset($memberById[(int) $postedUserIdText])) {
                    error_page(400);
                }
            }
        }
        $teamIdOrNull = function (string $teamIdText) use ($teamIdSet): ?int {
            if ($teamIdText === '') {
                return null;
            }
            if (!isset($teamIdSet[(int) $teamIdText]) || preg_match('/^\d{1,10}$/', $teamIdText) !== 1) {
                error_page(400);
            }
            return (int) $teamIdText;
        };
        $savePlanList = [];
        foreach ($memberById as $memberUserId => $memberRow) {
            $isFinalMember = (int) $memberRow['is_final'] === 1;
            $savePlanList[$memberUserId] = [
                'isFinal'      => $isFinalMember,
                'isReviewer'   => $isFinalMember || ($postedArrayByName['reviewer'][$memberUserId] ?? '') === '1',
                'reviewerTeam' => $isFinalMember ? null : $teamIdOrNull((string) ($postedArrayByName['reviewer_team'][$memberUserId] ?? '')),
                'doesCheck'    => $isFinalMember ? 1 : (($postedArrayByName['does_check'][$memberUserId] ?? '') === '1' ? 1 : 0),
                'isFieldAdmin' => ($postedArrayByName['field_admin'][$memberUserId] ?? '') === '1',
                'memberTeam'   => (int) $memberRow['department_id'] === $departmentId ? $teamIdOrNull((string) ($postedArrayByName['member_team'][$memberUserId] ?? '')) : null,
                'isMember'     => (int) $memberRow['department_id'] === $departmentId,
                'wasReviewer'  => (int) $memberRow['is_reviewer'] === 1,
            ];
        }
        tx(function () use ($savePlanList, $departmentId): void {
            foreach ($savePlanList as $memberUserId => $savePlan) {
                if ($savePlan['isFinal']) {
                    // 課長の行は G-14（管理者）で決める。ここでは班だけ
                } elseif ($savePlan['isReviewer'] && $savePlan['wasReviewer']) {
                    db_exec('UPDATE department_reviewers SET team_id = ?, does_check = ? WHERE department_id = ? AND user_id = ?',
                        [$savePlan['reviewerTeam'], $savePlan['doesCheck'], $departmentId, $memberUserId]);
                } elseif ($savePlan['isReviewer']) {
                    db_exec('INSERT INTO department_reviewers (department_id, user_id, is_final, sort_order, team_id, does_check) VALUES (?, ?, 0, ?, ?, ?)',
                        [$departmentId, $memberUserId, $memberUserId * 10, $savePlan['reviewerTeam'], $savePlan['doesCheck']]);
                } elseif ($savePlan['wasReviewer']) {
                    db_exec('DELETE FROM department_reviewers WHERE department_id = ? AND user_id = ? AND is_final = 0', [$departmentId, $memberUserId]);
                }
                db_exec('DELETE FROM department_admins WHERE department_id = ? AND user_id = ?', [$departmentId, $memberUserId]);
                if ($savePlan['isFieldAdmin']) {
                    db_exec('INSERT INTO department_admins (department_id, user_id) VALUES (?, ?)', [$departmentId, $memberUserId]);
                }
                if ($savePlan['isMember']) {
                    db_exec('UPDATE users SET team_id = ?, updated_at = ? WHERE id = ?', [$savePlan['memberTeam'], now(), $memberUserId]);
                }
            }
        });
        audit_log('admin', 'department:' . $departmentId . ':members');
        flash('success', msg('確認者と課員を保存しました'));
        rk_done($selfPath . '#dept-members');

    } else {
        // 班の追加・名前・有効（「班ごと」の課。K-025）
        $teamName = trim(post_str('team_name'));
        validate_text($teamName, msg('班の名前'), 40, true, $errorTextList);
        $editedTeamId = $action === 'team_update' ? input_id('team_id', 'POST') : null;
        if ($editedTeamId !== null && db_one('SELECT id FROM department_teams WHERE id = ? AND department_id = ?', [$editedTeamId, $departmentId]) === null) {
            error_page(404);
        }
        if (db_val('SELECT 1 FROM department_teams WHERE department_id = ? AND name = ? AND id <> ?', [$departmentId, $teamName, $editedTeamId ?? 0]) !== null) {
            $errorTextList[] = msg('同じ名前の班があります');
        }
        if (!$errorTextList) {
            if ($editedTeamId === null) {
                $nextTeamSortOrder = (int) db_val('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM department_teams WHERE department_id = ?', [$departmentId]);
                db_exec('INSERT INTO department_teams (department_id, name, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)',
                    [$departmentId, $teamName, $nextTeamSortOrder, now(), now()]);
                audit_log('admin', 'department:' . $departmentId . ':team_add');
            } else {
                $isTeamActive = ($_POST['team_active'] ?? '') === '1' ? 1 : 0;
                db_exec('UPDATE department_teams SET name = ?, is_active = ?, updated_at = ? WHERE id = ?', [$teamName, $isTeamActive, now(), $editedTeamId]);
                if ($isTeamActive === 0) {
                    // 無効にした班: 所属の人と担当の確認者の班を外す（課全体の扱いになる）
                    db_exec('UPDATE users SET team_id = NULL WHERE team_id = ?', [$editedTeamId]);
                    db_exec('UPDATE department_reviewers SET team_id = NULL WHERE team_id = ?', [$editedTeamId]);
                }
                audit_log('admin', 'department:' . $departmentId . ':team_update');
            }
            flash('success', msg('班を保存しました'));
            rk_done($selfPath . '#dept-teams');
        }
    }
    rk_cancel();
    $department = db_one('SELECT * FROM departments WHERE id = ?', [$departmentId]);
}

render('department_manage', [
    'title'                     => '課の管理',
    'user'                      => $user,
    'department'                => $department,
    'manageableDepartmentById'  => $manageableDepartmentById,
    'memberRowList'             => department_manage_member_rows($departmentId),
    'teamList'                  => department_teams_of($departmentId, false),
    'errorTextList'             => $errorTextList,
]);
