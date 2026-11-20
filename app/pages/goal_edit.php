<?php

declare(strict_types=1);

// ====================================================================
// G-27 目標管理: シートを見る・書く・評価する・コメントする・面談を記録する（詳細設計書 G27_goals.md）
//   GET  ?id=<ID>                    シートを開く（本人・課長・課長代理だけ。ほかの人は 404）
//   GET  ?year=<年度>&kind=<種類>      自分の、その年度のシートを作る（あればそれを開く）
//   POST action = save_owner（本人）・save_boss（課長）・comment・reply（本人・課長・課長代理）・meeting（課長・課長代理）
// コメント・面談の記録は、直さない・消さない（監査のため。直したいことは、新しく書いて残す）。
// ====================================================================

$user = page_start();
$userId = (int) $user['id'];
$errorTextList = [];

if (isset($_GET['id']) || isset($_POST['sheet_id'])) {
    $sheet = goal_sheet(isset($_POST['sheet_id']) ? input_id('sheet_id', 'POST') : input_id('id'));
    if ($sheet === null) {
        error_page(404);
    }
    $viewerRole = goal_viewer_role($user, $sheet['user_id']);
    if ($viewerRole === null) {
        error_page(404);
    }
} else {
    // 自分の、その年度のシートを作る
    $fiscalYear = isset($_POST['fiscal_year']) ? input_id('fiscal_year', 'POST') : (isset($_GET['year']) ? input_id('year') : goal_creatable_years()[0]);
    $existingSheetId = db_val('SELECT id FROM goal_sheets WHERE user_id = ? AND fiscal_year = ?', [$userId, $fiscalYear]);
    if ($existingSheetId !== null) {
        redirect('/goals/edit.php?id=' . (int) $existingSheetId);
    }
    if (!in_array($fiscalYear, goal_creatable_years(), true)) {
        error_page(404);
    }
    $requestedKind = (string) ($_POST['kind'] ?? ($_GET['kind'] ?? goal_default_kind($user)));
    $kindCode = in_array($requestedKind, goal_selectable_kinds($user), true) ? $requestedKind : goal_default_kind($user);   // 役職で選べない様式は使わない
    $sheet = ['id' => null, 'user_id' => $userId, 'fiscal_year' => $fiscalYear, 'kind' => $kindCode, 'created_at' => '', 'updated_at' => '',
              'data' => goal_new_sheet_data($user, $kindCode), 'unreadable' => false];
    $viewerRole = 'owner';
}
$sheetPath = $sheet['id'] !== null ? '/goals/edit.php?id=' . $sheet['id'] : '/goals/edit.php?year=' . $sheet['fiscal_year'] . '&kind=' . $sheet['kind'];

if (is_post()) {
    $action = post_str('action');
    $allowedActionList = ['owner' => ['save_owner', 'comment', 'reply'], 'boss' => ['save_boss', 'comment', 'reply', 'meeting'], 'deputy' => ['comment', 'reply', 'meeting']][$viewerRole];
    if (!in_array($action, $allowedActionList, true) || ($sheet['id'] === null && $action !== 'save_owner')) {
        error_page(403);
    }
    if ($sheet['unreadable'] && in_array($action, ['save_owner', 'save_boss'], true)) {
        error_page(409, msg('このシートは読めません（暗号の鍵が変わった可能性があります）。システム管理者に連絡してください。'));
    }
    rk_begin('goal.' . $action, $userId);
    if ($action === 'save_owner' || $action === 'save_boss') {
        $postedData = $action === 'save_owner' ? goal_apply_owner_post($sheet['data']) : goal_apply_boss_post($sheet['data']);
        $kindCode = $sheet['kind'];
        if ($action === 'save_owner' && $sheet['id'] === null) {
            $postedKind = post_str('kind');
            if (!in_array($postedKind, goal_selectable_kinds($user), true)) {
                error_page(403, msg('この様式は選べません（様式は役職で決まります）。'));
            }
            $kindCode = $postedKind;
            $postedData = goal_normalize($postedData, $kindCode);
        }
        $errorTextList = goal_validate($postedData);
        if (!$errorTextList) {
            $isNewSheet = $sheet['id'] === null;
            $savedSheetId = goal_save($sheet['id'], $sheet['user_id'], $sheet['fiscal_year'], $kindCode, $postedData, $userId, $isNewSheet ? 'create' : $action);
            flash('success', $action === 'save_boss' ? msg('評価を保存しました') : msg('目標管理シートを保存しました'));
            rk_done('/goals/edit.php?id=' . $savedSheetId);
        }
        rk_cancel();
        $sheet['data'] = $postedData;
        $sheet['kind'] = $kindCode;
    } elseif ($action === 'comment' || $action === 'reply') {
        $commentText = trim(str_replace(["\r\n", "\r"], "\n", post_str('body', false)));
        validate_text($commentText, msg('コメント'), 1000, true, $errorTextList, true);
        $parentCommentId = null;
        if ($action === 'reply') {
            $parentCommentId = input_id('parent_id', 'POST');
            if (db_val('SELECT 1 FROM goal_sheet_comments WHERE id = ? AND sheet_id = ? AND parent_id IS NULL', [$parentCommentId, $sheet['id']]) === null) {
                error_page(404);
            }
        }
        if (!$errorTextList) {
            db_exec('INSERT INTO goal_sheet_comments (sheet_id, user_id, parent_id, body_enc, created_at) VALUES (?, ?, ?, ?, ?)',
                [$sheet['id'], $userId, $parentCommentId, enc($commentText, 'goal_sheet_comments:' . $sheet['id']), now()]);
            goal_add_history((int) $sheet['id'], $userId, $action);
            flash('success', $action === 'reply' ? msg('返信しました') : msg('コメントしました'));
            rk_done($sheetPath . '#comments');
        }
        rk_cancel();
    } else {
        // 面談の結果は、自分の欄にだけ書く（課長・そのシートを見る課長代理。goal_meeting_reviewers()）
        if (!in_array($userId, array_column(goal_meeting_reviewers($sheet['user_id']), 'user_id'), true)) {
            error_page(403);
        }
        $heldOnText = post_str('held_on');
        $meetingNoteText = trim(str_replace(["\r\n", "\r"], "\n", post_str('note', false)));
        if (!expense_is_valid_date($heldOnText) || $heldOnText > date('Y-m-d')) {
            $errorTextList[] = msg('面談の日を正しく入れてください（今日より先の日は入れられません）');
        }
        validate_text($meetingNoteText, msg('面談の記録'), 2000, true, $errorTextList, true);
        if (!$errorTextList) {
            db_exec('INSERT INTO goal_sheet_meetings (sheet_id, held_on, note_enc, created_by, created_at) VALUES (?, ?, ?, ?, ?)',
                [$sheet['id'], $heldOnText, enc($meetingNoteText, 'goal_sheet_meetings:' . $sheet['id']), $userId, now()]);
            goal_add_history((int) $sheet['id'], $userId, 'meeting');
            flash('success', msg('面談の結果を残しました'));
            rk_done($sheetPath . '#meetings');
        }
        rk_cancel();
    }
}

$owner = db_one('SELECT id, display_name, role_title FROM users WHERE id = ?', [$sheet['user_id']]);
render('goal_edit', [
    'title'         => '目標管理シート',
    'user'          => $user,
    'sheet'         => $sheet,
    'sheetPath'     => $sheetPath,
    'ownerName'     => (string) ($owner['display_name'] ?? ''),
    'viewerRole'    => $viewerRole,
    'kindDefinition' => GOAL_TEMPLATE_KINDS[$sheet['kind']],
    'scores'        => goal_scores($sheet['data'], $sheet['kind']),
    'comments'      => $sheet['id'] !== null ? goal_comments((int) $sheet['id']) : [],
    'meetings'      => $sheet['id'] !== null ? goal_meetings((int) $sheet['id']) : [],
    'meetingReviewers' => $sheet['id'] !== null ? goal_meeting_reviewers($sheet['user_id']) : [],
    'history'       => $sheet['id'] !== null ? goal_history((int) $sheet['id']) : [],
    'errorTextList' => $errorTextList,
    'isMobile'      => is_mobile_request(),
]);
