<?php

declare(strict_types=1);

// ====================================================================
// G-03 ホーム（詳細設計書 G03_home.md）
// 中身（値・コメントの本文）は出さない。件数と項目名だけ。
// ====================================================================

$user = page_start();
$uid = (int) $user['id'];

$period = report_target_period($user);
$prevPeriod = period_shift($period, -1);

$mine = db_one('SELECT id, status FROM report_records WHERE user_id = ? AND period = ? AND is_legacy = 0 AND deleted_at IS NULL', [$uid, $period]);
$prev = db_one('SELECT id, status FROM report_records WHERE user_id = ? AND period = ? AND is_legacy = 0 AND deleted_at IS NULL', [$uid, $prevPeriod]);
$closeDate = report_close_date($user, $period);
$closeSoon = $closeDate !== null && strtotime($closeDate) - strtotime(date('Y-m-d')) <= 3 * 86400;

// 回答待ちのコメント（自分の報告への、最初のコメントで open のもの）
$openComments = db_all(
    "SELECT c.id, c.report_id, COALESCE(rf.label, '報告全体') AS field_label, u.display_name AS author
       FROM report_field_comments c
       JOIN report_records r ON r.id = c.report_id
       LEFT JOIN report_record_fields rf ON rf.report_id = c.report_id AND rf.field_id = c.field_id
       JOIN users u ON u.id = c.user_id
      WHERE r.user_id = ? AND r.deleted_at IS NULL
        AND c.parent_id IS NULL AND c.thread_status = 'open' AND c.deleted_at IS NULL
      ORDER BY c.created_at",
    [$uid]
);

// 臨時の調査（自分が対象で、公開中、未回答）
$surveys = db_all(
    "SELECT q.id, q.title, q.due_at
       FROM adhoc_requests q
       JOIN adhoc_request_targets t ON t.request_id = q.id AND t.user_id = ?
       LEFT JOIN adhoc_responses a ON a.request_id = q.id AND a.user_id = ?
      WHERE q.status = 'open' AND q.deleted_at IS NULL AND (a.submitted_at IS NULL)
      ORDER BY q.due_at",
    [$uid, $uid]
);

// 確認者の欄（課ごと）
$reviewSections = [];
foreach (reviewer_departments($uid) as $deptId => $isFinal) {
    $dept = db_one('SELECT id, name FROM departments WHERE id = ? AND is_active = 1', [$deptId]);
    if ($dept === null) {
        continue;
    }
    // 未チェック: その課・対象月の、自分が見られる（班ごとの課では担当の班の）報告のうち、チェックしていない・更新ありのもの
    // 「チェックしない」の課代・リーダーは数えない（K-039）
    $doesCheck = reviewer_does_check($uid, (int) $deptId);
    $unchecked = 0;
    foreach (!$doesCheck ? [] : db_all(
        "SELECT r.* FROM report_records r
           LEFT JOIN report_reviews v ON v.report_id = r.id AND v.user_id = ?
          WHERE r.department_id = ? AND r.period = ? AND r.status IN ('submitted', 'confirmed')
            AND r.is_legacy = 0 AND r.deleted_at IS NULL AND r.user_id <> ?
            AND (v.report_id IS NULL OR v.checked_revision < r.revision)",
        [$uid, $deptId, $period, $uid]
    ) as $uncheckedReport) {
        $unchecked += can_view_report($user, $uncheckedReport) ? 1 : 0;
    }
    $answered = (int) db_val(
        "SELECT COUNT(*) FROM report_field_comments c JOIN report_records r ON r.id = c.report_id
          WHERE c.user_id = ? AND r.department_id = ? AND c.parent_id IS NULL
            AND c.thread_status = 'answered' AND c.deleted_at IS NULL AND r.deleted_at IS NULL",
        [$uid, $deptId]
    );
    $notSubmitted = null;
    if ($isFinal) {
        $notSubmitted = (int) db_val(
            "SELECT COUNT(*) FROM users u
              WHERE u.department_id = ? AND u.is_active = 1
                AND NOT EXISTS (SELECT 1 FROM report_records r WHERE r.user_id = u.id AND r.period = ?
                                AND r.status IN ('submitted', 'confirmed') AND r.is_legacy = 0 AND r.deleted_at IS NULL)",
            [$deptId, $period]
        );
    }
    $reviewSections[] = [
        'dept' => $dept, 'isFinal' => $isFinal, 'unchecked' => $unchecked, 'doesCheck' => $doesCheck,
        'answered' => $answered, 'notSubmitted' => $notSubmitted,
    ];
}

// 課長がいない課（管理者に知らせる。K-031）
$departmentsWithoutFinal = can_manage_system($user) ? db_all(
    "SELECT d.id, d.name FROM departments d
      WHERE d.is_active = 1 AND NOT EXISTS (
            SELECT 1 FROM department_reviewers r JOIN users u ON u.id = r.user_id AND u.is_active = 1
             WHERE r.department_id = d.id AND r.is_final = 1)
      ORDER BY d.sort_order, d.id"
) : [];

// 要望・依頼（G-22）: 出した人には管理者側の返信、管理者・補佐には未受付・返信待ちの数
$supportRequestAnsweredCount = support_request_can_submit($user) ? support_request_answered_count((int) $user['id']) : 0;
$supportRequestStaffCounts = support_request_is_staff($user) ? support_request_staff_counts() : null;

render('home', [
    'activeNoticeList'            => notices_active(5),   // 更新のお知らせ（便利機能 A）
    'supportRequestAnsweredCount' => $supportRequestAnsweredCount,
    'supportRequestStaffCounts'   => $supportRequestStaffCounts,
    'departmentsWithoutFinal' => $departmentsWithoutFinal,
    'title'          => 'ホーム',
    'user'           => $user,
    'period'         => $period,
    'prevPeriod'     => $prevPeriod,
    'mine'           => $mine,
    'prev'           => $prev,
    'closeDate'      => $closeDate,
    'closeSoon'      => $closeSoon,
    'openComments'   => $openComments,
    'surveys'        => $surveys,
    'reviewSections' => $reviewSections,
]);
