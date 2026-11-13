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
if (!report_period_editable($period)) {
    error_page(400, msg('作成できるのは、当月と過去 3 か月までの報告です。'));
}

$report = report_find_own($uid, $period);
if ($report !== null && $report['status'] === 'confirmed') {
    redirect('/reports/view.php?id=' . (int) $report['id']);
}
if ($report === null && $user['department_id'] === null) {
    error_page(400, msg('所属の課が設定されていません。管理者に連絡してください。'));
}

$notices = [];
if ($report !== null) {
    $fields = form_snapshot_fields((int) $report['id']);
    $state = ['blocks' => [], 'report' => [], 'warnings' => [], 'carry_from' => $report['copied_from_id'] ?? null];
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
        $notices[] = msg('前月の報告の内容を写しています。変更があったところだけ直してください。');
    }
    $state = ['blocks' => [], 'report' => $carry['values'][0] ?? [], 'warnings' => [], 'carry_from' => $carry['from']];
    foreach ($carry['blocks'] as $pid) {
        $state['blocks'][] = ['project_id' => $pid, 'values' => $carry['values'][$pid] ?? []];
    }
}

// 過去の報告から写す（G-04 §4.4）。未作成・下書きのときだけ。写した内容は保存するまで反映しない
// copy_target = "<報告ID>"（報告全体）または "<報告ID>-<案件ID>"（その案件だけ。今の入力に足す）。copy_from は報告全体と同じ
$copyTargetText = isset($_GET['copy_target']) ? (string) $_GET['copy_target'] : (isset($_GET['copy_from']) ? (string) $_GET['copy_from'] : null);
if ($copyTargetText !== null) {
    if (preg_match('/^(\d{1,10})(?:-(\d{1,10}))?$/', $copyTargetText, $copyTargetParts) !== 1) {
        error_page(400);
    }
    $copySourceReportId = (int) $copyTargetParts[1];
    $copyOnlyProjectId = isset($copyTargetParts[2]) ? (int) $copyTargetParts[2] : null;   // null なら報告全体
    if ($report !== null && $report['status'] !== 'draft') {
        error_page(400, msg('提出済みの報告には写せません。'));
    }
    $copySourceReport = null;
    foreach (report_copy_sources($uid, $period) as $candidateSourceReport) {
        if ((int) $candidateSourceReport['id'] === $copySourceReportId) {
            $copySourceReport = $candidateSourceReport;
        }
    }
    if ($copySourceReport === null) {
        error_page(404);
    }
    $copiedValues = report_copy_values($copySourceReportId, $fields, false, msg('写した報告の案件 {code} は終了したため外しました'));
    $copySourcePeriodLabel = period_label((string) $copySourceReport['period']);

    if ($copyOnlyProjectId === null) {
        // 報告全体を写す（入力欄の内容を置き換える）
        $state = ['blocks' => [], 'report' => $copiedValues['values'][0] ?? [], 'warnings' => [], 'carry_from' => $state['carry_from'], 'copy_from' => $copySourceReportId];
        foreach ($copiedValues['blocks'] as $copiedProjectId) {
            $state['blocks'][] = ['project_id' => $copiedProjectId, 'values' => $copiedValues['values'][$copiedProjectId] ?? []];
        }
        $notices = array_merge($copiedValues['notices'], [
            msg('{period}の報告の内容を写しました。まだ保存していません。確かめて「下書き保存」か「提出する」を押してください。', ['period' => $copySourcePeriodLabel]),
        ]);
    } else {
        // 1 案件だけ写す（今の入力に足す。同じ案件があれば、その案件の内容を置き換える）
        if (!in_array($copyOnlyProjectId, $copiedValues['blocks'], true)) {
            error_page(404);
        }
        $copiedBlock = ['project_id' => $copyOnlyProjectId, 'values' => $copiedValues['values'][$copyOnlyProjectId] ?? []];
        $existingBlockIndex = null;
        foreach ($state['blocks'] as $blockIndex => $stateBlock) {
            if ((int) $stateBlock['project_id'] === $copyOnlyProjectId) {
                $existingBlockIndex = $blockIndex;
            }
        }
        if ($existingBlockIndex !== null) {
            $state['blocks'][$existingBlockIndex] = $copiedBlock;
        } elseif (count($state['blocks']) >= 10) {
            error_page(400, msg('案件は 10 件までにしてください'));
        } else {
            $state['blocks'][] = $copiedBlock;
        }
        $state['unsaved'] = true;
        $copiedProjectCode = (string) db_val('SELECT code FROM projects WHERE id = ?', [$copyOnlyProjectId]);
        $notices[] = msg('{period}の報告から案件 {code} の内容を写しました。まだ保存していません。', ['period' => $copySourcePeriodLabel, 'code' => $copiedProjectCode]);
    }

    // 写した自由記述にも、客先名らしい言葉の注意を出す
    foreach ($fields as $field) {
        if (!form_is_free_text($field)) {
            continue;
        }
        if (text_has_warning((string) ($state['report'][$field['id']] ?? ''))) {
            $state['warnings']['r_' . $field['id']] = true;
        }
        foreach ($state['blocks'] as $blockIndex => $stateBlock) {
            if (text_has_warning((string) ($stateBlock['values'][$field['id']] ?? ''))) {
                $state['warnings']['b' . $blockIndex . '_' . $field['id']] = true;
            }
        }
    }
}

report_edit_render($user, $period, $report, $fields, $state, [], $notices);
