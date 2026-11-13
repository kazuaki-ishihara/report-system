<?php

declare(strict_types=1);

// ====================================================================
// DB のバックアップを作る（コマンドラインで実行。便利機能 H）
//   php app/bin/backup.php
// 設定 backup_dir に reports_<日時>.sqlite を作り、古いものは backup_keep 個まで減らす。
// さくらのレンタルサーバーなら、コントロールパネルの定期実行（cron）に登録して毎日動かす。
// ====================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';

$opt = getopt('', ['db:']);
if (isset($opt['db'])) {
    $GLOBALS['APP_CONFIG']['db_path'] = (string) $opt['db'];
}
try {
    $backupPath = backup_create();
} catch (Throwable $backupError) {
    fwrite(STDERR, 'バックアップを作れませんでした: ' . $backupError->getMessage() . "\n");
    exit(1);
}
echo 'バックアップを作りました: ' . $backupPath . "\n";
