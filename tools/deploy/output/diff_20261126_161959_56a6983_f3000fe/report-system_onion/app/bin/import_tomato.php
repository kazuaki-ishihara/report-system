<?php

declare(strict_types=1);

// ====================================================================
// tomato（dev_hasimoto のシステム）の DB を、この DB に取り込む（コマンドラインで実行。一度だけ）
//   php app/bin/migrate.php [--db=<DB>]                                  先にテーブルを作る
//   php app/bin/import_tomato.php --from=<tomato の reports.sqlite> [--db=<DB>] [--dry-run]
//   php app/bin/import_tomato.php --fill-departments [--db=<DB>]        課を決めたあと、取り込んだ報告に課を入れる
//
// tomato の DB は読むだけで、変えない（tomato の表は、この DB の表と同じ名前で中身が違うため、同じファイルでは扱えない）。
// 1. この DB の控えを backups/ に取る
// 2. 全件を確かめ、問題があれば何もせずに終わる（ログイン ID の重複、日付の形、項目の型、コメントの項目など）
// 3. 1 つのトランザクションで、利用者・報告・追加した項目と選択肢・値・コメントを足す
//    - 利用者: ログイン ID はメールアドレスの @ より前（使えない文字は _）。パスワードはそのまま使え、初回ログインで
//      パスワードの変更と 2 段階認証の設定を求める。所属の課はなし（tomato に課はない。組織・ユーザーの画面か CSV で決める）
//    - 報告: 確認完了・移行した報告（is_legacy）として足す。対象月は日付の年月。項目は tomato の 6 項目と追加した項目
//      （無効の項目として足すので、新しい報告の様式には出ない）。自由記述とコメントは暗号化する
// 4. 件数を突き合わせる。取り込んだ印を app_state に残し、2 回目は何もしない
// ====================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';

$optionByName = getopt('', ['from:', 'db:', 'dry-run', 'fill-departments']);
if (isset($optionByName['db'])) {
    $GLOBALS['APP_CONFIG']['db_path'] = (string) $optionByName['db'];
}
const TOMATO_IMPORT_STATE_NAME = 'tomato_import_done';
// tomato の項目の型 => この DB の型
const TOMATO_FIELD_TYPE_MAP = ['text' => 'single_text', 'textarea' => 'multi_text', 'number' => 'number', 'date' => 'date', 'select' => 'select', 'radio' => 'radio', 'checkbox' => 'multi_check'];
// tomato の既存の 6 項目（reports の列）=> [表示名, この DB の型]。古い Web 版の移行（migrate_legacy.php）と同じコード
const TOMATO_FIXED_FIELD_LIST = [
    'report_date' => ['日付', 'date'], 'name' => ['氏名', 'single_text'], 'subject' => ['件名', 'single_text'],
    'content' => ['内容', 'multi_text'], 'issues' => ['課題', 'multi_text'], 'remarks' => ['備考', 'multi_text'],
];

$destinationTableNameSet = [];
foreach (db()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll() as $tableRow) {
    $destinationTableNameSet[$tableRow['name']] = true;
}
if (!isset($destinationTableNameSet['report_records'])) {
    fwrite(STDERR, "先に php app/bin/migrate.php でテーブルを作ってください\n");
    exit(1);
}

// ---- 課を決めたあとで、取り込んだ報告に課を入れる ----
if (isset($optionByName['fill-departments'])) {
    $filledCount = db_exec(
        'UPDATE report_records SET department_id = (SELECT u.department_id FROM users u WHERE u.id = report_records.user_id), updated_at = ?
          WHERE is_legacy = 1 AND department_id IS NULL AND (SELECT u.department_id FROM users u WHERE u.id = report_records.user_id) IS NOT NULL',
        [now()]
    );
    echo "取り込んだ報告に課を入れました: {$filledCount} 件（所属の課がまだない人の報告はそのまま）\n";
    exit(0);
}

if (!isset($optionByName['from']) || !is_file((string) $optionByName['from'])) {
    fwrite(STDERR, "使い方: php app/bin/import_tomato.php --from=<tomato の reports.sqlite> [--db=<DB>] [--dry-run]\n");
    exit(1);
}
if (db_val('SELECT value FROM app_state WHERE name = ?', [TOMATO_IMPORT_STATE_NAME]) !== null) {
    echo "tomato のデータは取り込み済みです（" . db_val('SELECT value FROM app_state WHERE name = ?', [TOMATO_IMPORT_STATE_NAME]) . "）。何もしません\n";
    exit(0);
}
$isDryRun = isset($optionByName['dry-run']);

// tomato の DB は読むだけ（PHP 8.1 以上は読み取り専用で開く）
$sourcePdoOptionList = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
if (defined('PDO::SQLITE_ATTR_OPEN_FLAGS')) {
    $sourcePdoOptionList[constant('PDO::SQLITE_ATTR_OPEN_FLAGS')] = constant('PDO::SQLITE_OPEN_READONLY');
}
$sourcePdo = new PDO('sqlite:' . (string) $optionByName['from'], null, null, $sourcePdoOptionList);
$sourceAll = function (string $sql, array $parameterList = []) use ($sourcePdo): array {
    $statement = $sourcePdo->prepare($sql);
    $statement->execute($parameterList);
    return $statement->fetchAll();
};
$sourceTableNameSet = [];
foreach ($sourceAll("SELECT name FROM sqlite_master WHERE type = 'table'") as $tableRow) {
    $sourceTableNameSet[$tableRow['name']] = true;
}
foreach (['users', 'reports', 'comments'] as $requiredTableName) {
    if (!isset($sourceTableNameSet[$requiredTableName])) {
        fwrite(STDERR, "tomato の DB ではないようです（{$requiredTableName} の表がありません）\n");
        exit(1);
    }
}
$hasCustomFields = isset($sourceTableNameSet['report_fields'], $sourceTableNameSet['report_field_options'], $sourceTableNameSet['report_field_values']);

$sourceUserList = $sourceAll('SELECT * FROM users ORDER BY id');
$sourceReportList = $sourceAll('SELECT * FROM reports ORDER BY id');
$sourceCommentList = $sourceAll('SELECT * FROM comments ORDER BY id');
$sourceCustomFieldList = $hasCustomFields ? $sourceAll('SELECT * FROM report_fields WHERE is_system = 0 ORDER BY sort_order, id') : [];
$sourceOptionListByFieldId = [];
$sourceValueList = [];
if ($hasCustomFields) {
    foreach ($sourceAll('SELECT * FROM report_field_options ORDER BY field_id, sort_order, id') as $optionRow) {
        $sourceOptionListByFieldId[(int) $optionRow['field_id']][] = $optionRow;
    }
    $sourceValueList = $sourceAll('SELECT * FROM report_field_values ORDER BY report_id, field_id, id');
}

// ---- 2. 確かめる ----
$problemList = [];
$takenLoginIdSet = [];
foreach (db_all('SELECT login_id FROM users WHERE login_id IS NOT NULL') as $existingUserRow) {
    $takenLoginIdSet[(string) $existingUserRow['login_id']] = true;
}
$loginIdBySourceUserId = [];
foreach ($sourceUserList as $sourceUser) {
    $baseLoginId = strtolower((string) preg_replace('/@.*$/', '', (string) $sourceUser['username']));
    $baseLoginId = ltrim((string) preg_replace('/[^a-z0-9._-]/', '_', $baseLoginId), '._-');
    if (strlen($baseLoginId) < 3) {
        $baseLoginId = 'user' . (int) $sourceUser['id'];
    }
    $baseLoginId = substr($baseLoginId, 0, 28);
    if (isset($takenLoginIdSet[$baseLoginId])) {
        // この DB にもう同じ ID の人がいる。同じ人を 2 人にしないよう、自動で番号を付けずに止める
        $problemList[] = '利用者 id=' . $sourceUser['id'] . ' のログイン ID「' . $baseLoginId . '」は、この DB にもういます（どちらかの ID を変えてから取り込む）';
        continue;
    }
    if (!auth_is_valid_login_id($baseLoginId)) {
        $problemList[] = '利用者 id=' . $sourceUser['id'] . ' のログイン ID を作れません';
        continue;
    }
    $takenLoginIdSet[$baseLoginId] = true;
    $loginIdBySourceUserId[(int) $sourceUser['id']] = $baseLoginId;
}
$sourceUserIdSet = array_fill_keys(array_map('intval', array_column($sourceUserList, 'id')), true);
foreach ($sourceReportList as $sourceReport) {
    if (DateTime::createFromFormat('!Y-m-d', (string) $sourceReport['report_date']) === false) {
        $problemList[] = '報告 id=' . $sourceReport['id'] . ' の日付の形が正しくありません: ' . $sourceReport['report_date'];
    }
    if (!isset($sourceUserIdSet[(int) $sourceReport['user_id']])) {
        $problemList[] = '報告 id=' . $sourceReport['id'] . ' の作成者がいません';
    }
}
$customFieldBySourceId = [];
foreach ($sourceCustomFieldList as $sourceField) {
    if (isset(TOMATO_FIXED_FIELD_LIST[(string) $sourceField['code']])) {
        continue;   // 内容・課題・備考は tomato でも is_system = 0 だが、値は reports の列にある既存の項目
    }
    if (!isset(TOMATO_FIELD_TYPE_MAP[(string) $sourceField['field_type']])) {
        $problemList[] = '追加した項目 id=' . $sourceField['id'] . ' の型が想定外です: ' . $sourceField['field_type'];
        continue;
    }
    $customFieldBySourceId[(int) $sourceField['id']] = $sourceField;
}
$commentableFieldNameSet = array_fill_keys(array_keys(TOMATO_FIXED_FIELD_LIST), true);
foreach ($customFieldBySourceId as $sourceField) {
    $commentableFieldNameSet[(string) $sourceField['code']] = true;
}
foreach ($sourceCommentList as $sourceComment) {
    if (!isset($commentableFieldNameSet[(string) $sourceComment['field_name']])) {
        $problemList[] = 'コメント id=' . $sourceComment['id'] . ' の項目が見つかりません: ' . $sourceComment['field_name'];
    }
}
if ($problemList) {
    foreach ($problemList as $problemText) {
        echo "問題: {$problemText}\n";
    }
    echo "問題があるため、何も取り込んでいません\n";
    exit(1);
}
echo 'tomato: 利用者 ' . count($sourceUserList) . ' 人、報告 ' . count($sourceReportList) . ' 件、追加した項目 ' . count($customFieldBySourceId)
    . ' 個（値 ' . count($sourceValueList) . ' 件）、コメント ' . count($sourceCommentList) . " 件\n";
foreach ($loginIdBySourceUserId as $sourceUserId => $loginId) {
    echo "  利用者 id={$sourceUserId} → ログイン ID {$loginId}\n";
}
if ($isDryRun) {
    echo "--dry-run のため、何も変更していません\n";
    exit(0);
}

// ---- 1. 控え ----
$backupDirectory = APP_DIR . '/backups';
if (!is_dir($backupDirectory)) {
    mkdir($backupDirectory, 0700, true);
}
db()->exec('PRAGMA wal_checkpoint(FULL)');
$backupPath = $backupDirectory . '/before_tomato_import_' . date('Ymd_His') . '.sqlite';
copy(cfg('db_path'), $backupPath);
@chmod($backupPath, 0600);
echo "控え: {$backupPath}\n";

// ---- 3. 取り込む ----
$importResult = tx(function () use ($sourceUserList, $sourceReportList, $sourceCommentList, $customFieldBySourceId, $sourceOptionListByFieldId, $sourceValueList, $loginIdBySourceUserId): array {
    $nowText = now();

    // 利用者
    $latestNameBySourceUserId = [];
    foreach ($sourceReportList as $sourceReport) {
        $latestNameBySourceUserId[(int) $sourceReport['user_id']] = (string) $sourceReport['name'];   // 日付の順ではないが、id の順で最後のもの
    }
    $userIdBySourceUserId = [];
    foreach ($sourceUserList as $sourceUser) {
        $sourceUserId = (int) $sourceUser['id'];
        $loginId = $loginIdBySourceUserId[$sourceUserId];
        $displayName = $latestNameBySourceUserId[$sourceUserId] ?? '';
        if ($displayName === '' || str_len($displayName) > 20) {
            $displayName = $loginId;
        }
        $roleTitle = in_array((string) $sourceUser['role_title'], ['一般', '主任', '課長代理', '課長', '部長'], true) ? (string) $sourceUser['role_title'] : '一般';
        db_exec(
            'INSERT INTO users (username, password_hash, role_title, is_admin, created_at, login_id, display_name, department_id, must_change_password, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 1, ?)',
            [$loginId, (string) $sourceUser['password_hash'], $roleTitle, (int) $sourceUser['is_admin'] === 1 ? 1 : 0, (string) $sourceUser['created_at'], $loginId, $displayName, $nowText]
        );
        $userIdBySourceUserId[$sourceUserId] = (int) db()->lastInsertId();
    }

    // 項目（無効の項目として足す。移行した報告の表示だけに使い、新しい報告の様式には出ない）
    $fieldIdByTomatoCode = [];
    $snapshotFieldList = [];
    $sortOrder = 900;
    foreach (TOMATO_FIXED_FIELD_LIST as $fieldCode => [$fieldLabel, $fieldType]) {
        $sortOrder += 10;
        $fieldId = db_val('SELECT id FROM report_fields WHERE code = ?', [$fieldCode]);
        if ($fieldId === null) {
            db_exec(
                "INSERT INTO report_fields (code, label, field_type, department_id, field_scope, confidentiality, sort_order, is_active, is_required, settings_json, created_at, updated_at)
                 VALUES (?, ?, ?, NULL, 'report', 'internal', ?, 0, 0, ?, ?, ?)",
                [$fieldCode, $fieldLabel, $fieldType, $sortOrder, json_encode($fieldType === 'single_text' ? ['max_length' => 400] : ($fieldType === 'multi_text' ? ['max_length' => 20000] : [])), $nowText, $nowText]
            );
            $fieldId = db()->lastInsertId();
        }
        $fieldIdByTomatoCode[$fieldCode] = (int) $fieldId;
        $snapshotFieldList[$fieldCode] = ['id' => (int) $fieldId, 'label' => $fieldLabel, 'type' => $fieldType, 'scope' => 'report', 'conf' => 'internal',
            'sort' => $sortOrder, 'required' => false, 'settings' => [], 'options' => []];
    }
    $optionCodeBySourceOptionId = [];
    foreach ($customFieldBySourceId as $sourceFieldId => $sourceField) {
        $sortOrder += 10;
        $fieldType = TOMATO_FIELD_TYPE_MAP[(string) $sourceField['field_type']];
        $settingList = $fieldType === 'single_text' ? ['max_length' => 400] : ($fieldType === 'multi_text' ? ['max_length' => 20000] : []);
        db_exec(
            "INSERT INTO report_fields (code, label, field_type, department_id, field_scope, confidentiality, sort_order, is_active, is_required, settings_json, created_at, updated_at)
             VALUES (?, ?, ?, NULL, 'report', 'internal', ?, 0, 0, ?, ?, ?)",
            ['tomato_' . $sourceField['code'], (string) $sourceField['label'], $fieldType, $sortOrder, json_encode($settingList), $nowText, $nowText]
        );
        $fieldId = (int) db()->lastInsertId();
        $optionList = [];
        foreach ($sourceOptionListByFieldId[$sourceFieldId] ?? [] as $optionIndex => $sourceOption) {
            $optionCode = 'o' . (int) $sourceOption['id'];
            db_exec('INSERT INTO report_field_options (field_id, code, label, sort_order, is_active) VALUES (?, ?, ?, ?, ?)',
                [$fieldId, $optionCode, (string) $sourceOption['label'], ($optionIndex + 1) * 10, (int) $sourceOption['is_active'] === 1 ? 1 : 0]);
            $optionCodeBySourceOptionId[(int) $sourceOption['id']] = $optionCode;
            $optionList[] = ['code' => $optionCode, 'label' => (string) $sourceOption['label'], 'active' => (int) $sourceOption['is_active'] === 1];
        }
        $fieldIdByTomatoCode[(string) $sourceField['code']] = $fieldId;
        $snapshotFieldList[(string) $sourceField['code']] = ['id' => $fieldId, 'label' => (string) $sourceField['label'], 'type' => $fieldType, 'scope' => 'report',
            'conf' => 'internal', 'sort' => $sortOrder, 'required' => false, 'settings' => $settingList, 'options' => $optionList];
    }

    // 追加した項目の値: 報告 => tomato の項目コード => 値（選択肢はコード。チェックボックスはコードの配列）
    $customValueByReport = [];
    foreach ($sourceValueList as $sourceValue) {
        $sourceField = $customFieldBySourceId[(int) $sourceValue['field_id']] ?? null;
        if ($sourceField === null) {
            continue;
        }
        $fieldCode = (string) $sourceField['code'];
        $sourceReportId = (int) $sourceValue['report_id'];
        if ($sourceValue['option_id'] !== null) {
            $optionCode = $optionCodeBySourceOptionId[(int) $sourceValue['option_id']] ?? null;
            if ($optionCode === null) {
                continue;
            }
            if ((string) $sourceField['field_type'] === 'checkbox') {
                $customValueByReport[$sourceReportId][$fieldCode][] = $optionCode;
            } else {
                $customValueByReport[$sourceReportId][$fieldCode] = $optionCode;
            }
        } elseif ($sourceValue['value_number'] !== null) {
            $customValueByReport[$sourceReportId][$fieldCode] = rtrim(rtrim(sprintf('%.6F', (float) $sourceValue['value_number']), '0'), '.');
        } else {
            $customValueByReport[$sourceReportId][$fieldCode] = (string) $sourceValue['value_text'];
        }
    }

    // 報告
    $reportIdBySourceReportId = [];
    $valueCount = 0;
    foreach ($sourceReportList as $sourceReport) {
        $sourceReportId = (int) $sourceReport['id'];
        db_exec(
            "INSERT INTO report_records (user_id, department_id, period, status, is_legacy, legacy_report_id, submitted_at, confirmed_at, created_at, updated_at)
             VALUES (?, NULL, ?, 'confirmed', 1, NULL, ?, ?, ?, ?)",
            [$userIdBySourceUserId[(int) $sourceReport['user_id']], substr((string) $sourceReport['report_date'], 0, 7),
             $sourceReport['created_at'], $sourceReport['updated_at'], $sourceReport['created_at'], $sourceReport['updated_at']]
        );
        $reportId = (int) db()->lastInsertId();
        $reportIdBySourceReportId[$sourceReportId] = $reportId;
        form_write_snapshot($reportId, array_values($snapshotFieldList));
        $valueByTomatoCode = [];
        foreach (array_keys(TOMATO_FIXED_FIELD_LIST) as $fieldCode) {
            $valueByTomatoCode[$fieldCode] = (string) ($sourceReport[$fieldCode] ?? '');
        }
        foreach ($customValueByReport[$sourceReportId] ?? [] as $fieldCode => $customValue) {
            $valueByTomatoCode[$fieldCode] = is_array($customValue) ? (string) json_encode(array_values($customValue)) : $customValue;
        }
        foreach ($valueByTomatoCode as $fieldCode => $valueText) {
            if ($valueText === '') {
                continue;
            }
            $snapshotField = $snapshotFieldList[$fieldCode];
            $isEncrypted = form_is_encrypted($snapshotField);
            db_exec(
                'INSERT INTO report_field_values (report_id, field_id, report_project_id, value_text, is_encrypted, has_warning, updated_at) VALUES (?, ?, 0, ?, ?, ?, ?)',
                [$reportId, $snapshotField['id'], $isEncrypted ? enc($valueText, form_aad($reportId, $snapshotField['id'], 0)) : $valueText,
                 $isEncrypted ? 1 : 0, form_is_free_text($snapshotField) && text_has_warning($valueText) ? 1 : 0, $nowText]
            );
            $valueCount++;
        }
    }

    // コメント
    $commentCount = 0;
    foreach ($sourceCommentList as $sourceComment) {
        $reportId = $reportIdBySourceReportId[(int) $sourceComment['report_id']] ?? null;
        $userId = $userIdBySourceUserId[(int) $sourceComment['user_id']] ?? null;
        if ($reportId === null || $userId === null) {
            continue;   // 報告か書いた人が消えているコメント
        }
        $fieldId = $fieldIdByTomatoCode[(string) $sourceComment['field_name']];
        db_exec(
            "INSERT INTO report_field_comments (report_id, field_id, report_project_id, user_id, comment_type, comment_enc, created_at, updated_at) VALUES (?, ?, 0, ?, 'note', '', ?, ?)",
            [$reportId, $fieldId, $userId, $sourceComment['created_at'], $sourceComment['updated_at']]
        );
        $commentId = (int) db()->lastInsertId();
        db_exec('UPDATE report_field_comments SET comment_enc = ? WHERE id = ?', [enc((string) $sourceComment['comment'], 'cmt:' . $reportId . ':' . $commentId), $commentId]);
        $commentCount++;
    }

    db_exec('INSERT INTO app_state (name, value) VALUES (?, ?)', [TOMATO_IMPORT_STATE_NAME, $nowText]);
    audit_log('admin', 'import:tomato;users=' . count($userIdBySourceUserId) . ';reports=' . count($reportIdBySourceReportId) . ';comments=' . $commentCount, null);
    return ['users' => count($userIdBySourceUserId), 'reports' => count($reportIdBySourceReportId), 'values' => $valueCount, 'comments' => $commentCount];
});

// ---- 4. 突き合わせ ----
$skippedCommentCount = count($sourceCommentList) - $importResult['comments'];
echo '取り込みました: 利用者 ' . $importResult['users'] . ' 人、報告 ' . $importResult['reports'] . ' 件（値 ' . $importResult['values'] . ' 件）、コメント ' . $importResult['comments'] . " 件\n";
echo '突き合わせ: 利用者 ' . ($importResult['users'] === count($sourceUserList) ? 'OK' : '不一致') . '・報告 ' . ($importResult['reports'] === count($sourceReportList) ? 'OK' : '不一致')
    . '・コメント ' . ($skippedCommentCount === 0 ? 'OK' : $skippedCommentCount . ' 件は報告か書いた人が消えているため取り込まず') . "\n";
echo "取り込んだ人は所属の課がありません。組織・ユーザーの画面か CSV で課を決めたあと、--fill-departments で報告に課を入れてください\n";
echo "初回ログインで、パスワードの変更と 2 段階認証の設定を求めます（パスワードは tomato と同じ）\n";
