<?php

declare(strict_types=1);

// ====================================================================
// G-05 報告の詳細（詳細設計書 G05_report_detail.md）
// ====================================================================

$user = page_start();
$uid = (int) $user['id'];

$id = input_id('id');
$report = report_find($id);
if ($report === null || !can_view_report($user, $report)) {
    error_page(404);
}
$owner = db_one('SELECT id, display_name, login_id FROM users WHERE id = ?', [(int) $report['user_id']]);
$isOwner = (int) $report['user_id'] === $uid;
$deptId = $report['department_id'] === null ? 0 : (int) $report['department_id'];
$isReviewer = !$isOwner && reviewer_can_see_report($uid, $report, false);
$isExpectedReviewer = !$isOwner && in_array($uid, report_expected_reviewer_ids($report), true);
$isFinal = reviewer_can_see_report($uid, $report, true, false);
$canRestricted = can_view_restricted($user, $report);

if (!$isOwner) {
    audit_log('view', 'report:' . $id);
}

$fields = form_snapshot_fields($id);
$values = report_values($id);
$blocks = report_blocks($id);

// 確認者ごとのチェック状況
$reviewers = db_all(
    'SELECT r.user_id, r.is_final, u.display_name, v.checked_revision, v.checked_at
       FROM department_reviewers r JOIN users u ON u.id = r.user_id
       LEFT JOIN report_reviews v ON v.report_id = ? AND v.user_id = r.user_id
      WHERE r.department_id = ? AND r.user_id <> ? AND u.is_active = 1
      ORDER BY r.is_final DESC, r.sort_order',
    [$id, $deptId, (int) $report['user_id']]
);
// チェックを求める確認者だけを出す（班ごとの課では、担当の班の確認者と課全体の確認者・課長）
$expectedReviewerIdSet = array_fill_keys(report_expected_reviewer_ids($report), true);
$reviewers = array_values(array_filter($reviewers, function (array $reviewerRow) use ($expectedReviewerIdSet): bool {
    return isset($expectedReviewerIdSet[(int) $reviewerRow['user_id']]);
}));
$myCheck = db_one('SELECT checked_revision FROM report_reviews WHERE report_id = ? AND user_id = ?', [$id, $uid]);

// 自分が最後にチェックしたあとに変わった値
$changedKeys = [];
if ($myCheck !== null) {
    foreach (db_all('SELECT DISTINCT field_id, report_project_id FROM report_value_history WHERE report_id = ? AND revision > ?', [$id, (int) $myCheck['checked_revision']]) as $c) {
        $changedKeys[(int) $c['report_project_id'] . ':' . (int) $c['field_id']] = true;
    }
}

// コメント（復号して、対象ごと・スレッドごとにまとめる）
$restrictedFieldIds = [];
foreach ($fields as $f) {
    if ($f['conf'] === 'restricted') {
        $restrictedFieldIds[$f['id']] = true;
    }
}
$threads = [];
$comments = db_all(
    'SELECT c.*, u.display_name AS author FROM report_field_comments c JOIN users u ON u.id = c.user_id
      WHERE c.report_id = ? ORDER BY c.created_at, c.id',
    [$id]
);
foreach ($comments as $c) {
    $fid = $c['field_id'] === null ? 0 : (int) $c['field_id'];
    if (!$canRestricted && isset($restrictedFieldIds[$fid])) {
        continue;
    }
    $c['text'] = $c['deleted_at'] !== null ? null : dec((string) $c['comment_enc'], 'cmt:' . $id . ':' . (int) $c['id']);
    $target = (int) $c['report_project_id'] . ':' . $fid;
    if ($c['parent_id'] === null) {
        $threads[$target][(int) $c['id']] = ['head' => $c, 'replies' => []];
    } else {
        foreach ($threads as $t => $list) {
            if (isset($list[(int) $c['parent_id']])) {
                $threads[$t][(int) $c['parent_id']]['replies'][] = $c;
                break;
            }
        }
    }
}

// 変更履歴（提出後）
$history = [];
foreach (db_all('SELECT h.*, u.display_name AS author FROM report_value_history h JOIN users u ON u.id = h.user_id WHERE h.report_id = ? ORDER BY h.id DESC', [$id]) as $hrow) {
    $fid = (int) $hrow['field_id'];
    if (!$canRestricted && isset($restrictedFieldIds[$fid])) {
        continue;
    }
    if ((int) $hrow['is_encrypted'] === 1) {
        $aad = 'rvh:' . $id . ':' . $fid . ':' . (int) $hrow['report_project_id'] . ':' . (int) $hrow['revision'];
        $hrow['old_value'] = dec((string) $hrow['old_value'], $aad . ':old');
        $hrow['new_value'] = dec((string) $hrow['new_value'], $aad . ':new');
    }
    $history[] = $hrow;
}

// 前月との比べ（G-05 §4.2）。移行データの報告では比べない
$previousComparison = (int) $report['is_legacy'] === 1
    ? report_no_previous_comparison()
    : report_previous_comparison($user, (int) $report['user_id'], (string) $report['period']);

$canComment = !$isOwner && $isReviewer && $report['status'] !== 'draft';

render('report_view', [
    'commentTemplateList' => $canComment && $deptId > 0 ? comment_templates_of($deptId) : [],
    'personalCommentTemplateList' => $canComment ? comment_template_rows('mine', $uid) : [],
    'previousComparison' => $previousComparison,
    'title'         => period_label((string) $report['period']) . 'の報告',
    'user'          => $user,
    'report'        => $report,
    'owner'         => $owner,
    'isOwner'       => $isOwner,
    'isReviewer'    => $isReviewer,
    'isExpectedReviewer' => $isExpectedReviewer,
    'isFinal'       => $isFinal,
    'canRestricted' => $canRestricted,
    'fields'        => $fields,
    'values'        => $values,
    'blocks'        => $blocks,
    'reviewers'     => $reviewers,
    'myCheck'       => $myCheck,
    'changedKeys'   => $changedKeys,
    'threads'       => $threads,
    'history'       => $history,
    'canComment'    => $canComment,
    'next'          => isset($_GET['seq']) && $_GET['seq'] === '1',
]);
