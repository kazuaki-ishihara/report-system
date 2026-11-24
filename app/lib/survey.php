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

/** 全課共通の調査で使える答え方（選択式とチェックだけ。だれも個人の回答を見ないため、集計できるものに限る。K-066） */
const SURVEY_ALL_DEPARTMENTS_TYPES = ['select', 'radio', 'multi_check', 'checkbox'];

/** 全課共通の調査か（department_id が NULL。システム管理者・補佐が作る。K-066） */
function survey_is_all_departments(array $req): bool
{
    return $req['department_id'] === null;
}

/**
 * 管理できるか。課の調査はその課の課長だけ（管理者でも課長でなければ不可。回答は報告の中身と同じ扱い）。
 * 全課共通の調査はシステム管理者と補佐（個人の回答は見られず、集計だけを見る）
 */
function survey_can_manage(array $user, array $req): bool
{
    if (survey_is_all_departments($req)) {
        return can_manage_system($user);
    }
    return is_final_reviewer_of((int) $user['id'], (int) $req['department_id']);
}

/** 部長以上か（すべての全課共通の調査の結果を見られる印。K-067） */
function is_executive(array $user): bool
{
    return (int) ($user['is_executive'] ?? 0) === 1;
}

/**
 * 全課共通の調査の結果（全課の集計・課ごとのまとめ・出力）を見られるか（K-067）。
 * 結果を見るのは依頼元と部長以上。管理者・補佐は作成と集計を代行する。個人の回答と名前は、だれにも出さない。
 * 見られる人: 管理者・補佐（管理する人）、部長以上の印の人（すべての調査）、調査ごとに指定された人（依頼元の担当など）
 */
function survey_can_view_all_departments_result(array $user, array $req): bool
{
    if (!survey_is_all_departments($req)) {
        return false;
    }
    if (survey_can_manage($user, $req) || is_executive($user)) {
        return true;
    }
    return db_val('SELECT 1 FROM adhoc_request_viewers WHERE request_id = ? AND user_id = ?', [(int) $req['id'], (int) $user['id']]) !== null;
}

/** 結果を見られる全課共通の調査（公開したもの。新しい順）。管理者・補佐でない人の「調査の結果」の画面に出す */
function survey_viewable_all_departments_requests(array $user): array
{
    $viewerCondition = is_executive($user) ? '1 = 1' : 'q.id IN (SELECT request_id FROM adhoc_request_viewers WHERE user_id = ?)';
    return db_all(
        "SELECT q.*,
                (SELECT COUNT(*) FROM adhoc_request_targets t WHERE t.request_id = q.id) AS targets,
                (SELECT COUNT(*) FROM adhoc_responses a WHERE a.request_id = q.id AND a.submitted_at IS NOT NULL) AS answered
           FROM adhoc_requests q
          WHERE q.department_id IS NULL AND q.deleted_at IS NULL AND q.is_template = 0 AND q.status IN ('open', 'closed') AND " . $viewerCondition . '
          ORDER BY q.created_at DESC',
        is_executive($user) ? [] : [(int) $user['id']]
    );
}

/** メニューに「調査の結果」を出すか（管理者・補佐は「臨時の調査」から見るので出さない） */
function survey_has_result_viewing_menu(array $user): bool
{
    if (can_manage_system($user)) {
        return false;
    }
    return is_executive($user) || (bool) survey_viewable_all_departments_requests($user);
}

/** 結果を見られる人に指定できる候補: 有効な利用者全員（課に所属しない部長・依頼元の担当も選べる）。課の無い人を先に */
function survey_viewer_candidates(): array
{
    return db_all(
        "SELECT u.id, u.display_name, u.login_id, u.role_title, COALESCE(d.name, '') AS department_name, u.is_executive
           FROM users u LEFT JOIN departments d ON d.id = u.department_id
          WHERE u.is_active = 1
          ORDER BY (u.department_id IS NOT NULL), d.sort_order, d.id, u.display_name"
    );
}

/** 臨時の調査の画面（一覧・作成）を使えるか: どこかの課の課長、またはシステム管理者・補佐（全課共通の調査） */
function survey_can_use_manage_screens(array $user): bool
{
    return (bool) final_departments($user) || can_manage_system($user);
}

/** 全課共通の調査を作れる DB か（古い DB は migrate.php で表を作り直すまで department_id が NOT NULL） */
function survey_all_departments_supported(): bool
{
    foreach (db_all('PRAGMA table_info(adhoc_requests)') as $columnInfo) {
        if ($columnInfo['name'] === 'department_id') {
            return (int) $columnInfo['notnull'] === 0;
        }
    }
    return false;
}

/** 全課共通の調査の対象者: 有効な課に所属する、有効な利用者全員（公開の時点） */
function survey_all_departments_members(): array
{
    return db_all(
        'SELECT u.id, u.display_name, u.login_id FROM users u JOIN departments d ON d.id = u.department_id
          WHERE u.is_active = 1 AND d.is_active = 1 ORDER BY d.sort_order, d.id, u.display_name'
    );
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
 * チェック（checkbox）は、チェックしないことも答えなので、「はい」と「チェックなし」の 2 行にし、未回答は null（未回答の行を出さない）。
 *
 * @param array<int, array<string, mixed>> $questionList survey_fields() の質問
 * @param array<int, array<string, mixed>> $responseList 提出済みの回答（id を持つ）
 * @param array<int, array<int, string|null>> $answerByResponseId survey_answers() の値
 * @return array<int, array{question: array<string, mixed>, is_multiple: bool, option_tally_list: array<int, array{label: string, count: int, percent: int|null}>, unanswered_count: int|null}>
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
        $isCheckbox = $question['type'] === 'checkbox';
        if ($isCheckbox) {
            // チェックしなかった人（値が空・「1」以外）を「チェックなし」として数える
            $countByCode['__unchecked'] = $respondentCount - (int) ($countByCode['1'] ?? 0);
        }
        $optionList = $isCheckbox ? [['code' => '1', 'label' => 'はい'], ['code' => '__unchecked', 'label' => 'チェックなし']] : $question['options'];
        $optionTallyList = [];
        foreach ($optionList as $option) {
            $count = (int) ($countByCode[(string) $option['code']] ?? 0);
            $optionTallyList[] = [
                'label'   => (string) $option['label'],
                'count'   => $count,
                'percent' => $respondentCount > 0 ? (int) round($count * 100 / $respondentCount) : null,
            ];
        }
        $tallyList[] = ['question' => $question, 'is_multiple' => $isMultiple, 'option_tally_list' => $optionTallyList, 'unanswered_count' => $isCheckbox ? null : $unansweredCount];
    }
    return $tallyList;
}

/**
 * 全課共通の調査の、課ごとのまとめ（G-11 の結果・出力。K-066）。
 * 課は回答した時点ではなく、今の所属で数える（異動した人は今の課。所属がなくなった人は「所属なし」）。
 * 課ごとの内訳は、回答者の人数にかかわらず出す（数字を知るための機能のため。名前は出さない）。回答者が 0 人の課だけ null（「回答なし」）。
 *
 * @param array<int, array<string, mixed>> $questionList survey_fields() の質問
 * @param array<int, array<int, string|null>> $answerByResponseId survey_answers() の値
 * @return array<int, array{department_name: string, target_count: int, respondent_count: int, tally_by_question_id: array<int, array>|null}>
 */
function survey_department_breakdown(int $requestId, array $questionList, array $answerByResponseId): array
{
    $departmentSummaryByKey = [];
    $departmentRowList = db_all(
        'SELECT t.user_id, u.department_id, d.name AS department_name, d.sort_order AS department_sort_order,
                a.id AS response_id, a.submitted_at
           FROM adhoc_request_targets t
           JOIN users u ON u.id = t.user_id
           LEFT JOIN departments d ON d.id = u.department_id
           LEFT JOIN adhoc_responses a ON a.request_id = t.request_id AND a.user_id = t.user_id
          WHERE t.request_id = ?
          ORDER BY (d.id IS NULL), d.sort_order, d.id',
        [$requestId]
    );
    $responseListByKey = [];
    foreach ($departmentRowList as $departmentRow) {
        $departmentKey = $departmentRow['department_id'] === null ? 'none' : 'd' . (int) $departmentRow['department_id'];
        if (!isset($departmentSummaryByKey[$departmentKey])) {
            $departmentSummaryByKey[$departmentKey] = [
                'department_name' => $departmentRow['department_id'] === null ? '所属なし' : (string) $departmentRow['department_name'],
                'target_count' => 0, 'respondent_count' => 0, 'tally_by_question_id' => null,
            ];
            $responseListByKey[$departmentKey] = [];
        }
        $departmentSummaryByKey[$departmentKey]['target_count']++;
        if ($departmentRow['response_id'] !== null && $departmentRow['submitted_at'] !== null) {
            $departmentSummaryByKey[$departmentKey]['respondent_count']++;
            $responseListByKey[$departmentKey][] = ['id' => (int) $departmentRow['response_id']];
        }
    }
    foreach ($departmentSummaryByKey as $departmentKey => $departmentSummary) {
        if ($departmentSummary['respondent_count'] === 0) {
            continue;
        }
        $tallyByQuestionId = [];
        foreach (survey_tally($questionList, $responseListByKey[$departmentKey], $answerByResponseId) as $tally) {
            $tallyByQuestionId[(int) $tally['question']['id']] = $tally;
        }
        $departmentSummaryByKey[$departmentKey]['tally_by_question_id'] = $tallyByQuestionId;
    }
    return array_values($departmentSummaryByKey);
}

/**
 * 全課共通の調査の出力（xlsx・CSV）の表: 全課の集計のあとに、課ごとの集計を続ける（1 行 1 選択肢。列の先頭に課）。
 * 回答者が少ない課は、内訳の代わりに 1 行だけ出す。
 *
 * @return array{header: string[], rows: array<int, string[]>, status_rows: array<int, string[]>}
 */
function survey_all_departments_export_table(array $overallTallyList, array $departmentBreakdownList): array
{
    $tallyRowList = function (string $departmentName, array $tallyList): array {
        $rowList = [];
        foreach ($tallyList as $tally) {
            $questionLabel = $tally['question']['label'] . ($tally['is_multiple'] ? '（複数回答）' : '');
            foreach ($tally['option_tally_list'] as $optionTally) {
                $rowList[] = [$departmentName, $questionLabel, $optionTally['label'], (string) $optionTally['count'], $optionTally['percent'] === null ? '' : $optionTally['percent'] . '%'];
            }
        }
        return $rowList;
    };
    $rowList = $tallyRowList('全課', $overallTallyList);
    $statusRowList = [['課', '対象', '回答', '回答率']];
    foreach ($departmentBreakdownList as $departmentBreakdown) {
        $statusRowList[] = [
            $departmentBreakdown['department_name'], (string) $departmentBreakdown['target_count'], (string) $departmentBreakdown['respondent_count'],
            $departmentBreakdown['target_count'] > 0 ? (int) round($departmentBreakdown['respondent_count'] * 100 / $departmentBreakdown['target_count']) . '%' : '',
        ];
        if ($departmentBreakdown['tally_by_question_id'] === null) {
            $rowList[] = [$departmentBreakdown['department_name'], '（回答なし）', '', '', ''];
            continue;
        }
        foreach ($tallyRowList($departmentBreakdown['department_name'], array_values($departmentBreakdown['tally_by_question_id'])) as $departmentRow) {
            $rowList[] = $departmentRow;
        }
    }
    return ['header' => ['課', '質問', '選択肢', '件数', '割合'], 'rows' => $rowList, 'status_rows' => $statusRowList];
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
        [$req['department_id'] === null ? null : (int) $req['department_id'], (string) $req['title'] . ($asTemplate ? '' : '（複製）'), $req['requester'], $req['purpose'], $req['result_visibility'], $asTemplate ? 1 : 0, $userId, now(), now()]
    );
    $newId = (int) db()->lastInsertId();
    db_exec(
        'INSERT INTO adhoc_request_fields (request_id, source_field_id, label, field_type, confidentiality, is_required, help_text, settings_json, options_json, sort_order)
         SELECT ?, source_field_id, label, field_type, confidentiality, is_required, help_text, settings_json, options_json, sort_order FROM adhoc_request_fields WHERE request_id = ?',
        [$newId, (int) $req['id']]
    );
    // 全課共通の調査の「結果を見られる人」も写す（同じ依頼元のことが多いため）
    db_exec('INSERT INTO adhoc_request_viewers (request_id, user_id) SELECT ?, user_id FROM adhoc_request_viewers WHERE request_id = ?', [$newId, (int) $req['id']]);
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
