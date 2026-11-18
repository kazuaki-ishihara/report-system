<?php

declare(strict_types=1);

// ====================================================================
// 利用者を作る（最初の管理者を作るときなど。コマンドラインで実行）
//   php app/bin/create_user.php --login=admin01 --name=管理者 --admin [--dept=A課] [--reviewer|--final]
// 仮のパスワードを 1 回だけ表示する。初回ログインで 2 段階認証の設定とパスワードの変更を求める。
// ====================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';

$opt = getopt('', ['login:', 'name:', 'admin', 'dept:', 'reviewer', 'final']);
$login = auth_normalize_login_id((string) ($opt['login'] ?? ''));
$name = trim((string) ($opt['name'] ?? ''));
if (!auth_is_valid_login_id($login) || $name === '') {
    fwrite(STDERR, "使い方: php app/bin/create_user.php --login=<ID> --name=<表示名> [--admin] [--dept=<課名>] [--reviewer|--final]\n");
    fwrite(STDERR, "ログイン ID は英小文字・数字・. _ - の 3〜32 文字（メールアドレスは不可）\n");
    exit(1);
}
if (auth_find_user($login) !== null) {
    fwrite(STDERR, "同じログイン ID があります: {$login}\n");
    exit(1);
}

$temp = substr(str_replace(['+', '/', '='], ['a', 'b', ''], base64_encode(random_bytes(12))), 0, 12);

$id = tx(function () use ($opt, $login, $name, $temp) {
    $deptId = null;
    if (isset($opt['dept'])) {
        $deptName = trim((string) $opt['dept']);
        $deptId = db_val('SELECT id FROM departments WHERE name = ?', [$deptName]);
        if ($deptId === null) {
            db_exec('INSERT INTO departments (name, created_at, updated_at) VALUES (?, ?, ?)', [$deptName, now(), now()]);
            $deptId = (int) db()->lastInsertId();
            echo "課を作りました: {$deptName}\n";
        }
    }
    db_exec(
        'INSERT INTO users (username, password_hash, role_title, is_admin, created_at, login_id, display_name, department_id, must_change_password, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
        [$login, password_hash($temp, PASSWORD_DEFAULT), '一般', isset($opt['admin']) ? 1 : 0, now(), $login, $name, $deptId, now()]
    );
    $id = (int) db()->lastInsertId();
    if ($deptId !== null && (isset($opt['reviewer']) || isset($opt['final']))) {
        db_exec(
            'INSERT INTO department_reviewers (department_id, user_id, is_final) VALUES (?, ?, ?)',
            [(int) $deptId, $id, isset($opt['final']) ? 1 : 0]
        );
    }
    audit_log('admin', 'user:' . $id . ':create_cli', null);
    return $id;
});

echo "利用者を作りました: id={$id} login_id={$login}\n";
echo "仮のパスワード: {$temp}\n";
echo "（この表示は一度だけです。本人に伝えてください）\n";
