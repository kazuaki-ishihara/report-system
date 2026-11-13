<?php

declare(strict_types=1);

// ====================================================================
// G-08 提出状況（詳細設計書 G08_status.md）。中身は出さない。
// ====================================================================

$user = page_start();
$uid = (int) $user['id'];

$depts = status_departments($user);   // 管理者は全課（中身は出さない）
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
$isReviewerOfDept = is_reviewer_of($uid, $deptId);
$doesCheckInDept = $isReviewerOfDept && reviewer_does_check($uid, $deptId);   // 「チェックしない」の課代・リーダー（K-039）
// 補佐は、確認者でない課では人ごとの行を出さない（課ごとの件数だけ。K-043）
$isPersonRowsShown = is_admin($user) || !is_admin_deputy($user) || $isReviewerOfDept;

// ---- 「変更なし」の報告をまとめてチェック（G-08 §6.2） ----
// 対象: この課・この月の、提出済み・確認完了で「前月から変更なし」のまま直していない（revision = 0）、自分以外の報告
if (is_post()) {
    if (post_str('action') !== 'bulk_check') {
        error_page(400);
    }
    if (!$doesCheckInDept) {
        error_page(403);
    }
    rk_begin('status.bulk_check', $uid);
    $selectedReportIdList = $_POST['report_ids'] ?? [];
    if (!is_array($selectedReportIdList) || !$selectedReportIdList || count($selectedReportIdList) > 500) {
        rk_cancel();
        flash('error', msg('チェックする報告を選んでください'));
        redirect('/status/index.php?period=' . rawurlencode($period) . '&dept=' . $deptId);
    }
    // 先にすべて確かめる（1 件でも対象外なら何もしない）
    $bulkTargetReportList = [];   // [['id' => int, 'revision' => int], ...]
    foreach (array_unique($selectedReportIdList) as $selectedReportIdText) {
        if (!is_string($selectedReportIdText) || preg_match('/^\d{1,10}$/', $selectedReportIdText) !== 1) {
            error_page(400);
        }
        $bulkTargetReport = db_one(
            "SELECT id, revision FROM report_records
              WHERE id = ? AND department_id = ? AND period = ? AND status IN ('submitted', 'confirmed')
                AND unchanged = 1 AND revision = 0 AND is_legacy = 0 AND deleted_at IS NULL AND user_id <> ?",
            [(int) $selectedReportIdText, $deptId, $period, $uid]
        );
        if ($bulkTargetReport === null || !can_view_report($user, db_one('SELECT * FROM report_records WHERE id = ?', [(int) $bulkTargetReport['id']]))) {
            error_page(400, msg('まとめてチェックできない報告が含まれています。画面を開き直してください。'));
        }
        $bulkTargetReportList[] = ['id' => (int) $bulkTargetReport['id'], 'revision' => (int) $bulkTargetReport['revision']];
    }
    tx(function () use ($bulkTargetReportList, $uid): void {
        foreach ($bulkTargetReportList as $bulkTargetReport) {
            db_exec(
                'INSERT INTO report_reviews (report_id, user_id, checked_revision, checked_at) VALUES (?, ?, ?, ?)
                 ON CONFLICT(report_id, user_id) DO UPDATE SET checked_revision = excluded.checked_revision, checked_at = excluded.checked_at',
                [$bulkTargetReport['id'], $uid, $bulkTargetReport['revision'], now()]
            );
        }
    });
    flash('success', msg('{count} 件をチェック済みにしました', ['count' => count($bulkTargetReportList)]));
    rk_done('/status/index.php?period=' . rawurlencode($period) . '&dept=' . $deptId);
}

$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'unchecked', 'not_submitted', 'open_comments', 'warning'], true)) {
    $filter = 'all';
}

// 課の所属者と、その月にこの課で報告を作った人
$rows = db_all(
    "SELECT u.id AS user_id, u.display_name, r.id AS report_id, r.user_id AS report_user_id, r.department_id AS report_department_id,
            r.status, r.revision, r.submitted_at, r.unchanged, r.deleted_at,
            v.checked_revision AS my_check,
            (SELECT COUNT(*) FROM report_field_comments c WHERE c.report_id = r.id AND c.parent_id IS NULL AND c.thread_status = 'open' AND c.deleted_at IS NULL) AS open_count,
            (SELECT COUNT(*) FROM report_field_values w WHERE w.report_id = r.id AND w.has_warning = 1) AS warnings
       FROM users u
       LEFT JOIN report_records r ON r.user_id = u.id AND r.period = ? AND r.department_id = ? AND r.is_legacy = 0 AND r.deleted_at IS NULL
       LEFT JOIN report_reviews v ON v.report_id = r.id AND v.user_id = ?
      WHERE (u.department_id = ? AND u.is_active = 1) OR r.id IS NOT NULL
      ORDER BY u.display_name",
    [$period, $deptId, $uid, $deptId]
);

$stats = ['members' => count($rows), 'submitted' => 0, 'confirmed' => 0, 'unchecked' => 0];
$list = [];
$firstUnchecked = null;
$bulkCandidateCount = 0;   // まとめてチェックできる報告の数
foreach ($rows as $r) {
    $submitted = in_array($r['status'], ['submitted', 'confirmed'], true);
    $mine = (int) $r['user_id'] === $uid;
    // 中身へのリンクと自分のチェックの状態は、その報告を見られるときだけ（管理者でも確認者でなければ出さない。班ごとの課では担当の班だけ）
    $reportForPermission = $r['report_id'] === null ? null : [
        'id' => (int) $r['report_id'], 'user_id' => (int) $r['report_user_id'], 'department_id' => $r['report_department_id'],
        'status' => (string) $r['status'], 'deleted_at' => $r['deleted_at'], 'revision' => (int) $r['revision'],
    ];
    $r['can_view'] = $submitted && $reportForPermission !== null && can_view_report($user, $reportForPermission);
    $r['my_state'] = (!$r['can_view'] || $mine || !$doesCheckInDept) ? '' : ($r['my_check'] === null ? 'unchecked' : ((int) $r['my_check'] < (int) $r['revision'] ? 'updated' : 'checked'));
    $checkCounts = $submitted ? report_check_counts($reportForPermission) : ['checked' => 0, 'expected' => 0];
    $r['checked'] = $checkCounts['checked'];
    $r['reviewers'] = $checkCounts['expected'];
    // まとめてチェックできるか: 確認者で、「変更なし」のまま直していない、自分が未チェックの報告
    $r['can_bulk_check'] = $doesCheckInDept && $r['my_state'] === 'unchecked'
        && (int) $r['unchanged'] === 1 && (int) $r['revision'] === 0;
    if ($r['can_bulk_check']) {
        $bulkCandidateCount++;
    }
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
if (!$isPersonRowsShown) {
    $list = [];
    $bulkCandidateCount = 0;
}
if ($filter === 'unchecked' && $firstUnchecked !== null && ($_GET['go'] ?? '') === '1') {
    redirect('/reports/view.php?id=' . $firstUnchecked . '&seq=1');
}

render('status_index', [
    'title' => '提出状況', 'user' => $user, 'depts' => $depts, 'deptId' => $deptId, 'period' => $period,
    'filter' => $filter, 'list' => $list, 'stats' => $stats, 'firstUnchecked' => $firstUnchecked,
    'bulkCandidateCount' => $bulkCandidateCount,
    'isPersonRowsShown' => $isPersonRowsShown,
]);
