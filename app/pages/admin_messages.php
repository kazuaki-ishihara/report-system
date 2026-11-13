<?php

declare(strict_types=1);

// ====================================================================
// G-17 メッセージ（詳細設計書 G17_messages.md）
// 課長が、画面に出す文言を変える。全体で 1 つ（課ごとには持たない）。課長以外は 403。
// ====================================================================

$user = page_start();
if (!msg_can_edit($user)) {
    error_page(403);
}
$userId = (int) $user['id'];
$catalogByDefaultText = msg_catalog();

/** 文言ごとの印（画面の中の移動先の id）: 既定の文言の SHA-1 の先頭 10 文字 */
$anchorIdFor = function (string $defaultText): string {
    return 'm-' . substr(sha1($defaultText), 0, 10);
};

// ---- 絞り込み（GET。保存のあとも同じ条件に戻す） ----
$filterScreenName = (string) ($_GET['screen'] ?? '');
$filterKeywordText = trim((string) ($_GET['q'] ?? ''));
$isChangedOnly = ($_GET['changed'] ?? '') === '1';
$screenNameList = [];
foreach ($catalogByDefaultText as $catalogEntry) {
    foreach ($catalogEntry['screens'] as $screenName) {
        $screenNameList[$screenName] = true;
    }
}
$screenNameList = array_keys($screenNameList);
sort($screenNameList);
if ($filterScreenName !== '' && !in_array($filterScreenName, $screenNameList, true)) {
    $filterScreenName = '';
}
if (str_len($filterKeywordText) > 100) {
    $filterKeywordText = '';
}
$filterQueryString = http_build_query(array_filter(['screen' => $filterScreenName, 'q' => $filterKeywordText, 'changed' => $isChangedOnly ? '1' : '']));
$selfPath = '/admin/messages.php' . ($filterQueryString !== '' ? '?' . $filterQueryString : '');

$errorTextByDefaultText = [];   // 保存できなかった文言 => 誤りの文言
$typedTextByDefaultText = [];   // 保存できなかった文言 => 入れた文言（画面に戻す）

if (is_post()) {
    $action = post_str('action');
    if (!in_array($action, ['save', 'reset', 'delete_unused'], true)) {
        error_page(400);
    }
    rk_begin('admin.message.' . $action, $userId);
    $defaultText = post_str('msg_key');
    $anchorId = $anchorIdFor($defaultText);
    $existingOverrideRow = db_one('SELECT msg_key FROM ui_messages WHERE msg_key = ?', [$defaultText]);

    if ($action === 'save') {
        if (!isset($catalogByDefaultText[$defaultText])) {
            error_page(404);
        }
        $newText = trim(str_replace(["\r\n", "\r"], "\n", post_str('body')));   // 改行は LF にそろえる
        $validationErrorText = msg_validate($defaultText, $newText);
        if ($validationErrorText !== null) {
            rk_cancel();
            $errorTextByDefaultText[$defaultText] = $validationErrorText;
            $typedTextByDefaultText[$defaultText] = $newText;
        } else {
            if ($newText === $defaultText) {
                // 既定と同じ文言なら、変えた記録を消す（既定に戻すのと同じ）
                db_exec('DELETE FROM ui_messages WHERE msg_key = ?', [$defaultText]);
            } else {
                db_exec(
                    'INSERT INTO ui_messages (msg_key, body, updated_by, updated_at) VALUES (?, ?, ?, ?)
                     ON CONFLICT(msg_key) DO UPDATE SET body = excluded.body, updated_by = excluded.updated_by, updated_at = excluded.updated_at',
                    [$defaultText, $newText, $userId, now()]
                );
            }
            audit_log('admin', 'message:update:' . substr($anchorId, 2));
            msg_overrides(true);
            flash('success', msg('文言を保存しました。すべての課の画面に反映されます'));
            rk_done($selfPath . '#' . $anchorId);
        }
    } else {
        // 既定に戻す・使われていない文言を消す（どちらも変えた記録を消す）
        if ($existingOverrideRow === null) {
            error_page(404);
        }
        if ($action === 'delete_unused' && isset($catalogByDefaultText[$defaultText])) {
            error_page(400);
        }
        db_exec('DELETE FROM ui_messages WHERE msg_key = ?', [$defaultText]);
        audit_log('admin', 'message:reset:' . substr($anchorId, 2));
        msg_overrides(true);
        flash('success', $action === 'reset' ? msg('既定の文言に戻しました') : msg('使われていない文言を消しました'));
        rk_done($selfPath . ($action === 'reset' ? '#' . $anchorId : ''));
    }
}

// ---- 一覧 ----
$overrideRowByDefaultText = [];
foreach (db_all('SELECT m.msg_key, m.body, m.updated_at, u.display_name AS updated_by_name
                   FROM ui_messages m LEFT JOIN users u ON u.id = m.updated_by') as $overrideRow) {
    $overrideRowByDefaultText[(string) $overrideRow['msg_key']] = $overrideRow;
}

$messageRowList = [];
foreach ($catalogByDefaultText as $defaultText => $catalogEntry) {
    $defaultText = (string) $defaultText;
    $overrideRow = $overrideRowByDefaultText[$defaultText] ?? null;
    if ($filterScreenName !== '' && !in_array($filterScreenName, $catalogEntry['screens'], true)) {
        continue;
    }
    if ($isChangedOnly && $overrideRow === null) {
        continue;
    }
    if ($filterKeywordText !== '' && strpos($defaultText, $filterKeywordText) === false
        && ($overrideRow === null || strpos((string) $overrideRow['body'], $filterKeywordText) === false)) {
        continue;
    }
    $messageRowList[] = [
        'anchorId'      => $anchorIdFor($defaultText),
        'defaultText'   => $defaultText,
        'screenNames'   => $catalogEntry['screens'],
        'placeholders'  => msg_placeholders($defaultText),
        'isChanged'     => $overrideRow !== null,
        'currentText'   => $typedTextByDefaultText[$defaultText] ?? ($overrideRow !== null ? (string) $overrideRow['body'] : $defaultText),
        'updatedAt'     => $overrideRow['updated_at'] ?? null,
        'updatedByName' => $overrideRow['updated_by_name'] ?? null,
        'errorText'     => $errorTextByDefaultText[$defaultText] ?? null,
    ];
}

// コードから消えた文言の変更（既定の文言が変わったなど）。画面に出ないので、確かめて消せるようにする
$unusedOverrideRowList = [];
foreach ($overrideRowByDefaultText as $defaultText => $overrideRow) {
    if (!isset($catalogByDefaultText[$defaultText])) {
        $unusedOverrideRowList[] = $overrideRow;
    }
}

render('admin_messages', [
    'title'                 => 'メッセージ',
    'user'                  => $user,
    'messageRowList'        => $messageRowList,
    'unusedOverrideRowList' => $unusedOverrideRowList,
    'screenNameList'        => $screenNameList,
    'filterScreenName'      => $filterScreenName,
    'filterKeywordText'     => $filterKeywordText,
    'isChangedOnly'         => $isChangedOnly,
    'selfPath'              => $selfPath,
    'totalCount'            => count($catalogByDefaultText),
    'changedCount'          => count(array_intersect_key($overrideRowByDefaultText, $catalogByDefaultText)),
]);
