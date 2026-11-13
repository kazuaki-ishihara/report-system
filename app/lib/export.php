<?php

declare(strict_types=1);

// ====================================================================
// 横並び・まとめのデータと、出力（印刷用画面・xlsx・CSV）（詳細設計書 G-09・G-10）
// ====================================================================

/**
 * まとめの判定に使う項目と値（設定で変えられる。初期の様式のコード）
 */
function summary_rules(): array
{
    $c = $GLOBALS['APP_CONFIG']['summary_rules'] ?? null;
    if (is_array($c)) {
        return $c;
    }
    return [
        'ending'   => ['label' => '終了が近い案件', 'field' => 'continuation', 'values' => ['ending'], 'month_field' => 'end_month', 'months' => 3],
        'staffing' => ['label' => '人員の動きがある現場', 'field' => 'staffing', 'values' => ['increase', 'decrease']],
        'trouble'  => ['label' => '遅延・問題がある現場', 'field' => 'progress', 'values' => ['delayed', 'other']],
    ];
}

/**
 * 課・期間の報告を横並びの行にする（提出済み・確認完了のみ）。
 * 行 = 人 × 案件ブロック。$withFree: 自由記述を復号して入れる。$withRestricted: 「限定」を入れる。
 * 返り値: ['columns' => [項目定義...], 'rows' => [[...]], 'reports' => n]
 */
function compare_dataset(int $deptId, string $fromPeriod, string $toPeriod, bool $withFree, bool $withRestricted, bool $listOnly, ?array $viewer = null): array
{
    $reports = db_all(
        "SELECT r.*, u.display_name FROM report_records r JOIN users u ON u.id = r.user_id
          WHERE r.department_id = ? AND r.period BETWEEN ? AND ? AND r.status IN ('submitted', 'confirmed') AND r.deleted_at IS NULL AND r.is_legacy = 0
          ORDER BY r.period, u.display_name",
        [$deptId, $fromPeriod, $toPeriod]
    );
    // 見る人を渡したときは、その人が見られる報告だけ（班ごとの課で担当の班だけを見る確認者など）
    if ($viewer !== null) {
        $reports = array_values(array_filter($reports, function (array $reportRow) use ($viewer): bool {
            return can_view_report($viewer, $reportRow);
        }));
    }
    // 列: 課の今の様式の項目（一覧に出す設定のもの、または出力するもの）
    $columns = [];
    foreach (form_template_fields($deptId) as $f) {
        if ($f['type'] === 'project') {
            continue;
        }
        if ($listOnly && !$f['show_in_list'] && !form_is_free_text($f)) {
            continue;
        }
        if (!$listOnly && !$f['include_in_export']) {
            continue;
        }
        if ($f['conf'] === 'restricted' && !$withRestricted) {
            continue;
        }
        if (form_is_free_text($f) && !$withFree) {
            continue;
        }
        $columns[] = $f;
    }
    $rows = [];
    foreach ($reports as $r) {
        $rid = (int) $r['id'];
        $snap = [];
        foreach (form_snapshot_fields($rid) as $sf) {
            $snap[$sf['id']] = $sf;
        }
        // 選択式は平文なのでそのまま、自由記述・限定は必要なときだけ復号する
        $raw = [];
        foreach (db_all('SELECT * FROM report_field_values WHERE report_id = ?', [$rid]) as $v) {
            $raw[(int) $v['report_project_id']][(int) $v['field_id']] = $v;
        }
        $blocks = report_blocks($rid);
        if (!$blocks) {
            $blocks = [['id' => 0, 'code' => '—', 'alias' => '']];
        }
        foreach ($blocks as $bi => $b) {
            $row = ['report_id' => $rid, 'rp_id' => (int) $b['id'], 'first' => $bi === 0, 'period' => $r['period'], 'name' => $r['display_name'], 'status' => $r['status'], 'project' => $b['code'], 'cells' => []];
            foreach ($columns as $f) {
                $rpId = $f['scope'] === 'report' ? 0 : (int) $b['id'];
                if ($f['scope'] === 'report' && $bi > 0) {
                    $row['cells'][$f['code']] = ['text' => '', 'value' => ''];
                    continue;
                }
                $v = $raw[$rpId][$f['id']] ?? null;
                $value = '';
                if ($v !== null) {
                    $value = (string) $v['value_text'];
                    if ((int) $v['is_encrypted'] === 1) {
                        $value = (string) dec($value, form_aad($rid, $f['id'], $rpId));
                    }
                }
                $def = $snap[$f['id']] ?? $f;
                $row['cells'][$f['code']] = ['text' => export_text($def, $value), 'value' => $value];
            }
            $rows[] = $row;
        }
    }
    return ['columns' => $columns, 'rows' => $rows, 'reports' => count($reports)];
}

/** 値を表示用の文字にする（選択肢は表示名） */
function export_text(array $f, string $value): string
{
    if ($value === '') {
        return '';
    }
    switch ($f['type']) {
        case 'select':
        case 'radio':
            return form_option_label($f, $value);
        case 'checkbox':
            return $value === '1' ? 'はい' : '';
        case 'multi_check':
            return implode('、', array_map(function ($c) use ($f) { return form_option_label($f, (string) $c); }, (array) json_decode($value, true)));
        case 'table':
            $out = [];
            foreach ((array) json_decode($value, true) as $row) {
                $out[] = implode(' / ', array_map('strval', (array) $row));
            }
            return implode("\n", $out);
        default:
            return $value;
    }
}

/** まとめ（終了が近い案件など）を作る */
function compare_summary(array $data, string $period): array
{
    $out = [];
    foreach (summary_rules() as $key => $rule) {
        $items = [];
        foreach ($data['rows'] as $row) {
            $v = $row['cells'][$rule['field']]['value'] ?? '';
            $hit = in_array($v, $rule['values'], true);
            if (!$hit && isset($rule['month_field'])) {
                $m = $row['cells'][$rule['month_field']]['value'] ?? '';
                if ($m !== '' && $m >= $period && $m <= period_shift($period, (int) $rule['months'])) {
                    $hit = true;
                }
            }
            if ($hit) {
                $items[] = $row;
            }
        }
        $out[$key] = ['label' => $rule['label'], 'items' => $items];
    }
    // 選択式の項目ごとの件数
    $counts = [];
    foreach ($data['columns'] as $f) {
        if (!in_array($f['type'], ['select', 'radio'], true)) {
            continue;
        }
        $c = [];
        foreach ($data['rows'] as $row) {
            $t = $row['cells'][$f['code']]['text'] ?? '';
            if ($t !== '') {
                $c[$t] = ($c[$t] ?? 0) + 1;
            }
        }
        $counts[$f['label']] = $c;
    }
    return ['groups' => $out, 'counts' => $counts];
}

/** 数式の注入を防ぐ（= + - @ タブ 改行で始まる値の先頭に '） */
function export_safe_cell(string $s): string
{
    if ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false) {
        return "'" . $s;
    }
    return $s;
}

function is_mobile_request(): bool
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return preg_match('/iPhone|iPod|Android.*Mobile|Mobile Safari|Windows Phone/i', $ua) === 1;
}

/** CSV（UTF-8 BOM 付き、CRLF、すべて " で囲む） */
function export_csv(array $headerLines, array $header, array $rows): string
{
    $line = function (array $cells): string {
        return implode(',', array_map(function ($c) {
            return '"' . str_replace('"', '""', export_safe_cell((string) $c)) . '"';
        }, $cells)) . "\r\n";
    };
    $out = "\xEF\xBB\xBF";
    foreach ($headerLines as $hl) {
        $out .= $line([$hl]);
    }
    $out .= $line($header);
    foreach ($rows as $r) {
        $out .= $line($r);
    }
    return $out;
}

// ---- xlsx（XML を zip にまとめる。zip は自前で書く: 無圧縮の格納のみ） --------------

function zip_store(array $files): string
{
    $data = '';
    $central = '';
    $offset = 0;
    $time = (int) ((date('H') << 11) | (date('i') << 5) | (int) (date('s') / 2));
    $date = (int) (((date('Y') - 1980) << 9) | (date('n') << 5) | date('j'));
    foreach ($files as $name => $content) {
        $crc = crc32($content);
        $len = strlen($content);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $time, $date, $crc, $len, $len, strlen($name), 0) . $name;
        $data .= $local . $content;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $time, $date, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        $offset += strlen($local) + $len;
    }
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), $offset, 0);
    return $data . $central . $end;
}

function xlsx_col(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}

/** 1 シートの xlsx を作る。$headerLines は上に入れる見出し（社外秘・出力者・日時） */
function export_xlsx(string $sheetName, array $headerLines, array $header, array $rows): string
{
    $x = function (string $s): string {
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };
    $all = [];
    foreach ($headerLines as $hl) {
        $all[] = ['cells' => [$hl], 'style' => 1];
    }
    $all[] = ['cells' => [], 'style' => 0];
    $all[] = ['cells' => $header, 'style' => 2];
    foreach ($rows as $r) {
        $all[] = ['cells' => $r, 'style' => 0];
    }
    $sheetRows = '';
    foreach ($all as $ri => $row) {
        $sheetRows .= '<row r="' . ($ri + 1) . '">';
        foreach ($row['cells'] as $ci => $cell) {
            // すべて文字列として書く（数式として動かない）
            $sheetRows .= '<c r="' . xlsx_col($ci) . ($ri + 1) . '" t="inlineStr"' . ($row['style'] ? ' s="' . $row['style'] . '"' : ' s="3"') . '><is><t xml:space="preserve">' . $x((string) $cell) . '</t></is></c>';
        }
        $sheetRows .= '</row>';
    }
    $headerFooter = '<headerFooter><oddHeader>&amp;L' . $x('社外秘') . '&amp;R' . $x($headerLines[2] ?? '') . '</oddHeader><oddFooter>&amp;C&amp;P / &amp;N</oddFooter></headerFooter>';
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $x(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3"><font><sz val="10"/><name val="Meiryo UI"/></font><font><b/><sz val="10"/><color rgb="FFCF222E"/><name val="Meiryo UI"/></font><font><b/><sz val="10"/><name val="Meiryo UI"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEAEEF2"/></patternFill></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs>'
            . '</styleSheet>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $sheetRows . '</sheetData>'
            . '<pageSetup orientation="landscape" paperSize="9"/>' . $headerFooter . '</worksheet>',
    ];
    return zip_store($files);
}
