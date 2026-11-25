<?php

declare(strict_types=1);

// ====================================================================
// G-11 臨時の調査の管理: 一覧（詳細設計書 G11_survey_manage.md）
// 課長は自分が課長の課の調査、システム管理者・補佐・人財管理者は全課共通の調査（K-066・K-071）を出す。両方の人は両方
// ====================================================================

$user = page_start();
if (!survey_can_use_manage_screens($user)) {
    error_page(403);
}
$depts = final_departments($user);
$canManageAllDepartments = survey_can_manage_all_departments($user);
survey_close_expired();

$state = (string) ($_GET['state'] ?? 'all');
$scopeConditionList = [];
if ($depts) {
    $scopeConditionList[] = 'q.department_id IN (' . implode(',', array_map('intval', array_keys($depts))) . ')';
}
if ($canManageAllDepartments) {
    $scopeConditionList[] = 'q.department_id IS NULL';
}
$where = ['q.deleted_at IS NULL', '(' . implode(' OR ', $scopeConditionList) . ')'];
if ($state === 'template') {
    $where[] = 'q.is_template = 1';
} elseif (in_array($state, ['draft', 'open', 'closed'], true)) {
    $where[] = 'q.is_template = 0';
    $where[] = "q.status = '" . $state . "'";
}
$rows = db_all(
    "SELECT q.*, COALESCE(d.name, '全課共通') AS dept_name,
            (SELECT COUNT(*) FROM adhoc_request_targets t WHERE t.request_id = q.id) AS targets,
            (SELECT COUNT(*) FROM adhoc_responses a WHERE a.request_id = q.id AND a.submitted_at IS NOT NULL) AS answered
       FROM adhoc_requests q LEFT JOIN departments d ON d.id = q.department_id
      WHERE " . implode(' AND ', $where) . '
      ORDER BY q.is_template, q.created_at DESC'
);

render('survey_manage', ['title' => '臨時の調査', 'user' => $user, 'rows' => $rows, 'state' => $state]);
