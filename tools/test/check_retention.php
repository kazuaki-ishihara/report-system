<?php

declare(strict_types=1);

// ====================================================================
// 保管期限による削除の確認（使い捨ての DB で行う）
//   php tools/test/check_retention.php
// 古い日付の報告を作り、retention_run() のあとに、自由記述が消え選択式が残ること、
// 古い報告と期限を過ぎた削除データが消えることを確かめる。
// ====================================================================

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../../app/bootstrap.php';

$tmp = sys_get_temp_dir() . '/mr_retention_' . getmypid() . '.sqlite';
@unlink($tmp);
$GLOBALS['APP_CONFIG']['db_path'] = $tmp;
crypto_generate_key();
tx(function (PDO $pdo) {
    schema_ensure($pdo);
});

$ng = 0;
$check = function (string $name, bool $ok) use (&$ng): void {
    echo ($ok ? 'OK  ' : 'NG  ') . $name . "\n";
    if (!$ok) {
        $ng++;
    }
};

$now = now();
db_exec("INSERT INTO users (username, password_hash, created_at, login_id, display_name) VALUES ('u1', 'x', ?, 'u1', 'U1')", [$now]);
db_exec("INSERT INTO report_fields (code, label, field_type, field_scope, created_at, updated_at) VALUES ('memo', 'メモ', 'multi_text', 'report', ?, ?)", [$now, $now]);
db_exec("INSERT INTO report_fields (code, label, field_type, field_scope, created_at, updated_at) VALUES ('phase', 'フェーズ', 'select', 'report', ?, ?)", [$now, $now]);
$fields = form_template_fields(null);

$make = function (string $period, string $status, ?string $confirmedAt, ?string $deletedAt) use ($fields, $now): int {
    db_exec(
        'INSERT INTO report_records (user_id, period, status, confirmed_at, deleted_at, created_at, updated_at) VALUES (1, ?, ?, ?, ?, ?, ?)',
        [$period, $status, $confirmedAt, $deletedAt, $now, $now]
    );
    $rid = (int) db()->lastInsertId();
    form_write_snapshot($rid, $fields);
    foreach ($fields as $f) {
        $v = $f['code'] === 'memo' ? 'メモの本文' : 'build';
        $enc = form_is_encrypted($f);
        db_exec('INSERT INTO report_field_values (report_id, field_id, report_project_id, value_text, is_encrypted, updated_at) VALUES (?, ?, 0, ?, ?, ?)',
            [$rid, $f['id'], $enc ? enc($v, form_aad($rid, $f['id'], 0)) : $v, $enc ? 1 : 0, $now]);
    }
    return $rid;
};

$old = date('Y-m-d H:i:s', strtotime('-13 months'));
$recent = date('Y-m-d H:i:s', strtotime('-1 month'));
$a = $make(date('Y-m', strtotime('-13 months')), 'confirmed', $old, null);   // 自由記述を消す対象
$b = $make(date('Y-m', strtotime('-1 month')), 'confirmed', $recent, null);   // まだ残す
$c = $make(date('Y-m', strtotime('-4 years')), 'confirmed', $old, null);      // 報告ごと消す
$d = $make(date('Y-m'), 'draft', null, date('Y-m-d H:i:s', strtotime('-31 days'))); // 削除データ（期限切れ）
$e = $make(date('Y-m', strtotime('-2 months')), 'draft', null, date('Y-m-d H:i:s', strtotime('-5 days'))); // 削除データ（まだ戻せる）
db_exec('UPDATE report_records SET copied_from_id = ? WHERE id = ?', [$c, $a]);

retention_run();

$val = function (int $rid, string $code): ?string {
    $v = db_one('SELECT v.value_text FROM report_field_values v JOIN report_fields f ON f.id = v.field_id WHERE v.report_id = ? AND f.code = ?', [$rid, $code]);
    return $v === null ? null : (string) $v['value_text'];
};
$check('確認完了から 12 か月を過ぎた報告の自由記述が消える', $val($a, 'memo') === '');
$check('同じ報告の選択式の値は残る', $val($a, 'phase') === 'build');
$check('消した日時が記録される', db_val('SELECT free_text_purged_at FROM report_records WHERE id = ?', [$a]) !== null);
$check('新しい報告の自由記述は残る', $val($b, 'memo') !== '' && $val($b, 'memo') !== null);
$check('保管年数を過ぎた報告は消える（引き継ぎ元の参照があっても）', db_val('SELECT 1 FROM report_records WHERE id = ?', [$c]) === null);
$check('期限を過ぎた削除データは完全に消える', db_val('SELECT 1 FROM report_records WHERE id = ?', [$d]) === null);
$check('期限内の削除データは残る', db_val('SELECT 1 FROM report_records WHERE id = ?', [$e]) !== null);
$check('消えた報告の値も消える', (int) db_val('SELECT COUNT(*) FROM report_field_values WHERE report_id IN (?, ?)', [$c, $d]) === 0);

@unlink($tmp);
@unlink($tmp . '-wal');
@unlink($tmp . '-shm');
echo ($ng === 0 ? "すべて OK\n" : "NG {$ng} 件\n");
exit($ng === 0 ? 0 : 1);
