<?php

declare(strict_types=1);

// ====================================================================
// ライブラリの関数の確かめ（誤り・異常系）。使い捨ての DB と鍵を一時フォルダーに作る
//   php tools/test/check_libs.php
// 開発の DB・鍵には触れない。
// ====================================================================

if (PHP_SAPI !== 'cli') {
    exit(1);
}
putenv('MR_VERIFY_DB');
require __DIR__ . '/../../app/bootstrap.php';
foreach (['export', 'expense', 'goal', 'org_csv', 'search', 'survey', 'backup', 'support_request', 'user_theme', 'notice', 'reminder', 'admin', 'report', 'form', 'perm', 'totp', 'maintenance'] as $libraryName) {
    if (is_file(APP_DIR . '/lib/' . $libraryName . '.php')) {
        require_once APP_DIR . '/lib/' . $libraryName . '.php';
    }
}

$ngCount = 0;
$check = function (string $screenCode, string $name, bool $isOk, $detail = null) use (&$ngCount): void {
    echo ($isOk ? 'OK  ' : 'NG  ') . '[' . $screenCode . '] ' . $name . ($isOk || $detail === null ? '' : '  ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    $ngCount += $isOk ? 0 : 1;
};
$throws = function (callable $action): ?string {
    try {
        $action();
    } catch (Throwable $error) {
        return get_class($error);
    }
    return null;
};
$contains = function (array $textList, string $part): bool {
    return (bool) array_filter($textList, function (string $text) use ($part): bool { return strpos($text, $part) !== false; });
};

$work = sys_get_temp_dir() . '/mr_libs_' . bin2hex(random_bytes(4));
mkdir($work . '/keys', 0700, true);
register_shutdown_function(function () use ($work): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir((string) $item) : @unlink((string) $item);
    }
    @rmdir($work);
});
$GLOBALS['APP_CONFIG']['db_path'] = $work . '/libs.sqlite';
$GLOBALS['APP_CONFIG']['key_dir'] = $work . '/keys';
$GLOBALS['APP_CONFIG']['log_dir'] = $work . '/logs';
$GLOBALS['APP_CONFIG']['backup_dir'] = $work . '/backups';

// ---- 暗号 ----
$check('共通', '鍵がないときは暗号化できない', $throws(function (): void { crypto_key('v9'); }) === 'RuntimeException');
file_put_contents($work . '/keys/field_v8.key', base64_encode('short'));
$check('共通', '短い鍵は使わない', $throws(function (): void { crypto_key('v8'); }) === 'RuntimeException');
$GLOBALS['APP_CONFIG']['key_dir'] = $work . '/keys/new';
crypto_generate_key();
$check('共通', '鍵の置き場所がなければ作る', is_file($work . '/keys/new/field_v1.key'));
$encrypted = enc('秘密', 'aad');
$check('共通', '暗号化して元に戻る', dec($encrypted, 'aad') === '秘密');
$check('共通', '別の行の付加データでは戻せない', dec($encrypted, 'other') === null);
$check('共通', '空・版のない文・壊れた文・知らない版は戻せない', (dec('', 'aad') ?? '') === '' && dec('abc', 'aad') === null && dec('v1:@@@', 'aad') === null && dec('v1:' . base64_encode('x'), 'aad') === null && dec('v7:' . base64_encode(str_repeat('x', 40)), 'aad') === null);

// ---- DB ----
$pdo = db();
schema_ensure($pdo);
$now = now();
db_exec('INSERT INTO departments (id, name, created_at, updated_at) VALUES (1, ?, ?, ?), (2, ?, ?, ?)', ['一課', $now, $now, '二課', $now, $now]);
$addUser = function (int $id, string $login, ?int $departmentId, string $role = '一般', int $isAdmin = 0): void {
    db_exec('INSERT INTO users (id, username, password_hash, role_title, is_admin, created_at, login_id, display_name, department_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, $login, 'x', $role, $isAdmin, now(), $login, $login, $departmentId]);
};
$addUser(1, 'boss', null, '部長', 1);
$addUser(2, 'member2', 1);
$addUser(3, 'member3', 1);
$addUser(4, 'final4', 1, '課長');
db_exec('INSERT INTO department_reviewers (department_id, user_id, is_final, sort_order) VALUES (1, 4, 1, 10)');

// ---- 組織の CSV ----
$header = implode(',', ORG_CSV_COLUMNS) . "\n";
$row = function (array $values): string {
    $cells = array_merge(array_fill_keys(ORG_CSV_COLUMNS, ''), ['有効' => '1'], $values);
    return implode(',', array_map(function (string $column) use ($cells): string { return (string) $cells[$column]; }, ORG_CSV_COLUMNS)) . "\n";
};
$plan = function (string $rows) use ($header): array { return org_csv_plan("\xEF\xBB\xBF社外秘\n\n" . $header . "\n" . $rows, 1); };
$base = ['ログイン ID' => 'member2', '所属の課' => '一課'];
$cases = [
    ['見出しがない', org_csv_plan("a,b\n1,2\n", 1), '見出しの行'],
    ['列の数が違う', $plan("a,b\n"), '列の数が違います'],
    ['登録のない ID', $plan($row(['ログイン ID' => 'nobody'])), '登録されていない'],
    ['同じ ID が 2 回', $plan($row($base) . $row($base)), '2 回あります'],
    ['無い課', $plan($row(['所属の課' => '無い課'] + $base)), '有効な課にない'],
    ['役割の誤り', $plan($row(['役割' => 'x'] + $base)), '役割は'],
    ['有効の誤り', $plan($row(['有効' => 'x'] + $base)), '「有効」は 1 か 0'],
    ['自分を無効にする', $plan($row(['ログイン ID' => 'boss', '有効' => '0'])), '自分自身は無効にできません'],
    ['課のない人のライン', $plan($row(['ログイン ID' => 'member2', 'ライン' => 'L'])), '所属の課がない人'],
    ['確認者でない人の担当のライン', $plan($row(['担当のライン' => 'L'] + $base)), '担当のラインは'],
    ['長いライン', $plan($row(['ライン' => str_repeat('あ', 41)] + $base)), '40 文字以内'],
    ['行が多すぎる', $plan(str_repeat($row(['ログイン ID' => 'x']), 2001)), '行が多すぎます'],
];
foreach ($cases as [$title, $result, $part]) {
    $check('G-14', '組織の CSV を断る: ' . $title, $contains($result['errorTextList'], $part), $result['errorTextList']);
}
$good = $plan($row(['ライン' => '新ライン', '項目管理者' => '1'] + $base) . $row(['ログイン ID' => 'member3', '所属の課' => '一課', 'ライン' => '新ライン', '役割' => '確認者', '担当のライン' => '新ライン']) . $row(['ログイン ID' => 'final4', '所属の課' => '一課', '有効' => '0']));
$check('G-14', '組織の CSV: 誤りがない', $good['errorTextList'] === [], $good['errorTextList']);
if ($good['errorTextList'] === []) {
    org_csv_apply($good);
    $check('G-14', '組織の CSV: ラインは 1 つだけ作る', (int) db_val("SELECT COUNT(*) FROM department_teams WHERE name = '新ライン'") === 1);
    $check('G-14', '組織の CSV: 項目管理者・確認者が付く', db_val('SELECT 1 FROM department_admins WHERE user_id = 2') !== null && db_val('SELECT 1 FROM department_reviewers WHERE user_id = 3 AND is_final = 0') !== null);
    $check('G-14', '組織の CSV: 無効にした人は役割から外れる', (int) db_val('SELECT is_active FROM users WHERE id = 4') === 0 && db_val('SELECT 1 FROM department_reviewers WHERE user_id = 4') === null);
    db_exec("UPDATE department_teams SET is_active = 0 WHERE name = '新ライン'");
    $again = $plan($row(['ライン' => '新ライン'] + $base));
    $check('G-14', '組織の CSV: 無効のラインを含む CSV を受け付ける', $again['errorTextList'] === [], $again['errorTextList']);
    org_csv_apply($again);
    $check('G-14', '組織の CSV: 無効のラインは有効に戻る', (int) db_val("SELECT is_active FROM department_teams WHERE name = '新ライン'") === 1);
}

// ---- 入力部品 ----
$field = function (string $type, array $settings = [], array $options = []): array {
    return ['id' => 1, 'code' => 'c', 'label' => '項目', 'type' => $type, 'scope' => 'report', 'conf' => 'normal', 'required' => false, 'settings' => $settings, 'options' => $options,
        'help' => '', 'placeholder' => '', 'carry' => false, 'sort' => 0, 'show_when' => null];
};
$parse = function (array $fieldDefinition, $raw, string $previous = ''): array {
    $errors = [];
    $value = form_parse_value($fieldDefinition, $raw, false, $errors, 'k', $previous);
    return [$value, $errors['k'] ?? null];
};
$check('G-04', '文の欄に配列・不正な文字コード・制御文字を断る', $parse($field('single_text'), ['x'])[1] !== null && $parse($field('single_text'), "\xFF")[1] !== null && $parse($field('multi_text'), "a\x01b")[1] !== null);
$check('G-04', '知らない入力部品を断る', $parse($field('unknown'), 'x')[1] !== null);
$table = $field('table', ['columns' => [['key' => 'a', 'label' => 'A', 'type' => 'single_text']], 'max_rows' => 2]);
$check('G-04', '表の行が配列でなければ読み飛ばす', $parse($table, ['x', ['a' => '1']])[0] === json_encode([['a' => '1']]));
$check('G-04', '表の長すぎる値・制御文字を断る', $parse($table, [['a' => str_repeat('あ', 2001)]])[1] !== null && $parse($table, [['a' => "x\x01"]])[1] !== null);
$hidden = [['code' => 'on', 'label' => '出す', 'active' => true], ['code' => 'off', 'label' => '無効', 'active' => false]];
$check('G-04', '無効の選択肢は、新しくは選べず、前の値なら残せる', $parse($field('radio', [], $hidden), 'off')[1] !== null && $parse($field('radio', [], $hidden), 'off', 'off') === ['off', null]);
foreach (['select', 'radio', 'multi_check'] as $type) {
    $html = form_input_html($field($type, [], $hidden), $type === 'multi_check' ? '[]' : '', 'n', 'k', null);
    $check('G-04', '無効の選択肢を新しい欄に出さない: ' . $type, strpos($html, '無効') === false);
    $kept = form_input_html($field($type, [], $hidden), $type === 'multi_check' ? '["off"]' : 'off', 'n', 'k', null);
    $check('G-04', '保存済みの無効の選択肢は残して出す: ' . $type, strpos($kept, '無効') !== false);
}

// ---- 通常の部品 ----
$errors = [];
validate_text(str_repeat('あ', 5), '名前', 4, true, $errors);
validate_text("a\x01", '名前', 10, true, $errors);
$check('共通', '長すぎる文・使えない文字を断る', count($errors) === 2, $errors);
$check('共通', 'よそへの転送先を断る', !is_safe_local_path('') && !is_safe_local_path('x') && !is_safe_local_path('//evil') && !is_safe_local_path('/a\\b') && is_safe_local_path('/index.php'));
$check('G-16', '長すぎるパスワード・ログイン ID と同じパスワードを断る', auth_validate_new_password(str_repeat('a', 73), 'x') !== null && auth_validate_new_password('longlogin01', 'longlogin01') !== null);
foreach (['iPad' => 'iPad', 'Android' => 'Android', 'Mac OS X' => 'Mac', 'X11; Linux' => 'Linux', 'Windows Edg/1' => 'Edge', 'Windows Firefox/1' => 'Firefox', 'Windows Chrome/1' => 'Chrome', 'iPhone Safari/1' => 'Safari', 'x' => 'その他'] as $agent => $part) {
    $_SERVER['HTTP_USER_AGENT'] = $agent;
    $check('G-16', '端末の名前: ' . $agent, strpos(auth_device_label(), $part) !== false, auth_device_label());
}
$check('G-01', '2 段階認証が未設定・読めない人のコードを断る', auth_verify_second_factor(['id' => 2, 'totp_secret_enc' => null, 'totp_last_step' => null] + db_one('SELECT * FROM users WHERE id = 2'), '000000') === false
    && auth_verify_second_factor(array_merge(db_one('SELECT * FROM users WHERE id = 2'), ['totp_secret_enc' => 'v1:xx']), '000000') === false);
$check('G-01', '誤った Base32 を断る', base32_decode('!!!') === null || base32_decode('!!!') === false);

// ---- 自分の色 ----
$check('G-16', '知らない土台の色を断る', user_theme_format_errors(['base' => 'x', 'accent' => '#000000', 'background' => '#ffffff']) !== []);
$check('G-16', '読みにくい組み合わせを知らせる', user_theme_readability_warnings(['base' => 'light', 'accent' => '#eeeeee', 'background' => '#ffffff']) !== []);

// ---- バックアップ ----
$check('G-21', 'バックアップがないときは古いと知らせる', backup_status()['isStale'] === true);
backup_create();
touch((string) glob($work . '/backups/*.sqlite')[0], time() - 86400 * 30);
$check('G-21', '古いバックアップを知らせる', backup_status()['isStale'] === true && backup_status()['backupCount'] === 1);
file_put_contents($work . '/file', 'x');
$GLOBALS['APP_CONFIG']['backup_dir'] = $work . '/file/sub';
$check('G-21', '置き場所を作れないときは知らせる', $throws(function (): void { @backup_create(); }) !== null);

// ---- 精算・目標 ----
if (function_exists('expense_validate')) {
    $sheet = expense_normalize(['submitted_on' => 'x', 'payment_method' => 'x', 'rows' => ['x', ['start_date' => '2026-02-30', 'purpose' => str_repeat('あ', 61), 'transport' => 'x', 'trip' => 'x', 'amount' => 'x']]]);
    $errorsExpense = expense_validate($sheet);
    foreach (['提出日', '精算方法', '所属', '月／日', '業務・行先', '交通手段', '片道か往復', '金額'] as $part) {
        $check('G-26', '精算の誤り: ' . $part, $contains($errorsExpense, $part), $errorsExpense);
    }
    $many = expense_normalize(['rows' => array_fill(0, EXPENSE_MAX_ROWS + 1, ['amount' => '1'])]);
    $check('G-26', '精算の行の上限', $contains(expense_validate($many), '行までです'));
    $check('G-26', '読めない精算書に印を付ける', (expense_decode('v1:xx', 2)['unreadable'] ?? false) === true);
    $id = expense_save(null, 2, expense_normalize(['full_name' => '検証', 'employee_number' => '7']));
    $check('G-26', '自分の精算書の一覧', count(expense_sheets_of_owner(2)) === 1 && expense_sheets_of_owner(3) === []);
    $check('G-26', '新しい精算書は、前の上の欄を引き継ぐ', expense_new_sheet_data(db_one('SELECT * FROM users WHERE id = 2'))['employee_number'] === '7');
}
if (function_exists('goal_validate')) {
    $data = goal_normalize(['full_name' => '検証', 'business_plan' => 'x', 'business_actual' => '5', 'overall_boss' => 'Z'], goal_default_kind(db_one('SELECT * FROM users WHERE id = 2')));
    $data['goals'][0]['mid_self'] = 'Z';
    $data['goals'][0]['difficulty'] = 'Z';
    foreach (array_keys(GOAL_TEXT_FIELDS) as $textKey) {
        $data['goals'][0][$textKey] = "a\x01";
    }
    $data['competency_self'][0] = '9';
    $errorsGoal = goal_validate($data);
    foreach (['業績目標の計画', '業績目標の実績を入れるとき', '使えない文字', '評価は A', '配点は', 'コンピテンシーの評点', '総合評価'] as $part) {
        $check('G-27', '目標の誤り: ' . $part, $contains($errorsGoal, $part), $errorsGoal);
    }
    $check('G-27', '無いシート・課のない人のシートは見られない', goal_sheet(99999) === null && goal_viewer_role(db_one('SELECT * FROM users WHERE id = 2'), 1) === null);
}

// ---- 権限・そのほか ----
$check('共通', '削除した報告は見られない', can_view_report(db_one('SELECT * FROM users WHERE id = 2'), ['id' => 1, 'user_id' => 2, 'department_id' => 1, 'status' => 'submitted', 'deleted_at' => $now]) === false);
$_SERVER['HTTP_ACCEPT'] = '';
$check('G-22', '開いていた画面のパスの形を確かめる', support_request_clean_source_path('/../x') === '' && support_request_clean_source_path('http://evil/') === '' && support_request_clean_source_path('/reports/mine.php') !== '');
foreach ([1 => 'x'] as $unused) {
    $check('G-24', 'まだ出す日でないお知らせは出さない', (function () use ($now): bool {
        db_exec("INSERT INTO update_notices (title, body, show_from, show_until, created_by, updated_by, created_at, updated_at) VALUES ('先', '', '2999-01-01', '2999-02-01', 1, 1, ?, ?)", [$now, $now]);
        return notices_active(5) === [];
    })());
}
echo $ngCount === 0 ? "\nすべて OK\n" : "\nNG {$ngCount}\n";
exit($ngCount === 0 ? 0 : 1);
