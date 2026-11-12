<?php

declare(strict_types=1);

// ====================================================================
// G-14 組織・ユーザーの管理: 課・確認者・注意の言葉（詳細設計書 G14_org_users.md）
// ユーザーの編集は admin_user_edit.php
// ====================================================================

$user = page_start();
require_admin($user);

$tab = (string) ($_GET['tab'] ?? 'departments');
if (!in_array($tab, ['departments', 'users', 'words'], true)) {
    $tab = 'departments';
}
$errors = [];

if (is_post()) {
    $action = post_str('action');
    rk_begin('admin.org.' . $action, (int) $user['id']);

    if ($action === 'dept_save') {
        $id = isset($_POST['id']) && $_POST['id'] !== '' ? input_id('id', 'POST') : null;
        $name = post_str('name');
        $closeRaw = post_str('close_day');
        $sort = post_str('sort_order');
        validate_text($name, '課の名前', 40, true, $errors);
        $close = null;
        if ($closeRaw !== '') {
            if (preg_match('/^\d{1,2}$/', $closeRaw) !== 1 || (int) $closeRaw < 1 || (int) $closeRaw > 31) {
                $errors[] = '締め日は 1〜31（31 は月末）にしてください';
            } else {
                $close = (int) $closeRaw;
            }
        }
        $sortN = preg_match('/^\d{1,6}$/', $sort) === 1 ? (int) $sort : 0;
        $dup = db_val('SELECT id FROM departments WHERE name = ? AND id <> ?', [$name, $id ?? 0]);
        if ($dup !== null) {
            $errors[] = '同じ名前の課があります';
        }
        $active = ($_POST['is_active'] ?? '') === '1' ? 1 : 0;
        if ($id !== null && $active === 0) {
            $members = (int) db_val('SELECT COUNT(*) FROM users WHERE department_id = ? AND is_active = 1', [$id]);
            if ($members > 0) {
                $errors[] = '所属している人がいるため、無効にできません';
            }
        }
        if (!$errors) {
            if ($id === null) {
                db_exec('INSERT INTO departments (name, sort_order, close_day, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)', [$name, $sortN, $close, now(), now()]);
                $id = (int) db()->lastInsertId();
                audit_log('admin', 'department:' . $id . ':create');
            } else {
                if (db_one('SELECT id FROM departments WHERE id = ?', [$id]) === null) {
                    error_page(404);
                }
                db_exec('UPDATE departments SET name = ?, sort_order = ?, close_day = ?, is_active = ?, updated_at = ? WHERE id = ?', [$name, $sortN, $close, $active, now(), $id]);
                audit_log('admin', 'department:' . $id . ':update');
            }
            flash('success', '保存しました');
            rk_done('/admin/org.php?tab=departments&edit=' . $id);
        }
        rk_cancel();

    } elseif ($action === 'roles_save') {
        // 課の確認者・課長・項目管理者をまとめて保存する
        $deptId = input_id('dept_id', 'POST');
        if (db_one('SELECT id FROM departments WHERE id = ?', [$deptId]) === null) {
            error_page(404);
        }
        $roles = $_POST['role'] ?? [];
        $fieldAdmins = $_POST['field_admin'] ?? [];
        if (!is_array($roles) || !is_array($fieldAdmins)) {
            error_page(400);
        }
        $finals = 0;
        $clean = [];
        foreach ($roles as $uid => $role) {
            if (!is_string($role) || preg_match('/^\d+$/', (string) $uid) !== 1 || !in_array($role, ['', 'reviewer', 'final'], true)) {
                error_page(400);
            }
            if ($role === '') {
                continue;
            }
            if (db_one('SELECT id FROM users WHERE id = ? AND is_active = 1', [(int) $uid]) === null) {
                error_page(400);
            }
            $clean[(int) $uid] = $role;
            if ($role === 'final') {
                $finals++;
            }
        }
        if ($finals > 1) {
            $errors[] = '課長（最終確認者）は 1 人にしてください';
        }
        $admins = [];
        foreach ($fieldAdmins as $uid => $v) {
            if (preg_match('/^\d+$/', (string) $uid) !== 1 || $v !== '1') {
                error_page(400);
            }
            $admins[] = (int) $uid;
        }
        if (!$errors) {
            tx(function () use ($deptId, $clean, $admins) {
                db_exec('DELETE FROM department_reviewers WHERE department_id = ?', [$deptId]);
                $i = 0;
                foreach ($clean as $uid => $role) {
                    $i += 10;
                    db_exec('INSERT INTO department_reviewers (department_id, user_id, is_final, sort_order) VALUES (?, ?, ?, ?)', [$deptId, $uid, $role === 'final' ? 1 : 0, $role === 'final' ? 0 : $i]);
                }
                db_exec('DELETE FROM department_admins WHERE department_id = ?', [$deptId]);
                foreach ($admins as $uid) {
                    db_exec('INSERT INTO department_admins (department_id, user_id) VALUES (?, ?)', [$deptId, $uid]);
                }
            });
            audit_log('admin', 'department:' . $deptId . ':roles');
            flash('success', '確認者を保存しました');
            rk_done('/admin/org.php?tab=departments&edit=' . $deptId);
        }
        rk_cancel();

    } elseif ($action === 'word_add') {
        $tab = 'words';
        $word = post_str('word');
        validate_text($word, '言葉', 20, true, $errors);
        if (!$errors && db_val('SELECT 1 FROM warning_words WHERE word = ?', [$word]) !== null) {
            $errors[] = '同じ言葉があります';
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
    foreach (db_all('SELECT r.department_id, r.is_final, u.display_name FROM department_reviewers r JOIN users u ON u.id = r.user_id ORDER BY r.is_final DESC, r.sort_order') as $r) {
        $vars['reviewers'][(int) $r['department_id']][] = $r;
    }
    $editId = isset($_GET['edit']) ? input_id('edit') : null;
    $vars['edit'] = null;
    if ($editId !== null) {
        $vars['edit'] = db_one('SELECT * FROM departments WHERE id = ?', [$editId]);
        if ($vars['edit'] === null) {
            error_page(404);
        }
        $vars['candidates'] = db_all(
            'SELECT u.id, u.login_id, u.display_name, u.department_id, d.name AS dept_name,
                    r.is_final, (r.user_id IS NOT NULL) AS is_reviewer, (a.user_id IS NOT NULL) AS is_field_admin
               FROM users u
               LEFT JOIN departments d ON d.id = u.department_id
               LEFT JOIN department_reviewers r ON r.user_id = u.id AND r.department_id = ?
               LEFT JOIN department_admins a ON a.user_id = u.id AND a.department_id = ?
              WHERE u.is_active = 1
              ORDER BY (u.department_id = ?) DESC, u.display_name',
            [$editId, $editId, $editId]
        );
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
        "SELECT u.*, d.name AS dept_name,
                (SELECT GROUP_CONCAT(d2.name || CASE r.is_final WHEN 1 THEN '（課長）' ELSE '（確認者）' END, '、')
                   FROM department_reviewers r JOIN departments d2 ON d2.id = r.department_id WHERE r.user_id = u.id) AS roles
           FROM users u LEFT JOIN departments d ON d.id = u.department_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY d.sort_order, u.display_name',
        $params
    );
    $vars['departments'] = departments_all();
    $vars['deptFilter'] = $deptFilter;
    $vars['state'] = $state;
    $vars['q'] = $q;
} else {
    $vars['words'] = db_all('SELECT * FROM warning_words ORDER BY word');
}

render('admin_org', $vars);
