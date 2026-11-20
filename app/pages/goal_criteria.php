<?php

declare(strict_types=1);

// ====================================================================
// G-27 目標管理: コンピテンシー評価基準を見る（詳細設計書 G27_goals.md §3.5）
//   GET ?sheet=<番号>  評価基準のシート（システム・ヘルプデスク・管理職登用基準・職能レベル定義）を切り替える
// 目標管理シートの画面から、別のタブで開く。ログインしている人ならだれでも見られる（評価の参考の資料）。
// 中身は app/templates/competency_criteria.xlsx から tools/lib/goal_criteria_gen.py で作った表（app/lib/goal_criteria.php）。
// ====================================================================

require_once APP_DIR . '/lib/goal_criteria.php';

$user = page_start();
$sheetNameList = array_keys(GOAL_CRITERIA_SHEETS);
$selectedSheetIndex = isset($_GET['sheet']) ? input_id('sheet') - 1 : 0;
if (!isset($sheetNameList[$selectedSheetIndex])) {
    error_page(404);
}

render('goal_criteria', [
    'title'              => 'コンピテンシー評価基準',
    'user'               => $user,
    'sheetNameList'      => $sheetNameList,
    'selectedSheetIndex' => $selectedSheetIndex,
    'tableRowList'       => GOAL_CRITERIA_SHEETS[$sheetNameList[$selectedSheetIndex]],
]);
