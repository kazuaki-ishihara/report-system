<?php

declare(strict_types=1);

// ====================================================================
// 予約した日が来た組織の改訂を反映する（コマンドラインで実行。便利機能 G）
//   php app/bin/run_schedules.php
// 画面の表示のたびにも動くが、定期の処理（cron。日付が変わったあと）に登録しておくと、だれも開かなくても反映される。
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
$appliedCount = org_schedules_run_due();
echo '反映した組織の改訂: ' . $appliedCount . " 件\n";
