<?php

declare(strict_types=1);

// ====================================================================
// よくある質問のチャットの記録（G-25 §2.7）を、読める形の CSV に書き出す（ローカル検証環境専用）
//   php tools/test/export_faq_chat_log.php
// 出力: リポジトリ（一式）の直下の faq_chat_log_<日時>.csv（UTF-8 BOM 付き・CRLF・値はすべて " で囲む）と、
//       app_log_errors_<日時>.txt（app/logs/app.log のうち、エラー・警告の行だけ。「通信エラー」などの原因を調べるため）
// ローカル検証環境の一式の「3_記録を書き出す.bat」から使う。質問の文は復号して書き出す（鍵は渡さなくてよい）。
// 報告の中身・コメント・パスワード・鍵は書き出さない。
// ====================================================================

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../../app/bootstrap.php';

$localConfigPath = APP_DIR . '/config/config.local.php';
$localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
if (empty($localConfig['allow_dev_seed']) || cfg('https')) {
    fwrite(STDERR, "ローカル検証環境でだけ使えます\n");
    exit(1);
}

faq_chat_log_ensure_table();
$faqQuestionById = array_column(db_all('SELECT id, question FROM faq_entries'), 'question', 'id');
$questionTextOf = static function (?int $faqId) use ($faqQuestionById): string {
    return $faqId === null ? '' : (string) ($faqQuestionById[$faqId] ?? '（消した質問 #' . $faqId . '）');
};
$logRowList = db_all(
    'SELECT l.*, u.login_id, u.display_name FROM faq_chat_logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id'
);

$headerList = ['番号', '日時', 'ログイン ID', '表示名', '役割', '開いていた画面', '質問・選んだ分野', '結果', '心の不調の段階', '返事をしなかった',
    '出した答え（いちばん近い質問）', '出した質問の数', '反応', '反応した答え', '送った要望・依頼の番号', '心の不調に対応した日時', '会話'];
$csvLineList = [];
$csvLine = static function (array $valueList): string {
    return implode(',', array_map(static function ($value): string {
        return '"' . str_replace('"', '""', str_replace(["\r\n", "\r", "\n"], ' ', (string) $value)) . '"';
    }, $valueList));
};
$csvLineList[] = $csvLine($headerList);
foreach ($logRowList as $logRow) {
    $shownFaqIdList = array_values(array_filter(array_map('intval', explode(',', (string) $logRow['shown_faq_ids']))));
    $csvLineList[] = $csvLine([
        $logRow['id'],
        $logRow['asked_at'],
        $logRow['login_id'] ?? '',
        $logRow['display_name'] ?? '',
        FAQ_CHAT_LOG_ROLE_LABELS[$logRow['role']] ?? $logRow['role'],
        $logRow['screen_code'],
        $logRow['result'] === 'category' ? '分野: ' . (FAQ_CATEGORY_LABELS[(string) $logRow['category']] ?? '') : faq_chat_log_display_text($logRow),   // 要配慮・緊急の文は、だれにも見せない決まりなので書き出さない
        FAQ_CHAT_LOG_RESULT_LABELS[$logRow['result']] ?? $logRow['result'],
        FAQ_CHAT_LOG_DISTRESS_LABELS[(string) ($logRow['distress_level'] ?? '')] ?? '',
        (int) $logRow['is_ignored'] === 1 ? 'はい' : '',
        $shownFaqIdList ? $questionTextOf($shownFaqIdList[0]) : '',
        count($shownFaqIdList),
        FAQ_CHAT_LOG_FEEDBACK_LABELS[$logRow['feedback']] ?? '',
        $logRow['feedback_faq_id'] === null ? '' : $questionTextOf((int) $logRow['feedback_faq_id']),
        $logRow['request_id'] ?? '',
        $logRow['handled_at'] ?? '',
        $logRow['conversation_id'] ?? '',   // 会話の番号（G-25 §2.14。同じ人が 10 分以内に続けて入れた発言で同じ）
    ]);
}

$outputStamp = date('Ymd_His');
$outputPath = dirname(APP_DIR) . DIRECTORY_SEPARATOR . 'faq_chat_log_' . $outputStamp . '.csv';
file_put_contents($outputPath, "\xEF\xBB\xBF" . implode("\r\n", $csvLineList) . "\r\n");
echo 'チャットの記録を書き出しました（' . count($logRowList) . " 件）: {$outputPath}\n";

// app.log のエラー・警告の行（ERROR・WARN）。毎日の片付けなどの行は入れない
$appLogPath = cfg('log_dir') . '/app.log';
$errorLineList = [];
if (is_file($appLogPath)) {
    foreach (file($appLogPath, FILE_IGNORE_NEW_LINES) ?: [] as $appLogLine) {
        if (preg_match('/^\S+ \S+ (ERROR|WARN) /', $appLogLine) === 1) {
            $errorLineList[] = $appLogLine;
        }
    }
}
$errorOutputPath = dirname(APP_DIR) . DIRECTORY_SEPARATOR . 'app_log_errors_' . $outputStamp . '.txt';
file_put_contents($errorOutputPath, "\xEF\xBB\xBF" . ($errorLineList ? implode("\r\n", $errorLineList) . "\r\n" : "（エラー・警告はありません）\r\n"));
echo 'エラー・警告の記録を書き出しました（' . count($errorLineList) . " 行）: {$errorOutputPath}\n";
