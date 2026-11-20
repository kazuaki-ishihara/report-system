<?php

declare(strict_types=1);

// ====================================================================
// G-26 通勤定期代の精算: 精算書を作る・直す・消す（通勤定期だけを受け付ける）（詳細設計書 G26_expenses.md）
//   GET  ?id=<ID>    自分の精算書を開く（ほかの人の ID は 404）
//   GET  ?copy=<ID>  自分の精算書を写して新しく作る（提出日は今日、仮払いは空にする）
//   GET  （なし）     新しく作る（上の欄は前に作った精算書か、利用者の情報から入れる）
//   POST action = save（保存）・delete（削除）
// 記録（access_logs）には残さない（精算書は本人だけのもの。K-063）。
// ====================================================================

$user = page_start();
$userId = (int) $user['id'];
$sheetId = null;
$errorTextList = [];

if (isset($_GET['id']) || isset($_POST['sheet_id'])) {
    $sheetId = isset($_POST['sheet_id']) ? input_id('sheet_id', 'POST') : input_id('id');
    $existingSheet = expense_sheet_of_owner($sheetId, $userId);
    if ($existingSheet === null) {
        error_page(404);
    }
    $sheetData = $existingSheet['data'];
} elseif (isset($_GET['copy'])) {
    $sourceSheet = expense_sheet_of_owner(input_id('copy'), $userId);
    if ($sourceSheet === null) {
        error_page(404);
    }
    $sheetData = $sourceSheet['data'];
    unset($sheetData['unreadable']);
    $sheetData['submitted_on'] = date('Y-m-d');
    $sheetData['advance_amount'] = '';
} else {
    $sheetData = expense_new_sheet_data($user);
}

if (is_post()) {
    $action = post_str('action');
    if (!in_array($action, ['save', 'delete'], true) || ($action === 'delete' && $sheetId === null)) {
        error_page(400);
    }
    rk_begin('expense.' . $action, $userId);
    if ($action === 'delete') {
        db_exec('DELETE FROM expense_sheets WHERE id = ? AND user_id = ?', [$sheetId, $userId]);
        flash('success', msg('精算書を削除しました'));
        rk_done('/expenses/index.php');
    }
    $sheetData = expense_data_from_post();
    $errorTextList = expense_validate($sheetData);
    if (!$errorTextList) {
        $savedSheetId = expense_save($sheetId, $userId, $sheetData);
        flash('success', msg('精算書を保存しました'));
        rk_done('/expenses/edit.php?id=' . $savedSheetId);
    }
    rk_cancel();
}

// 明細の入力欄は、書いた行のあとに空の行を足して出す（13 行に満たなければ 13 行まで。最大 26 行）
$visibleRowCount = min(EXPENSE_MAX_ROWS, max(EXPENSE_ROWS_PER_PAGE, count($sheetData['rows']) + 3));
$editRowList = $sheetData['rows'];
while (count($editRowList) < $visibleRowCount) {
    $editRowList[] = expense_empty_row();
}

render('expense_edit', [
    'title'         => $sheetId === null ? '精算書を作る' : '精算書を直す',
    'user'          => $user,
    'sheetId'       => $sheetId,
    'sheetData'     => $sheetData,
    'editRowList'   => $editRowList,
    'totals'        => expense_totals($sheetData),
    'errorTextList' => $errorTextList,
    'isMobile'      => is_mobile_request(),
]);
