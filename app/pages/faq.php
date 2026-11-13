<?php

declare(strict_types=1);

// ====================================================================
// G-25 よくある質問（便利機能 J）。全員が見られる。管理者・補佐が質問と答えを書く。
// 要望・依頼の「質問」に答えたものから載せるときも、文は管理者側が書き直す（出した人の文や名前は出さない）。
// ====================================================================

$user = page_start();
$userId = (int) $user['id'];
$canEdit = can_manage_system($user);
$errorTextList = [];
$editedFaqId = isset($_GET['edit']) ? input_id('edit') : null;
$sourceRequestId = isset($_GET['request']) ? input_id('request') : null;
if (($editedFaqId !== null || $sourceRequestId !== null) && !$canEdit) {
    error_page(403);
}
$editedFaq = $editedFaqId === null ? null : db_one('SELECT * FROM faq_entries WHERE id = ?', [$editedFaqId]);
if ($editedFaqId !== null && $editedFaq === null) {
    error_page(404);
}
$typedValueByName = ['question' => (string) ($editedFaq['question'] ?? ''), 'answer' => (string) ($editedFaq['answer'] ?? '')];

if (is_post()) {
    if (!$canEdit) {
        error_page(403);
    }
    $action = post_str('action');
    if (!in_array($action, ['save', 'delete', 'move_up', 'move_down'], true)) {
        error_page(400);
    }
    rk_begin('faq.' . $action, $userId);
    $postedFaqId = isset($_POST['id']) && $_POST['id'] !== '' ? input_id('id', 'POST') : null;
    if ($postedFaqId !== null && db_one('SELECT id FROM faq_entries WHERE id = ?', [$postedFaqId]) === null) {
        error_page(404);
    }
    if ($action === 'delete' || $action === 'move_up' || $action === 'move_down') {
        if ($postedFaqId === null) {
            error_page(400);
        }
        if ($action === 'delete') {
            db_exec('DELETE FROM faq_entries WHERE id = ?', [$postedFaqId]);
            audit_log('admin', 'faq:' . $postedFaqId . ':delete');
            flash('success', msg('よくある質問を消しました'));
        } else {
            // 並べ替え: 隣と入れ替える
            $faqOrderList = array_map('intval', array_column(db_all('SELECT id FROM faq_entries ORDER BY sort_order, id'), 'id'));
            $currentIndex = array_search($postedFaqId, $faqOrderList, true);
            $swapIndex = $action === 'move_up' ? $currentIndex - 1 : $currentIndex + 1;
            if ($currentIndex !== false && isset($faqOrderList[$swapIndex])) {
                [$faqOrderList[$currentIndex], $faqOrderList[$swapIndex]] = [$faqOrderList[$swapIndex], $faqOrderList[$currentIndex]];
                tx(function () use ($faqOrderList): void {
                    foreach ($faqOrderList as $orderIndex => $faqId) {
                        db_exec('UPDATE faq_entries SET sort_order = ? WHERE id = ?', [($orderIndex + 1) * 10, $faqId]);
                    }
                });
            }
        }
        rk_done('/faq.php');
    }
    $typedValueByName = [
        'question' => post_str('question'),
        'answer'   => trim(str_replace(["\r\n", "\r"], "\n", post_str('answer', false))),
    ];
    validate_text($typedValueByName['question'], msg('質問'), FAQ_QUESTION_MAX_LENGTH, true, $errorTextList);
    validate_text($typedValueByName['answer'], msg('答え'), FAQ_ANSWER_MAX_LENGTH, true, $errorTextList, true);
    if ($postedFaqId === null && (int) db_val('SELECT COUNT(*) FROM faq_entries') >= FAQ_MAX_COUNT) {
        $errorTextList[] = msg('よくある質問は {max} 件までです', ['max' => FAQ_MAX_COUNT]);
    }
    $postedSourceRequestId = isset($_POST['source_request_id']) && $_POST['source_request_id'] !== '' ? input_id('source_request_id', 'POST') : null;
    if (!$errorTextList) {
        if ($postedFaqId === null) {
            $nextSortOrder = (int) db_val('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM faq_entries');
            db_exec('INSERT INTO faq_entries (question, answer, sort_order, source_request_id, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$typedValueByName['question'], $typedValueByName['answer'], $nextSortOrder, $postedSourceRequestId, $userId, now(), now()]);
            $postedFaqId = (int) db()->lastInsertId();
            audit_log('admin', 'faq:' . $postedFaqId . ':create');
        } else {
            db_exec('UPDATE faq_entries SET question = ?, answer = ?, updated_by = ?, updated_at = ? WHERE id = ?',
                [$typedValueByName['question'], $typedValueByName['answer'], $userId, now(), $postedFaqId]);
            audit_log('admin', 'faq:' . $postedFaqId . ':update');
        }
        flash('success', msg('よくある質問を保存しました'));
        rk_done('/faq.php#faq-' . $postedFaqId);
    }
    rk_cancel();
    $editedFaqId = $postedFaqId;
    $sourceRequestId = $postedSourceRequestId;
}

render('faq', [
    'title'            => 'よくある質問',
    'user'             => $user,
    'faqList'          => db_all('SELECT * FROM faq_entries ORDER BY sort_order, id'),
    'canEdit'          => $canEdit,
    'editedFaqId'      => $editedFaqId,
    'sourceRequestId'  => $sourceRequestId,
    'isFormOpen'       => $errorTextList !== [] || $editedFaqId !== null || $sourceRequestId !== null,
    'typedValueByName' => $typedValueByName,
    'errorTextList'    => $errorTextList,
]);
