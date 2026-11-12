<?php

declare(strict_types=1);

// ====================================================================
// 初期の様式を登録する（基本設計書 §9.5。コマンドラインで実行）
//   php app/bin/seed_template.php [--dept-a=<課ID>] [--dept-b=<課ID>]
// 共通項目は必ず登録する。課別項目は、課の ID を指定したときだけ登録する。
// 同じコードの項目があれば何もしない（何度実行しても同じ結果）。
// ====================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';

$opt = getopt('', ['dept-a:', 'dept-b:']);

$common = [
    ['project', '案件', 'project', 'project', 'normal', true, true, true, [], [], '参画している案件を選びます。複数の案件に参画しているときは「案件を追加」で足します。'],
    ['duties', '担当内容', 'multi_text', 'project', 'internal', false, true, false, ['max_length' => 1000], [], ''],
    ['phase', '作業フェーズ', 'select', 'project', 'normal', false, true, true, [], [
        ['requirements', '要件定義'], ['basic_design', '基本設計'], ['detailed_design', '詳細設計'], ['build_unit', '製造・単体'],
        ['integration', '結合テスト'], ['system_test', '総合テスト'], ['operation', '運用・保守'], ['other', 'その他']], ''],
    ['progress', '進捗', 'radio', 'project', 'normal', true, true, true, [], [
        ['on_track', '問題なく進行'], ['delayed', '遅延あり'], ['other', 'その他']], ''],
    ['progress_note', '進捗の補足', 'multi_text', 'project', 'internal', false, false, false,
        ['max_length' => 1000, 'show_when' => ['field' => 'progress', 'values' => ['delayed', 'other']]], [], '遅延の理由や見込みを書きます。'],
    ['continuation', '案件の継続見込み', 'radio', 'project', 'internal', true, true, true, [], [
        ['continuing', '当分継続'], ['ending', '終了の話あり'], ['undecided', '未定']], ''],
    ['end_month', '終了予定', 'month', 'project', 'internal', false, true, true, [], [], '分かる範囲で入れます。'],
    ['staffing', '人員の動き', 'radio', 'project', 'internal', false, true, true, [], [
        ['increase', '増員の話あり'], ['decrease', '減員の話あり'], ['none', '特になし'], ['unknown', '不明']], ''],
    ['staffing_note', '人員の動きの詳細', 'multi_text', 'project', 'restricted', false, false, false,
        ['max_length' => 1000, 'show_when' => ['field' => 'staffing', 'values' => ['increase', 'decrease']]], [], ''],
];
$deptA = [
    ['trouble_note', '問題・悩み事', 'multi_text', 'report', 'internal', false, false, false, ['max_length' => 2000], [], '困っていることがあれば書きます（なければ空欄で構いません）。'],
    ['system_type', '業務システムの種類', 'select', 'project', 'normal', false, true, true, [], [
        ['inventory', '在庫管理'], ['sales', '販売管理'], ['production', '生産管理'], ['accounting', '会計'], ['hr', '人事・給与'], ['other', 'その他']], ''],
    ['after_end_work', '終了後の作業', 'radio', 'project', 'internal', false, true, false, [], [
        ['yes', 'あり'], ['no', 'なし'], ['unknown', '不明']], ''],
];
$deptB = [
    ['client_recruiting', '客先の追加要員の募集', 'radio', 'project', 'restricted', false, false, false, [], [
        ['heard', '聞いている'], ['not_heard', '聞いていない']], ''],
];

$insert = function (array $defs, ?int $deptId): array {
    $done = [];
    $sort = 0;
    foreach ($defs as $d) {
        [$code, $label, $type, $scope, $conf, $required, $carry, $list, $settings, $options, $help] = $d;
        $sort += 10;
        if (db_val('SELECT 1 FROM report_fields WHERE code = ?', [$code]) !== null) {
            continue;
        }
        db_exec(
            'INSERT INTO report_fields (code, label, field_type, department_id, field_scope, confidentiality, sort_order, is_active, is_required, help_text, settings_json, carry_over, show_in_list, include_in_export, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, 1, ?, ?)',
            [$code, $label, $type, $deptId, $scope, $conf, $sort, $required ? 1 : 0, $help, json_encode($settings, JSON_UNESCAPED_UNICODE),
             $carry ? 1 : 0, $list ? 1 : 0, now(), now()]
        );
        $fid = (int) db()->lastInsertId();
        $i = 0;
        foreach ($options as $o) {
            $i += 10;
            db_exec('INSERT INTO report_field_options (field_id, code, label, sort_order, is_active) VALUES (?, ?, ?, ?, 1)', [$fid, $o[0], $o[1], $i]);
        }
        $done[] = $code;
    }
    return $done;
};

$result = tx(function () use ($insert, $common, $deptA, $deptB, $opt) {
    $out = ['共通' => $insert($common, null)];
    foreach (['dept-a' => $deptA, 'dept-b' => $deptB] as $k => $defs) {
        if (isset($opt[$k])) {
            $id = (int) $opt[$k];
            if (db_one('SELECT id FROM departments WHERE id = ?', [$id]) === null) {
                throw new RuntimeException('課がありません: ' . $id);
            }
            $out[$k] = $insert($defs, $id);
        }
    }
    return $out;
});

foreach ($result as $k => $codes) {
    echo $k . ': ' . ($codes ? implode(', ', $codes) : '（登録済み）') . "\n";
}
