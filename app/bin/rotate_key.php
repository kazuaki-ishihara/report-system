<?php

declare(strict_types=1);

// ====================================================================
// 暗号化の鍵の入れ替え（詳細設計書 共通編 §8.2.1。コマンドラインで実行）
//   php app/bin/rotate_key.php --dry-run   全件を今の鍵で復号できるかを確かめるだけ（何も変えない）
//   php app/bin/rotate_key.php             新しい鍵を作り、全件を暗号化し直す（メンテナンス中だけ）
//   --db=<DB>                              別の DB（写し）で試す。新しい鍵は同じ key_dir に作る
// 1 件でも復号できない・確かめが合わないときは、DB を変えずに止まる。古い鍵は消さない（前のバックアップを読むのに要る）。
// 入れ替えのあとは、全員がログインし直しになる（サイトの印に鍵を使うため）。
// ====================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';

/**
 * 暗号化している列。[表, 列, 条件, 付加データを作る関数]
 * 暗号化する列を増やしたら、ここにも足す（名前が _enc で終わる列の足し忘れは、実行のときに止める）。
 */
function rotate_key_targets(): array
{
    $byId = function (string $prefix): callable {
        return function (array $row) use ($prefix): string {
            return $prefix . (int) $row['id'];
        };
    };
    $bySheet = function (string $prefix): callable {
        return function (array $row) use ($prefix): string {
            return $prefix . (int) $row['sheet_id'];
        };
    };
    $history = function (string $side): callable {
        return function (array $row) use ($side): string {
            return 'rvh:' . (int) $row['report_id'] . ':' . (int) $row['field_id'] . ':' . (int) $row['report_project_id'] . ':' . (int) $row['revision'] . ':' . $side;
        };
    };
    return [
        ['users', 'totp_secret_enc', '1 = 1', $byId('totp:')],
        ['user_backup_codes', 'code_enc', '1 = 1', function (array $row): string {
            return 'bkcode:' . (int) $row['user_id'] . ':' . (int) $row['id'];
        }],
        ['lead_recovery_requests', 'first_half_enc', '1 = 1', $byId('leadrec:')],
        ['report_field_values', 'value_text', 'is_encrypted = 1', function (array $row): string {
            return form_aad((int) $row['report_id'], (int) $row['field_id'], (int) $row['report_project_id']);
        }],
        ['report_value_history', 'old_value', 'is_encrypted = 1', $history('old')],
        ['report_value_history', 'new_value', 'is_encrypted = 1', $history('new')],
        ['report_field_comments', 'comment_enc', '1 = 1', function (array $row): string {
            return 'cmt:' . (int) $row['report_id'] . ':' . (int) $row['id'];
        }],
        ['adhoc_response_values', 'value_text', 'is_encrypted = 1', function (array $row): string {
            return survey_aad((int) $row['response_id'], (int) $row['request_field_id']);
        }],
        ['support_requests', 'title_enc', '1 = 1', $byId('sreq_title:')],
        ['support_requests', 'body_enc', '1 = 1', $byId('sreq_body:')],
        ['support_request_messages', 'body_enc', '1 = 1', function (array $row): string {
            return support_request_message_aad((int) $row['request_id'], (int) $row['id']);
        }],
        ['faq_chat_logs', 'question_enc', '1 = 1', $byId('faq_chat:')],
        // 次の 4 つは、表があるブランチだけ（通勤定期代の精算・目標管理）
        ['expense_sheets', 'data_enc', '1 = 1', function (array $row): string {
            return 'expense_sheets:' . (int) $row['user_id'];
        }],
        ['goal_sheets', 'data_enc', '1 = 1', $byId('goal_sheets:')],
        ['goal_sheet_comments', 'body_enc', '1 = 1', $bySheet('goal_sheet_comments:')],
        ['goal_sheet_meetings', 'note_enc', '1 = 1', $bySheet('goal_sheet_meetings:')],
    ];
}

function rotate_key_stop(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$options = getopt('', ['db:', 'dry-run']);
if (isset($options['db'])) {
    $GLOBALS['APP_CONFIG']['db_path'] = (string) $options['db'];
}
if (!is_file((string) cfg('db_path'))) {
    rotate_key_stop('DB がありません: ' . cfg('db_path'));
}

// ---- 確かめる（何も変えない） ----
$columnSetByTable = [];
foreach (db_all("SELECT name FROM sqlite_master WHERE type = 'table'") as $tableRow) {
    $tableName = (string) $tableRow['name'];
    $columnSetByTable[$tableName] = array_fill_keys(array_column(db_all('PRAGMA table_info("' . $tableName . '")'), 'name'), true);
}
if (!isset($columnSetByTable['app_state'], $columnSetByTable['users'])) {
    rotate_key_stop('表がありません（先に migrate.php を実行してください）');
}
$targetList = [];
$listedColumnSet = [];
foreach (rotate_key_targets() as $target) {
    $listedColumnSet[$target[0] . '.' . $target[1]] = true;
    if (isset($columnSetByTable[$target[0]][$target[1]])) {
        $targetList[] = $target;
    }
}
foreach ($columnSetByTable as $tableName => $columnSet) {
    foreach (array_keys($columnSet) as $columnName) {
        if (substr($columnName, -4) === '_enc' && !isset($listedColumnSet[$tableName . '.' . $columnName])) {
            rotate_key_stop("入れ替えの対象に入っていない暗号化の列があります: {$tableName}.{$columnName}（何も変えていません）");
        }
    }
}

$oldVersion = crypto_version();
$newVersion = 'v' . ((int) substr($oldVersion, 1) + 1);
$isDryRun = isset($options['dry-run']);
if (!$isDryRun && maintenance_state() === null) {
    rotate_key_stop('メンテナンス中ではありません。管理のトップでメンテナンスを始めてから実行してください（何も変えていません）');
}

/** 対象の列を順に見て、$each(表, 列, 行, 付加データ, 平文) を呼ぶ。[復号できた件数, 復号できなかった件数の一覧] を返す */
$walk = function (callable $each) use ($targetList): array {
    $readableCount = 0;
    $unreadableCountByColumn = [];
    foreach ($targetList as [$tableName, $columnName, $condition, $buildAad]) {
        foreach (db_all("SELECT rowid AS rotate_rowid, * FROM {$tableName} WHERE {$condition} AND {$columnName} IS NOT NULL AND {$columnName} <> ''") as $row) {
            $aad = $buildAad($row);
            $plainText = dec((string) $row[$columnName], $aad);
            if ($plainText === null) {
                $label = $tableName . '.' . $columnName;
                $unreadableCountByColumn[$label] = ($unreadableCountByColumn[$label] ?? 0) + 1;
                continue;
            }
            $each($tableName, $columnName, $row, $aad, $plainText);
            $readableCount++;
        }
    }
    return [$readableCount, $unreadableCountByColumn];
};
$onlyRead = function (): void {
};

[$checkedCount, $unreadableCountByColumn] = $walk($onlyRead);
echo "今の鍵の版: {$oldVersion} / 暗号化されている値: {$checkedCount} 件\n";
foreach ($unreadableCountByColumn as $label => $unreadableCount) {
    echo "復号できない値: {$label} {$unreadableCount} 件\n";
}
if ($unreadableCountByColumn) {
    rotate_key_stop('復号できない値があるため、何も変えていません');
}
if ($isDryRun) {
    echo "確かめだけです（何も変えていません）\n";
    exit(0);
}

// ---- 控え ----
$backupDirectory = backup_directory();
if (!is_dir($backupDirectory)) {
    mkdir($backupDirectory, 0700, true);
}
$backupPath = $backupDirectory . '/before_key_rotation_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2)) . '.sqlite';
db()->exec('VACUUM INTO ' . db()->quote($backupPath));
@chmod($backupPath, 0600);
echo "控え: {$backupPath}\n";

// ---- 新しい鍵を作り、1 つのトランザクションで全件を暗号化し直す ----
$newKeyPath = cfg('key_dir') . '/field_' . $newVersion . '.key';
if (is_file($newKeyPath)) {
    // 前に途中で止まったときの鍵。中身が正しければ使う（次の crypto_key() が確かめる）
    echo "新しい版の鍵がもうあるので、それを使います: {$newKeyPath}\n";
}
crypto_generate_key($newVersion);
crypto_key($newVersion);
db()->exec('PRAGMA secure_delete = ON');   // 古い暗号文を DB のファイルに残さない

$rotatedCount = tx(function () use ($walk, $targetList, $oldVersion, $newVersion): int {
    crypto_version_forget();
    if (crypto_version() !== $oldVersion) {
        throw new RuntimeException('ほかの入れ替えが先に終わっています');
    }
    [$count, $unreadable] = $walk(function (string $tableName, string $columnName, array $row, string $aad, string $plainText) use ($newVersion): void {
        $newBlob = enc($plainText, $aad, $newVersion);
        if (dec($newBlob, $aad) !== $plainText) {
            throw new RuntimeException('暗号化し直した値が元に戻りません: ' . $tableName . '.' . $columnName);
        }
        db_exec("UPDATE {$tableName} SET {$columnName} = ? WHERE rowid = ?", [$newBlob, (int) $row['rotate_rowid']]);
    });
    if ($unreadable) {
        throw new RuntimeException('復号できない値が見つかりました');
    }
    foreach ($targetList as [$tableName, $columnName, $condition]) {
        $leftCount = (int) db_val("SELECT COUNT(*) FROM {$tableName} WHERE {$condition} AND {$columnName} IS NOT NULL AND {$columnName} <> '' AND {$columnName} NOT LIKE ?", [$newVersion . ':%']);
        if ($leftCount > 0) {
            throw new RuntimeException("新しい鍵になっていない値があります: {$tableName}.{$columnName} {$leftCount} 件");
        }
    }
    db_exec("INSERT OR REPLACE INTO app_state (name, value) VALUES ('crypto_version', ?)", [$newVersion]);
    audit_log('admin', 'key:rotate;' . $oldVersion . '->' . $newVersion . ';count=' . $count);
    return $count;
});
crypto_version_forget();

// ---- 入れ替えのあとの確かめ（書き込んだものを読み直す） ----
[$verifiedCount, $unreadableCountByColumn] = $walk($onlyRead);
if ($unreadableCountByColumn || $verifiedCount < $rotatedCount || crypto_version() !== $newVersion) {
    rotate_key_stop("入れ替えのあとの確かめが合いません（読めた値 {$verifiedCount} 件、入れ替えた値 {$rotatedCount} 件）。控えと古い鍵を残したまま、開発担当に連絡してください");
}
db()->exec('VACUUM');
db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
echo "入れ替えました: {$oldVersion} → {$newVersion}、{$rotatedCount} 件（すべて新しい鍵で読めることを確かめました）\n";
echo "新しい鍵: {$newKeyPath}\n";
echo "古い鍵（field_{$oldVersion}.key）は残しています。前のバックアップを読むのに要るので、www の外の安全な場所に移してください\n";
