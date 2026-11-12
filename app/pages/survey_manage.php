<?php

declare(strict_types=1);

// ====================================================================
// G-11 臨時の調査の管理: 一覧（詳細設計書 G11_survey_manage.md）
// ====================================================================

$user = page_start();
$depts = final_departments($user);
if (!$depts) {
    error_page(403);
}
survey_close_expired();

$state = (string) ($_GET['state'] ?? 'all');
$where = ['q.deleted_at IS NULL', 'q.department_id IN (' . implode(',', array_map('intval', array_keys($depts))) . ')'];
if ($state === 'template') {
    $where[] = 'q.is_template = 1';
} elseif (in_array($state, ['draft', 'open', 'closed'], true)) {
    $where[] = 'q.is_template = 0';
    $where[] = "q.status = '" . $state . "'";
}
$rows = db_all(
    'SELECT q.*, d.name AS dept_name,
            (SELECT COUNT(*) FROM adhoc_request_targets t WHERE t.request_id = q.id) AS targets,
            (SELECT COUNT(*) FROM adhoc_responses a WHERE a.request_id = q.id AND a.submitted_at IS NOT NULL) AS answered
       FROM adhoc_requests q JOIN departments d ON d.id = q.department_id
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY q.is_template, q.created_at DESC'
);

render('survey_manage', ['title' => '臨時の調査', 'user' => $user, 'rows' => $rows, 'state' => $state]);
