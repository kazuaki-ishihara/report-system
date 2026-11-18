<?php

declare(strict_types=1);

// ====================================================================
// G-06 自分の報告一覧（詳細設計書 G06_my_reports.md）。本文は出さない。
// ====================================================================

$user = page_start();
$uid = (int) $user['id'];
$page = isset($_GET['page']) ? input_id('page') : 1;
$per = 12;

$rows = db_all(
    "SELECT r.id, r.user_id, r.department_id, r.period, r.status, r.revision, r.is_legacy, r.unchanged,
            (SELECT COUNT(*) FROM report_field_comments c WHERE c.report_id = r.id AND c.parent_id IS NULL AND c.thread_status = 'open' AND c.deleted_at IS NULL) AS open_count
       FROM report_records r
      WHERE r.user_id = ? AND r.deleted_at IS NULL
      ORDER BY r.period DESC, r.id DESC
      LIMIT ? OFFSET ?",
    [$uid, $per + 1, ($page - 1) * $per]
);
$hasMore = count($rows) > $per;
$rows = array_slice($rows, 0, $per);
// チェック n/m（チェックを求める確認者だけ数える。班ごとの課では担当の班の確認者と課全体の確認者）
foreach ($rows as $rowIndex => $reportRow) {
    $checkCounts = report_check_counts($reportRow);
    $rows[$rowIndex]['checked'] = $checkCounts['checked'];
    $rows[$rowIndex]['reviewers'] = $checkCounts['expected'];
}

render('report_mine', ['title' => '自分の報告', 'user' => $user, 'rows' => $rows, 'page' => $page, 'hasMore' => $hasMore]);
