<?php

declare(strict_types=1);

// ====================================================================
// G-26 通勤定期代の精算: 自分の精算書の一覧（詳細設計書 G26_expenses.md）
// 自分の精算書だけを出す。ほかの人の精算書は、管理者・補佐・課長も見られない（K-063）。
// ====================================================================

$user = page_start();
$sheetList = expense_sheets_of_owner((int) $user['id']);
foreach ($sheetList as $sheetIndex => $sheetItem) {
    $sheetList[$sheetIndex]['totals'] = expense_totals($sheetItem['data']);
}

render('expense_list', ['title' => '通勤定期代の精算', 'user' => $user, 'sheetList' => $sheetList, 'isMobile' => is_mobile_request()]);
