<?php

declare(strict_types=1);

// ====================================================================
// G-22 要望・依頼の一覧と、新しく出す（詳細設計書 G22_support_requests.md §6.1・§6.2）
//   課長・確認者: 自分が出した要望・依頼の一覧と、新しく出す欄
//   管理者・補佐: すべての要望・依頼の一覧（状態・種類で絞り込む）
// ====================================================================

$user = page_start();
$userId = (int) $user['id'];
$canSubmit = support_request_can_submit($user);
$isStaff = support_request_is_staff($user);
if (!$canSubmit && !$isStaff) {
    error_page(403);
}
$errorTextList = [];
// 各画面の「この画面について要望・依頼」から来たときは、画面と開いていた画面を入れておく
$linkedScreenCode = (string) ($_GET['screen'] ?? '');
$typedValueByName = [
    'kind' => 'improvement', 'urgency' => 'normal', 'title' => '', 'body' => '',
    'screen_code' => isset(SUPPORT_REQUEST_SCREEN_LABELS[$linkedScreenCode]) ? $linkedScreenCode : '',
    'source_path' => support_request_clean_source_path((string) ($_GET['from'] ?? '')),
];

// ---- 返信の定型文の追加・削除（便利機能 B。管理者側） ----
if (is_post() && in_array(post_str('action'), ['template_add', 'template_delete'], true)) {
    if (!$isStaff) {
        error_page(403);
    }
    rk_begin('support_request.' . post_str('action'), $userId);
    if (post_str('action') === 'template_delete') {
        db_exec('DELETE FROM support_reply_templates WHERE id = ?', [input_id('template_id', 'POST')]);
        flash('success', msg('返信の定型文を消しました'));
        rk_done('/requests/index.php#reply-templates');
    }
    $templateBodyText = trim(str_replace(["\r\n", "\r"], "\n", post_str('template_body', false)));
    $templateErrorList = [];
    validate_text($templateBodyText, msg('返信の定型文'), SUPPORT_REPLY_TEMPLATE_MAX_LENGTH, true, $templateErrorList, true);
    if ((int) db_val('SELECT COUNT(*) FROM support_reply_templates') >= SUPPORT_REPLY_TEMPLATE_MAX_COUNT) {
        $templateErrorList[] = msg('返信の定型文は {max} 件までです', ['max' => SUPPORT_REPLY_TEMPLATE_MAX_COUNT]);
    }
    if ($templateErrorList) {
        rk_cancel();
        flash('error', implode("\n", $templateErrorList));
        redirect('/requests/index.php#reply-templates');
    }
    $templateKind = post_str('template_kind');
    if ($templateKind !== '' && !isset(SUPPORT_REQUEST_KIND_LABELS[$templateKind])) {
        error_page(400);
    }
    db_exec('INSERT INTO support_reply_templates (kind, body, sort_order, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$templateKind, $templateBodyText, (int) db_val('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM support_reply_templates'), $userId, now(), now()]);
    flash('success', msg('返信の定型文を足しました'));
    rk_done('/requests/index.php#reply-templates');
}

if (is_post()) {
    if (post_str('action') !== 'create' || !$canSubmit) {
        error_page($canSubmit ? 400 : 403);
    }
    rk_begin('support_request.create', $userId);
    $typedValueByName = [
        'kind'        => post_str('kind'),
        'urgency'     => post_str('urgency'),
        'screen_code' => post_str('screen_code'),
        'title'       => post_str('title'),
        'body'        => trim(str_replace(["\r\n", "\r"], "\n", post_str('body', false))),
        'source_path' => support_request_clean_source_path(post_str('source_path')),
    ];
    if (!isset(SUPPORT_REQUEST_KIND_LABELS[$typedValueByName['kind']])) {
        $errorTextList[] = msg('種類を選んでください');
    }
    if (!isset(SUPPORT_REQUEST_URGENCY_LABELS[$typedValueByName['urgency']])) {
        $errorTextList[] = msg('急ぎかどうかを選んでください');
    }
    if (!array_key_exists($typedValueByName['screen_code'], SUPPORT_REQUEST_SCREEN_LABELS)) {
        $errorTextList[] = msg('関係する画面が正しくありません');
    }
    validate_text($typedValueByName['title'], msg('件名'), SUPPORT_REQUEST_TITLE_MAX_LENGTH, true, $errorTextList);
    validate_text($typedValueByName['body'], msg('内容'), SUPPORT_REQUEST_BODY_MAX_LENGTH, true, $errorTextList, true);
    $activeRequestCount = (int) db_val(
        'SELECT COUNT(*) FROM support_requests WHERE user_id = ? AND status IN (' . support_request_active_status_sql() . ')', [$userId]
    );
    if ($activeRequestCount >= SUPPORT_REQUEST_ACTIVE_MAX_COUNT) {
        $errorTextList[] = msg('続いている要望・依頼が {max} 件あります。終わったものが閉じられてから出してください', ['max' => SUPPORT_REQUEST_ACTIVE_MAX_COUNT]);
    }
    if (!$errorTextList) {
        $createdRequestId = tx(function () use ($userId, $user, $typedValueByName): int {
            $createdAt = now();
            db_exec(
                'INSERT INTO support_requests (user_id, department_id, kind, urgency, screen_code, source_path, client_text, has_warning, status, last_activity_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$userId, $user['department_id'], $typedValueByName['kind'], $typedValueByName['urgency'], $typedValueByName['screen_code'],
                 $typedValueByName['source_path'], support_request_client_text(),
                 text_has_warning($typedValueByName['title'] . "\n" . $typedValueByName['body']) ? 1 : 0, 'open', $createdAt, $createdAt, $createdAt]
            );
            $newRequestId = (int) db()->lastInsertId();
            db_exec(
                'UPDATE support_requests SET title_enc = ?, body_enc = ? WHERE id = ?',
                [enc($typedValueByName['title'], support_request_title_aad($newRequestId)), enc($typedValueByName['body'], support_request_body_aad($newRequestId)), $newRequestId]
            );
            return $newRequestId;
        });
        audit_log('admin', 'support_request:' . $createdRequestId . ':create');
        flash('success', msg('要望・依頼を出しました。システム管理者から返信があると、ホームに出ます'));
        rk_done('/requests/view.php?id=' . $createdRequestId);
    }
    rk_cancel();
}

// ---- 一覧 ----
$statusFilter = (string) ($_GET['status'] ?? 'active');
if ($statusFilter !== 'active' && $statusFilter !== 'all' && !isset(SUPPORT_REQUEST_STATUS_LABELS[$statusFilter])) {
    $statusFilter = 'active';
}
$kindFilter = (string) ($_GET['kind'] ?? '');
if ($kindFilter !== '' && !isset(SUPPORT_REQUEST_KIND_LABELS[$kindFilter])) {
    $kindFilter = '';
}
// 管理者・補佐は全員分、ほかの人は自分の分（管理者・補佐でも ?scope=mine で自分の分だけ）
$isAllScope = $isStaff && ($_GET['scope'] ?? '') !== 'mine';
$whereList = [];
$parameterList = [];
if (!$isAllScope) {
    $whereList[] = 'q.user_id = ?';
    $parameterList[] = $userId;
}
if ($statusFilter === 'active') {
    $whereList[] = 'q.status IN (' . support_request_active_status_sql() . ')';
} elseif ($statusFilter !== 'all') {
    $whereList[] = 'q.status = ?';
    $parameterList[] = $statusFilter;
}
if ($kindFilter !== '') {
    $whereList[] = 'q.kind = ?';
    $parameterList[] = $kindFilter;
}
$requestRowList = db_all(
    'SELECT q.*, u.display_name AS requester_name, d.name AS department_name,
            (SELECT m.is_staff FROM support_request_messages m WHERE m.request_id = q.id ORDER BY m.id DESC LIMIT 1) AS last_message_is_staff,
            (SELECT COUNT(*) FROM support_request_messages m WHERE m.request_id = q.id AND m.is_staff = 1
               AND m.id > q.requester_seen_message_id) AS unseen_staff_message_count
       FROM support_requests q JOIN users u ON u.id = q.user_id LEFT JOIN departments d ON d.id = q.department_id'
    . ($whereList ? ' WHERE ' . implode(' AND ', $whereList) : '')
    . " ORDER BY CASE WHEN q.status = 'open' THEN 0 WHEN q.status IN (" . support_request_active_status_sql() . ") THEN 1 ELSE 2 END, q.urgency = 'urgent' DESC, q.last_activity_at DESC LIMIT 300",
    $parameterList
);
foreach ($requestRowList as $requestIndex => $requestRow) {
    $requestRowList[$requestIndex]['title_text'] = support_request_title_text($requestRow);
}

// ---- CSV の出力（便利機能 E。管理者側・PC。内容を含めるかを選べる） ----
if (($_GET['csv'] ?? '') === '1') {
    if (!$isStaff || !$isAllScope) {
        error_page(403);
    }
    if (is_mobile_request()) {
        error_page(403, msg('出力は PC から行ってください。'));
    }
    $isBodyIncluded = ($_GET['body'] ?? '') === '1';
    $csvHeader = ['番号', '種類', '件名', '状態', '急ぎ', '関係する画面', '課', '出した人', '出した日時', '最後のやり取り', '閉じた日時', '対応の予定', 'まとめた先'];
    if ($isBodyIncluded) {
        $csvHeader[] = '内容';
    }
    $csvRows = [];
    foreach ($requestRowList as $requestRow) {
        $csvRow = [
            (string) $requestRow['id'], SUPPORT_REQUEST_KIND_LABELS[$requestRow['kind']] ?? '', $requestRow['title_text'],
            SUPPORT_REQUEST_STATUS_LABELS[$requestRow['status']] ?? '', SUPPORT_REQUEST_URGENCY_LABELS[$requestRow['urgency']] ?? '',
            SUPPORT_REQUEST_SCREEN_LABELS[$requestRow['screen_code']] ?? (string) $requestRow['screen_code'], (string) ($requestRow['department_name'] ?? ''),
            (string) $requestRow['requester_name'], (string) $requestRow['created_at'], (string) $requestRow['last_activity_at'], (string) ($requestRow['closed_at'] ?? ''),
            (string) $requestRow['plan_text'], $requestRow['merged_into_id'] === null ? '' : (string) $requestRow['merged_into_id'],
        ];
        if ($isBodyIncluded) {
            $csvRow[] = dec((string) $requestRow['body_enc'], support_request_body_aad((int) $requestRow['id'])) ?? '';
        }
        $csvRows[] = $csvRow;
    }
    audit_log('export', 'export:support_requests;body=' . ($isBodyIncluded ? '1' : '0'));
    $csvBody = export_csv(['社外秘', '要望・依頼の一覧', '出力者: ' . $user['login_id'] . '  出力日時: ' . date('Y-m-d H:i')], $csvHeader, $csvRows);
    send_security_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="support_requests_' . date('Ymd-Hi') . '.csv"');
    echo $csvBody;
    exit;
}

render('support_request_list', [
    'title'            => '要望・依頼',
    'user'             => $user,
    'canSubmit'        => $canSubmit,
    'isStaff'          => $isStaff,
    'isAllScope'       => $isAllScope,
    'statusFilter'     => $statusFilter,
    'kindFilter'       => $kindFilter,
    'requestRowList'   => $requestRowList,
    'errorTextList'    => $errorTextList,
    'typedValueByName' => $typedValueByName,
    'isNewFormOpen'    => $errorTextList !== [] || $linkedScreenCode !== '',
    'staffCounts'      => $isStaff ? support_request_staff_counts() : null,
    'replyTemplateList' => $isStaff ? support_reply_templates() : [],
]);
