<?php

declare(strict_types=1);

// ====================================================================
// G-27 目標管理: 課長が、課の人のその年度のシートをまとめて受け取る（zip。詳細設計書 G27_goals.md）
//   GET ?year=<年度>&dept=<課 ID>
// その課の課長だけ（課長代理・ほかの課の課長は 403）。PC からだけ。中は 1 人 1 つの、会社の様式の xlsx。
// ====================================================================

$user = page_start();
$fiscalYear = input_id('year');
$departmentId = input_id('dept');
if (!is_final_reviewer_of((int) $user['id'], $departmentId)) {
    error_page(403);
}
if (is_mobile_request()) {
    error_page(403, msg('目標管理シートのファイルは PC で受け取ってください。'));
}
$zipBytes = goal_department_zip($user, $departmentId, $fiscalYear);
if ($zipBytes === null) {
    flash('info', msg('この年度の課の人のシートは、まだありません。'));
    redirect('/goals/index.php?year=' . $fiscalYear . '#members');
}
$departmentName = (string) preg_replace('/[\\\\\/:*?"<>|\s]+/u', '', (string) db_val('SELECT name FROM departments WHERE id = ?', [$departmentId]));
header('Content-Type: application/zip');
header("Content-Disposition: attachment; filename=\"goals.zip\"; filename*=UTF-8''" . rawurlencode('目標管理評価シート_' . $fiscalYear . '年度_' . ($departmentName !== '' ? $departmentName : 'dept') . '.zip'));
header('Content-Length: ' . strlen($zipBytes));
header('Cache-Control: no-store');
echo $zipBytes;
exit;
