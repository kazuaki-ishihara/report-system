<?php

declare(strict_types=1);

// ====================================================================
// migrate.php の前後で、もとからある表・列の中身が変わっていないかを突き合わせる（コマンドラインで実行）
//   php migration_check.php snapshot <DB> <控えの JSON>   … 移行の前に、表ごとの件数と中身のハッシュを控える
//   php migration_check.php compare  <DB> <控えの JSON>   … 移行の後に比べる（終了コード 0 = 変わっていない、1 = 変わった、2 = 使い方の誤り）
// DB は読むだけ。名前・報告の文などの中身は出さない（件数と OK/NG だけ）。
// サーバーでは www の外に置いて使う。app/ は要らない。PHP 7.4 で動く書き方。
// ====================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** 表ごとの [列, 件数, 中身のハッシュ]。$columnsByTable を渡すと、その表・列だけを見る */
function migration_check_read(PDO $pdo, ?array $columnsByTable): array
{
    $tableNameList = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $result = [];
    foreach ($tableNameList as $tableName) {
        if ($columnsByTable !== null && !isset($columnsByTable[$tableName])) {
            continue;
        }
        $presentColumnList = array_column($pdo->query('PRAGMA table_info("' . $tableName . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $columnList = $columnsByTable === null ? $presentColumnList : $columnsByTable[$tableName];
        if (array_diff($columnList, $presentColumnList)) {
            $result[$tableName] = ['columns' => $columnList, 'count' => -1, 'hash' => 'column missing'];
            continue;
        }
        $rowHashList = [];
        $statement = $pdo->query('SELECT ' . implode(', ', array_map(function (string $columnName): string {
            return 'quote("' . $columnName . '")';
        }, $columnList)) . ' FROM "' . $tableName . '"');
        while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            $rowHashList[] = hash('sha256', implode("\x1f", array_map('strval', $row)));
        }
        sort($rowHashList);
        $result[$tableName] = ['columns' => $columnList, 'count' => count($rowHashList), 'hash' => hash('sha256', implode("\n", $rowHashList))];
    }
    return $result;
}

$action = $argv[1] ?? '';
$dbPath = $argv[2] ?? '';
$snapshotPath = $argv[3] ?? '';
if (!in_array($action, ['snapshot', 'compare'], true) || $dbPath === '' || $snapshotPath === '' || !is_file($dbPath) || ($action === 'compare' && !is_file($snapshotPath))) {
    fwrite(STDERR, "使い方: php migration_check.php snapshot|compare <DB> <控えの JSON>\n");
    exit(2);
}
if ($action === 'snapshot' && file_exists($snapshotPath)) {
    fwrite(STDERR, "控えがもうあります（上書きしません）: {$snapshotPath}\n");
    exit(2);
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA query_only = ON');
$pdo->exec('PRAGMA busy_timeout = 5000');
echo 'PHP ' . PHP_VERSION . ' / SQLite ' . $pdo->query('SELECT sqlite_version()')->fetchColumn() . "\n";
$integrityResult = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
$foreignKeyProblemCount = count($pdo->query('PRAGMA foreign_key_check')->fetchAll());
echo '整合の確かめ: ' . ($integrityResult === 'ok' ? 'OK' : 'NG') . ' / 外部キーの誤り: ' . $foreignKeyProblemCount . " 件\n";

if ($action === 'snapshot') {
    $snapshot = ['integrity' => $integrityResult, 'foreign_key_problems' => $foreignKeyProblemCount, 'tables' => migration_check_read($pdo, null)];
    if (file_put_contents($snapshotPath, json_encode($snapshot)) === false) {
        fwrite(STDERR, "控えを書けません: {$snapshotPath}\n");
        exit(2);
    }
    @chmod($snapshotPath, 0600);
    echo '控えました: ' . count($snapshot['tables']) . " 表\n";
    exit(0);
}

$snapshot = json_decode((string) file_get_contents($snapshotPath), true);
if (!is_array($snapshot) || !isset($snapshot['tables'])) {
    fwrite(STDERR, "控えが読めません: {$snapshotPath}\n");
    exit(2);
}
$current = migration_check_read($pdo, array_map(function (array $table): array {
    return $table['columns'];
}, $snapshot['tables']));
$changedCount = 0;
foreach ($snapshot['tables'] as $tableName => $table) {
    $after = $current[$tableName] ?? ['count' => -1, 'hash' => ''];
    if ($after['hash'] !== $table['hash']) {
        $changedCount++;
        echo "NG  {$tableName}: 移行前 {$table['count']} 件 → 移行後 {$after['count']} 件"
            . ($after['count'] === -1 ? '（表か列がない）' : ($after['count'] === $table['count'] ? '（件数は同じで、中身が変わった）' : '')) . "\n";
    }
}
$isOk = $changedCount === 0 && $integrityResult === 'ok' && $foreignKeyProblemCount === 0;
echo count($snapshot['tables']) . " 表のうち、変わった表 {$changedCount}\n判定: " . ($isOk ? 'OK' : 'NG') . "\n";
exit($isOk ? 0 : 1);
