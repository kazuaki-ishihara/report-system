<?php

declare(strict_types=1);

// ====================================================================
// G-24 更新のお知らせ（便利機能 A）。管理者・補佐が書き、表示の期間の間、全員のホームに出す。
// 要望・依頼を「リリース済み」にした画面から来たときは、もとの要望の番号を残す（件名や出した人は写さない）。
// ====================================================================

$user = page_start();
require_system_manager($user);
$userId = (int) $user['id'];
$errorTextList = [];
$editedNoticeId = isset($_GET['edit']) ? input_id('edit') : null;
$sourceRequestId = isset($_GET['request']) ? input_id('request') : null;
if ($sourceRequestId !== null && db_one('SELECT id FROM support_requests WHERE id = ?', [$sourceRequestId]) === null) {
    error_page(404);
}
$editedNotice = $editedNoticeId === null ? null : db_one('SELECT * FROM update_notices WHERE id = ?', [$editedNoticeId]);
if ($editedNoticeId !== null && $editedNotice === null) {
    error_page(404);
}
$typedValueByName = [
    'title'      => (string) ($editedNotice['title'] ?? ''),
    'body'       => (string) ($editedNotice['body'] ?? ''),
    'show_from'  => (string) ($editedNotice['show_from'] ?? date('Y-m-d')),
    'show_until' => (string) ($editedNotice['show_until'] ?? date('Y-m-d', strtotime('+' . (int) cfg('notice_default_days') . ' days'))),
];

if (is_post()) {
    $action = post_str('action');
    if (!in_array($action, ['save', 'delete'], true)) {
        error_page(400);
    }
    rk_begin('admin.notice.' . $action, $userId);
    $postedNoticeId = isset($_POST['id']) && $_POST['id'] !== '' ? input_id('id', 'POST') : null;
    if ($postedNoticeId !== null && db_one('SELECT id FROM update_notices WHERE id = ?', [$postedNoticeId]) === null) {
        error_page(404);
    }
    if ($action === 'delete') {
        if ($postedNoticeId === null) {
            error_page(400);
        }
        db_exec('DELETE FROM update_notices WHERE id = ?', [$postedNoticeId]);
        audit_log('admin', 'notice:' . $postedNoticeId . ':delete');
        flash('success', msg('更新のお知らせを消しました'));
        rk_done('/admin/notices.php');
    }
    $typedValueByName = [
        'title'      => post_str('title'),
        'body'       => trim(str_replace(["\r\n", "\r"], "\n", post_str('body', false))),
        'show_from'  => post_str('show_from'),
        'show_until' => post_str('show_until'),
    ];
    validate_text($typedValueByName['title'], msg('題名'), NOTICE_TITLE_MAX_LENGTH, true, $errorTextList);
    validate_text($typedValueByName['body'], msg('内容'), NOTICE_BODY_MAX_LENGTH, false, $errorTextList, true);
    foreach (['show_from' => msg('出す日'), 'show_until' => msg('出す最後の日')] as $dateName => $dateLabel) {
        if (DateTime::createFromFormat('!Y-m-d', $typedValueByName[$dateName]) === false) {
            $errorTextList[] = msg('{label}を入れてください', ['label' => $dateLabel]);
        }
    }
    if (!$errorTextList && $typedValueByName['show_until'] < $typedValueByName['show_from']) {
        $errorTextList[] = msg('出す最後の日は、出す日と同じ日かそれより後にしてください');
    }
    $postedSourceRequestId = isset($_POST['source_request_id']) && $_POST['source_request_id'] !== '' ? input_id('source_request_id', 'POST') : null;
    if (!$errorTextList) {
        if ($postedNoticeId === null) {
            db_exec('INSERT INTO update_notices (title, body, show_from, show_until, source_request_id, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$typedValueByName['title'], $typedValueByName['body'], $typedValueByName['show_from'], $typedValueByName['show_until'], $postedSourceRequestId, $userId, $userId, now(), now()]);
            $postedNoticeId = (int) db()->lastInsertId();
            audit_log('admin', 'notice:' . $postedNoticeId . ':create');
        } else {
            db_exec('UPDATE update_notices SET title = ?, body = ?, show_from = ?, show_until = ?, updated_by = ?, updated_at = ? WHERE id = ?',
                [$typedValueByName['title'], $typedValueByName['body'], $typedValueByName['show_from'], $typedValueByName['show_until'], $userId, now(), $postedNoticeId]);
            audit_log('admin', 'notice:' . $postedNoticeId . ':update');
        }
        $hasWarning = text_has_warning($typedValueByName['title'] . "\n" . $typedValueByName['body']);
        flash($hasWarning ? 'error' : 'success', $hasWarning
            ? msg('保存しましたが、客先名や個人名らしい言葉が含まれています。全員に出るので、確かめてください')
            : msg('更新のお知らせを保存しました。出す期間の間、全員のホームに出ます'));
        rk_done('/admin/notices.php');
    }
    rk_cancel();
    $editedNoticeId = $postedNoticeId;
    $sourceRequestId = $postedSourceRequestId;
}

render('admin_notices', [
    'title'            => '更新のお知らせ',
    'user'             => $user,
    'noticeList'       => db_all('SELECT n.*, u.display_name AS updated_by_name FROM update_notices n LEFT JOIN users u ON u.id = n.updated_by ORDER BY n.show_from DESC, n.id DESC LIMIT 200'),
    'editedNoticeId'   => $editedNoticeId,
    'sourceRequestId'  => $sourceRequestId,
    'typedValueByName' => $typedValueByName,
    'errorTextList'    => $errorTextList,
]);
