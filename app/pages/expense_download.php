<?php

declare(strict_types=1);

// ====================================================================
// G-26 通勤定期代の精算: 精算書を、元の様式の Excel（.xlsx）で受け取る（詳細設計書 G26_expenses.md）
// 総務の様式（app/templates/expense_sheet.xlsx）に値を入れて渡す。罫線・並び・選択肢・小計と合計の式は様式のまま。
// 自分の精算書だけ。PC からだけ（スマートフォンにファイルを残さないため。出力と同じ決まり）。記録には残さない（K-063）。
// ====================================================================

$user = page_start();
$sheet = expense_sheet_of_owner(input_id('id'), (int) $user['id']);
if ($sheet === null) {
    error_page(404);
}
if (is_mobile_request()) {
    error_page(403, msg('精算書のファイルは PC で受け取ってください。'));
}
if (isset($sheet['data']['unreadable'])) {
    error_page(500, msg('この精算書は読めません（暗号の鍵が変わった可能性があります）。システム管理者に連絡してください。'));
}
$workbookBytes = expense_xlsx($sheet['data']);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"expense.xlsx\"; filename*=UTF-8''" . rawurlencode(expense_xlsx_file_name($sheet['data'])));
header('Content-Length: ' . strlen($workbookBytes));
header('Cache-Control: no-store');
echo $workbookBytes;
exit;
