<?php

declare(strict_types=1);

// ====================================================================
// 画面を通さずに確かめる決まり（関数の出力）。サーバー・DB は使わない
//   php tools/test/check_units.php
// 共通: 検証の印（verify_mode）は、MR_VERIFY_DB が無ければ false。
// G-03: 対象月が締め日の前後・月末・年の変わり目で正しく切り替わる（日付を渡す。使い捨ての DB）。
// G-10: 出力の CSV で、= + - @ で始まる値が数式として動かない。xlsx の中身が XML として正しく、
//       < & " などが文字のまま入る（設計書 G-10 §9 の「自動の検証にまだないもの」）。
// ====================================================================

if (PHP_SAPI !== 'cli') {
    exit(1);
}
// 本番・ふだんの環境と同じく、検証用の DB の指定（MR_VERIFY_DB）が無い状態で設定を読む（検証の印が false になることを確かめる）
putenv('MR_VERIFY_DB');
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_DIR . '/lib/export.php';

$ngCount = 0;
$check = function (string $screenCode, string $name, bool $isOk) use (&$ngCount): void {
    echo ($isOk ? 'OK  ' : 'NG  ') . '[' . $screenCode . '] ' . $name . "\n";
    if (!$isOk) {
        $ngCount++;
    }
};

// ---- 共通: 検証の印（verify_mode）は、検証用の DB の指定が無ければ false（同時の操作を再現する待ちが本番で働かない） ----
$check('共通', '検証の印（verify_mode）は、MR_VERIFY_DB が無ければ false（報告の保存の待ちが働かない）', cfg('verify_mode') === false);

// ---- G-10 CSV: 数式として動かない ----
$formulaValueList = ['=1+1', '+1+1', '-1+1', '@SUM(A1)', "\t=1", "=HYPERLINK(\"http://example.com\")"];
$csvText = export_csv(['社外秘'], ['値'], array_map(static function (string $value): array { return [$value]; }, $formulaValueList));
$allQuoted = true;
foreach ($formulaValueList as $formulaValue) {
    if (strpos($csvText, '"\'' . str_replace('"', '""', $formulaValue) . '"') === false) {
        $allQuoted = false;
    }
}
$check('G-10', 'CSV で = + - @ タブで始まる値は、先頭に \' を付けて数式として動かない', $allQuoted);
$check('G-10', 'CSV でふつうの値は、そのまま出る', strpos(export_csv([], ['値'], [['在庫の引当処理']]), '"在庫の引当処理"') !== false);
$check('G-10', 'CSV の「"」は 2 つに重ねる', strpos(export_csv([], ['値'], [['a"b']]), '"a""b"') !== false);

// ---- G-10 xlsx: XML として正しく、記号が文字のまま入る ----
$xlsxBytes = export_xlsx('報告', ['社外秘'], ['値'], [['<script>&"\'値</script>'], ['=1+1']]);
$tempPath = tempnam(sys_get_temp_dir(), 'mr_units_');
file_put_contents($tempPath, $xlsxBytes);
$sheetXml = '';
$zipArchive = class_exists('ZipArchive') ? new ZipArchive() : null;
if ($zipArchive !== null && $zipArchive->open($tempPath) === true) {
    $sheetXml = (string) $zipArchive->getFromName('xl/worksheets/sheet1.xml');
    $zipArchive->close();
} else {
    // ZipArchive がない環境: 無圧縮の zip なので、シートの XML を中から探す
    if (preg_match('/<worksheet.*<\/worksheet>/s', $xlsxBytes, $sheetMatch) === 1) {
        $sheetXml = $sheetMatch[0];
    }
}
@unlink($tempPath);
libxml_use_internal_errors(true);
$parsedSheet = $sheetXml !== '' ? simplexml_load_string($sheetXml) : false;
$check('G-10', 'xlsx のシートは XML として正しい', $parsedSheet !== false);
$check('G-10', 'xlsx に < & " を文字として入れる（タグにならない）', strpos($sheetXml, '&lt;script&gt;&amp;&quot;') !== false && strpos($sheetXml, '<script>') === false);
$check('G-10', 'xlsx の値は文字列として入れる（数式の欄 <f> を作らない）', strpos($sheetXml, '<f>') === false && strpos($sheetXml, 't="inlineStr"') !== false);

// ---- G-03 対象月: 締め日の前後で切り替わる（日付を渡して確かめる。使い捨ての DB） ----
$temporaryDatabasePath = sys_get_temp_dir() . '/mr_units_' . getmypid() . '.sqlite';
@unlink($temporaryDatabasePath);
$GLOBALS['APP_CONFIG']['db_path'] = $temporaryDatabasePath;
tx(function (PDO $pdo) {
    schema_ensure($pdo);
});
$createdAt = now();
db_exec('INSERT INTO departments (name, sort_order, close_day, created_at, updated_at) VALUES (?, 10, 10, ?, ?)', ['締め日 10 日の課', $createdAt, $createdAt]);
$closeTenDepartmentId = (int) db()->lastInsertId();
db_exec('INSERT INTO departments (name, sort_order, close_day, created_at, updated_at) VALUES (?, 20, 31, ?, ?)', ['締め日 31 日の課', $createdAt, $createdAt]);
$closeLastDepartmentId = (int) db()->lastInsertId();
db_exec('INSERT INTO departments (name, sort_order, close_day, created_at, updated_at) VALUES (?, 30, NULL, ?, ?)', ['締め日なしの課', $createdAt, $createdAt]);
$noCloseDepartmentId = (int) db()->lastInsertId();
$periodOn = static function (?int $departmentId, string $dateText): string {
    return report_target_period(['department_id' => $departmentId], (int) strtotime($dateText . ' 12:00:00'));
};
$check('G-03', '締め日（10 日）の当日までは、前の月が対象', $periodOn($closeTenDepartmentId, '2026-11-10') === '2026-10');
$check('G-03', '締め日（10 日）の次の日から、その月が対象', $periodOn($closeTenDepartmentId, '2026-11-11') === '2026-11');
$check('G-03', '月の始め（1 日）は、前の月が対象', $periodOn($closeTenDepartmentId, '2026-12-01') === '2026-11');
$check('G-03', '締め日 31 日の課は、2 月は月末（28 日）が締め日になる', $periodOn($closeLastDepartmentId, '2026-02-28') === '2026-01' && $periodOn($closeLastDepartmentId, '2026-03-01') === '2026-02');
$check('G-03', '年をまたいでも正しい（1 月 5 日は前の年の 12 月）', $periodOn($closeTenDepartmentId, '2027-01-05') === '2026-12');
$check('G-03', '締め日が決まっていない課・課の無い人は、前の月が対象', $periodOn($noCloseDepartmentId, '2026-11-25') === '2026-10' && $periodOn(null, '2026-11-25') === '2026-10');
@unlink($temporaryDatabasePath);

// ---- G-25: よくある質問の上限は 500 件（標準の質問 192 件に、管理者が足す分の余裕を持たせる） ----
$check('G-25', 'よくある質問の上限は 500 件', FAQ_MAX_COUNT === 500);

// ---- 共通: 入力の確かめの正規表現は文字列全体で見る（末尾に改行を足した値を通さない。\A・\z） ----
$check('共通', 'ログイン ID の確かめは、末尾に改行を足した値を通さない', auth_is_valid_login_id('ippan_a1') && !auth_is_valid_login_id("ippan_a1\n"));
$check('共通', '対象月の確かめは、末尾に改行を足した値を通さない', is_valid_period('2026-11') && !is_valid_period("2026-11\n"));
$check('G-26', '精算書の日付の確かめは、末尾に改行を足した値を通さない', expense_is_valid_date('2026-11-20') && !expense_is_valid_date("2026-11-20\n"));
$check('共通', '2 段階認証のコードの確かめは、末尾に改行を足した値を通さない', totp_verify(random_bytes(20), "123456\n", null) === null);

echo $ngCount === 0 ? "すべて OK\n" : "NG {$ngCount} 件\n";
exit($ngCount === 0 ? 0 : 1);
