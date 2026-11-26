<?php

declare(strict_types=1);

// ====================================================================
// G-27 目標管理: シートを、会社の様式の Excel（.xlsx）で受け取る（詳細設計書 G27_goals.md）
// 本人・課長・課長代理（見られる人）だけ。PC からだけ（出力と同じ決まり）。受け取ったことは更新の履歴に残す。
// ====================================================================

$user = page_start();
$sheet = goal_sheet(input_id('id'));
if ($sheet === null || goal_viewer_role($user, $sheet['user_id']) === null) {
    error_page(404);
}
if (is_mobile_request()) {
    error_page(403, msg('目標管理シートのファイルは PC で受け取ってください。'));
}
if ($sheet['unreadable']) {
    error_page(500, msg('このシートは読めません（暗号の鍵が変わった可能性があります）。システム管理者に連絡してください。'));
}
$workbookBytes = goal_xlsx($sheet);
goal_add_history($sheet['id'], (int) $user['id'], 'download');
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"goal.xlsx\"; filename*=UTF-8''" . rawurlencode(goal_xlsx_file_name($sheet)));
header('Content-Length: ' . strlen($workbookBytes));
header('Cache-Control: no-store');
echo $workbookBytes;
exit;
