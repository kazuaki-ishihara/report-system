<?php

declare(strict_types=1);

// ====================================================================
// G-10 出力（詳細設計書 G10_export.md）
// GET: 条件の画面。POST: 印刷用画面（PDF 保存）、xlsx、CSV。
// 課長だけ（自分が課長の課だけ。管理者でも課長でなければ不可）。PC からだけ。「限定」は、出力する課の課長が選べる。
// ====================================================================

$user = page_start();
$uid = (int) $user['id'];
$depts = final_departments($user);
if (!$depts) {
    error_page(403);
}
$mobile = is_mobile_request();

if (!is_post()) {
    $surveys = db_all(
        "SELECT id, title, department_id FROM adhoc_requests WHERE deleted_at IS NULL AND is_template = 0 AND status <> 'draft'
            AND department_id IN (" . implode(',', array_map('intval', array_keys($depts))) . ') ORDER BY created_at DESC'
    );
    render('export_index', [
        'title' => '出力', 'user' => $user, 'depts' => $depts, 'mobile' => $mobile, 'surveys' => $surveys,
        'period' => (string) ($_GET['period'] ?? report_target_period($user)),
        'deptSel' => (string) ($_GET['dept'] ?? ''), 'type' => (($_GET['type'] ?? '') === 'survey') ? 'survey' : 'report',
        'surveySel' => (string) ($_GET['survey_id'] ?? ''),
    ]);
}

if ($mobile) {
    error_page(403, msg('出力は PC から行ってください。'));
}
$type = post_str('type') === 'survey' ? 'survey' : 'report';
$format = post_str('format');
if (!in_array($format, ['print', 'xlsx', 'csv'], true)) {
    error_page(400);
}
$withFree = ($_POST['free'] ?? '') === '1';
$withRestricted = ($_POST['restricted'] ?? '') === '1';
// 「限定」を含められるのは、出力する課の課長だけ（課の確認は下で行う。管理者でも課長でなければ不可）
$stamp = date('Y-m-d H:i');
$exporter = '出力者: ' . ($user['display_name'] !== '' ? $user['display_name'] : $user['login_id']) . '（' . $user['login_id'] . '）  出力日時: ' . $stamp;

if ($type === 'report') {
    $deptId = (int) post_str('dept');
    if (!isset($depts[$deptId])) {
        error_page(403);
    }
    $from = post_str('from');
    $to = post_str('to');
    if (!is_valid_period($from) || !is_valid_period($to) || $from > $to) {
        error_page(400, msg('対象月を正しく選んでください（開始 ≦ 終了）。'));
    }
    if ($to > period_shift($from, 11)) {
        error_page(400, msg('対象月は 12 か月以内にしてください。'));
    }
    $content = post_str('content');
    if (!in_array($content, ['table', 'summary', 'both'], true)) {
        error_page(400);
    }
    $data = compare_dataset($deptId, $from, $to, $withFree, $withRestricted, false);
    if (!$data['rows']) {
        error_page(404, msg('出力する報告がありません。'));
    }
    $title = '月次報告 ' . $depts[$deptId]['name'] . ' ' . period_label($from) . ($from !== $to ? '〜' . period_label($to) : '');
    $header = ['対象月', '氏名', '案件'];
    foreach ($data['columns'] as $f) {
        $header[] = $f['label'] . ($f['conf'] === 'restricted' ? '（限定）' : '');
    }
    $rows = [];
    if ($content !== 'summary') {
        foreach ($data['rows'] as $r) {
            $line = [$r['period'], $r['name'], $r['project']];
            foreach ($data['columns'] as $f) {
                $line[] = $r['cells'][$f['code']]['text'] ?? '';
            }
            $rows[] = $line;
        }
    }
    $summaryRows = [];
    if ($content !== 'table') {
        $sum = compare_summary($data, $to);
        foreach ($sum['groups'] as $g) {
            $summaryRows[] = ['■ ' . $g['label'], count($g['items']) . '件'];
            foreach ($g['items'] as $r) {
                $summaryRows[] = [$r['period'], $r['name'], $r['project']];
            }
        }
        foreach ($sum['counts'] as $label => $c) {
            $line = ['■ ' . $label];
            foreach ($c as $k => $n) {
                $line[] = $k . ' ' . $n;
            }
            $summaryRows[] = $line;
        }
    }
    $target = 'export:report;period=' . $from . '..' . $to . ';dept=' . $deptId . ';format=' . $format . ';content=' . $content;
    $fileBase = 'report_' . preg_replace('/[^\p{L}\p{N}]+/u', '_', $depts[$deptId]['name']) . '_' . $from . ($from !== $to ? '_' . $to : '');
} else {
    $sid = (int) post_str('survey_id');
    $req = survey_find($sid);
    if ($req === null || !isset($depts[(int) $req['department_id']]) || $req['status'] === 'draft') {
        error_page(404);
    }
    $questions = survey_fields($sid);
    $responses = db_all('SELECT a.id, u.display_name FROM adhoc_responses a JOIN users u ON u.id = a.user_id WHERE a.request_id = ? AND a.submitted_at IS NOT NULL ORDER BY u.display_name', [$sid]);
    $answers = survey_answers($sid);
    $title = '臨時の調査 ' . $req['title'] . ($req['requester'] !== '' ? '（依頼元: ' . $req['requester'] . '）' : '');
    $header = ['質問', '選択肢', '件数', '割合'];
    $rows = [];
    foreach ($questions as $q) {
        if ($q['conf'] === 'restricted' && !$withRestricted) {
            continue;
        }
        if (in_array($q['type'], ['select', 'radio', 'multi_check', 'checkbox'], true)) {
            $opts = $q['type'] === 'checkbox' ? [['code' => '1', 'label' => 'はい']] : $q['options'];
            foreach ($opts as $o) {
                $n = 0;
                foreach ($responses as $r) {
                    $v = (string) ($answers[(int) $r['id']][$q['id']] ?? '');
                    $codes = $q['type'] === 'multi_check' ? (array) json_decode($v, true) : [$v];
                    if (in_array((string) $o['code'], array_map('strval', $codes), true)) {
                        $n++;
                    }
                }
                $rows[] = [$q['label'], $o['label'], (string) $n, count($responses) > 0 ? round($n * 100 / count($responses)) . '%' : ''];
            }
        }
    }
    $summaryRows = [];
    // 個人の回答は「個人の回答も渡す」設定のときだけ
    if ($req['result_visibility'] === 'detail') {
        $line = ['回答者'];
        $qs = array_values(array_filter($questions, function ($q) use ($withRestricted, $withFree) {
            return ($q['conf'] !== 'restricted' || $withRestricted) && (!form_is_free_text($q) || $withFree);
        }));
        foreach ($qs as $q) {
            $line[] = $q['label'];
        }
        $summaryRows[] = $line;
        foreach ($responses as $r) {
            $line = [$r['display_name']];
            foreach ($qs as $q) {
                $line[] = export_text($q, (string) ($answers[(int) $r['id']][$q['id']] ?? ''));
            }
            $summaryRows[] = $line;
        }
    }
    $content = 'both';
    $target = 'export:survey;id=' . $sid . ';format=' . $format;
    $fileBase = 'survey_' . $sid;
}
$target .= ';free_text=' . ($withFree ? 1 : 0) . ';restricted=' . ($withRestricted ? 1 : 0);
audit_log('export', $target);

$headerLines = ['社外秘', $title, $exporter];
if ($format === 'print') {
    render('export_print', ['title' => $title, 'user' => $user, 'headerLines' => $headerLines, 'header' => $header, 'rows' => $rows, 'summaryRows' => $summaryRows]);
}
$allRows = $rows;
if ($summaryRows) {
    $allRows[] = [];
    foreach ($summaryRows as $r) {
        $allRows[] = $r;
    }
}
$filename = $fileBase . '_' . date('Ymd-Hi') . '.' . $format;
send_security_headers();
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    $body = export_csv($headerLines, $header, $allRows);
} else {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $body = export_xlsx('報告', $headerLines, $header, $allRows);
}
header("Content-Disposition: attachment; filename=\"export.{$format}\"; filename*=UTF-8''" . rawurlencode($filename));
header('Content-Length: ' . strlen($body));
echo $body;
exit;
