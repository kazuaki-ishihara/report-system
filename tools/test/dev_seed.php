<?php

declare(strict_types=1);

// ====================================================================
// ローカル検証環境のテストデータを作り直す（本番では使わない）
//   php tools/test/dev_seed.php --yes             （--with-faq を付けると、標準のよくある質問も入れる。ローカル検証環境の一式で使う）
// config.local.php に 'allow_dev_seed' => true があるときだけ動く。DB を消して作り直す。
// テスト用の利用者は決まったパスワードと 2 段階認証の秘密の値を持つ（検証の道具がコードを計算する）。
// ====================================================================

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../../app/bootstrap.php';

$local = APP_DIR . '/config/config.local.php';
$localCfg = is_file($local) ? require $local : [];
if (empty($localCfg['allow_dev_seed']) || cfg('https') || cfg('base_path') !== '') {
    fwrite(STDERR, "ローカル検証環境でだけ使えます（config.local.php に allow_dev_seed => true）\n");
    exit(1);
}
$opt = getopt('', ['yes', 'with-faq']);
if (!isset($opt['yes'])) {
    fwrite(STDERR, "DB を消して作り直します。--yes を付けて実行してください\n");
    exit(1);
}

$db = cfg('db_path');
foreach ([$db, $db . '-wal', $db . '-shm'] as $f) {
    if (is_file($f)) {
        unlink($f);
    }
}
crypto_generate_key();
tx(function (PDO $pdo) {
    schema_ensure($pdo);
});

$now = now();
db_exec('INSERT INTO departments (name, sort_order, created_at, updated_at) VALUES (?, 10, ?, ?)', ['A課', $now, $now]);
$deptA = (int) db()->lastInsertId();
db_exec('INSERT INTO departments (name, sort_order, created_at, updated_at) VALUES (?, 20, ?, ?)', ['B課', $now, $now]);
$deptB = (int) db()->lastInsertId();

// 様式を作る道具は、リポジトリの直下から相対の場所で呼ぶ（Windows で、置き場所に日本語などが入っていても開けるように）
chdir(dirname(APP_DIR));
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg('app/bin/seed_template.php') . ' --dept-a=' . $deptA . ' --dept-b=' . $deptB);

$users = [
    ['admin01', '管理者', null, 1, null],
    ['kacho_a', 'A課長', $deptA, 0, 'final'],
    ['dairi_a', 'A代理', $deptA, 0, 'reviewer'],
    ['leader_a', 'Aリーダー', $deptA, 0, 'reviewer'],
    ['ippan_a1', 'A一般1', $deptA, 0, null],
    ['ippan_a2', 'A一般2', $deptA, 0, null],
    ['kacho_b', 'B課長', $deptB, 0, 'final'],
    ['ippan_b1', 'B一般1', $deptB, 0, null],
    ['hosa01', 'システム補佐', null, 0, null],   // システム管理者補佐（K-043。課なし）
];
$out = [];
foreach ($users as [$login, $name, $dept, $admin, $role]) {
    $password = 'pass-' . $login . '-1234';
    $secret = base32_encode(substr(hash('sha1', 'dev-totp-' . $login, true), 0, 20));
    db_exec(
        'INSERT INTO users (username, password_hash, role_title, is_admin, created_at, login_id, display_name, department_id, totp_enabled_at, password_changed_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$login, password_hash($password, PASSWORD_DEFAULT), ['kacho_a' => '課長', 'kacho_b' => '課長', 'dairi_a' => '課長代理', 'leader_a' => '主任'][$login] ?? '一般', $admin, $now, $login, $name, $dept, $now, $now, $now]
    );
    $id = (int) db()->lastInsertId();
    db_exec('UPDATE users SET totp_secret_enc = ? WHERE id = ?', [enc($secret, 'totp:' . $id), $id]);
    if ($role !== null) {
        db_exec('INSERT INTO department_reviewers (department_id, user_id, is_final, sort_order) VALUES (?, ?, ?, ?)', [$dept, $id, $role === 'final' ? 1 : 0, $id * 10]);
    }
    // 予備のコード: 実際の利用者は 2 段階認証の設定で必ず持つので、テスト用の利用者にも持たせる（無いとホームに作り直しの知らせが出る）。
    // ハッシュを作るのに時間がかかる（自動検証は何度も作り直す）ので、1 人 1 つだけ
    $backupCodePlain = substr(str_replace(['i', 'l', 'o'], '', strtolower(base32_encode(hash('sha1', 'dev-backup-' . $login, true)))), 0, 10);
    db_exec('INSERT INTO user_backup_codes (user_id, code_hash, created_at) VALUES (?, ?, ?)', [$id, password_hash($backupCodePlain, PASSWORD_DEFAULT), $now]);
    $out[$login] = ['id' => $id, 'password' => $password, 'totp' => $secret,
                    'backup_code' => substr($backupCodePlain, 0, 4) . '-' . substr($backupCodePlain, 4)];
}

db_exec("UPDATE users SET is_admin_deputy = 1 WHERE login_id = 'hosa01'");

$projects = [['P-0001', '物流系・在庫管理更改', $deptA], ['P-0002', '製造系・生産管理保守', $deptA], ['P-0003', '金融系・勘定系', $deptB], ['P-0004', '社内・共通基盤', null]];
foreach ($projects as [$code, $alias, $dept]) {
    db_exec('INSERT INTO projects (code, alias, department_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$code, $alias, $dept, $now, $now]);
}
db_exec("INSERT INTO app_state (name, value) VALUES ('project_seq', '4')");
// システムの管理担当の課（K-006。本番ではシステム 2 部システム 2 課）。検証では A課
db_exec("INSERT INTO app_state (name, value) VALUES ('management_department_id', ?)", [(string) $deptA]);

// 標準のよくある質問（G-25 §2.4）。自動検証は入れない（取り込みを検証するため）。ローカル検証環境の一式では入れる
if (isset($opt['with-faq'])) {
    require_once APP_DIR . '/lib/faq_standard.php';
    $faqSortOrder = 10;
    foreach (FAQ_STANDARD_ENTRIES as [$faqCategory, $faqQuestion, $faqAnswer]) {
        db_exec('INSERT INTO faq_entries (category, question, answer, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$faqCategory, $faqQuestion, $faqAnswer, $faqSortOrder, $now, $now]);
        $faqSortOrder += 10;
    }
    echo '標準のよくある質問を入れました: ' . count(FAQ_STANDARD_ENTRIES) . " 件
";
}

file_put_contents(__DIR__ . '/output_dev_users.json', json_encode(['dept_a' => $deptA, 'dept_b' => $deptB, 'users' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "テストデータを作りました: {$db}\n";
