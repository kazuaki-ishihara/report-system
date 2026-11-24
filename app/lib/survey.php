<?php

declare(strict_types=1);

// ====================================================================
// 臨時の調査の共通の処理（詳細設計書 G07・G11）
// ====================================================================

const SURVEY_TYPES = ['single_text', 'multi_text', 'number', 'date', 'month', 'select', 'radio', 'checkbox', 'multi_check'];

function survey_find(int $id): ?array
{
    return db_one('SELECT * FROM adhoc_requests WHERE id = ? AND deleted_at IS NULL', [$id]);
}

/** 管理できるか（その課の課長、または管理者） */
function survey_can_manage(array $user, array $req): bool
{
    return is_final_reviewer_of((int) $user['id'], (int) $req['department_id']);   // 管理者でも課長でなければ不可（回答は報告の中身と同じ扱い）
}

/** 質問を様式の仕組みで扱える形にする */
function survey_fields(int $requestId): array
{
    $out = [];
    foreach (db_all('SELECT * FROM adhoc_request_fields WHERE request_id = ? ORDER BY sort_order, id', [$requestId]) as $q) {
        $opts = json_decode((string) $q['options_json'], true);
        $settings = json_decode((string) $q['settings_json'], true);
        $out[] = [
            'id' => (int) $q['id'], 'code' => 'q' . (int) $q['id'], 'label' => (string) $q['label'], 'type' => (string) $q['field_type'],
            'scope' => 'report', 'conf' => (string) $q['confidentiality'], 'required' => (int) $q['is_required'] === 1,
            'settings' => is_array($settings) ? $settings : [], 'options' => is_array($opts) ? $opts : [],
            'help' => (string) $q['help_text'], 'placeholder' => '', 'carry' => false, 'dept' => null,
            'sort' => (int) $q['sort_order'], 'active' => true, 'source' => $q['source_field_id'] === null ? null : (int) $q['source_field_id'],
        ];
    }
    return $out;
}

function survey_aad(int $responseId, int $questionId): string
{
    return 'arv:' . $responseId . ':' . $questionId;
}

/** 回答の値を復号して返す: [response_id][question_id] => value|null */
function survey_answers(int $requestId): array
{
    $out = [];
    foreach (db_all(
        'SELECT v.* FROM adhoc_response_values v JOIN adhoc_responses r ON r.id = v.response_id WHERE r.request_id = ?',
        [$requestId]
    ) as $v) {
        $val = (string) $v['value_text'];
        if ((int) $v['is_encrypted'] === 1) {
            $val = dec($val, survey_aad((int) $v['response_id'], (int) $v['request_field_id']));
        }
        $out[(int) $v['response_id']][(int) $v['request_field_id']] = $val;
    }
    return $out;
}

/** 集計する質問の型（選択式とチェック） */
const SURVEY_TALLY_TYPES = ['select', 'radio', 'multi_check', 'checkbox'];

/**
 * 選択式の質問ごとの集計（G-10 の出力と G-11 の結果で同じものを使う）。
 * 割合は回答者数に対する整数の %（回答者が 0 人なら null）。複数選べる質問（multi_check）は、足すと 100% を超える。
 * 未回答は、回答を出した人のうち、その質問を空にした人の数。
 *
 * @param array<int, array<string, mixed>> $questionList survey_fields() の質問
 * @param array<int, array<string, mixed>> $responseList 提出済みの回答（id を持つ）
 * @param array<int, array<int, string|null>> $answerByResponseId survey_answers() の値
 * @return array<int, array{question: array<string, mixed>, is_multiple: bool, option_tally_list: array<int, array{label: string, count: int, percent: int|null}>, unanswered_count: int}>
 */
function survey_tally(array $questionList, array $responseList, array $answerByResponseId): array
{
    $respondentCount = count($responseList);
    $tallyList = [];
    foreach ($questionList as $question) {
        if (!in_array($question['type'], SURVEY_TALLY_TYPES, true)) {
            continue;
        }
        $isMultiple = $question['type'] === 'multi_check';
        $countByCode = [];
        $unansweredCount = 0;
        foreach ($responseList as $response) {
            $valueText = (string) ($answerByResponseId[(int) $response['id']][$question['id']] ?? '');
            $codeList = $valueText === '' ? [] : ($isMultiple ? array_map('strval', (array) json_decode($valueText, true)) : [$valueText]);
            if (!$codeList) {
                $unansweredCount++;
                continue;
            }
            foreach (array_unique($codeList) as $code) {
                $countByCode[$code] = ($countByCode[$code] ?? 0) + 1;
            }
        }
        $optionList = $question['type'] === 'checkbox' ? [['code' => '1', 'label' => 'はい']] : $question['options'];
        $optionTallyList = [];
        foreach ($optionList as $option) {
            $count = (int) ($countByCode[(string) $option['code']] ?? 0);
            $optionTallyList[] = [
                'label'   => (string) $option['label'],
                'count'   => $count,
                'percent' => $respondentCount > 0 ? (int) round($count * 100 / $respondentCount) : null,
            ];
        }
        $tallyList[] = ['question' => $question, 'is_multiple' => $isMultiple, 'option_tally_list' => $optionTallyList, 'unanswered_count' => $unansweredCount];
    }
    return $tallyList;
}

/** 回答の状況の 1 行（例: 「対象 10 人・回答 6 人（回答率 60%）」） */
function survey_response_rate_text(int $targetCount, int $respondentCount): string
{
    return '対象 ' . $targetCount . ' 人・回答 ' . $respondentCount . ' 人'
        . ($targetCount > 0 ? '（回答率 ' . (int) round($respondentCount * 100 / $targetCount) . '%）' : '');
}

/**
 * 割合の横棒（SVG）。CSP で style 属性を使えないため、幅は SVG の属性で決める。色は CSS（.survey-bar-*）。
 */
function survey_bar_svg(?int $percent): string
{
    $width = max(0, min(100, (int) $percent));
    return '<svg class="survey-bar" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
        . '<rect class="survey-bar-track" width="100" height="10"/>'
        . ($width > 0 ? '<rect class="survey-bar-fill" width="' . $width . '" height="10"/>' : '')
        . '</svg>';
}

/** 期限を過ぎた公開中の依頼を締め切る */
function survey_close_expired(): void
{
    db_exec("UPDATE adhoc_requests SET status = 'closed', closed_at = ?, updated_at = ? WHERE status = 'open' AND due_at IS NOT NULL AND due_at < ?", [now(), now(), date('Y-m-d')]);
}

/** 依頼を複製する（質問と設定。対象・期限は空） */
function survey_copy(array $req, int $userId, bool $asTemplate): int
{
    db_exec(
        "INSERT INTO adhoc_requests (department_id, title, requester, purpose, result_visibility, target_type, is_template, status, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'all', ?, 'draft', ?, ?, ?)",
        [(int) $req['department_id'], (string) $req['title'] . ($asTemplate ? '' : '（複製）'), $req['requester'], $req['purpose'], $req['result_visibility'], $asTemplate ? 1 : 0, $userId, now(), now()]
    );
    $newId = (int) db()->lastInsertId();
    db_exec(
        'INSERT INTO adhoc_request_fields (request_id, source_field_id, label, field_type, confidentiality, is_required, help_text, settings_json, options_json, sort_order)
         SELECT ?, source_field_id, label, field_type, confidentiality, is_required, help_text, settings_json, options_json, sort_order FROM adhoc_request_fields WHERE request_id = ?',
        [$newId, (int) $req['id']]
    );
    return $newId;
}

/** 回答者の最新の月次報告の値（月次の項目を使う質問の初期値） */
function survey_prefill(int $userId, array $questions): array
{
    $report = db_one(
        "SELECT id FROM report_records WHERE user_id = ? AND status IN ('submitted', 'confirmed') AND is_legacy = 0 AND deleted_at IS NULL ORDER BY period DESC LIMIT 1",
        [$userId]
    );
    if ($report === null) {
        return [];
    }
    $values = report_values((int) $report['id']);
    $firstBlock = db_val('SELECT id FROM report_projects WHERE report_id = ? ORDER BY sort_order, id LIMIT 1', [(int) $report['id']]);
    $out = [];
    foreach ($questions as $q) {
        if ($q['source'] === null) {
            continue;
        }
        $v = $values[0][$q['source']]['value'] ?? null;
        if (($v === null || $v === '') && $firstBlock !== null) {
            $v = $values[(int) $firstBlock][$q['source']]['value'] ?? null;
        }
        if ($v !== null && $v !== '') {
            $out[$q['id']] = $v;
        }
    }
    return $out;
}
