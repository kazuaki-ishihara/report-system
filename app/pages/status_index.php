<?php

declare(strict_types=1);

// ====================================================================
// G-08 提出状況（詳細設計書 G08_status.md）。中身は出さない。
// ====================================================================

$user = page_start();
$uid = (int) $user['id'];

$depts = reviewable_departments($user);
if (!$depts) {
    error_page(403);
}
$period = (string) ($_GET['period'] ?? report_target_period($user));
if (!is_valid_period($period)) {
    error_page(400);
}
$deptId = isset($_GET['dept']) ? input_id('dept') : (int) array_key_first($depts);
if (!isset($depts[$deptId])) {
    error_page(403);
}
$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'unchecked', 'not_submitted', 'open_comments', 'warning'], true)) {
    $filter = 'all';
}

// 課の所属者と、その月にこの課で報告を作った人
$rows = db_all(
    "SELECT u.id AS user_id, u.display_name, r.id AS report_id, r.status, r.revision, r.submitted_at, r.unchanged,
            v.checked_revision AS my_check,
            (SELECT COUNT(*) FROM report_reviews x WHERE x.report_id = r.id AND x.checked_revision >= r.revision AND x.user_id <> r.user_id) AS checked,
            (SELECT COUNT(*) FROM department_reviewers d WHERE d.department_id = ? AND d.user_id <> u.id) AS reviewers,
            (SELECT COUNT(*) FROM report_field_comments c WHERE c.report_id = r.id AND c.parent_id IS NULL AND c.thread_status = 'open' AND c.deleted_at IS NULL) AS open_count,
            (SELECT COUNT(*) FROM report_field_values w WHERE w.report_id = r.id AND w.has_warning = 1) AS warnings
       FROM users u
       LEFT JOIN report_records r ON r.user_id = u.id AND r.period = ? AND r.department_id = ? AND r.is_legacy = 0 AND r.deleted_at IS NULL
       LEFT JOIN report_reviews v ON v.report_id = r.id AND v.user_id = ?
      WHERE (u.department_id = ? AND u.is_active = 1) OR r.id IS NOT NULL
      ORDER BY u.display_name",
    [$deptId, $period, $deptId, $uid, $deptId]
);

$stats = ['members' => count($rows), 'submitted' => 0, 'confirmed' => 0, 'unchecked' => 0];
$list = [];
$firstUnchecked = null;
foreach ($rows as $r) {
    $submitted = in_array($r['status'], ['submitted', 'confirmed'], true);
    $mine = (int) $r['user_id'] === $uid;
    $r['my_state'] = (!$submitted || $mine) ? '' : ($r['my_check'] === null ? 'unchecked' : ((int) $r['my_check'] < (int) $r['revision'] ? 'updated' : 'checked'));
    if ($submitted) {
        $stats['submitted']++;
    }
    if ($r['status'] === 'confirmed') {
        $stats['confirmed']++;
    }
    if ($r['my_state'] === 'unchecked' || $r['my_state'] === 'updated') {
        $stats['unchecked']++;
        if ($firstUnchecked === null) {
            $firstUnchecked = (int) $r['report_id'];
        }
    }
    $keep = true;
    if ($filter === 'unchecked') {
        $keep = in_array($r['my_state'], ['unchecked', 'updated'], true);
    } elseif ($filter === 'not_submitted') {
        $keep = !$submitted;
    } elseif ($filter === 'open_comments') {
        $keep = (int) $r['open_count'] > 0;
    } elseif ($filter === 'warning') {
        $keep = $submitted && (int) $r['warnings'] > 0;
    }
    if ($keep) {
        $list[] = $r;
    }
}
if ($filter === 'unchecked' && $firstUnchecked !== null && ($_GET['go'] ?? '') === '1') {
    redirect('/reports/view.php?id=' . $firstUnchecked . '&seq=1');
}

render('status_index', [
    'title' => '提出状況', 'user' => $user, 'depts' => $depts, 'deptId' => $deptId, 'period' => $period,
    'filter' => $filter, 'list' => $list, 'stats' => $stats, 'firstUnchecked' => $firstUnchecked,
]);
