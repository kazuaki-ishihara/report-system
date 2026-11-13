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
if (!survey_can_manage($user, $req)) {
    error_page(403);
}
$questions = survey_fields($id);
$responses = db_all(
    'SELECT a.id, a.user_id, a.submitted_at, u.display_name FROM adhoc_responses a JOIN users u ON u.id = a.user_id
      WHERE a.request_id = ? AND a.submitted_at IS NOT NULL ORDER BY u.display_name',
    [$id]
);
$targets = db_all(
    'SELECT u.id, u.display_name FROM adhoc_request_targets t JOIN users u ON u.id = t.user_id WHERE t.request_id = ? ORDER BY u.display_name',
    [$id]
);
$answered = array_map(function ($r) { return (int) $r['user_id']; }, $responses);
$notAnswered = array_values(array_filter($targets, function ($t) use ($answered) { return !in_array((int) $t['id'], $answered, true); }));

$showDetail = ($_GET['detail'] ?? '') === '1';
$answers = survey_answers($id);
if ($showDetail) {
    audit_log('view', 'survey:' . $id . ':responses');
}

// 集計（選択式・チェック）
$summary = [];
foreach ($questions as $q) {
    if (!in_array($q['type'], ['select', 'radio', 'multi_check', 'checkbox'], true)) {
        continue;
    }
    $counts = [];
    foreach ($responses as $r) {
        $v = $answers[(int) $r['id']][$q['id']] ?? '';
        if ($v === null || $v === '') {
            $counts['__none'] = ($counts['__none'] ?? 0) + 1;
            continue;
        }
        $codes = $q['type'] === 'multi_check' ? (array) json_decode($v, true) : [$v];
        foreach ($codes as $c) {
            $counts[(string) $c] = ($counts[(string) $c] ?? 0) + 1;
        }
    }
    $summary[$q['id']] = $counts;
}

render('survey_result', [
    'title' => '調査の結果', 'user' => $user, 'req' => $req, 'questions' => $questions, 'responses' => $responses,
    'answers' => $answers, 'summary' => $summary, 'notAnswered' => $notAnswered, 'targets' => $targets, 'showDetail' => $showDetail,
]);
