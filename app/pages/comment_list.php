<?php

declare(strict_types=1);

// ====================================================================
// G-18 コメントの一覧（詳細設計書 G18_comments.md）
// 報告をまたいで、自分が出したコメント（確認者）と、自分の報告へのコメント（本人）を一覧にする。
// 本文は先頭 40 文字だけ出す。押すと G-05 のそのコメントへ。
// ====================================================================

$user = page_start();
$userId = (int) $user['id'];

$tabName = (string) ($_GET['tab'] ?? '');
if (!in_array($tabName, ['sent', 'received'], true)) {
    // 既定: 確認者なら自分が出したコメント、そうでなければ自分の報告へのコメント
    $tabName = reviewer_departments($userId) ? 'sent' : 'received';
}
$statusFilterName = (string) ($_GET['status'] ?? 'unresolved');
$threadStatusListByFilterName = [
    'unresolved' => ['open', 'answered'],
    'open'       => ['open'],
    'answered'   => ['answered'],
    'resolved'   => ['resolved'],
];
if (!isset($threadStatusListByFilterName[$statusFilterName])) {
    $statusFilterName = 'unresolved';
}
$threadStatusList = $threadStatusListByFilterName[$statusFilterName];
$statusPlaceholders = implode(', ', array_fill(0, count($threadStatusList), '?'));

// 最初のコメント（返信でないもの）で、状態のあるもの（連絡 note は状態がないので出さない）
$commentRowList = db_all(
    "SELECT c.id, c.report_id, c.field_id, c.report_project_id, c.user_id AS author_id, c.comment_type, c.thread_status,
            c.comment_enc, c.created_at,
            r.period, r.user_id AS owner_id, r.department_id, r.status AS report_status, r.deleted_at AS report_deleted_at,
            r.is_legacy, r.free_text_purged_at,
            owner.display_name AS owner_name, author.display_name AS author_name,
            COALESCE(rf.label, '') AS field_label, rf.confidentiality AS field_confidentiality,
            p.code AS project_code,
            (SELECT COUNT(*) FROM report_field_comments reply WHERE reply.parent_id = c.id AND reply.deleted_at IS NULL) AS reply_count
       FROM report_field_comments c
       JOIN report_records r ON r.id = c.report_id
       JOIN users owner ON owner.id = r.user_id
       JOIN users author ON author.id = c.user_id
       LEFT JOIN report_record_fields rf ON rf.report_id = c.report_id AND rf.field_id = c.field_id
       LEFT JOIN report_projects rp ON rp.id = c.report_project_id
       LEFT JOIN projects p ON p.id = rp.project_id
      WHERE c.parent_id IS NULL AND c.deleted_at IS NULL AND r.deleted_at IS NULL
        AND c.thread_status IN ($statusPlaceholders)
        AND " . ($tabName === 'sent' ? 'c.user_id = ?' : 'r.user_id = ?') . "
      ORDER BY c.created_at DESC, c.id DESC
      LIMIT 200",
    array_merge($threadStatusList, [$userId])
);

$commentItemList = [];
foreach ($commentRowList as $commentRow) {
    // 今その報告を見られる人にだけ出す（確認者から外れた・課を移ったなど）
    $reportForPermission = ['user_id' => $commentRow['owner_id'], 'department_id' => $commentRow['department_id'],
                            'status' => $commentRow['report_status'], 'deleted_at' => $commentRow['report_deleted_at']];
    if (!can_view_report($user, $reportForPermission)) {
        continue;
    }
    $isRestrictedField = $commentRow['field_confidentiality'] === 'restricted';
    if ($isRestrictedField && !can_view_restricted($user, $reportForPermission)) {
        continue;
    }
    $commentText = dec((string) $commentRow['comment_enc'], 'cmt:' . (int) $commentRow['report_id'] . ':' . (int) $commentRow['id']);
    $commentItemList[] = [
        'commentId'    => (int) $commentRow['id'],
        'reportId'     => (int) $commentRow['report_id'],
        'periodLabel'  => period_label((string) $commentRow['period']),
        'ownerName'    => (string) $commentRow['owner_name'],
        'authorName'   => (string) $commentRow['author_name'],
        'targetLabel'  => trim(((string) $commentRow['project_code'] !== '' ? $commentRow['project_code'] . ' ' : '')
                              . ((string) $commentRow['field_label'] !== '' ? $commentRow['field_label'] : msg('報告全体'))),
        'commentType'  => (string) $commentRow['comment_type'],
        'threadStatus' => (string) $commentRow['thread_status'],
        'snippetText'  => $commentText === null ? '' : mb_strimwidth(str_replace("\n", ' ', $commentText), 0, 80, '…', 'UTF-8'),
        'replyCount'   => (int) $commentRow['reply_count'],
        'createdAt'    => (string) $commentRow['created_at'],
    ];
}

render('comment_list', [
    'title'            => 'コメント',
    'user'             => $user,
    'tabName'          => $tabName,
    'statusFilterName' => $statusFilterName,
    'commentItemList'  => $commentItemList,
    'isReviewer'       => (bool) reviewer_departments($userId),
]);
