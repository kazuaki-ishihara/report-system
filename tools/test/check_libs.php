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
$check('G-24', 'まだ出す日でないお知らせは出さない', (function () use ($now): bool {
        db_exec("INSERT INTO update_notices (title, body, show_from, show_until, created_by, updated_by, created_at, updated_at) VALUES ('先', '', '2999-01-01', '2999-02-01', 1, 1, ?, ?)", [$now, $now]);
        return notices_active(5) === [];
    })());

// ---- 様式（zip・xlsx）の破損 ----
if (function_exists('zip_read_entries')) {
    $template = (string) file_get_contents(EXPENSE_TEMPLATE_PATH);
    $check('G-26', '様式を読める', isset(zip_read_entries($template)[EXPENSE_TEMPLATE_SHEET_PATH]));
    $check('G-26', '格納だけの zip も読める', zip_read_entries(zip_store(['a.txt' => '中身'])) === ['a.txt' => '中身']);
    $store = zip_store(['a.txt' => 'abc']);
    $centralAt = strpos($store, "PK\x01\x02");
    $endAt = strrpos($store, "PK\x05\x06");
    $broken = [
        '終わりの印がない' => 'not a zip',
        '一覧が壊れている' => substr_replace($store, 'XXXX', $centralAt, 4),
        '知らない圧縮' => substr_replace($store, pack('v', 99), $centralAt + 10, 2),
        'CRC が合わない' => substr_replace($store, "\x00\x00\x00\x01", $centralAt + 16, 4),
        '圧縮の中身が壊れている' => substr_replace($store, pack('v', 8), $centralAt + 10, 2),
    ];
    foreach ($broken as $title => $bytes) {
        $check('G-26', '壊れた zip を断る: ' . $title, @$throws(function () use ($bytes): void { @zip_read_entries($bytes); }) === 'RuntimeException');
    }
    $entries = zip_read_entries($template);
    $sheetXml = new DOMDocument();
    $sheetXml->loadXML($entries[EXPENSE_TEMPLATE_SHEET_PATH]);
    $xpath = new DOMXPath($sheetXml);
    $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $check('G-26', '様式に無いセルを断る', $throws(function () use ($sheetXml, $xpath): void { expense_xlsx_set_cell($sheetXml, $xpath, 'ZZ999', 'x'); }) === 'RuntimeException');
    $formulaCell = $xpath->query('//m:c[m:f]')->item(0);
    $check('G-26', '式のセルに文字を入れない', $formulaCell !== null && $throws(function () use ($sheetXml, $xpath, $formulaCell): void { expense_xlsx_set_cell($sheetXml, $xpath, $formulaCell->getAttribute('r'), '文字'); }) === 'RuntimeException');
    $oneRow = expense_normalize(['submitted_on' => '2026-11-01', 'payment_method' => 'transfer', 'employee_number' => '1', 'full_name' => '検証', 'rows' => [['start_date' => '2026-11-01', 'amount' => '1']]]);
    $check('G-26', '1 ページだけの精算書', substr(expense_xlsx($oneRow), 0, 2) === 'PK');
    $_POST['rows'] = ['x', ['amount' => '１,０００円']];
    foreach (['submitted_on', 'payment_method', 'employee_number', 'office', 'department_name', 'full_name', 'advance_amount'] as $postedName) {
        $_POST[$postedName] = '';
    }
    $posted = expense_data_from_post();
    $check('G-26', '配列でない行は読み飛ばし、全角・カンマ・円の金額を読む', count($posted['rows']) === 1 && $posted['rows'][0]['amount'] === '1000', $posted['rows']);
    $_POST = [];
    // 様式のファイルそのものが壊れているとき（写した app で試す。本物の様式は変えない）
    $copyApp = $work . '/app';
    $copyDir = function (string $from, string $to) use (&$copyDir): void {
        @mkdir($to, 0700, true);
        foreach (scandir($from) as $name) {
            if ($name === '.' || $name === '..' || in_array($name, ['data', 'logs', 'backups'], true)) {
                continue;
            }
            is_dir("$from/$name") ? $copyDir("$from/$name", "$to/$name") : copy("$from/$name", "$to/$name");
        }
    };
    $copyDir(APP_DIR, $copyApp);
    $entriesWithoutSheet = $entries;
    unset($entriesWithoutSheet[EXPENSE_TEMPLATE_SHEET_PATH]);
    $runCopy = function (string $templateName, string $bytes, string $code) use ($copyApp): string {
        file_put_contents($copyApp . '/templates/' . $templateName, $bytes);
        $script = $copyApp . '/run_copy.php';
        file_put_contents($script, '<?php require __DIR__ . "/bootstrap.php"; foreach (["expense", "goal"] as $n) { require_once APP_DIR . "/lib/$n.php"; } try { ' . $code . ' echo "OK"; } catch (Throwable $e) { echo get_class($e) . ":" . $e->getMessage(); }');
        return (string) shell_exec('"' . PHP_BINARY . '" ' . escapeshellarg($script) . ' 2>&1');
    };
    $expenseCode = '@expense_xlsx(' . var_export($oneRow, true) . ');';
    $check('G-26', '様式にシートが無い', strpos($runCopy('expense_sheet.xlsx', zip_store($entriesWithoutSheet), $expenseCode), 'template sheet not found') !== false);
    $check('G-26', '様式のシートが壊れている', strpos($runCopy('expense_sheet.xlsx', zip_store([EXPENSE_TEMPLATE_SHEET_PATH => '<x'] + $entries), $expenseCode), 'RuntimeException') !== false);
    if (function_exists('goal_xlsx')) {
        $goalKind = goal_default_kind(db_one('SELECT * FROM users WHERE id = 2'));
        $definition = GOAL_TEMPLATE_KINDS[$goalKind];
        $goalEntries = zip_read_entries((string) file_get_contents(APP_DIR . '/templates/' . $definition['file']));
        $goalSheet = ['id' => 1, 'user_id' => 2, 'fiscal_year' => 2026, 'kind' => $goalKind, 'data' => goal_normalize(['full_name' => '検証'], $goalKind)];
        $goalCode = '@goal_xlsx(' . var_export($goalSheet, true) . ');';
        $withoutGoal = $goalEntries;
        unset($withoutGoal[$definition['sheet_path']]);
        $check('G-27', '様式にシートが無い', strpos($runCopy($definition['file'], zip_store($withoutGoal), $goalCode), 'template sheet not found') !== false);
        $check('G-27', '様式のシートが壊れている', strpos($runCopy($definition['file'], zip_store([$definition['sheet_path'] => '<x'] + $goalEntries), $goalCode), 'RuntimeException') !== false);
        $goalXml = new DOMDocument();
        $goalXml->loadXML('<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1"/><c r="D1"><f>1+1</f></c><c r="AA1"/></row></sheetData></worksheet>');
        $goalXpath = new DOMXPath($goalXml);
        $goalXpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        goal_xlsx_set_cell($goalXml, $goalXpath, 'B1', '足した');
        goal_xlsx_set_cell($goalXml, $goalXpath, 'Z1', 1.5);
        goal_xlsx_set_cell($goalXml, $goalXpath, 'AB1', 2);
        $order = [];
        foreach ($goalXpath->query('//m:c') as $cell) {
            $order[] = $cell->getAttribute('r');
        }
        $check('G-27', '無いセルは、列の順を守って足す', $order === ['A1', 'B1', 'D1', 'Z1', 'AA1', 'AB1'], $order);
        $check('G-27', '無い行・式のセルへの文字を断る', $throws(function () use ($goalXml, $goalXpath): void { goal_xlsx_set_cell($goalXml, $goalXpath, 'A99', 'x'); }) === 'RuntimeException'
            && $throws(function () use ($goalXml, $goalXpath): void { goal_xlsx_set_cell($goalXml, $goalXpath, 'D1', 'x'); }) === 'RuntimeException');
        // 同じ氏名の人のシートをまとめて受け取る
        db_exec('UPDATE users SET is_active = 1 WHERE id = 4');
        db_exec('DELETE FROM department_reviewers WHERE department_id = 1 AND is_final = 1');
        db_exec('INSERT INTO department_reviewers (department_id, user_id, is_final, sort_order) VALUES (1, 4, 1, 10)');
        foreach ([2, 3] as $memberId) {
            goal_save(null, $memberId, 2026, $goalKind, goal_normalize(['full_name' => '同じ名前'], $goalKind), $memberId, 'create');
        }
        $zip = goal_department_zip(db_one('SELECT * FROM users WHERE id = 4'), 1, 2026);
        $check('G-27', '同じ氏名の人のファイルは、番号で分ける', $zip !== null && count(zip_read_entries($zip)) === 2, $zip === null ? null : array_keys(zip_read_entries($zip)));
        $check('G-27', 'だれもいない年度は null', goal_department_zip(db_one('SELECT * FROM users WHERE id = 4'), 1, 1999) === null);
        $check('G-27', '課のない人の面談をする人はいない', goal_meeting_reviewers(1) === []);
    }
}

// ---- そのほかの関数 ----
$check('共通', '知らない設定の名前は止める', $throws(function (): void { cfg('no_such_key'); }) === 'RuntimeException');
$check('G-22', '知らない状態の定型の返信は空', support_request_default_reply_text('unknown') === '');
$check('G-24', 'お知らせの状態の名前', notice_state_label(['show_from' => '2999-01-01', 'show_until' => '2999-01-02']) === '出す前' && notice_state_label(['show_from' => '2000-01-01', 'show_until' => '2000-01-02']) === '終わった'
    && notice_state_label(['show_from' => '2000-01-01', 'show_until' => '2999-01-02']) === '出している');
$check('G-21', 'メンテナンス中でないときの知らせは空', maintenance_notice_text() === '');
$check('G-25', '体の状態をからかう文は不適切とみなす', faq_is_abusive('うつ病みたいに返事をしないですね') || faq_is_condition_insult('うつ病なんですか変な態度ですね'));
faq_ensure_category_column();
faq_ensure_category_column();
report_comment_ensure_edited_column();
report_comment_ensure_edited_column();
$check('共通', '列を足す処理は 2 回呼んでも同じ', true === in_array('edited_at', array_column(db()->query('PRAGMA table_info(report_field_comments)')->fetchAll(), 'name'), true));
$check('G-11', '課の調査は、全課共通の結果の権限では見られない', survey_can_view_all_departments_result(db_one('SELECT * FROM users WHERE id = 1'), ['id' => 1, 'department_id' => 1]) === false);
$check('G-14', '新しく課長にした人はチェックする', reviewer_default_does_check(2, true) === 1);
$check('G-20', '探す課がなければ何も出さない', search_reports(db_one('SELECT * FROM users WHERE id = 2'), ['departmentIdList' => [], 'periodFrom' => '2026-01', 'periodTo' => '2026-02', 'choiceField' => null, 'choiceCode' => '', 'keywordText' => ''])['resultList'] === []);
$check('G-10', 'まとめの決まりは設定で変えられる', (function (): bool {
    $GLOBALS['APP_CONFIG']['summary_rules'] = ['x' => ['label' => 'X', 'field' => 'a', 'values' => []]];
    $rules = summary_rules();
    unset($GLOBALS['APP_CONFIG']['summary_rules']);
    return array_keys($rules) === ['x'];
})());
$check('G-10', '不正な文字コードの幅も数える', xlsx_text_width("\xFF\xFE") > 0);
$check('G-05', '読めない値の表示', strpos(form_display_html($field('single_text'), null), '表示できません') !== false);
$noComparison = ['previousReport' => ['id' => 1], 'previousPeriodLabel' => '前', 'previousFieldById' => [1 => ['conf' => 'restricted'] + $field('single_text')], 'previousValueByProjectId' => [0 => [1 => '前の値']],
    'previousProjectCodeById' => [], 'canViewPreviousRestricted' => false, 'isPreviousFreeTextPurged' => false];
$check('G-05', '限定を見られない人に、前月の限定の値を出さない', report_previous_value_html($noComparison, $field('single_text'), 0, '今') === '');
$check('G-05', '限定を見られる人には出す', strpos(report_previous_value_html(['canViewPreviousRestricted' => true] + $noComparison, $field('single_text'), 0, '今'), '前の値') !== false);
$purged = ['isPreviousFreeTextPurged' => true, 'previousFieldById' => [1 => $field('single_text')]] + $noComparison;
$check('G-05', '保管期限で消した自由記述は比べない', report_previous_value_html($purged, $field('single_text'), 0, '今') === '');

// ---- 組織の CSV（残り） ----
$noAdmin = org_csv_plan($header . $row(['ログイン ID' => 'boss', '有効' => '0']), 999);
$check('G-14', '管理者がいなくなる CSV を断る', $contains($noAdmin['errorTextList'], '管理者が 1 人もいなくなる'), $noAdmin['errorTextList']);
db_exec('INSERT INTO department_reviewers (department_id, user_id, is_final, sort_order) VALUES (2, 3, 0, 10)');
$moved = $plan($row(['ログイン ID' => 'member3', '所属の課' => '二課']));
$check('G-14', '前の課の役割を外すこと・ほかの課の役割は残ることを知らせる', $contains(array_map('json_encode', [$moved['changeList']]), substr(json_encode('前の課の役割は外す'), 1, 20)), $moved['changeList']);
db_exec("INSERT INTO org_schedules (apply_on, csv_text, status, created_by, created_at) VALUES ('2020-01-01', ?, 'scheduled', 1, ?)", [$header . $row(['ログイン ID' => 'member2', '所属の課' => '二課']), $now]);
db()->exec('CREATE TRIGGER tr_verify_fail BEFORE UPDATE OF department_id ON users BEGIN SELECT RAISE(ABORT, \'verify\'); END');
org_schedules_run_due();
db()->exec('DROP TRIGGER tr_verify_fail');
$check('G-14', '反映の途中で誤りが起きた予約は、失敗として残り、何も変わらない', db_val("SELECT status FROM org_schedules ORDER BY id DESC LIMIT 1") === 'failed' && (int) db_val('SELECT department_id FROM users WHERE id = 2') === 1);

// ---- サイトの印（session_site_stamp）: DB を読めないときは、印を変えずに誤りにする（ログアウトさせない） ----
// DB の接続は 1 つのプロセスで 1 回だけ作るので、壊れた DB を指す別のプロセスで試す
$brokenDatabasePath = $work . '/broken.sqlite';
file_put_contents($brokenDatabasePath, str_repeat('this is not a database', 100));
$siteStampScriptPath = $work . '/site_stamp_check.php';
file_put_contents($siteStampScriptPath, '<?php putenv("MR_VERIFY_DB"); require ' . var_export(APP_DIR . '/bootstrap.php', true) . ';'
    . ' $GLOBALS["APP_CONFIG"]["db_path"] = ' . var_export($brokenDatabasePath, true) . ';'
    . ' $GLOBALS["APP_CONFIG"]["key_dir"] = ' . var_export($work . '/keys/new', true) . ';'
    . ' try { session_site_stamp(); echo "NO_ERROR"; } catch (Throwable $e) { echo "THROWN:" . get_class($e); }');
$siteStampOutput = (string) shell_exec('"' . PHP_BINARY . '" ' . escapeshellarg($siteStampScriptPath) . ' 2>&1');
$check('共通', 'DB を読めないときは、サイトの印を空の鍵で作らずに誤りにする（DB の一時の失敗でログアウトさせない）', strpos($siteStampOutput, 'THROWN:PDOException') !== false, $siteStampOutput);
$validStampScriptPath = $work . '/site_stamp_valid.php';
file_put_contents($validStampScriptPath, '<?php putenv("MR_VERIFY_DB"); require ' . var_export(APP_DIR . '/bootstrap.php', true) . ';'
    . ' $GLOBALS["APP_CONFIG"]["db_path"] = ' . var_export($work . '/libs.sqlite', true) . ';'
    . ' $GLOBALS["APP_CONFIG"]["key_dir"] = ' . var_export($work . '/keys/none', true) . ';'
    . ' try { echo strlen(session_site_stamp()) === 64 ? "STAMP" : "BAD"; } catch (Throwable $e) { echo "THROWN:" . get_class($e); }');
$validStampOutput = (string) shell_exec('"' . PHP_BINARY . '" ' . escapeshellarg($validStampScriptPath) . ' 2>&1');
$check('共通', '鍵を作る前（初期設定）でも、サイトの印を作れる（ログインの画面を開ける）', strpos($validStampOutput, 'STAMP') !== false, $validStampOutput);

// ---- よくある質問の CSV（G-25 §2.19）: 誤りの行・上限・記録の CSV に入れない記録 ----
require_once APP_DIR . '/lib/faq_csv.php';
$faqCsvText = function (array $rowList): string {
    $lineList = [implode(',', FAQ_CSV_COLUMNS)];
    foreach ($rowList as $cellList) {
        $lineList[] = implode(',', array_map(function (string $cellText): string { return '"' . str_replace('"', '""', $cellText) . '"'; }, $cellList));
    }
    return implode("\r\n", $lineList) . "\r\n";
};
$loginLabel = FAQ_CATEGORY_LABELS['login'];
$entryRow = function (int $faqId, string $question, string $answer, string $deleteMark = '') use ($loginLabel, $now): array {
    return [FAQ_CSV_KIND_ENTRY, (string) $faqId, $loginLabel, $question, $answer, $deleteMark, $now, '', '', ''];
};
$newRow = function (string $question, string $answer, string $deleteMark = '') use ($loginLabel): array {
    return ['', '', $loginLabel, $question, $answer, $deleteMark, '', '', '', ''];
};
db_exec('DELETE FROM faq_entries');
db_exec('INSERT INTO faq_entries (id, category, question, answer, sort_order, created_at, updated_at) VALUES (1, ?, ?, ?, 1, ?, ?), (2, ?, ?, ?, 2, ?, ?)',
    ['login', '質問一', '答え一', $now, $now, 'login', '質問二', '答え二', $now, $now]);
$faqPlan = faq_csv_plan($faqCsvText([$entryRow(1, '質問一', '答え一'), $entryRow(2, '質問二', '答え二')]) . "\r\n\r\n");
$check('G-25', 'CSV: 空の行は読み飛ばす（誤りにしない）', $faqPlan['errorTextList'] === [] && $faqPlan['unchangedCount'] === 2, $faqPlan['errorTextList']);
$faqPlan = faq_csv_plan($faqCsvText([$entryRow(1, '質問一', '答え一'), ['a', 'b', 'c']]));
$check('G-25', 'CSV: 列の数が違う行は誤り', $contains($faqPlan['errorTextList'], '3 行目: 列の数が違います'), $faqPlan['errorTextList']);
$faqPlan = faq_csv_plan($faqCsvText([$entryRow(1, '質問一', '答え一'), $entryRow(1, '質問一', '答え一')]));
$check('G-25', 'CSV: 同じ番号が 2 回出ていれば誤り', $contains($faqPlan['errorTextList'], '番号 1 が 2 回出ています'), $faqPlan['errorTextList']);
$faqPlan = faq_csv_plan($faqCsvText([$newRow('新しい質問', '新しい答え', '1')]));
$check('G-25', 'CSV: 番号の無い行に「消す」を付けると誤り（足しもしない）', $contains($faqPlan['errorTextList'], '番号の無い行は消せません') && $faqPlan['addList'] === [], $faqPlan['errorTextList']);
$faqPlan = faq_csv_plan($faqCsvText([$newRow('', '答えだけ')]));
$check('G-25', 'CSV: 質問が空で答えのある行は、質問が要る誤り（同じ質問の確かめは空の質問を数えない）',
    $contains($faqPlan['errorTextList'], '2 行目: 質問を入れてください') && count($faqPlan['errorTextList']) === 1 && $faqPlan['addList'] === [], $faqPlan['errorTextList']);
$faqPlan = faq_csv_plan($faqCsvText([$entryRow(1, '質問一', '答え一'), $newRow('質問二', '別の答え')]));
$check('G-25', 'CSV: CSV に無い（残る）質問と同じ質問を足す行は誤り（画面は誤りがあれば取り込まない）', $faqPlan['errorTextList'] === ['3 行目: 同じ質問（番号 2）がもう載っています'], $faqPlan['errorTextList']);
$faqPlan = faq_csv_plan($faqCsvText([$entryRow(1, '質問一', '答え一'), $newRow('質問三', '答え三')]));
$check('G-25', 'CSV: CSV に無い質問と違う質問なら足せる', $faqPlan['errorTextList'] === [] && count($faqPlan['addList']) === 1, $faqPlan['errorTextList']);
tx(function () use ($now): void {
    for ($faqNumber = 3; $faqNumber <= FAQ_MAX_COUNT; $faqNumber++) {
        db_exec('INSERT INTO faq_entries (id, category, question, answer, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$faqNumber, 'login', '上限の質問' . $faqNumber, '答え', $faqNumber, $now, $now]);
    }
});
$faqPlan = faq_csv_plan($faqCsvText([$newRow('上限を超える質問', '答え')]));
$check('G-25', 'CSV: 取り込むと上限（' . FAQ_MAX_COUNT . ' 件）を超えるときは誤り', $contains($faqPlan['errorTextList'], 'よくある質問は ' . FAQ_MAX_COUNT . ' 件までです（取り込むと ' . (FAQ_MAX_COUNT + 1) . ' 件になります）'), $faqPlan['errorTextList']);
$faqPlan = faq_csv_plan($faqCsvText([$entryRow(1, '質問一', '答え一', '1'), $newRow('上限ちょうどの質問', '答え')]));
$check('G-25', 'CSV: 1 件消して 1 件足すなら、上限ちょうどで取り込める', $faqPlan['errorTextList'] === [] && count($faqPlan['deleteList']) === 1 && count($faqPlan['addList']) === 1, $faqPlan['errorTextList']);
db_exec('DELETE FROM faq_entries');
$faqLogRow = function (?string $distressLevel, string $questionText): array {
    return ['distress_level' => $distressLevel, 'category' => null, 'asked_at' => '2026-11-01 10:00:00', 'role' => 'member', 'screen_code' => 'G-03', 'question_text' => $questionText,
        'result' => 'answered', 'is_ignored' => 0, 'shown_faq_ids' => '1', 'feedback' => '', 'feedback_faq_id' => null, 'conversation_id' => 'c1'];
};
$faqLogCsvRows = faq_csv_log_rows([$faqLogRow('concern', '知らせる質問'), $faqLogRow(null, 'ふつうの質問')]);
$check('G-25', '記録の CSV: 知らせる質問（心の不調など）の記録は入れず、ふつうの記録は入れる', count($faqLogCsvRows) === 1 && $faqLogCsvRows[0][3] === 'ふつうの質問', $faqLogCsvRows);

// ---- 送信の番号が同時に来たとき（写しで試す） ----
$check('共通', '検索・権限の関数のあとも DB は正しい', db()->query('PRAGMA integrity_check')->fetchColumn() === 'ok');

echo $ngCount === 0 ? "\nすべて OK\n" : "\nNG {$ngCount}\n";
exit($ngCount === 0 ? 0 : 1);
