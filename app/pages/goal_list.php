<?php

declare(strict_types=1);

// ====================================================================
// G-27 目標管理: 一覧（詳細設計書 G27_goals.md）
// 自分のシート（年度ごと）と、課長・課長代理なら、見られる人のシートと進み具合。管理者・補佐は、自分のシートだけ。
// ====================================================================

$user = page_start();
$userId = (int) $user['id'];
$creatableYearList = goal_creatable_years();
$selectedYear = isset($_GET['year']) ? input_id('year') : $creatableYearList[0];

$mySheetList = [];
foreach (db_all('SELECT id FROM goal_sheets WHERE user_id = ? ORDER BY fiscal_year DESC', [$userId]) as $sheetRow) {
    $mySheet = goal_sheet((int) $sheetRow['id']);
    if ($mySheet !== null) {
        $mySheet['progress'] = goal_progress($mySheet);
        $mySheetList[] = $mySheet;
    }
}
$existingYearList = array_map(function (array $mySheet): int { return $mySheet['fiscal_year']; }, $mySheetList);

render('goal_list', [
    'title'             => '目標管理シート',
    'user'              => $user,
    'mySheetList'       => $mySheetList,
    'newYearList'       => array_values(array_diff($creatableYearList, $existingYearList)),
    'selectedYear'      => $selectedYear,
    'yearOptionList'    => array_values(array_unique(array_merge($creatableYearList, $existingYearList, [$selectedYear]))),
    'memberOverview'    => goal_member_overview($user, $selectedYear),
]);
