<?php

declare(strict_types=1);

// ====================================================================
// G-14 組織・ユーザーの管理: 課・確認者・注意の言葉（詳細設計書 G14_org_users.md）
// ユーザーの編集は admin_user_edit.php
// ====================================================================

$user = page_start();
require_system_manager($user);

$tab = (string) ($_GET['tab'] ?? 'departments');
if (!in_array($tab, ['departments', 'users', 'words', 'bulk'], true)) {
    $tab = 'departments';
}
$errors = [];

// ---- 一括（CSV）: 今の組織の出力（G-14 §6.8） ----
if ($tab === 'bulk' && ($_GET['csv'] ?? '') === '1') {
    audit_log('export', 'export:organization');
    $organizationCsvBody = export_csv(
        ['社外秘', '組織（ユーザーの所属・班・役割）', '出力者: ' . $user['login_id'] . '  出力日時: ' . date('Y-m-d H:i')],
        ORG_CSV_COLUMNS,
        org_csv_current_rows()
    );
    send_security_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="organization_' . date('Ymd-Hi') . '.csv"');
    echo $organizationCsvBody;
    exit;
}
$bulkPlan = null;   // 取り込んだ CSV の計画（変わる内容を確かめる画面に出す）

if (is_post() && post_str('action') === 'bulk_preview') {
    // 取り込む: 読んで計画を作るだけ（DB は変えない）。計画はセッションに置き、「反映する」で使う
    $tab = 'bulk';
    $uploadedFile = $_FILES['org_csv'] ?? null;
    if (!is_array($uploadedFile) || ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $uploadedFile['tmp_name'])) {
        $errors[] = msg('CSV のファイルを選んでください');
    } elseif ((int) $uploadedFile['size'] > 1024 * 1024) {
        $errors[] = msg('ファイルが大きすぎます（1 MB まで）');
    } else {
        $uploadedCsvText = (string) file_get_contents((string) $uploadedFile['tmp_name']);
        if (preg_match('//u', $uploadedCsvText) !== 1) {   // UTF-8 として正しいか
            $errors[] = msg('CSV は UTF-8 で保存してください（出力したファイルを Excel で直したときは「CSV UTF-8」で保存）');
        } else {
            $bulkPlan = org_csv_plan($uploadedCsvText, (int) $user['id']);
            $bulkPlan['token'] = random_token(16);
            // 予約するときのため、取り込んだ CSV も持っておく（便利機能 G）
            $_SESSION['org_csv_plan'] = ['token' => $bulkPlan['token'], 'createdAt' => time(), 'plan' => $bulkPlan, 'csvText' => $uploadedCsvText];
        }
    }
} elseif (is_post() && post_str('action') === 'bulk_schedule') {
    // 予約: 取り込んだ CSV を、予約した日に反映する（便利機能 G）
    rk_begin('admin.org.bulk_schedule', (int) $user['id']);
    $storedPlan = $_SESSION['org_csv_plan'] ?? null;
    if (!is_array($storedPlan) || !hash_equals((string) $storedPlan['token'], post_str('plan_token')) || time() - (int) $storedPlan['createdAt'] > 1800
        || $storedPlan['plan']['errorTextList'] || !isset($storedPlan['csvText'])) {
        rk_cancel();
        flash('error', msg('取り込んだ内容が見つからないか、30 分を過ぎました。もう一度取り込んでください'));
        redirect('/admin/org.php?tab=bulk');
    }
    $applyOnText = post_str('apply_on');
    $applyOnDate = DateTime::createFromFormat('!Y-m-d', $applyOnText);
    if ($applyOnDate === false || $applyOnText <= date('Y-m-d') || $applyOnDate->getTimestamp() > time() + ORG_SCHEDULE_MAX_DAYS * 86400) {
        rk_cancel();
        flash('error', msg('予約する日は、明日から 1 年以内の日にしてください（今日反映するときは「すぐに反映する」）'));
        redirect('/admin/org.php?tab=bulk');
    }
    db_exec('INSERT INTO org_schedules (apply_on, csv_text, change_count, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$applyOnText, (string) $storedPlan['csvText'], count($storedPlan['plan']['changeList']), 'scheduled', (int) $user['id'], now()]);
    $scheduleId = (int) db()->lastInsertId();
    unset($_SESSION['org_csv_plan']);
    audit_log('admin', 'organization:schedule:' . $scheduleId . ':create:' . $applyOnText);
    flash('success', msg('{date} に組織を反映するよう予約しました（{count} 人）。その日に、その時点の組織に対してもう一度確かめてから反映します', ['date' => display_date($applyOnText), 'count' => count($storedPlan['plan']['changeList'])]));
    rk_done('/admin/org.php?tab=bulk');
} elseif (is_post() && post_str('action') === 'schedule_cancel') {
    rk_begin('admin.org.schedule_cancel', (int) $user['id']);
    $cancelledScheduleId = input_id('schedule_id', 'POST');
    db_exec("UPDATE org_schedules SET status = 'cancelled', finished_at = ? WHERE id = ? AND status = 'scheduled'", [now(), $cancelledScheduleId]);
    audit_log('admin', 'organization:schedule:' . $cancelledScheduleId . ':cancel');
    flash('success', msg('予約を取り消しました'));
    rk_done('/admin/org.php?tab=bulk');
} elseif (is_post() && post_str('action') === 'bulk_apply') {
    rk_begin('admin.org.bulk_apply', (int) $user['id']);
    $storedPlan = $_SESSION['org_csv_plan'] ?? null;
    if (!is_array($storedPlan) || !hash_equals((string) $storedPlan['token'], post_str('plan_token')) || time() - (int) $storedPlan['createdAt'] > 1800
        || $storedPlan['plan']['errorTextList']) {
        rk_cancel();
        flash('error', msg('取り込んだ内容が見つからないか、30 分を過ぎました。もう一度取り込んでください'));
        redirect('/admin/org.php?tab=bulk');
    }
    $changedUserCount = org_csv_apply($storedPlan['plan']);
    unset($_SESSION['org_csv_plan']);
    audit_log('admin', 'organization:bulk_apply:' . $changedUserCount);
    flash('success', msg('組織を反映しました（{count} 人）', ['count' => $changedUserCount]));
    rk_done('/admin/org.php?tab=bulk');
} elseif (is_post()) {
    $action = post_str('action');
    rk_begin('admin.org.' . $action, (int) $user['id']);

    if ($action === 'dept_save') {
        // 課の名前・並び順・有効・管理担当（システム全体の管理。締め日・管理の仕方は課長が「課の管理」で決める。K-042）
        $id = isset($_POST['id']) && $_POST['id'] !== '' ? input_id('id', 'POST') : null;
        $name = post_str('name');
        $sort = post_str('sort_order');
        validate_text($name, '課の名前', 40, true, $errors);
        $sortN = preg_match('/^\d{1,6}$/', $sort) === 1 ? (int) $sort : 0;
        $dup = db_val('SELECT id FROM departments WHERE name = ? AND id <> ?', [$name, $id ?? 0]);
        if ($dup !== null) {
            $errors[] = msg('同じ名前の課があります');
        }
        $active = $id === null || ($_POST['is_active'] ?? '') === '1' ? 1 : 0;
        $isManagementDepartment = ($_POST['is_management'] ?? '') === '1';
        if ($id !== null && $active === 0) {
            $members = (int) db_val('SELECT COUNT(*) FROM users WHERE department_id = ? AND is_active = 1', [$id]);
            if ($members > 0) {
                $errors[] = msg('所属している人がいるため、無効にできません');
            }
        }
        if (!$errors) {
            if ($id === null) {
                db_exec("INSERT INTO departments (name, sort_order, close_day, is_active, review_scope, created_at, updated_at) VALUES (?, ?, NULL, 1, 'department', ?, ?)", [$name, $sortN, now(), now()]);
                $id = (int) db()->lastInsertId();
                audit_log('admin', 'department:' . $id . ':create');
            } else {
                if (db_one('SELECT id FROM departments WHERE id = ?', [$id]) === null) {
                    error_page(404);
                }
                db_exec('UPDATE departments SET name = ?, sort_order = ?, is_active = ?, updated_at = ? WHERE id = ?', [$name, $sortN, $active, now(), $id]);
                audit_log('admin', 'department:' . $id . ':update');
            }
            // 管理担当の課（1 つだけ）。外したときは、この課が管理担当だったときだけ空にする
            if ($isManagementDepartment) {
                db_exec("INSERT INTO app_state (name, value) VALUES ('management_department_id', ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value", [(string) $id]);
            } elseif (management_department_id() === $id) {
                db_exec("DELETE FROM app_state WHERE name = 'management_department_id'");
            }
            flash('success', msg('保存しました'));
            rk_done('/admin/org.php?tab=departments&edit=' . $id);
        }
        rk_cancel();

    } elseif ($action === 'succession_save') {
        // 引き継ぐ課（組織の改訂の前の課）。この課の確認者が、選んだ課の報告も見られるようになる
        $successorDepartmentId = input_id('dept_id', 'POST');
        if (db_one('SELECT id FROM departments WHERE id = ?', [$successorDepartmentId]) === null) {
            error_page(404);
        }
        $predecessorIdTextList = $_POST['predecessor_ids'] ?? [];
        if (!is_array($predecessorIdTextList)) {
            error_page(400);
        }
        // この課を（直接・間接に）引き継いでいる課は選べない（輪になるため）
        $forbiddenDepartmentIdSet = [$successorDepartmentId => true];
        $pendingDepartmentIdList = [$successorDepartmentId];
        while ($pendingDepartmentIdList) {
            $predecessorCheckId = array_pop($pendingDepartmentIdList);
            foreach (db_all('SELECT successor_department_id FROM department_successions WHERE predecessor_department_id = ?', [$predecessorCheckId]) as $successionRow) {
                $laterDepartmentId = (int) $successionRow['successor_department_id'];
                if (!isset($forbiddenDepartmentIdSet[$laterDepartmentId])) {
                    $forbiddenDepartmentIdSet[$laterDepartmentId] = true;
                    $pendingDepartmentIdList[] = $laterDepartmentId;
                }
            }
        }
        $predecessorIdList = [];
        foreach ($predecessorIdTextList as $predecessorIdText) {
            if (!is_string($predecessorIdText) || preg_match('/^\d{1,10}$/', $predecessorIdText) !== 1
                || db_one('SELECT id FROM departments WHERE id = ?', [(int) $predecessorIdText]) === null) {
                error_page(400);
            }
            if (isset($forbiddenDepartmentIdSet[(int) $predecessorIdText])) {
                rk_cancel();
                flash('error', msg('この課を引き継いでいる課は、引き継ぐ課に選べません（輪になります）'));
                redirect('/admin/org.php?tab=departments&edit=' . $successorDepartmentId);
            }
            $predecessorIdList[] = (int) $predecessorIdText;
        }
        tx(function () use ($successorDepartmentId, $predecessorIdList, $user): void {
            db_exec('DELETE FROM department_successions WHERE successor_department_id = ?', [$successorDepartmentId]);
            foreach (array_unique($predecessorIdList) as $predecessorDepartmentId) {
                db_exec('INSERT INTO department_successions (predecessor_department_id, successor_department_id, created_by, created_at) VALUES (?, ?, ?, ?)',
                    [$predecessorDepartmentId, $successorDepartmentId, (int) $user['id'], now()]);
            }
        });
        audit_log('admin', 'department:' . $successorDepartmentId . ':succession');
        flash('success', msg('引き継ぐ課を保存しました。この課の確認者は、引き継いだ課の報告も見られます'));
        rk_done('/admin/org.php?tab=departments&edit=' . $successorDepartmentId . '#dept-succession');

    } elseif ($action === 'final_save') {
        // 課長（最終確認者）を決める。課代・リーダー・項目管理者・班は、その課の課長が「課の管理」で決める（K-042）
        $deptId = input_id('dept_id', 'POST');
        if (db_one('SELECT id FROM departments WHERE id = ?', [$deptId]) === null) {
            error_page(404);
        }
        $finalUserIdText = post_str('final_user_id');
        $finalUserId = null;
        if ($finalUserIdText !== '') {
            if (preg_match('/^\d{1,10}$/', $finalUserIdText) !== 1 || db_one('SELECT id FROM users WHERE id = ? AND is_active = 1', [(int) $finalUserIdText]) === null) {
                error_page(400);
            }
            $finalUserId = (int) $finalUserIdText;
        }
        tx(function () use ($deptId, $finalUserId): void {
            // 今の課長は、課長の役を外す（課代・リーダーの役には残さない）
            db_exec('DELETE FROM department_reviewers WHERE department_id = ? AND is_final = 1', [$deptId]);
            if ($finalUserId !== null) {
                db_exec('DELETE FROM department_reviewers WHERE department_id = ? AND user_id = ?', [$deptId, $finalUserId]);
                db_exec('INSERT INTO department_reviewers (department_id, user_id, is_final, sort_order, team_id, does_check) VALUES (?, ?, 1, 0, NULL, 1)', [$deptId, $finalUserId]);
            }
        });
        audit_log('admin', 'department:' . $deptId . ':final');
        flash('success', $finalUserId === null ? msg('課長を外しました') : msg('課長を保存しました'));
        rk_done('/admin/org.php?tab=departments&edit=' . $deptId . '#dept-final');

    } elseif ($action === 'word_add') {
        $tab = 'words';
        $word = post_str('word');
        validate_text($word, '言葉', 20, true, $errors);
        if (!$errors && db_val('SELECT 1 FROM warning_words WHERE word = ?', [$word]) !== null) {
            $errors[] = msg('同じ言葉があります');
        }
        if (!$errors) {
            db_exec('INSERT INTO warning_words (word, created_at) VALUES (?, ?)', [$word, now()]);
            audit_log('admin', 'word:' . (int) db()->lastInsertId() . ':add');
            rk_done('/admin/org.php?tab=words');
        }
        rk_cancel();
    } elseif ($action === 'word_delete') {
        $id = input_id('id', 'POST');
        db_exec('DELETE FROM warning_words WHERE id = ?', [$id]);
        audit_log('admin', 'word:' . $id . ':delete');
        rk_done('/admin/org.php?tab=words');
    } else {
        error_page(400);
    }
}

$vars = ['title' => '組織・ユーザー', 'user' => $user, 'tab' => $tab, 'errors' => $errors];

if ($tab === 'departments') {
    $vars['departments'] = departments_all();
    $vars['reviewers'] = [];
    foreach (db_all('SELECT r.department_id, r.is_final, u.display_name FROM department_reviewers r JOIN users u ON u.id = r.user_id AND u.is_active = 1 ORDER BY r.is_final DESC, r.sort_order') as $r) {
        $vars['reviewers'][(int) $r['department_id']][] = $r;
    }
    // 引き継いだ課の名前（課 ID => 改訂前の課の名前の一覧）
    $vars['predecessorNameListByDepartmentId'] = [];
    foreach (db_all('SELECT s.successor_department_id, d.name FROM department_successions s JOIN departments d ON d.id = s.predecessor_department_id ORDER BY d.sort_order, d.id') as $successionRow) {
        $vars['predecessorNameListByDepartmentId'][(int) $successionRow['successor_department_id']][] = (string) $successionRow['name'];
    }
    $editId = isset($_GET['edit']) ? input_id('edit') : null;
    $vars['edit'] = null;
    if ($editId !== null) {
        $vars['edit'] = db_one('SELECT * FROM departments WHERE id = ?', [$editId]);
        if ($vars['edit'] === null) {
            error_page(404);
        }
        // 課長の候補（有効な人。この課の人を上に）
        $vars['candidates'] = db_all(
            'SELECT u.id, u.login_id, u.display_name, u.role_title, d.name AS dept_name, COALESCE(r.is_final, 0) AS is_final
               FROM users u
               LEFT JOIN departments d ON d.id = u.department_id
               LEFT JOIN department_reviewers r ON r.user_id = u.id AND r.department_id = ?
              WHERE u.is_active = 1
              ORDER BY (u.department_id = ?) DESC, u.display_name',
            [$editId, $editId]
        );
    }
    $vars['managementDepartmentId'] = management_department_id();
    if ($vars['edit'] !== null) {
        $vars['teamList'] = department_teams_of((int) $vars['edit']['id'], false);
        $vars['predecessorIdSet'] = [];
        foreach (db_all('SELECT predecessor_department_id FROM department_successions WHERE successor_department_id = ?', [$editId]) as $successionRow) {
            $vars['predecessorIdSet'][(int) $successionRow['predecessor_department_id']] = true;
        }
        $vars['allDepartmentList'] = db_all('SELECT id, name, is_active FROM departments WHERE id <> ? ORDER BY is_active DESC, sort_order, id', [$editId]);
    }
    $vars['newForm'] = isset($_GET['new']);
} elseif ($tab === 'users') {
    $deptFilter = (string) ($_GET['dept'] ?? '');
    $state = (string) ($_GET['state'] ?? 'active');
    $q = trim((string) ($_GET['q'] ?? ''));
    $where = [];
    $params = [];
    if ($deptFilter !== '' && preg_match('/^\d+$/', $deptFilter) === 1) {
        $where[] = 'u.department_id = ?';
        $params[] = (int) $deptFilter;
    }
    if ($state === 'active') {
        $where[] = 'u.is_active = 1';
    } elseif ($state === 'inactive') {
        $where[] = 'u.is_active = 0';
    }
    if ($q !== '') {
        $where[] = "(u.login_id LIKE ? ESCAPE '\\' OR u.display_name LIKE ? ESCAPE '\\')";
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $vars['users'] = db_all(
        "SELECT u.*, d.name AS dept_name, t.name AS team_name,
                (SELECT GROUP_CONCAT(d2.name || CASE r.is_final WHEN 1 THEN '（課長）' ELSE '（確認者' || COALESCE('・' || rt.name, '') || '）' END, '、')
                   FROM department_reviewers r JOIN departments d2 ON d2.id = r.department_id
                   LEFT JOIN department_teams rt ON rt.id = r.team_id WHERE r.user_id = u.id) AS roles
           FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN department_teams t ON t.id = u.team_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY d.sort_order, u.display_name',
        $params
    );
    $vars['departments'] = departments_all();
    $vars['deptFilter'] = $deptFilter;
    $vars['state'] = $state;
    $vars['q'] = $q;
} elseif ($tab === 'bulk') {
    $vars['bulkPlan'] = $bulkPlan;
    $vars['scheduleList'] = db_all('SELECT s.id, s.apply_on, s.change_count, s.status, s.result_text, s.created_at, s.finished_at, u.display_name AS created_by_name
                                       FROM org_schedules s LEFT JOIN users u ON u.id = s.created_by ORDER BY s.apply_on DESC, s.id DESC LIMIT 30');
} else {
    $vars['words'] = db_all('SELECT * FROM warning_words ORDER BY word');
}

render('admin_org', $vars);
