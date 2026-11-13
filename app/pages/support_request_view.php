<?php

declare(strict_types=1);

// ====================================================================
// G-22 要望・依頼の詳細とやり取り（詳細設計書 G22_support_requests.md §6.3〜§6.5）
//   出した人: 返信する、未受付のうちは取り下げる
//   管理者・補佐: 対応した内容・検討の結果を返信し、状態を変える（完了・見送りは返信が必須）
// ====================================================================

$user = page_start();
$userId = (int) $user['id'];
$requestId = input_id('id');
$requestRow = db_one('SELECT * FROM support_requests WHERE id = ?', [$requestId]);
if ($requestRow === null) {
    error_page(404);
}
if (!support_request_can_view($user, $requestRow)) {
    error_page(403);
}
$isStaff = support_request_is_staff($user);
$isRequester = (int) $requestRow['user_id'] === $userId;
$isActive = in_array($requestRow['status'], SUPPORT_REQUEST_ACTIVE_STATUSES, true);
$selfPath = '/requests/view.php?id=' . $requestId;
$errorTextList = [];
$typedReplyText = '';
$typedStatusCode = '';

if (is_post()) {
    $action = post_str('action');
    if (!in_array($action, ['reply', 'staff_reply', 'withdraw', 'plan_save', 'merge'], true)) {
        error_page(400);
    }
    rk_begin('support_request.' . $action, $userId);
    $typedReplyText = trim(str_replace(["\r\n", "\r"], "\n", post_str('body', false)));

    if ($action === 'reply') {
        // 出した人の返信（続いている間だけ）
        if (!$isRequester) {
            error_page(403);
        }
        if (!$isActive) {
            rk_cancel();
            flash('error', msg('閉じた要望・依頼には返信できません。新しく出してください'));
            redirect($selfPath);
        }
        validate_text($typedReplyText, msg('返信'), SUPPORT_REQUEST_BODY_MAX_LENGTH, true, $errorTextList, true);
        if (!$errorTextList) {
            tx(function () use ($requestId, $userId, $typedReplyText): void {
                support_request_add_message($requestId, $userId, false, $typedReplyText, null);
                support_request_mark_seen_by_requester($requestId);
            });
            flash('success', msg('返信しました'));
            rk_done($selfPath);
        }

    } elseif ($action === 'staff_reply') {
        // 管理者側の返信と状態の変更（対応した内容・検討の結果を、出した人へ返信する）
        if (!$isStaff) {
            error_page(403);
        }
        // 状態のボタン（押したボタンの状態）。返信が空なら定型の返信を送る（見送りは返信が必須）
        $typedStatusCode = post_str('status');
        $isStatusChanged = $typedStatusCode !== '' && $typedStatusCode !== $requestRow['status'];
        if ($typedStatusCode !== '' && !in_array($typedStatusCode, SUPPORT_REQUEST_STAFF_STATUSES, true)) {
            error_page(400);
        }
        if ($requestRow['status'] === 'withdrawn') {
            rk_cancel();
            flash('error', msg('取り下げられた要望・依頼は変えられません'));
            redirect($selfPath);
        }
        $isClosing = $isStatusChanged && !in_array($typedStatusCode, SUPPORT_REQUEST_ACTIVE_STATUSES, true);
        if ($isStatusChanged && $typedReplyText === '' && in_array($typedStatusCode, SUPPORT_REQUEST_REPLY_REQUIRED_STATUSES, true)) {
            $errorTextList[] = msg('見送りにするときは、検討の結果を返信に書いてください');
        } elseif (!$isStatusChanged && $typedReplyText === '') {
            $errorTextList[] = msg('返信を入れるか、状態のボタンを押してください');
        } elseif ($isStatusChanged && $typedReplyText === '') {
            $typedReplyText = support_request_default_reply_text($typedStatusCode);
        }
        if ($typedReplyText !== '') {
            validate_text($typedReplyText, msg('返信'), SUPPORT_REQUEST_BODY_MAX_LENGTH, true, $errorTextList, true);
        }
        if (!$errorTextList) {
            tx(function () use ($requestId, $userId, $typedReplyText, $isStatusChanged, $typedStatusCode, $isClosing): void {
                support_request_add_message($requestId, $userId, true, $typedReplyText, $isStatusChanged ? $typedStatusCode : null);
                // まとめた要望にも同じ返信を届ける（便利機能 C）
                support_request_copy_reply_to_merged($requestId, $userId, $typedReplyText, $isStatusChanged ? $typedStatusCode : null);
                if ($isStatusChanged) {
                    db_exec('UPDATE support_requests SET status = ?, closed_at = ?, updated_at = ? WHERE id = ?',
                        [$typedStatusCode, $isClosing ? now() : null, now(), $requestId]);
                }
            });
            audit_log('admin', 'support_request:' . $requestId . ':' . ($isStatusChanged ? 'status_' . $typedStatusCode : 'reply'));
            flash('success', $isStatusChanged
                ? msg('返信し、状態を「{status}」にしました', ['status' => SUPPORT_REQUEST_STATUS_LABELS[$typedStatusCode]])
                : msg('返信しました'));
            rk_done($selfPath);
        }

    } elseif ($action === 'plan_save') {
        // 対応の予定（便利機能 D。管理者側が書き、出した人に見せる。空で消す）
        if (!$isStaff) {
            error_page(403);
        }
        $typedPlanText = post_str('plan_text');
        validate_text($typedPlanText, msg('対応の予定'), SUPPORT_REQUEST_PLAN_MAX_LENGTH, false, $errorTextList);
        if (!$errorTextList) {
            db_exec('UPDATE support_requests SET plan_text = ?, updated_at = ? WHERE id = ?', [$typedPlanText, now(), $requestId]);
            audit_log('admin', 'support_request:' . $requestId . ':plan');
            flash('success', $typedPlanText === '' ? msg('対応の予定を消しました') : msg('対応の予定を保存しました（出した人にも見えます）'));
            rk_done($selfPath);
        }

    } elseif ($action === 'merge') {
        // 重複をまとめる（便利機能 C）: この要望を、同じ内容の要望（まとめる先）にまとめて閉じる。まとめる先の返信は、この要望にも届く
        if (!$isStaff) {
            error_page(403);
        }
        $mergeTargetId = (int) preg_replace('/[^0-9]/', '', post_str('merge_into'));
        $mergeTargetRow = $mergeTargetId > 0 ? db_one('SELECT * FROM support_requests WHERE id = ?', [$mergeTargetId]) : null;
        if ($mergeTargetRow === null || $mergeTargetId === $requestId) {
            $errorTextList[] = msg('まとめる先の番号が正しくありません');
        } elseif ($mergeTargetRow['merged_into_id'] !== null) {
            $errorTextList[] = msg('まとめる先も、ほかの要望にまとめられています。まとめた先の番号を入れてください');
        } elseif (!in_array($requestRow['status'], SUPPORT_REQUEST_ACTIVE_STATUSES, true)) {
            $errorTextList[] = msg('閉じた要望・依頼はまとめられません');
        }
        if (!$errorTextList) {
            tx(function () use ($requestId, $userId, $mergeTargetId): void {
                support_request_add_message($requestId, $userId, true,
                    msg('同じ内容の要望 #{target} にまとめました。対応の結果は、この要望にも届きます。', ['target' => $mergeTargetId]), 'done');
                db_exec("UPDATE support_requests SET merged_into_id = ?, status = 'done', closed_at = ?, updated_at = ? WHERE id = ?",
                    [$mergeTargetId, now(), now(), $requestId]);
            });
            audit_log('admin', 'support_request:' . $requestId . ':merge:' . $mergeTargetId);
            flash('success', msg('#{target} にまとめました', ['target' => $mergeTargetId]));
            rk_done($selfPath);
        }

    } else {
        // 取り下げ（出した人が、未受付のうちだけ）
        if (!$isRequester) {
            error_page(403);
        }
        if ($requestRow['status'] !== 'open') {
            rk_cancel();
            flash('error', msg('未受付のときだけ取り下げられます'));
            redirect($selfPath);
        }
        if ($typedReplyText !== '') {
            validate_text($typedReplyText, msg('取り下げの理由'), SUPPORT_REQUEST_BODY_MAX_LENGTH, false, $errorTextList, true);
        }
        if (!$errorTextList) {
            tx(function () use ($requestId, $userId, $typedReplyText): void {
                support_request_add_message($requestId, $userId, false, $typedReplyText, 'withdrawn');
                db_exec("UPDATE support_requests SET status = 'withdrawn', closed_at = ?, updated_at = ? WHERE id = ?", [now(), now(), $requestId]);
                support_request_mark_seen_by_requester($requestId);
            });
            audit_log('admin', 'support_request:' . $requestId . ':withdraw');
            flash('success', msg('取り下げました'));
            rk_done($selfPath);
        }
    }
    rk_cancel();
}

// 出した人が開いたら、管理者側の返信を「見た」にする（ホームの「回答あり」を消す）
if ($isRequester && !is_post()) {
    support_request_mark_seen_by_requester($requestId);
}

$requesterRow = db_one('SELECT u.display_name, u.login_id, d.name AS department_name FROM users u LEFT JOIN departments d ON d.id = ? WHERE u.id = ?',
    [$requestRow['department_id'], (int) $requestRow['user_id']]);
$bodyText = dec((string) $requestRow['body_enc'], support_request_body_aad($requestId)) ?? msg('（読み出せません）');
$messageList = [];
foreach (db_all('SELECT m.*, u.display_name FROM support_request_messages m JOIN users u ON u.id = m.user_id WHERE m.request_id = ? ORDER BY m.id', [$requestId]) as $messageRow) {
    $messageRow['body_text'] = $messageRow['body_enc'] === '' ? '' : (dec((string) $messageRow['body_enc'], support_request_message_aad($requestId, (int) $messageRow['id'])) ?? msg('（読み出せません）'));
    $messageList[] = $messageRow;
}

$mergedRequestIdList = $isStaff ? array_map('intval', array_column(db_all('SELECT id FROM support_requests WHERE merged_into_id = ? ORDER BY id', [$requestId]), 'id')) : [];

render('support_request_view', [
    'mergedRequestIdList' => $mergedRequestIdList,
    'replyTemplateList'   => $isStaff ? support_reply_templates((string) $requestRow['kind']) : [],
    'title'          => '要望・依頼',
    'user'           => $user,
    'requestRow'     => $requestRow,
    'titleText'      => support_request_title_text($requestRow),
    'bodyText'       => $bodyText,
    'requesterRow'   => $requesterRow,
    'messageList'    => $messageList,
    'isStaff'        => $isStaff,
    'isRequester'    => $isRequester,
    'isActive'       => $isActive,
    'errorTextList'  => $errorTextList,
    'typedReplyText' => $typedReplyText,
    'typedStatusCode' => $typedStatusCode,
]);
