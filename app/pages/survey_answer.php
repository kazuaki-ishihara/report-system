<?php

declare(strict_types=1);

// ====================================================================
// G-07 臨時の調査への回答（詳細設計書 G07_survey_answer.md）
// ====================================================================

$user = page_start();
$uid = (int) $user['id'];
survey_close_expired();

$id = input_id(is_post() ? 'id' : 'id', is_post() ? 'POST' : 'GET');
$req = survey_find($id);
$isTarget = $req !== null && db_val('SELECT 1 FROM adhoc_request_targets WHERE request_id = ? AND user_id = ?', [$id, $uid]) !== null;
if ($req === null || !$isTarget || $req['status'] === 'draft' || (int) $req['is_template'] === 1) {
    error_page(404);
}
$questions = survey_fields($id);
$response = db_one('SELECT * FROM adhoc_responses WHERE request_id = ? AND user_id = ?', [$id, $uid]);
$current = [];
if ($response !== null) {
    foreach (db_all('SELECT * FROM adhoc_response_values WHERE response_id = ?', [(int) $response['id']]) as $v) {
        $val = (string) $v['value_text'];
        if ((int) $v['is_encrypted'] === 1) {
            $val = (string) dec($val, survey_aad((int) $response['id'], (int) $v['request_field_id']));
        }
        $current[(int) $v['request_field_id']] = $val;
    }
} else {
    $current = survey_prefill($uid, $questions);
}
$errors = [];
$open = $req['status'] === 'open';

if (is_post()) {
    rk_begin('survey.answer', $uid);
    if (!$open) {
        error_page(400, 'この調査は締め切られました。');
    }
    $raw = $_POST['q'] ?? [];
    if (!is_array($raw)) {
        error_page(400);
    }
    $values = [];
    foreach ($questions as $q) {
        $values[$q['id']] = form_parse_value($q, $raw[$q['id']] ?? ($q['type'] === 'multi_check' ? [] : ''), true, $errors, 'q_' . $q['id'], (string) ($current[$q['id']] ?? ''));
    }
    if ($errors) {
        rk_cancel();
        $current = $values;
    } else {
        tx(function () use ($id, $uid, $response, $questions, $values) {
            if ($response === null) {
                db_exec('INSERT INTO adhoc_responses (request_id, user_id, submitted_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$id, $uid, now(), now(), now()]);
                $resId = (int) db()->lastInsertId();
            } else {
                $resId = (int) $response['id'];
                db_exec('UPDATE adhoc_responses SET submitted_at = ?, updated_at = ? WHERE id = ?', [now(), now(), $resId]);
            }
            db_exec('DELETE FROM adhoc_response_values WHERE response_id = ?', [$resId]);
            foreach ($questions as $q) {
                $v = $values[$q['id']];
                if ($v === '') {
                    continue;
                }
                $encrypted = form_is_encrypted($q);
                db_exec(
                    'INSERT INTO adhoc_response_values (response_id, request_field_id, value_text, is_encrypted, has_warning) VALUES (?, ?, ?, ?, ?)',
                    [$resId, $q['id'], $encrypted ? enc($v, survey_aad($resId, $q['id'])) : $v, $encrypted ? 1 : 0, form_is_free_text($q) && text_has_warning($v) ? 1 : 0]
                );
            }
        });
        flash('success', '回答しました');
        rk_done('/index.php');
    }
}

render('survey_answer', [
    'title' => '臨時の調査', 'user' => $user, 'req' => $req, 'questions' => $questions,
    'current' => $current, 'errors' => $errors, 'response' => $response, 'open' => $open,
]);
