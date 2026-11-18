<?php

declare(strict_types=1);

// ====================================================================
// G-09 横並び表示・状況のまとめ（詳細設計書 G09_compare.md）
// 一覧では自由記述を復号しない。セルを開いたときだけ 1 つを復号して返す。
// ====================================================================

$user = page_start();
$uid = (int) $user['id'];
$depts = reviewable_departments($user);
if (!$depts) {
    error_page(403);
}

// 自由記述のセルを 1 つ開く（JSON）
if (isset($_GET['cell'])) {
    if (preg_match('/^(\d+):(\d+):(\d+)$/', (string) $_GET['cell'], $m) !== 1) {
        error_page(400);
    }
    [$_x, $rid, $rpId, $fid] = array_map('intval', $m);
    $report = report_find($rid);
    if ($report === null || !can_view_report($user, $report) || $report['status'] === 'draft') {
        json_response(['ok' => false], 404);
    }
    $def = null;
    foreach (form_snapshot_fields($rid) as $f) {
        if ($f['id'] === $fid) {
            $def = $f;
        }
    }
    if ($def === null || ($def['conf'] === 'restricted' && !can_view_restricted($user, $report))) {
        json_response(['ok' => false], 404);
    }
    $v = report_values($rid)[$rpId][$fid]['value'] ?? '';
    audit_log('view', 'report:' . $rid);
    json_response(['ok' => true, 'text' => export_text($def, (string) $v)]);
}

$period = (string) ($_GET['period'] ?? report_target_period($user));
if (!is_valid_period($period)) {
    error_page(400);
}
$deptId = isset($_GET['dept']) ? input_id('dept') : (int) array_key_first($depts);
if (!isset($depts[$deptId])) {
    error_page(403);
}
$view = ($_GET['view'] ?? 'table') === 'summary' ? 'summary' : 'table';
$diff = ($_GET['diff'] ?? '1') === '1';
$canRestricted = is_final_reviewer_of($uid, $deptId);

// 自由記述は列としては出すが、値は開いたときだけ復号する（ここでは withFree=false で選択式だけを集める）
$data = compare_dataset($deptId, $period, $period, false, $canRestricted, true, $user);
$freeColumns = array_values(array_filter(form_template_fields($deptId), function ($f) use ($canRestricted) {
    return form_is_free_text($f) && ($f['conf'] !== 'restricted' || $canRestricted);
}));
$prevData = $diff ? compare_dataset($deptId, period_shift($period, -1), period_shift($period, -1), false, $canRestricted, true, $user) : ['rows' => []];
$prevIndex = [];
foreach ($prevData['rows'] as $r) {
    $prevIndex[$r['name'] . '|' . $r['project']] = $r;
}
// 自由記述に値があるか（印だけ出す）
$hasFree = [];
foreach ($data['rows'] as $r) {
    foreach (db_all("SELECT v.field_id, v.report_project_id FROM report_field_values v WHERE v.report_id = ? AND v.value_text <> ''", [$r['report_id']]) as $v) {
        $hasFree[$r['report_id'] . ':' . (int) $v['report_project_id'] . ':' . (int) $v['field_id']] = true;
    }
}
audit_log('view', 'compare:period=' . $period . ';dept=' . $deptId);

render('status_compare', [
    'title' => '横並び・まとめ', 'user' => $user, 'depts' => $depts, 'deptId' => $deptId, 'period' => $period, 'view' => $view,
    'diff' => $diff, 'data' => $data, 'prevIndex' => $prevIndex, 'freeColumns' => $freeColumns, 'hasFree' => $hasFree,
    'summary' => compare_summary($data, $period),
]);
