<?php

declare(strict_types=1);

// ====================================================================
// 既存の Web 版のデータを移行する（詳細設計書 DB 編 §6。コマンドラインで実行）
//   php app/bin/migrate.php [--db=<DB>]              先にテーブルを作る
//   php app/bin/migrate_legacy.php [--db=<DB>] [--dry-run]
//
// 1. DB の控えを backups/ に取る
// 2. 全件を確かめ、問題があれば何もせずに終わる
// 3. 1 つのトランザクションで、利用者のログイン ID、報告、コメントを移す
//    （自由記述とコメントは暗号化。メールアドレスのユーザー名はログイン ID に置き換える）
// 4. 件数を突き合わせ、元のテーブルを legacy_reports / legacy_comments に名前を変えて残す
// 何度実行しても同じ結果になる（移行済みの報告は legacy_report_id で判定）。
// ====================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';

$opt = getopt('', ['db:', 'dry-run']);
if (isset($opt['db'])) {
    $GLOBALS['APP_CONFIG']['db_path'] = (string) $opt['db'];
}
$dry = isset($opt['dry-run']);
$pdo = db();

$tables = [];
foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll() as $t) {
    $tables[$t['name']] = true;
}
if (!isset($tables['report_records'])) {
    fwrite(STDERR, "先に php app/bin/migrate.php でテーブルを作ってください\n");
    exit(1);
}
$srcReports = isset($tables['legacy_reports']) ? 'legacy_reports' : (isset($tables['reports']) ? 'reports' : null);
$srcComments = isset($tables['legacy_comments']) ? 'legacy_comments' : (isset($tables['comments']) ? 'comments' : null);
if ($srcReports === null) {
    echo "移行元の報告がありません（移行済みか、新しい DB です）\n";
    exit(0);
}

// ---- 1. 控え ----
if (!$dry) {
    $backupDir = APP_DIR . '/backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0700, true);
    }
    $pdo->exec('PRAGMA wal_checkpoint(FULL)');
    $backup = $backupDir . '/before_legacy_migration_' . date('Ymd_His') . '.sqlite';
    copy(cfg('db_path'), $backup);
    @chmod($backup, 0600);
    echo "控え: {$backup}\n";
}

// ---- 2. 確認 ----
$problems = [];
$legacyFieldNames = ['report_date', 'name', 'subject', 'content', 'issues', 'remarks'];

// ログイン ID の割り当て（メールアドレスの @ より前。使えない文字は _）
$users = db_all('SELECT * FROM users ORDER BY id');
$taken = [];
foreach ($users as $u) {
    if ($u['login_id'] !== null) {
        $taken[(string) $u['login_id']] = true;
    }
}
$assign = [];
foreach ($users as $u) {
    if ($u['login_id'] !== null) {
        continue;
    }
    $base = strtolower((string) preg_replace('/@.*$/', '', (string) $u['username']));
    $base = (string) preg_replace('/[^a-z0-9._-]/', '_', $base);
    $base = ltrim($base, '._-');
    if (strlen($base) < 3) {
        $base = 'user' . (int) $u['id'];
    }
    $base = substr($base, 0, 28);
    $cand = $base;
    $n = 2;
    while (isset($taken[$cand])) {
        $cand = $base . $n++;
    }
    if (!auth_is_valid_login_id($cand)) {
        $problems[] = '利用者 id=' . $u['id'] . ' のログイン ID を作れません';
        continue;
    }
    $taken[$cand] = true;
    $assign[(int) $u['id']] = $cand;
}

$reports = db_all("SELECT * FROM {$srcReports} ORDER BY id");
foreach ($reports as $r) {
    if (DateTime::createFromFormat('!Y-m-d', (string) $r['report_date']) === false) {
        $problems[] = '報告 id=' . $r['id'] . ' の日付の形が正しくありません: ' . $r['report_date'];
    }
    if (db_one('SELECT id FROM users WHERE id = ?', [(int) $r['user_id']]) === null) {
        $problems[] = '報告 id=' . $r['id'] . ' の作成者がいません';
    }
}
$comments = $srcComments !== null ? db_all("SELECT * FROM {$srcComments} ORDER BY id") : [];
foreach ($comments as $c) {
    if (!in_array((string) $c['field_name'], $legacyFieldNames, true)) {
        $problems[] = 'コメント id=' . $c['id'] . ' の項目名が想定外です: ' . $c['field_name'];
    }
}
if ($problems) {
    foreach ($problems as $p) {
        echo "問題: {$p}\n";
    }
    echo "問題があるため、何も移行していません\n";
    exit(1);
}
echo '移行元: 利用者 ' . count($users) . ' 人（ログイン ID を割り当てる ' . count($assign) . ' 人）、報告 ' . count($reports) . ' 件、コメント ' . count($comments) . " 件\n";
if ($dry) {
    foreach ($assign as $id => $login) {
        echo "  利用者 id={$id} → ログイン ID {$login}\n";
    }
    echo "--dry-run のため、何も変更していません\n";
    exit(0);
}

// ---- 3. 移行 ----
$result = tx(function () use ($assign, $reports, $comments, $legacyFieldNames, $srcReports, $srcComments) {
    $now = now();
    // 利用者: ログイン ID。メールアドレスのユーザー名はログイン ID に置き換える（個人情報を減らす）
    foreach ($assign as $id => $login) {
        $name = db_val("SELECT name FROM {$srcReports} WHERE user_id = ? ORDER BY report_date DESC, id DESC LIMIT 1", [$id]);
        db_exec(
            "UPDATE users SET login_id = ?, username = ?, display_name = CASE WHEN display_name = '' THEN ? ELSE display_name END,
                    must_change_password = 1, updated_at = ? WHERE id = ?",
            [$login, $login, $name !== null && str_len((string) $name) <= 20 ? (string) $name : $login, $now, $id]
        );
    }

    // 既存の 6 項目（共通・報告全体・無効。移行した報告の表示だけに使う）
    $defs = [
        'report_date' => ['日付', 'date'], 'name' => ['氏名', 'single_text'], 'subject' => ['件名', 'single_text'],
        'content' => ['内容', 'multi_text'], 'issues' => ['課題', 'multi_text'], 'remarks' => ['備考', 'multi_text'],
    ];
    $fieldIds = [];
    $sort = 900;
    foreach ($defs as $code => [$label, $type]) {
        $sort += 10;
        $fid = db_val('SELECT id FROM report_fields WHERE code = ?', [$code]);
        if ($fid === null) {
            db_exec(
                "INSERT INTO report_fields (code, label, field_type, department_id, field_scope, confidentiality, sort_order, is_active, is_required, settings_json, created_at, updated_at)
                 VALUES (?, ?, ?, NULL, 'report', 'internal', ?, 0, 0, ?, ?, ?)",
                [$code, $label, $type, $sort, json_encode($type === 'single_text' ? ['max_length' => 400] : ($type === 'multi_text' ? ['max_length' => 20000] : [])), $now, $now]
            );
            $fid = db()->lastInsertId();
        }
        $fieldIds[$code] = (int) $fid;
    }
    $snapshotFields = [];
    foreach ($defs as $code => [$label, $type]) {
        $snapshotFields[] = [
            'id' => $fieldIds[$code], 'label' => $label, 'type' => $type, 'scope' => 'report', 'conf' => 'internal',
            'sort' => 0, 'required' => false, 'settings' => [], 'options' => [],
        ];
    }

    $migrated = 0;
    $map = [];
    foreach ($reports as $r) {
        $exists = db_val('SELECT id FROM report_records WHERE legacy_report_id = ?', [(int) $r['id']]);
        if ($exists !== null) {
            $map[(int) $r['id']] = (int) $exists;
            continue;
        }
        $dept = db_val('SELECT department_id FROM users WHERE id = ?', [(int) $r['user_id']]);
        db_exec(
            "INSERT INTO report_records (user_id, department_id, period, status, is_legacy, legacy_report_id, submitted_at, confirmed_at, created_at, updated_at)
             VALUES (?, ?, ?, 'confirmed', 1, ?, ?, ?, ?, ?)",
            [(int) $r['user_id'], $dept, substr((string) $r['report_date'], 0, 7), (int) $r['id'], $r['created_at'], $r['updated_at'], $r['created_at'], $r['updated_at']]
        );
        $rid = (int) db()->lastInsertId();
        $map[(int) $r['id']] = $rid;
        form_write_snapshot($rid, $snapshotFields);
        foreach ($legacyFieldNames as $code) {
            $v = (string) ($r[$code] ?? '');
            if ($v === '') {
                continue;
            }
            $fid = $fieldIds[$code];
            $encrypted = $code !== 'report_date';
            db_exec(
                'INSERT INTO report_field_values (report_id, field_id, report_project_id, value_text, is_encrypted, has_warning, updated_at) VALUES (?, ?, 0, ?, ?, ?, ?)',
                [$rid, $fid, $encrypted ? enc($v, form_aad($rid, $fid, 0)) : $v, $encrypted ? 1 : 0, $encrypted && text_has_warning($v) ? 1 : 0, $now]
            );
        }
        $migrated++;
    }

    $cMigrated = 0;
    foreach ($comments as $c) {
        $rid = $map[(int) $c['report_id']] ?? null;
        if ($rid === null) {
            continue; // 報告が削除済みのコメント（外部キーが効いていなかったため残っていたもの）
        }
        $fid = $fieldIds[(string) $c['field_name']];
        $dup = db_val('SELECT id FROM report_field_comments WHERE report_id = ? AND user_id = ? AND created_at = ? AND field_id = ?', [$rid, (int) $c['user_id'], $c['created_at'], $fid]);
        if ($dup !== null) {
            continue;
        }
        db_exec(
            "INSERT INTO report_field_comments (report_id, field_id, report_project_id, user_id, comment_type, comment_enc, created_at, updated_at) VALUES (?, ?, 0, ?, 'note', '', ?, ?)",
            [$rid, $fid, (int) $c['user_id'], $c['created_at'], $c['updated_at']]
        );
        $cid = (int) db()->lastInsertId();
        db_exec('UPDATE report_field_comments SET comment_enc = ? WHERE id = ?', [enc((string) $c['comment'], 'cmt:' . $rid . ':' . $cid), $cid]);
        $cMigrated++;
    }

    // ---- 4. 名前を変えて残す ----
    if ($srcReports === 'reports') {
        db()->exec('ALTER TABLE reports RENAME TO legacy_reports');
    }
    if ($srcComments === 'comments') {
        db()->exec('ALTER TABLE comments RENAME TO legacy_comments');
    }
    return [$migrated, $cMigrated, count($map)];
});

[$migrated, $cMigrated, $total] = $result;
$legacyCount = (int) db_val('SELECT COUNT(*) FROM report_records WHERE is_legacy = 1');
echo "移行した報告: {$migrated} 件（移行済みを含め {$total} 件）、コメント: {$cMigrated} 件\n";
echo '突き合わせ: 移行元 ' . count($reports) . ' 件 / 移行先 ' . $legacyCount . ' 件 ' . ($legacyCount === count($reports) ? 'OK' : '不一致') . "\n";
foreach ($assign as $id => $login) {
    echo "  利用者 id={$id} → ログイン ID {$login}（初回ログインでパスワードの変更と 2 段階認証の設定を求めます）\n";
}
echo "元のテーブルは legacy_reports / legacy_comments として残しています。確認が終わったら消してください\n";
