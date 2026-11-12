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
    return is_admin($user) || is_final_reviewer_of((int) $user['id'], (int) $req['department_id']);
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
