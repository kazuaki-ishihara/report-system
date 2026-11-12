<?php

declare(strict_types=1);

// ====================================================================
// G-04 報告の作成・編集: 画面（詳細設計書 G04_report_edit.md）
// 保存・提出などは report_save.php
// ====================================================================

$user = page_start();
$uid = (int) $user['id'];

$period = (string) ($_GET['period'] ?? '');
if (!is_valid_period($period)) {
    error_page(400);
}
$current = date('Y-m');
if ($period > $current || $period < period_shift($current, -3)) {
    error_page(400, '作成できるのは、当月と過去 3 か月までの報告です。');
}

$report = report_find_own($uid, $period);
if ($report !== null && $report['status'] === 'confirmed') {
    redirect('/reports/view.php?id=' . (int) $report['id']);
}
if ($report === null && $user['department_id'] === null) {
    error_page(400, '所属の課が設定されていません。管理者に連絡してください。');
}

$notices = [];
if ($report !== null) {
    $fields = form_snapshot_fields((int) $report['id']);
    $state = ['blocks' => [], 'report' => [], 'warnings' => []];
    $values = report_values((int) $report['id']);
    foreach ($values[0] ?? [] as $fid => $v) {
        $state['report'][$fid] = (string) ($v['value'] ?? '');
        $state['warnings']['r_' . $fid] = $v['warning'];
    }
    foreach (report_blocks((int) $report['id']) as $i => $b) {
        $bv = [];
        foreach ($values[(int) $b['id']] ?? [] as $fid => $v) {
            $bv[$fid] = (string) ($v['value'] ?? '');
            $state['warnings']['b' . $i . '_' . $fid] = $v['warning'];
        }
        $state['blocks'][] = ['project_id' => (int) $b['project_id'], 'values' => $bv];
    }
} else {
    $fields = form_template_fields((int) $user['department_id']);
    $carry = report_carry_over($uid, $period, $fields);
    $notices = $carry['notices'];
    if ($carry['from'] !== null) {
        $notices[] = '前月の内容を入れてあります。変わったところだけ直してください。';
    }
    $state = ['blocks' => [], 'report' => $carry['values'][0] ?? [], 'warnings' => []];
    foreach ($carry['blocks'] as $pid) {
        $state['blocks'][] = ['project_id' => $pid, 'values' => $carry['values'][$pid] ?? []];
    }
}

report_edit_render($user, $period, $report, $fields, $state, [], $notices);
