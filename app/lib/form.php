<?php

declare(strict_types=1);

// ====================================================================
// 様式の仕組み（項目マスタから入力画面を組み立て、確認し、表示する）
// 詳細設計書 共通編 §9、G-04 §4、G-12
// ====================================================================

const FIELD_TYPES = [
    'single_text' => '1 行テキスト',
    'multi_text'  => '複数行テキスト',
    'date'        => '日付',
    'month'       => '年月',
    'number'      => '数値',
    'select'      => 'プルダウン',
    'radio'       => 'ラジオボタン',
    'checkbox'    => 'チェック 1 つ',
    'multi_check' => 'チェックボックス（複数）',
    'table'       => '繰り返し行（表）',
    'project'     => '案件',
];
const FIELD_CONF = ['normal' => '通常', 'internal' => '社外秘', 'restricted' => '限定'];
const TABLE_COLUMN_TYPES = ['single_text', 'multi_text', 'number', 'date', 'select'];
const CHOICE_TYPES = ['select', 'radio', 'multi_check'];

// ---- 定義の読み込み --------------------------------------------------

function form_row_to_def(array $r, array $options): array
{
    $settings = json_decode((string) $r['settings_json'], true);
    return [
        'id'          => (int) $r['id'],
        'code'        => (string) $r['code'],
        'label'       => (string) $r['label'],
        'type'        => (string) $r['field_type'],
        'scope'       => (string) $r['field_scope'],
        'conf'        => (string) $r['confidentiality'],
        'required'    => (int) $r['is_required'] === 1,
        'settings'    => is_array($settings) ? $settings : [],
        'options'     => $options,
        'help'        => (string) ($r['help_text'] ?? ''),
        'placeholder' => (string) ($r['placeholder'] ?? ''),
        'carry'       => (int) ($r['carry_over'] ?? 0) === 1,
        'dept'        => isset($r['department_id']) && $r['department_id'] !== null ? (int) $r['department_id'] : null,
        'sort'        => (int) $r['sort_order'],
        'active'      => (int) ($r['is_active'] ?? 1) === 1,
        'show_in_list'      => (int) ($r['show_in_list'] ?? 0) === 1,
        'include_in_export' => (int) ($r['include_in_export'] ?? 1) === 1,
    ];
}

function form_field_options(int $fieldId, bool $activeOnly): array
{
    $rows = db_all(
        'SELECT code, label, is_active FROM report_field_options WHERE field_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, id',
        [$fieldId]
    );
    $out = [];
    foreach ($rows as $o) {
        $out[] = ['code' => (string) $o['code'], 'label' => (string) $o['label'], 'active' => (int) $o['is_active'] === 1];
    }
    return $out;
}

/** 課の様式（共通項目＋課別項目。有効なものだけ） */
function form_template_fields(?int $deptId): array
{
    $rows = db_all(
        'SELECT * FROM report_fields WHERE is_active = 1 AND (department_id IS NULL OR department_id = ?)
          ORDER BY (department_id IS NOT NULL), sort_order, id',
        [$deptId ?? 0]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[] = form_row_to_def($r, form_field_options((int) $r['id'], true));
    }
    return $out;
}

/** 報告の作成時の定義 */
function form_snapshot_fields(int $reportId): array
{
    $rows = db_all(
        'SELECT s.field_id AS id, f.code, s.label, s.field_type, s.field_scope, s.confidentiality, s.sort_order,
                s.is_required, s.settings_json, s.options_json, f.help_text, f.placeholder, f.carry_over,
                f.department_id, f.show_in_list, f.include_in_export
           FROM report_record_fields s JOIN report_fields f ON f.id = s.field_id
          WHERE s.report_id = ?
          ORDER BY (f.department_id IS NOT NULL), s.sort_order, s.field_id',
        [$reportId]
    );
    $out = [];
    foreach ($rows as $r) {
        $opts = json_decode((string) $r['options_json'], true);
        $out[] = form_row_to_def($r, is_array($opts) ? $opts : []);
    }
    return $out;
}

function form_write_snapshot(int $reportId, array $fields): void
{
    foreach ($fields as $f) {
        db_exec(
            'INSERT OR REPLACE INTO report_record_fields (report_id, field_id, label, field_type, field_scope, confidentiality, sort_order, is_required, settings_json, options_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$reportId, $f['id'], $f['label'], $f['type'], $f['scope'], $f['conf'], $f['sort'], $f['required'] ? 1 : 0,
             json_encode($f['settings'], JSON_UNESCAPED_UNICODE), json_encode($f['options'], JSON_UNESCAPED_UNICODE)]
        );
    }
}

// ---- 暗号化・注意の言葉 ------------------------------------------------

/** 暗号化して保存する項目か（自由記述・表・限定） */
function form_is_encrypted(array $f): bool
{
    return in_array($f['type'], ['single_text', 'multi_text', 'table'], true) || $f['conf'] === 'restricted';
}

function form_is_free_text(array $f): bool
{
    return in_array($f['type'], ['single_text', 'multi_text', 'table'], true);
}

function form_aad(int $reportId, int $fieldId, int $rpId): string
{
    return 'rfv:' . $reportId . ':' . $fieldId . ':' . $rpId;
}

function text_normalize(string $s): string
{
    if (function_exists('mb_convert_kana')) {
        $s = mb_convert_kana($s, 'asKV', 'UTF-8');
    }
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function warning_words(): array
{
    static $words = null;
    if ($words === null) {
        $words = [];
        foreach (db_all('SELECT word FROM warning_words') as $w) {
            $n = text_normalize((string) $w['word']);
            if ($n !== '') {
                $words[] = $n;
            }
        }
    }
    return $words;
}

function text_has_warning(string $text): bool
{
    if ($text === '') {
        return false;
    }
    $n = text_normalize($text);
    foreach (warning_words() as $w) {
        if (strpos($n, $w) !== false) {
            return true;
        }
    }
    return false;
}

// ---- 入力の確認 --------------------------------------------------------

function form_option_codes(array $f, bool $activeOnly = true): array
{
    $codes = [];
    foreach ($f['options'] as $o) {
        if (!$activeOnly || !empty($o['active'])) {
            $codes[] = (string) $o['code'];
        }
    }
    return $codes;
}

/**
 * 送られた値を確かめて、保存の形にする（共通編 §9.2）。
 * $raw は文字列か配列（multi_check・table）。$checkRequired は提出のときだけ true。
 * 誤りは $errors[$key] に入れる。
 */
function form_parse_value(array $f, $raw, bool $checkRequired, array &$errors, string $key, string $prevValue = ''): string
{
    $s = $f['settings'];
    $label = $f['label'];
    $type = $f['type'];
    $value = '';

    if ($type === 'multi_check') {
        $arr = is_array($raw) ? $raw : [];
        $allowed = form_option_codes($f);
        $prev = json_decode($prevValue, true);
        $prev = is_array($prev) ? $prev : [];
        $picked = [];
        foreach ($f['options'] as $o) {
            $code = (string) $o['code'];
            if (in_array($code, $arr, true) && (in_array($code, $allowed, true) || in_array($code, $prev, true))) {
                $picked[] = $code;
            }
        }
        foreach ($arr as $v) {
            if (!is_string($v) || !in_array($v, form_option_codes($f, false), true)) {
                $errors[$key] = '選べない値です';
            }
        }
        $value = $picked ? json_encode($picked, JSON_UNESCAPED_UNICODE) : '';
    } elseif ($type === 'table') {
        $rows = is_array($raw) ? $raw : [];
        $cols = isset($s['columns']) && is_array($s['columns']) ? $s['columns'] : [];
        $maxRows = (int) ($s['max_rows'] ?? 20);
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean = [];
            $empty = true;
            foreach ($cols as $c) {
                $ck = (string) $c['key'];
                $cv = isset($row[$ck]) && is_string($row[$ck]) ? trim($row[$ck]) : '';
                if ($cv !== '') {
                    $empty = false;
                }
                if (has_control_chars($cv, ($c['type'] ?? '') === 'multi_text') || str_len($cv) > 2000) {
                    $errors[$key] = $label . 'の「' . $c['label'] . '」に使えない文字か、長すぎる値があります';
                }
                $clean[$ck] = $cv;
            }
            if (!$empty) {
                $out[] = $clean;
            }
        }
        if (count($out) > $maxRows) {
            $errors[$key] = '行は ' . $maxRows . ' 行までにしてください';
        }
        $value = $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : '';
    } else {
        if (is_array($raw)) {
            $errors[$key] = '値が正しくありません';
            return '';
        }
        $str = trim((string) $raw);
        if ($str !== '' && preg_match('//u', $str) !== 1) {
            $errors[$key] = '値が正しくありません';
            return '';
        }
        switch ($type) {
            case 'single_text':
                $max = (int) ($s['max_length'] ?? 200);
                if (has_control_chars($str)) {
                    $errors[$key] = '改行や使えない文字が含まれています';
                } elseif (str_len($str) > $max) {
                    $errors[$key] = $max . ' 文字以内にしてください（今 ' . str_len($str) . ' 文字）';
                }
                break;
            case 'multi_text':
                $str = str_replace(["\r\n", "\r"], "\n", $str);
                $max = (int) ($s['max_length'] ?? 2000);
                if (has_control_chars($str, true)) {
                    $errors[$key] = '使えない文字が含まれています';
                } elseif (str_len($str) > $max) {
                    $errors[$key] = $max . ' 文字以内にしてください（今 ' . str_len($str) . ' 文字）';
                }
                break;
            case 'date':
                if ($str !== '') {
                    $d = DateTime::createFromFormat('!Y-m-d', $str);
                    if ($d === false || $d->format('Y-m-d') !== $str) {
                        $errors[$key] = '日付の形が正しくありません';
                    }
                }
                break;
            case 'month':
                if ($str !== '' && !is_valid_period($str)) {
                    $errors[$key] = '年月の形が正しくありません';
                }
                break;
            case 'number':
                if ($str !== '') {
                    $dec = (int) ($s['decimals'] ?? 0);
                    $pattern = $dec > 0 ? '/^-?\d{1,12}(\.\d{1,' . $dec . '})?$/' : '/^-?\d{1,12}$/';
                    if (preg_match($pattern, $str) !== 1) {
                        $errors[$key] = $dec > 0 ? '数値（小数は ' . $dec . ' 桁まで）を入れてください' : '整数を入れてください';
                    } else {
                        if (isset($s['min']) && $s['min'] !== '' && (float) $str < (float) $s['min']) {
                            $errors[$key] = $s['min'] . ' 以上にしてください';
                        }
                        if (isset($s['max']) && $s['max'] !== '' && (float) $str > (float) $s['max']) {
                            $errors[$key] = $s['max'] . ' 以下にしてください';
                        }
                    }
                }
                break;
            case 'select':
            case 'radio':
                // 無効にした選択肢は、すでに保存されている値のままなら受け付ける
                if ($str !== '' && !in_array($str, form_option_codes($f), true) && $str !== $prevValue) {
                    $errors[$key] = '選べない値です';
                }
                break;
            case 'checkbox':
                if ($str !== '' && $str !== '0' && $str !== '1') {
                    $errors[$key] = '値が正しくありません';
                }
                $str = $str === '1' ? '1' : '';
                break;
            default:
                $errors[$key] = '値が正しくありません';
        }
        $value = $str;
    }

    if ($checkRequired && $f['required'] && $value === '' && !isset($errors[$key])) {
        $errors[$key] = '入力してください';
    }
    return $value;
}

/** 表示の条件（settings.show_when = {field: コード, values: [...]}) を満たすか */
function form_is_visible(array $f, array $valuesByCode): bool
{
    $w = $f['settings']['show_when'] ?? null;
    if (!is_array($w) || empty($w['field'])) {
        return true;
    }
    $v = $valuesByCode[(string) $w['field']] ?? '';
    $values = isset($w['values']) && is_array($w['values']) ? $w['values'] : [];
    return in_array($v, $values, true);
}

// ---- 入力欄の HTML -------------------------------------------------------

function form_badges(array $f): string
{
    $html = '';
    if ($f['required']) {
        $html .= '<span class="badge badge-required">必須</span>';
    }
    if ($f['conf'] === 'internal') {
        $html .= '<span class="badge badge-internal">社外秘</span>';
    } elseif ($f['conf'] === 'restricted') {
        $html .= '<span class="badge badge-restricted">限定</span>';
    }
    return $html;
}

function form_option_label(array $f, string $code): string
{
    foreach ($f['options'] as $o) {
        if ((string) $o['code'] === $code) {
            return (string) $o['label'];
        }
    }
    return $code;
}

/** 1 つの項目の入力欄（ラベル・説明・注意を含む） */
function form_input_html(array $f, string $value, string $name, string $id, ?string $error = null, bool $warning = false): string
{
    $s = $f['settings'];
    $type = $f['type'];
    $attrs = ' id="' . h($id) . '" name="' . h($name) . '"';
    $ph = $f['placeholder'] !== '' ? ' placeholder="' . h($f['placeholder']) . '"' : '';
    $showWhen = '';
    if (isset($s['show_when']['field'])) {
        $showWhen = ' data-show-field="' . h((string) $s['show_when']['field']) . '" data-show-values="' . h(implode(',', (array) ($s['show_when']['values'] ?? []))) . '"';
    }
    $html = '<div class="field" data-code="' . h($f['code']) . '"' . $showWhen . '>';
    $html .= '<label for="' . h($id) . '">' . h($f['label']) . form_badges($f) . '</label>';

    switch ($type) {
        case 'single_text':
            $html .= '<input type="text"' . $attrs . $ph . ' maxlength="' . (int) ($s['max_length'] ?? 200) . '" value="' . h($value) . '" data-free-text="1">';
            break;
        case 'multi_text':
            $html .= '<textarea' . $attrs . $ph . ' maxlength="' . (int) ($s['max_length'] ?? 2000) . '" data-free-text="1">' . h($value) . '</textarea>';
            break;
        case 'date':
            $html .= '<input type="date"' . $attrs . ' value="' . h($value) . '">';
            break;
        case 'month':
            $html .= '<input type="month"' . $attrs . $ph . ' value="' . h($value) . '">';
            break;
        case 'number':
            $step = (int) ($s['decimals'] ?? 0) > 0 ? '0.' . str_repeat('0', (int) $s['decimals'] - 1) . '1' : '1';
            $html .= '<input type="number" inputmode="decimal"' . $attrs . ' step="' . h($step) . '" value="' . h($value) . '"'
                . (isset($s['min']) && $s['min'] !== '' ? ' min="' . h((string) $s['min']) . '"' : '')
                . (isset($s['max']) && $s['max'] !== '' ? ' max="' . h((string) $s['max']) . '"' : '') . '>';
            break;
        case 'select':
            $html .= '<select' . $attrs . '><option value="">選んでください</option>';
            foreach ($f['options'] as $o) {
                if (empty($o['active']) && (string) $o['code'] !== $value) {
                    continue;
                }
                $html .= '<option value="' . h($o['code']) . '"' . ((string) $o['code'] === $value ? ' selected' : '') . '>' . h($o['label']) . '</option>';
            }
            $html .= '</select>';
            break;
        case 'radio':
            $html .= '<div class="choices" role="radiogroup">';
            $i = 0;
            foreach ($f['options'] as $o) {
                if (empty($o['active']) && (string) $o['code'] !== $value) {
                    continue;
                }
                $oid = $id . '_' . $i++;
                $html .= '<label class="choice" for="' . h($oid) . '"><input type="radio" id="' . h($oid) . '" name="' . h($name) . '" value="' . h($o['code']) . '"'
                    . ((string) $o['code'] === $value ? ' checked' : '') . '> ' . h($o['label']) . '</label>';
            }
            if (!$f['required']) {
                $oid = $id . '_none';
                $html .= '<label class="choice muted" for="' . h($oid) . '"><input type="radio" id="' . h($oid) . '" name="' . h($name) . '" value=""' . ($value === '' ? ' checked' : '') . '> 未選択</label>';
            }
            $html .= '</div>';
            break;
        case 'checkbox':
            $html .= '<label class="choice"><input type="hidden" name="' . h($name) . '" value="0"><input type="checkbox"' . $attrs . ' value="1"' . ($value === '1' ? ' checked' : '') . '> はい</label>';
            break;
        case 'multi_check':
            $picked = json_decode($value, true);
            $picked = is_array($picked) ? $picked : [];
            $html .= '<div class="choices">';
            $i = 0;
            foreach ($f['options'] as $o) {
                if (empty($o['active']) && !in_array((string) $o['code'], $picked, true)) {
                    continue;
                }
                $oid = $id . '_' . $i++;
                $html .= '<label class="choice" for="' . h($oid) . '"><input type="checkbox" id="' . h($oid) . '" name="' . h($name) . '[]" value="' . h($o['code']) . '"'
                    . (in_array((string) $o['code'], $picked, true) ? ' checked' : '') . '> ' . h($o['label']) . '</label>';
            }
            $html .= '</div>';
            break;
        case 'table':
            $cols = isset($s['columns']) && is_array($s['columns']) ? $s['columns'] : [];
            $rows = json_decode($value, true);
            $rows = is_array($rows) ? $rows : [];
            $rows[] = [];
            $rows[] = [];
            $html .= '<div class="table-input"><table class="table"><thead><tr>';
            foreach ($cols as $c) {
                $html .= '<th>' . h((string) $c['label']) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $ri => $row) {
                $html .= '<tr>';
                foreach ($cols as $c) {
                    $ck = (string) $c['key'];
                    $cv = isset($row[$ck]) ? (string) $row[$ck] : '';
                    $cn = $name . '[' . $ri . '][' . $ck . ']';
                    $ct = (string) ($c['type'] ?? 'single_text');
                    if ($ct === 'select') {
                        $cell = '<select name="' . h($cn) . '"><option value=""></option>';
                        foreach ((array) ($c['options'] ?? []) as $co) {
                            $cell .= '<option' . ((string) $co === $cv ? ' selected' : '') . '>' . h((string) $co) . '</option>';
                        }
                        $cell .= '</select>';
                    } elseif ($ct === 'multi_text') {
                        $cell = '<textarea name="' . h($cn) . '" data-free-text="1">' . h($cv) . '</textarea>';
                    } else {
                        $it = $ct === 'number' ? 'number' : ($ct === 'date' ? 'date' : 'text');
                        $cell = '<input type="' . $it . '" name="' . h($cn) . '" value="' . h($cv) . '"' . ($it === 'text' ? ' data-free-text="1"' : '') . '>';
                    }
                    $html .= '<td>' . $cell . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table><p class="note">行が足りないときは、保存すると空の行が増えます。</p></div>';
            break;
    }

    if ($f['help'] !== '') {
        $html .= '<p class="note">' . nl2br(h($f['help'])) . '</p>';
    }
    if (form_is_free_text($f)) {
        $html .= '<p class="note">客先名・固有名詞・個人名は書かないでください。</p>';
        $html .= '<p class="field-warning"' . ($warning ? '' : ' hidden') . '>客先名や個人名が含まれていませんか</p>';
    }
    if ($error !== null) {
        $html .= '<p class="field-error">' . h($error) . '</p>';
    }
    return $html . '</div>';
}

// ---- 表示 ---------------------------------------------------------------

function form_display_html(array $f, ?string $value): string
{
    if ($value === null) {
        return '<span class="muted">この内容は表示できません</span>';
    }
    if ($value === '') {
        return '<span class="muted">—</span>';
    }
    switch ($f['type']) {
        case 'multi_text':
            return nl2br(h($value));
        case 'select':
        case 'radio':
            return h(form_option_label($f, $value));
        case 'checkbox':
            return $value === '1' ? 'はい' : 'いいえ';
        case 'multi_check':
            $arr = json_decode($value, true);
            $labels = [];
            foreach (is_array($arr) ? $arr : [] as $c) {
                $labels[] = h(form_option_label($f, (string) $c));
            }
            return implode('、', $labels);
        case 'table':
            $rows = json_decode($value, true);
            $cols = isset($f['settings']['columns']) && is_array($f['settings']['columns']) ? $f['settings']['columns'] : [];
            $html = '<table class="table"><thead><tr>';
            foreach ($cols as $c) {
                $html .= '<th>' . h((string) $c['label']) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach (is_array($rows) ? $rows : [] as $row) {
                $html .= '<tr>';
                foreach ($cols as $c) {
                    $html .= '<td>' . nl2br(h((string) ($row[(string) $c['key']] ?? ''))) . '</td>';
                }
                $html .= '</tr>';
            }
            return $html . '</tbody></table>';
        default:
            return h($value);
    }
}
