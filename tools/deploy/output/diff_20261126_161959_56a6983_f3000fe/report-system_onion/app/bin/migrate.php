<?php

declare(strict_types=1);

// ====================================================================
// テーブルの作成・更新と、暗号化の鍵の作成（コマンドラインで実行）
//   php app/bin/migrate.php
// 何度実行しても同じ結果になる。
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

$keyFile = crypto_generate_key();
echo "暗号化の鍵: {$keyFile}\n";

// 表の作り直し（外部キーを止めるので、トランザクションの外で先に行う）
$rebuildLogLine = schema_make_survey_department_nullable(db());
$log = tx(function (PDO $pdo) {
    return schema_ensure($pdo);
});
if ($rebuildLogLine !== null) {
    array_unshift($log, $rebuildLogLine);
}
foreach ($log as $line) {
    echo $line . "\n";
}
echo "テーブルを確認しました: " . cfg('db_path') . "\n";
