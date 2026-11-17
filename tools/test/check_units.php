<?php

declare(strict_types=1);

// ====================================================================
// 画面を通さずに確かめる決まり（関数の出力）。サーバー・DB は使わない
//   php tools/test/check_units.php
// G-10: 出力の CSV で、= + - @ で始まる値が数式として動かない。xlsx の中身が XML として正しく、
//       < & " などが文字のまま入る（設計書 G-10 §9 の「自動の検証にまだないもの」）。
// ====================================================================

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_DIR . '/lib/export.php';

$ngCount = 0;
$check = function (string $screenCode, string $name, bool $isOk) use (&$ngCount): void {
    echo ($isOk ? 'OK  ' : 'NG  ') . '[' . $screenCode . '] ' . $name . "\n";
    if (!$isOk) {
        $ngCount++;
    }
};

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

echo $ngCount === 0 ? "すべて OK\n" : "NG {$ngCount} 件\n";
exit($ngCount === 0 ? 0 : 1);
