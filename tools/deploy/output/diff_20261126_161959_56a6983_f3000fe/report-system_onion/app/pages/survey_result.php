<?php

declare(strict_types=1);

// ====================================================================
// G-11 臨時の調査の結果（回答の一覧・集計）
// ====================================================================

$user = page_start();
survey_close_expired();
$id = input_id('id');
$req = survey_find($id);
// 結果があるのは公開した依頼（公開中・締め切り後）だけ。下書き・ひな形は 404
if ($req === null || (int) $req['is_template'] === 1 || !in_array($req['status'], ['open', 'closed'], true)) {
    error_page(404);
}
// 課の調査はその課の課長だけ。全課共通の調査は、管理者・補佐と、依頼元・部長以上（K-067）
$canViewResult = survey_is_all_departments($req) ? survey_can_view_all_departments_result($user, $req) : survey_can_manage($user, $req);
if (!$canViewResult) {
    error_page(403);
}
$questions = survey_fields($id);

// 全課共通の調査（K-066・K-067）: 全課の集計と課ごとのまとめを見る（見るのは依頼元・部長以上。管理者・補佐は代行）。個人の回答・未回答者の名前は出さない
if (survey_is_all_departments($req)) {
    $allResponseList = db_all('SELECT id FROM adhoc_responses WHERE request_id = ? AND submitted_at IS NOT NULL', [$id]);
    $allAnswerByResponseId = survey_answers($id);
    $overallTallyList = survey_tally($questions, $allResponseList, $allAnswerByResponseId);
    $departmentBreakdownList = survey_department_breakdown($id, $questions, $allAnswerByResponseId);
    $targetCount = (int) db_val('SELECT COUNT(*) FROM adhoc_request_targets WHERE request_id = ?', [$id]);
    $responseRateText = survey_response_rate_text($targetCount, count($allResponseList));

    if (is_post()) {
        // 出力（G-10 と同じ形式。全課共通の調査は G-10 の画面ではなく、ここから出す）
        if (is_mobile_request()) {
            error_page(403, msg('出力は PC から行ってください。'));
        }
        $format = post_str('format');
        if (!in_array($format, ['print', 'xlsx', 'csv'], true)) {
            error_page(400);
        }
        $title = '臨時の調査（全課共通） ' . $req['title'] . ($req['requester'] !== '' ? '（依頼元: ' . $req['requester'] . '）' : '');
        $headerLines = [
            '社外秘', $title,
            '出力者: ' . ($user['display_name'] !== '' ? $user['display_name'] : $user['login_id']) . '（' . $user['login_id'] . '）  出力日時: ' . date('Y-m-d H:i'),
            $responseRateText . '  割合は回答者数に対する %（複数回答の質問は、足すと 100% を超えます）',
        ];
        audit_log('export', 'export:survey;id=' . $id . ';format=' . $format . ';scope=all_departments');
        $exportTable = survey_all_departments_export_table($overallTallyList, $departmentBreakdownList);
        if ($format === 'print') {
            render('export_print', [
                'title' => $title, 'user' => $user, 'headerLines' => $headerLines, 'header' => [], 'rows' => [], 'summaryRows' => [],
                'surveyTallyList' => $overallTallyList, 'departmentBreakdownList' => $departmentBreakdownList,
            ]);
            exit;
        }
        $allRows = $exportTable['rows'];
        $allRows[] = [];
        foreach ($exportTable['status_rows'] as $statusRow) {
            $allRows[] = $statusRow;
        }
        $filename = 'survey_all_' . $id . '_' . date('Ymd-Hi') . '.' . $format;
        send_security_headers();
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            $body = export_csv($headerLines, $exportTable['header'], $allRows);
        } else {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $body = export_xlsx('調査', $headerLines, $exportTable['header'], $allRows);
        }
        header("Content-Disposition: attachment; filename=\"export.{$format}\"; filename*=UTF-8''" . rawurlencode($filename));
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }

    render('survey_result_all', [
        'title' => '調査の結果（全課共通）', 'user' => $user, 'req' => $req, 'questions' => $questions,
        'overallTallyList' => $overallTallyList, 'departmentBreakdownList' => $departmentBreakdownList,
        'responseRateText' => $responseRateText, 'isMobile' => is_mobile_request(), 'canManage' => survey_can_manage($user, $req),
    ]);
    exit;
}

$responses = db_all(
    'SELECT a.id, a.user_id, a.submitted_at, u.display_name FROM adhoc_responses a JOIN users u ON u.id = a.user_id
      WHERE a.request_id = ? AND a.submitted_at IS NOT NULL AND a.user_id NOT IN (' . specialist_unviewable_id_sql(true) . ') ORDER BY u.display_name',
    [$id]
);
$targets = db_all(
    'SELECT u.id, u.display_name FROM adhoc_request_targets t JOIN users u ON u.id = t.user_id WHERE t.request_id = ? AND t.user_id NOT IN (' . specialist_unviewable_id_sql(true) . ') ORDER BY u.display_name',
    [$id]
);
$answered = array_map(function ($r) { return (int) $r['user_id']; }, $responses);
$notAnswered = array_values(array_filter($targets, function ($t) use ($answered) { return !in_array((int) $t['id'], $answered, true); }));

$showDetail = ($_GET['detail'] ?? '') === '1';
$answers = survey_answers($id);
if ($showDetail) {
    audit_log('view', 'survey:' . $id . ':responses');
}

// 集計（選択式・チェック。出力と同じ survey_tally()）
$surveyTallyByQuestionId = [];
foreach (survey_tally($questions, $responses, $answers) as $tally) {
    $surveyTallyByQuestionId[(int) $tally['question']['id']] = $tally;
}

render('survey_result', [
    'title' => '調査の結果', 'user' => $user, 'req' => $req, 'questions' => $questions, 'responses' => $responses,
    'answers' => $answers, 'surveyTallyByQuestionId' => $surveyTallyByQuestionId, 'notAnswered' => $notAnswered, 'targets' => $targets, 'showDetail' => $showDetail,
]);
