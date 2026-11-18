<?php

declare(strict_types=1);

// ====================================================================
// G-12 項目マスタ: 一覧・並べ替え・有効/無効（詳細設計書 G12_fields.md）
// ====================================================================

$user = page_start();
$uid = (int) $user['id'];

// 管理できる課
$manageable = [];
foreach (departments_all(true) as $d) {
    if (can_manage_fields_of($user, (int) $d['id'])) {
        $manageable[(int) $d['id']] = $d;
    }
}
if (!can_manage_system($user) && !$manageable) {
    error_page(403);
}

$deptParam = (string) ($_GET['dept'] ?? '');
if ($deptParam === '' ) {
    $deptParam = $manageable ? (string) array_key_first($manageable) : 'common';
}
$deptId = null;
if ($deptParam !== 'common') {
    if (preg_match('/^\d+$/', $deptParam) !== 1 || !isset($manageable[(int) $deptParam])) {
        error_page(403);
    }
    $deptId = (int) $deptParam;
}

/** この項目を変更できるか */
$canEdit = function (array $field) use ($user): bool {
    if ($field['department_id'] === null) {
        return can_manage_system($user);
    }
    return can_manage_fields_of($user, (int) $field['department_id']);
};

if (is_post()) {
    $action = post_str('action');
    rk_begin('admin.fields.' . $action, $uid);
    $fid = input_id('field_id', 'POST');
    $field = db_one('SELECT * FROM report_fields WHERE id = ?', [$fid]);
    if ($field === null) {
        error_page(404);
    }
    if (!$canEdit($field)) {
        error_page(403);
    }
    $back = '/admin/fields.php?dept=' . rawurlencode($deptParam);

    if ($action === 'move_up' || $action === 'move_down') {
        // 同じ区分・同じ適用（共通／課）の中で入れ替える
        $siblings = db_all(
            'SELECT id FROM report_fields WHERE field_scope = ? AND ' . ($field['department_id'] === null ? 'department_id IS NULL' : 'department_id = ?') . ' ORDER BY sort_order, id',
            $field['department_id'] === null ? [$field['field_scope']] : [$field['field_scope'], (int) $field['department_id']]
        );
        $ids = array_map(function ($r) { return (int) $r['id']; }, $siblings);
        $pos = array_search($fid, $ids, true);
        $swap = $action === 'move_up' ? $pos - 1 : $pos + 1;
        if ($pos !== false && $swap >= 0 && $swap < count($ids)) {
            $tmp = $ids[$pos];
            $ids[$pos] = $ids[$swap];
            $ids[$swap] = $tmp;
            tx(function () use ($ids) {
                foreach ($ids as $i => $id) {
                    db_exec('UPDATE report_fields SET sort_order = ?, updated_at = ? WHERE id = ?', [($i + 1) * 10, now(), $id]);
                }
            });
            audit_log('admin', 'field:' . $fid . ':reorder');
        }
        rk_done($back . '#field-' . $fid);   // 並べ替えた行の位置に戻る
    } elseif ($action === 'deactivate' || $action === 'activate') {
        if ($field['field_type'] === 'project') {
            flash('error', msg('案件の項目は無効にできません'));
            rk_done($back);
        }
        db_exec('UPDATE report_fields SET is_active = ?, updated_at = ? WHERE id = ?', [$action === 'activate' ? 1 : 0, now(), $fid]);
        audit_log('admin', 'field:' . $fid . ':' . $action);
        flash('success', $action === 'activate' ? msg('有効にしました') : msg('無効にしました。新しい報告に出なくなります（過去の報告には残ります）'));
        rk_done($back . '#field-' . $fid);
    }
    error_page(400);
}

// 一覧（共通項目＋選んだ課の課別項目）
$rows = db_all(
    'SELECT f.*, d.name AS dept_name,
            (SELECT COUNT(*) FROM report_field_values v WHERE v.field_id = f.id) AS used
       FROM report_fields f LEFT JOIN departments d ON d.id = f.department_id
      WHERE f.department_id IS NULL OR f.department_id = ?
      ORDER BY f.is_active DESC, f.field_scope DESC, (f.department_id IS NOT NULL), f.sort_order, f.id',
    [$deptId ?? 0]
);

render('admin_fields', [
    'title'      => '項目マスタ',
    'user'       => $user,
    'rows'       => $rows,
    'deptParam'  => $deptParam,
    'deptId'     => $deptId,
    'manageable' => $manageable,
    'canEdit'    => $canEdit,
]);
