<?php

declare(strict_types=1);

// ====================================================================
// 報告の検索（詳細設計書 G20_search.md、共通編 §8.3）
// 自由記述は暗号化しているため DB では探せない。条件（対象月・課・選択式の値）で絞った報告を、
// 見る権限のある範囲だけ復号して、アプリの中で文字列を探す。1 回に復号する報告は search_decrypt_limit 件まで。
// ====================================================================

/**
 * 選択式の条件に使える項目（有効、選択式、「限定」でない。共通の項目と、見られる課の項目）。
 *
 * @param int[] $departmentIdList 見られる課
 * @return array<int, array> 項目 ID => form_row_to_def() の形の定義（options を含む）
 */
function search_choice_fields(array $departmentIdList): array
{
    if (!$departmentIdList) {
        return [];
    }
    $departmentPlaceholders = implode(', ', array_fill(0, count($departmentIdList), '?'));
    $choiceFieldById = [];
    foreach (db_all(
        "SELECT * FROM report_fields
          WHERE is_active = 1 AND field_type IN ('select', 'radio', 'checkbox', 'multi_check') AND confidentiality <> 'restricted'
            AND (department_id IS NULL OR department_id IN ($departmentPlaceholders))
          ORDER BY department_id IS NOT NULL, department_id, field_scope, sort_order, id",
        $departmentIdList
    ) as $fieldRow) {
        $choiceFieldById[(int) $fieldRow['id']] = form_row_to_def($fieldRow, form_field_options((int) $fieldRow['id'], true));
    }
    return $choiceFieldById;
}

/**
 * 報告を探す。
 *
 * @param array $viewer 探す人（users の行）
 * @param array{
 *   departmentIdList: int[],      探す課（見られる課の中から）
 *   periodFrom: string,           対象月の始め（YYYY-MM）
 *   periodTo: string,             対象月の終わり（YYYY-MM）
 *   choiceField: ?array,          選択式の条件の項目（search_choice_fields() の 1 つ）。null なら条件なし
 *   choiceCode: string,           選択式の条件の値（選択肢のコード。checkbox は '1'）
 *   keywordText: string,          キーワード（空なら条件なし）
 *   ownerUserId?: ?int            自分の報告だけを探すとき、その人の ID（課の条件は使わず、下書きも含める）
 * } $condition
 * @return array{
 *   isTooMany: bool,              キーワードで探す報告が上限を超えた（探していない）
 *   candidateCount: int,          条件で絞った報告の数
 *   resultList: array<int, array{reportId: int, ownerName: string, periodLabel: string, statusLabel: string, matchList: array<int, array{label: string, snippetText: string}>}>
 * }
 */
function search_reports(array $viewer, array $condition): array
{
    $searchResult = ['isTooMany' => false, 'candidateCount' => 0, 'resultList' => []];
    $ownerUserId = $condition['ownerUserId'] ?? null;
    if ($ownerUserId !== null) {
        // 自分の報告だけ（K-015）。下書きも含める
        $whereSqlList = ['r.user_id = ?', 'r.period >= ?', 'r.period <= ?', 'r.is_legacy = 0', 'r.deleted_at IS NULL'];
        $whereParams = [$ownerUserId, $condition['periodFrom'], $condition['periodTo']];
    } else {
        if (!$condition['departmentIdList']) {
            return $searchResult;
        }
        $departmentPlaceholders = implode(', ', array_fill(0, count($condition['departmentIdList']), '?'));
        $whereSqlList = [
            "(r.department_id IN ($departmentPlaceholders) OR u.department_id IN ($departmentPlaceholders))",   // 課を移った人の、移る前の報告も（K-032）
            'r.period >= ?', 'r.period <= ?',
            "r.status IN ('submitted', 'confirmed')",
            'r.is_legacy = 0', 'r.deleted_at IS NULL',
        ];
        $whereParams = array_merge($condition['departmentIdList'], $condition['departmentIdList'], [$condition['periodFrom'], $condition['periodTo']]);
    }

    // 選択式の条件（「限定」でない選択式は暗号化していないので、DB で絞れる）
    $choiceField = $condition['choiceField'];
    if ($choiceField !== null) {
        if ($choiceField['type'] === 'multi_check') {
            $whereSqlList[] = "EXISTS (SELECT 1 FROM report_field_values v WHERE v.report_id = r.id AND v.field_id = ? AND v.value_text LIKE ? ESCAPE '\\')";
            $whereParams[] = (int) $choiceField['id'];
            $whereParams[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], json_encode($condition['choiceCode'], JSON_UNESCAPED_UNICODE)) . '%';
        } else {
            $whereSqlList[] = 'EXISTS (SELECT 1 FROM report_field_values v WHERE v.report_id = r.id AND v.field_id = ? AND v.value_text = ?)';
            $whereParams[] = (int) $choiceField['id'];
            $whereParams[] = $condition['choiceCode'];
        }
    }

    $candidateReportList = db_all(
        'SELECT r.*, u.display_name AS owner_name FROM report_records r JOIN users u ON u.id = r.user_id
          WHERE ' . implode(' AND ', $whereSqlList) . '
          ORDER BY r.period DESC, u.display_name, r.id',
        $whereParams
    );
    $searchResult['candidateCount'] = count($candidateReportList);
    $keywordText = trim($condition['keywordText']);
    if ($keywordText !== '' && count($candidateReportList) > (int) cfg('search_decrypt_limit')) {
        $searchResult['isTooMany'] = true;
        return $searchResult;
    }
    $normalizedKeywordText = text_normalize($keywordText);
    $searchableFieldIdSet = array_flip(array_map('intval', array_column(
        db_all('SELECT id FROM report_fields WHERE is_searchable = 1'), 'id'
    )));

    foreach ($candidateReportList as $candidateReport) {
        if (!can_view_report($viewer, $candidateReport)) {
            continue;
        }
        $matchList = [];
        if ($keywordText !== '') {
            $matchList = search_keyword_matches($viewer, $candidateReport, $normalizedKeywordText, $searchableFieldIdSet);
            if (!$matchList) {
                continue;
            }
        }
        $searchResult['resultList'][] = [
            'reportId'    => (int) $candidateReport['id'],
            'ownerName'   => (string) $candidateReport['owner_name'],
            'periodLabel' => period_label((string) $candidateReport['period']),
            'statusLabel' => report_status_label((string) $candidateReport['status']),
            'matchList'   => $matchList,
        ];
        if (count($searchResult['resultList']) >= 200) {
            break;
        }
    }
    return $searchResult;
}

/**
 * 1 つの報告の中でキーワードを探す（検索の対象の項目の値・選択肢の表示名、案件のコードと呼び名）。
 *
 * @param array<int, int> $searchableFieldIdSet 検索の対象の項目 ID => 何でもよい（array_flip した集合）
 * @return array<int, array{label: string, snippetText: string}> 見つかった場所と前後の文（最大 5 件）
 */
function search_keyword_matches(array $viewer, array $report, string $normalizedKeywordText, array $searchableFieldIdSet): array
{
    $reportId = (int) $report['id'];
    $canViewRestricted = can_view_restricted($viewer, $report);
    $isFreeTextPurged = $report['free_text_purged_at'] !== null;
    $fieldById = [];
    foreach (form_snapshot_fields($reportId) as $snapshotField) {
        $fieldById[(int) $snapshotField['id']] = $snapshotField;
    }
    $projectLabelByReportProjectId = [0 => msg('報告全体')];
    $matchList = [];
    foreach (report_blocks($reportId) as $reportBlock) {
        $projectLabelText = trim($reportBlock['code'] . ' ' . $reportBlock['alias']);
        $projectLabelByReportProjectId[(int) $reportBlock['id']] = (string) $reportBlock['code'];
        if (strpos(text_normalize($projectLabelText), $normalizedKeywordText) !== false) {
            $matchList[] = ['label' => msg('案件'), 'snippetText' => $projectLabelText];
        }
    }
    foreach (report_values($reportId) as $reportProjectId => $valueByFieldId) {
        foreach ($valueByFieldId as $fieldId => $storedValue) {
            $field = $fieldById[(int) $fieldId] ?? null;
            if ($field === null || !isset($searchableFieldIdSet[(int) $fieldId]) || $storedValue['value'] === null || $storedValue['value'] === '') {
                continue;
            }
            if ($field['conf'] === 'restricted' && !$canViewRestricted) {
                continue;
            }
            if ($isFreeTextPurged && form_is_free_text($field)) {
                continue;
            }
            $searchedText = search_value_text($field, (string) $storedValue['value']);
            $matchPosition = mb_strpos(text_normalize($searchedText), $normalizedKeywordText, 0, 'UTF-8');
            if ($matchPosition === false) {
                continue;
            }
            $snippetStart = max(0, $matchPosition - 20);
            $snippetText = ($snippetStart > 0 ? '…' : '') . mb_substr(str_replace("\n", ' ', $searchedText), $snippetStart, 60, 'UTF-8')
                . (mb_strlen($searchedText, 'UTF-8') > $snippetStart + 60 ? '…' : '');
            $matchList[] = [
                'label'       => ($projectLabelByReportProjectId[(int) $reportProjectId] ?? '') . ' ' . $field['label'],
                'snippetText' => $snippetText,
            ];
            if (count($matchList) >= 5) {
                return $matchList;
            }
        }
    }
    return $matchList;
}

/**
 * 探すための文字列（選択式は表示名、表は各セルをつないだもの、それ以外は値そのもの）。
 */
function search_value_text(array $field, string $storedValue): string
{
    if (in_array($field['type'], ['select', 'radio'], true)) {
        return form_option_label($field, $storedValue);
    }
    if ($field['type'] === 'multi_check') {
        $selectedCodeList = json_decode($storedValue, true);
        return implode('、', array_map(function ($selectedCode) use ($field): string {
            return form_option_label($field, (string) $selectedCode);
        }, is_array($selectedCodeList) ? $selectedCodeList : []));
    }
    if ($field['type'] === 'table') {
        $tableRowList = json_decode($storedValue, true);
        $cellTextList = [];
        foreach (is_array($tableRowList) ? $tableRowList : [] as $tableRow) {
            foreach (is_array($tableRow) ? $tableRow : [] as $cellValue) {
                $cellTextList[] = (string) $cellValue;
            }
        }
        return implode(' ', $cellTextList);
    }
    return $storedValue;
}
