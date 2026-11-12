<?php

declare(strict_types=1);

// ====================================================================
// G-13 案件の管理（詳細設計書 G13_projects.md）
// 客先名・案件名は入れない。案件コードと、客先が特定されない社内向けの呼び名だけ。
// ====================================================================

$user = page_start();
require_admin($user);
$errors = [];
$warn = false;

if (is_post()) {
    $action = post_str('action');
    rk_begin('admin.projects.' . $action, (int) $user['id']);

    if ($action === 'add' || $action === 'update') {
        $alias = post_str('alias');
        $deptRaw = post_str('department_id');
        validate_text($alias, '社内向けの呼び名', 40, false, $errors);
        $deptId = null;
        if ($deptRaw !== '') {
            if (preg_match('/^\d+$/', $deptRaw) !== 1 || db_one('SELECT id FROM departments WHERE id = ?', [(int) $deptRaw]) === null) {
                $errors[] = '課が正しくありません';
            } else {
                $deptId = (int) $deptRaw;
            }
        }
        if (!$errors) {
            if ($action === 'add') {
                $code = tx(function () use ($alias, $deptId) {
                    // コードは最大の番号 + 1。終了・削除したコードも再利用しない
                    $max = 0;
                    foreach (db_all("SELECT code FROM projects WHERE code LIKE 'P-%'") as $r) {
                        if (preg_match('/^P-(\d+)$/', (string) $r['code'], $m) === 1) {
                            $max = max($max, (int) $m[1]);
                        }
                    }
                    $last = (int) db_val("SELECT value FROM app_state WHERE name = 'project_seq'");
                    $next = max($max, $last) + 1;
                    db_exec("INSERT INTO app_state (name, value) VALUES ('project_seq', ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value", [(string) $next]);
                    $code = 'P-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
                    db_exec('INSERT INTO projects (code, alias, department_id, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)', [$code, $alias, $deptId, now(), now()]);
                    audit_log('admin', 'project:' . (int) db()->lastInsertId() . ':create');
                    return $code;
                });
                flash('success', '案件 ' . $code . ' を追加しました');
            } else {
                $id = input_id('id', 'POST');
                if (db_one('SELECT id FROM projects WHERE id = ?', [$id]) === null) {
                    error_page(404);
                }
                db_exec('UPDATE projects SET alias = ?, department_id = ?, updated_at = ? WHERE id = ?', [$alias, $deptId, now(), $id]);
                audit_log('admin', 'project:' . $id . ':update');
                flash('success', '保存しました');
            }
            if (text_has_warning($alias)) {
                flash('info', '呼び名に客先名や案件名が含まれていませんか');
            }
            rk_done('/admin/projects.php');
        }
        rk_cancel();
    } elseif ($action === 'end' || $action === 'reopen') {
        $id = input_id('id', 'POST');
        if (db_one('SELECT id FROM projects WHERE id = ?', [$id]) === null) {
            error_page(404);
        }
        if ($action === 'end') {
            $clear = ($_POST['clear_alias'] ?? '') === '1';
            db_exec('UPDATE projects SET is_active = 0, ended_on = ?, alias = CASE WHEN ? = 1 THEN \'\' ELSE alias END, updated_at = ? WHERE id = ?', [date('Y-m-d'), $clear ? 1 : 0, now(), $id]);
            flash('success', '案件を終了にしました。次から作る報告では選べません');
        } else {
            db_exec('UPDATE projects SET is_active = 1, ended_on = NULL, updated_at = ? WHERE id = ?', [now(), $id]);
            flash('success', '案件を再開しました');
        }
        audit_log('admin', 'project:' . $id . ':' . $action);
        rk_done('/admin/projects.php');
    } else {
        error_page(400);
    }
}

$state = (string) ($_GET['state'] ?? 'active');
$where = $state === 'ended' ? 'p.is_active = 0' : ($state === 'all' ? '1 = 1' : 'p.is_active = 1');
$latest = db_val('SELECT MAX(period) FROM report_records WHERE deleted_at IS NULL AND is_legacy = 0');
$projects = db_all(
    "SELECT p.*, d.name AS dept_name,
            (SELECT COUNT(DISTINCT r.user_id) FROM report_projects rp JOIN report_records r ON r.id = rp.report_id
              WHERE rp.project_id = p.id AND r.period = ? AND r.deleted_at IS NULL) AS users_now
       FROM projects p LEFT JOIN departments d ON d.id = p.department_id
      WHERE {$where} ORDER BY p.code",
    [(string) $latest]
);

render('admin_projects', [
    'title'       => '案件の管理',
    'user'        => $user,
    'projects'    => $projects,
    'state'       => $state,
    'departments' => departments_all(true),
    'errors'      => $errors,
    'editId'      => isset($_GET['edit']) ? input_id('edit') : null,
]);
