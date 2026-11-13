<?php

declare(strict_types=1);

// ====================================================================
// G-20 報告の検索（詳細設計書 G20_search.md）
// 確認者・課長は、見られる課の提出済みの報告を探す。だれでも、自分の報告（下書きを含む）を探せる（K-015）。
// 条件は対象月・課・選択式の値・キーワード。
// ====================================================================

$user = page_start();
$reviewableDepartmentById = reviewable_departments($user);
// 探す範囲: department（見られる課）か mine（自分の報告）。確認者でない人は自分の報告だけ
$searchScope = (string) ($_GET['scope'] ?? '');
if (!$reviewableDepartmentById || $searchScope !== 'department') {
    $searchScope = $reviewableDepartmentById && $searchScope === '' ? 'department' : 'mine';
}
$isOwnReportsOnly = $searchScope === 'mine';

$defaultPeriodTo = report_target_period($user);
$periodFrom = (string) ($_GET['from'] ?? period_shift($defaultPeriodTo, -2));
$periodTo = (string) ($_GET['to'] ?? $defaultPeriodTo);
$selectedDepartmentText = (string) ($_GET['dept'] ?? '');
$choiceTargetText = (string) ($_GET['choice'] ?? '');   // "<項目 ID>:<選択肢のコード>" または空
$keywordText = trim((string) ($_GET['q'] ?? ''));
$isSearchRequested = isset($_GET['search']);

$errorTextList = [];
if (!is_valid_period($periodFrom) || !is_valid_period($periodTo)) {
    error_page(400);
}
if ($periodFrom > $periodTo) {
    $errorTextList[] = msg('対象月の始めは、終わりと同じ月かそれより前にしてください');
} elseif ($periodFrom < period_shift($periodTo, -23)) {
    $errorTextList[] = msg('対象月は 24 か月以内にしてください');
}
if (str_len($keywordText) > 50 || has_control_chars($keywordText)) {
    $errorTextList[] = msg('キーワードは 50 文字以内にしてください');
}

if ($isOwnReportsOnly || $selectedDepartmentText === '') {
    $selectedDepartmentIdList = array_map('intval', array_keys($reviewableDepartmentById));
} elseif (preg_match('/^\d{1,10}$/', $selectedDepartmentText) === 1 && isset($reviewableDepartmentById[(int) $selectedDepartmentText])) {
    $selectedDepartmentIdList = [(int) $selectedDepartmentText];
} else {
    error_page(403);
}
// 組織の改訂で引き継いだ課（改訂前の課）の報告も探す
$searchDepartmentIdList = [];
foreach ($selectedDepartmentIdList as $selectedDepartmentId) {
    $searchDepartmentIdList = array_merge($searchDepartmentIdList, department_with_predecessor_ids($selectedDepartmentId));
}
$searchDepartmentIdList = array_values(array_unique($searchDepartmentIdList));

// 選択式の条件の項目: 確認者の課（自分の報告だけのときは、自分の課も）の項目
$choiceDepartmentIdList = array_map('intval', array_keys($reviewableDepartmentById));
if ($user['department_id'] !== null) {
    $choiceDepartmentIdList[] = (int) $user['department_id'];
}
$choiceFieldById = search_choice_fields(array_values(array_unique($choiceDepartmentIdList)));
$selectedChoiceField = null;
$selectedChoiceCode = '';
if ($choiceTargetText !== '') {
    if (preg_match('/^(\d{1,10}):([a-z0-9_]{1,40})$/', $choiceTargetText, $choiceTargetParts) !== 1
        || !isset($choiceFieldById[(int) $choiceTargetParts[1]])) {
        error_page(400);
    }
    $selectedChoiceField = $choiceFieldById[(int) $choiceTargetParts[1]];
    $selectedChoiceCode = $choiceTargetParts[2];
    $allowedChoiceCodeList = $selectedChoiceField['type'] === 'checkbox' ? ['1'] : form_option_codes($selectedChoiceField);
    if (!in_array($selectedChoiceCode, $allowedChoiceCodeList, true)) {
        error_page(400);
    }
}

$searchResult = null;
if ($isSearchRequested && !$errorTextList) {
    $searchResult = search_reports($user, [
        'departmentIdList' => $searchDepartmentIdList,
        'periodFrom'       => $periodFrom,
        'periodTo'         => $periodTo,
        'choiceField'      => $selectedChoiceField,
        'choiceCode'       => $selectedChoiceCode,
        'keywordText'      => $keywordText,
        'ownerUserId'      => $isOwnReportsOnly ? (int) $user['id'] : null,
    ]);
    // 記録には条件を残す。キーワードは客先名などが入りうるので、入れたかどうかだけ残す
    audit_log('view', 'search:dept=' . ($isOwnReportsOnly ? 'mine' : ($selectedDepartmentText === '' ? 'all' : $selectedDepartmentText)) . ';from=' . $periodFrom . ';to=' . $periodTo
        . ';choice=' . ($choiceTargetText === '' ? '-' : $choiceTargetText) . ';keyword=' . ($keywordText === '' ? 'no' : 'yes'));
}

render('search', [
    'title'                    => '検索',
    'user'                     => $user,
    'reviewableDepartmentById' => $reviewableDepartmentById,
    'searchScope'              => $searchScope,
    'choiceFieldById'          => $choiceFieldById,
    'periodFrom'               => $periodFrom,
    'periodTo'                 => $periodTo,
    'selectedDepartmentText'   => $selectedDepartmentText,
    'choiceTargetText'         => $choiceTargetText,
    'keywordText'              => $keywordText,
    'errorTextList'            => $errorTextList,
    'searchResult'             => $searchResult,
    'decryptLimit'             => (int) cfg('search_decrypt_limit'),
]);
