<?php

declare(strict_types=1);

// ====================================================================
// 更新のお知らせ（G-24）を、反映のときにコマンドで出す（コマンドラインで実行）
//   php app/bin/post_notice.php --file=<お知らせ.txt> [--db=<DB>] [--dry-run]
// ファイルの形（UTF-8）:
//   題名: <題名（60 文字まで）>
//   出す日数: <日数（省くと notice_default_days）>
//   ---
//   <本文。最後に署名「以上、システム管理補佐（AI）より」を付けて 1,000 文字まで>
// 本文の最後に署名を付け、書いた人の欄は空にする（人が書いたお知らせと見分ける）。
// 同じ題名・同じ本文のお知らせがあれば書き込まない（何度実行しても 1 件）。
// ====================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';

$optionByName = getopt('', ['file:', 'db:', 'dry-run']);
if (isset($optionByName['db'])) {
    $GLOBALS['APP_CONFIG']['db_path'] = (string) $optionByName['db'];
}
$noticeFilePath = (string) ($optionByName['file'] ?? '');
if ($noticeFilePath === '' || !is_file($noticeFilePath)) {
    fwrite(STDERR, "使い方: php app/bin/post_notice.php --file=<お知らせ.txt> [--db=<DB>] [--dry-run]\n");
    exit(1);
}

$parsedNotice = notice_parse_file((string) file_get_contents($noticeFilePath));
if ($parsedNotice['errors']) {
    foreach ($parsedNotice['errors'] as $errorText) {
        fwrite(STDERR, $errorText . "\n");
    }
    exit(1);
}
$noticeTitle = $parsedNotice['title'];
$noticeBody = $parsedNotice['body'];
$showFromDate = date('Y-m-d');
$showUntilDate = date('Y-m-d', strtotime('+' . $parsedNotice['days'] . ' days'));

echo "題名: {$noticeTitle}\n";
echo "出す期間: {$showFromDate} 〜 {$showUntilDate}\n";
echo '本文: ' . str_len($noticeBody) . ' 文字（' . NOTICE_BODY_MAX_LENGTH . " 文字まで）\n";
echo "----\n{$noticeBody}\n----\n";

$existingNoticeId = db_val('SELECT id FROM update_notices WHERE title = ? AND body = ?', [$noticeTitle, $noticeBody]);
if ($existingNoticeId !== null) {
    echo "同じお知らせがあります（id={$existingNoticeId}）。書き込みませんでした\n";
    exit(0);
}
if (isset($optionByName['dry-run'])) {
    echo "確かめだけです（--dry-run）。書き込みませんでした\n";
    exit(0);
}

$createdNoticeId = tx(function () use ($noticeTitle, $noticeBody, $showFromDate, $showUntilDate): int {
    db_exec(
        'INSERT INTO update_notices (title, body, show_from, show_until, source_request_id, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, NULL, NULL, ?, ?)',
        [$noticeTitle, $noticeBody, $showFromDate, $showUntilDate, now(), now()]
    );
    return (int) db()->lastInsertId();
});
audit_log('admin', 'notice:' . $createdNoticeId . ':create_cli', null);
echo "お知らせを出しました: id={$createdNoticeId}\n";
