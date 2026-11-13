<?php

declare(strict_types=1);

// ====================================================================
// G-12 項目マスタ: 項目の作成・編集・プレビュー（詳細設計書 G12_fields.md §5・§6）
// ====================================================================

$user = page_start();
$uid = (int) $user['id'];

// ---- プレビュー ----
if (isset($_GET['preview'])) {
    $deptId = input_id('preview');
    if (!can_manage_fields_of($user, $deptId) && !can_manage_system($user)) {
        error_page(403);
    }
    $dept = db_one('SELECT * FROM departments WHERE id = ?', [$deptId]);
    if ($dept === null) {
        error_page(404);
    }
    render('admin_field_preview', ['title' => 'プレビュー', 'user' => $user, 'dept' => $dept, 'fields' => form_template_fields($deptId)]);
}

$id = isset($_GET['id']) ? input_id('id') : null;
$field = null;
if ($id !== null) {
    $field = db_one('SELECT * FROM report_fields WHERE id = ?', [$id]);
    if ($field === null) {
        error_page(404);
    }
    $deptId = $field['department_id'] === null ? null : (int) $field['department_id'];
} else {
    $dp = (string) ($_GET['dept'] ?? 'common');
    $deptId = $dp === 'common' ? null : (preg_match('/^\d+$/', $dp) === 1 ? (int) $dp : -1);
    if ($deptId === -1 || ($deptId !== null && db_one('SELECT id FROM departments WHERE id = ?', [$deptId]) === null)) {
        error_page(400);
    }
}
$allowed = $deptId === null ? can_manage_system($user) : can_manage_fields_of($user, $deptId);
if (!$allowed) {
    error_page(403);
}
$deptParam = $deptId === null ? 'common' : (string) $deptId;
$used = $field !== null && (
    db_val('SELECT 1 FROM report_field_values WHERE field_id = ? LIMIT 1', [(int) $field['id']]) !== null
    || db_val('SELECT 1 FROM adhoc_request_fields WHERE source_field_id = ? LIMIT 1', [(int) $field['id']]) !== null
);
$errors = [];

if (is_post()) {
    rk_begin('admin.field.save', $uid);
    $label = post_str('label');
    $type = post_str('field_type');
    $scope = post_str('field_scope');
    $conf = post_str('confidentiality');
    $help = post_str('help_text');
    $placeholder = post_str('placeholder');
    $flags = [];
    foreach (['is_required', 'carry_over', 'show_in_list', 'is_searchable', 'include_in_export'] as $k) {
        $flags[$k] = ($_POST[$k] ?? '') === '1' ? 1 : 0;
    }

    validate_text($label, '表示名', 60, true, $errors);
    validate_text($help, '説明文', 300, false, $errors, true);
    validate_text($placeholder, '入力例', 100, false, $errors);
    if (!isset(FIELD_TYPES[$type])) {
        $errors[] = msg('入力部品が正しくありません');
    }
    if (!in_array($scope, ['report', 'project'], true)) {
        $errors[] = msg('区分が正しくありません');
    }
    if (!isset(FIELD_CONF[$conf])) {
        $errors[] = msg('機密区分が正しくありません');
    }

    // コード（作成時のみ）。空なら自動で付ける（item_<番号>）
    $newFieldCode = '';
    if ($field === null) {
        $code = post_str('code');
        if ($code === '') {
            $nextFieldNumber = (int) db_val('SELECT COALESCE(MAX(id), 0) + 1 FROM report_fields');
            do {
                $code = 'item_' . $nextFieldNumber++;
            } while (db_val('SELECT 1 FROM report_fields WHERE code = ?', [$code]) !== null);
        }
        $newFieldCode = $code;
        if (preg_match('/^[a-z][a-z0-9_]{2,39}$/', $code) !== 1) {
            $errors[] = msg('コードは英小文字で始まる、英小文字・数字・_ の 3〜40 文字にしてください');
        } elseif (db_val('SELECT 1 FROM report_fields WHERE code = ?', [$code]) !== null) {
            $errors[] = msg('同じコードの項目があります');
        }
    }

    // 変更の決まり（§6.2）
    if ($field !== null) {
        $oldType = (string) $field['field_type'];
        if ($oldType !== $type) {
            $compatible = [['single_text', 'multi_text'], ['select', 'radio'], ['radio', 'select']];
            if ($oldType === 'project' || $type === 'project') {
                $errors[] = msg('案件の項目の入力部品は変えられません');
            } elseif ($used && !in_array([$oldType, $type], $compatible, true)) {
                $errors[] = msg('この項目には入力済みのデータがあるため、入力部品をこの種類には変えられません');
            }
        }
        if ($used && $field['field_scope'] !== $scope) {
            $errors[] = msg('この項目には入力済みのデータがあるため、区分は変えられません');
        }
        if (($field['confidentiality'] === 'restricted') !== ($conf === 'restricted') && !can_manage_system($user)) {
            $errors[] = msg('「限定」の付け外しは管理者だけができます');
        }
    } elseif ($conf === 'restricted' && !can_manage_system($user)) {
        $errors[] = msg('「限定」にできるのは管理者だけです');
    }
    if ($type === 'project') {
        $other = db_val("SELECT id FROM report_fields WHERE field_type = 'project' AND id <> ?", [$field['id'] ?? 0]);
        if ($other !== null) {
            $errors[] = msg('案件の項目はすでにあります（1 つだけ置けます）');
        }
        $scope = 'project';
    }

    // 設定
    $settings = [];
    $num = function (string $name, int $min, int $max, string $lbl) use (&$errors): ?int {
        $v = post_str($name);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $v) !== 1 || (int) $v < $min || (int) $v > $max) {
            $errors[] = msg('{label}は {min}〜{max} にしてください', ['label' => $lbl, 'min' => $min, 'max' => $max]);
            return null;
        }
        return (int) $v;
    };
    if (in_array($type, ['single_text', 'multi_text'], true)) {
        $ml = $num('max_length', 1, 10000, '最大文字数');
        if ($ml !== null) {
            $settings['max_length'] = $ml;
        }
    }
    if ($type === 'number') {
        foreach (['min' => '最小', 'max' => '最大'] as $k => $lbl) {
            $v = post_str($k);
            if ($v !== '' && preg_match('/^-?\d{1,12}(\.\d+)?$/', $v) !== 1) {
                $errors[] = msg('{label}は数値にしてください', ['label' => $lbl]);
            } elseif ($v !== '') {
                $settings[$k] = $v;
            }
        }
        if (isset($settings['min'], $settings['max']) && (float) $settings['min'] > (float) $settings['max']) {
            $errors[] = msg('最小は最大以下にしてください');
        }
        $d = $num('decimals', 0, 4, '小数の桁');
        if ($d !== null) {
            $settings['decimals'] = $d;
        }
    }
    if ($type === 'table') {
        $mr = $num('max_rows', 1, 50, '行数の上限');
        $settings['max_rows'] = $mr ?? 20;
        $cols = [];
        foreach (preg_split('/\r\n|\r|\n/', post_str('columns_text')) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $p = array_map('trim', explode('|', $line));
            if (count($p) < 3 || preg_match('/^[a-z][a-z0-9_]{0,29}$/', $p[0]) !== 1 || $p[1] === '' || !in_array($p[2], TABLE_COLUMN_TYPES, true)) {
                $errors[] = msg('表の列の定義が正しくありません: {line}', ['line' => $line]);
                continue;
            }
            $col = ['key' => $p[0], 'label' => $p[1], 'type' => $p[2]];
            if ($p[2] === 'select') {
                $col['options'] = array_values(array_filter(array_map('trim', explode(',', $p[3] ?? '')), 'strlen'));
            }
            $cols[] = $col;
        }
        if (count($cols) < 1 || count($cols) > 10) {
            $errors[] = msg('表の列は 1〜10 にしてください');
        }
        $settings['columns'] = $cols;
    }
    $swField = post_str('show_when_field');
    if ($swField !== '') {
        // 値は、チェックボックス（show_when_value[]）か、カンマ区切りの文字（show_when_values）で受け取る
        $showWhenCheckedValueList = $_POST['show_when_value'] ?? null;
        if ($showWhenCheckedValueList !== null && !is_array($showWhenCheckedValueList)) {
            error_page(400);
        }
        $swValues = $showWhenCheckedValueList !== null
            ? array_values(array_filter(array_map(function ($checkedValue): string { return is_string($checkedValue) ? trim($checkedValue) : ''; }, $showWhenCheckedValueList), 'strlen'))
            : array_values(array_filter(array_map('trim', explode(',', post_str('show_when_values'))), 'strlen'));
        // 条件にできるのは、同じ様式（共通の項目は共通の項目だけ）・同じ区分の、有効な選択式の項目
        $target = db_one(
            "SELECT id, field_type FROM report_fields
              WHERE code = ? AND field_type IN ('select', 'radio', 'checkbox') AND is_active = 1 AND field_scope = ? AND id <> ?
                AND " . ($deptId === null ? 'department_id IS NULL' : '(department_id IS NULL OR department_id = ?)'),
            $deptId === null ? [$swField, $scope, $field['id'] ?? 0] : [$swField, $scope, $field['id'] ?? 0, $deptId]
        );
        if ($target === null || !$swValues) {
            $errors[] = msg('表示の条件は、同じ様式・同じ区分にある有効な選択式の項目のコードと、その値を入れてください');
        } else {
            if ($target['field_type'] === 'checkbox') {
                $allowedValues = ['1'];
            } else {
                $allowedValues = array_map('strval', array_column(
                    db_all('SELECT code FROM report_field_options WHERE field_id = ? AND is_active = 1', [(int) $target['id']]),
                    'code'
                ));
            }
            $bad = array_values(array_diff($swValues, $allowedValues));
            if ($bad) {
                $errors[] = $target['field_type'] === 'checkbox'
                    ? msg('表示の条件の値が、その項目の選択肢にありません: {values}（チェックの項目は 1）', ['values' => implode(', ', $bad)])
                    : msg('表示の条件の値が、その項目の選択肢にありません: {values}', ['values' => implode(', ', $bad)]);
            } else {
                $settings['show_when'] = ['field' => $swField, 'values' => $swValues];
            }
        }
    }

    // 選択肢
    $options = [];
    $removedOptionCodeList = [];   // 削除する選択肢（「削除」にチェックがあり、報告で使われていないもの）
    if (in_array($type, CHOICE_TYPES, true)) {
        $codes = $_POST['opt_code'] ?? [];
        $labels = $_POST['opt_label'] ?? [];
        $actives = $_POST['opt_active'] ?? [];
        $deleteFlags = $_POST['opt_delete'] ?? [];
        if (!is_array($codes) || !is_array($labels) || !is_array($actives) || !is_array($deleteFlags)) {
            error_page(400);
        }
        $existing = [];
        if ($field !== null) {
            foreach (db_all('SELECT code FROM report_field_options WHERE field_id = ?', [(int) $field['id']]) as $o) {
                $existing[(string) $o['code']] = true;
            }
        }
        // コードが空で表示名がある行は、あとで自動のコード（o1, o2, …。使われていない番号）を付ける
        $usedOptionCodeSet = $existing;
        foreach ($codes as $optionCodeText) {
            if (is_string($optionCodeText) && trim($optionCodeText) !== '') {
                $usedOptionCodeSet[trim($optionCodeText)] = true;
            }
        }
        $nextOptionNumber = 1;
        $seen = [];
        $deleteRequestedCodeSet = [];   // 「削除」にチェックがあった既存の選択肢
        foreach ($codes as $i => $c) {
            $c = is_string($c) ? trim($c) : '';
            $l = isset($labels[$i]) && is_string($labels[$i]) ? trim($labels[$i]) : '';
            if ($c === '' && $l === '') {
                continue;
            }
            if (isset($existing[$c]) && ($deleteFlags[$i] ?? '') === '1') {
                $deleteRequestedCodeSet[$c] = true;
                continue;   // 削除する（下で、使われていないかを確かめる）
            }
            if ($c === '') {
                while (isset($usedOptionCodeSet['o' . $nextOptionNumber])) {
                    $nextOptionNumber++;
                }
                $c = 'o' . $nextOptionNumber;
                $usedOptionCodeSet[$c] = true;
            }
            if (preg_match('/^[a-z0-9_]{1,40}$/', $c) !== 1) {
                $errors[] = msg('選択肢のコードは英小文字・数字・_ の 1〜40 文字にしてください: {code}', ['code' => $c]);
                continue;
            }
            if (isset($seen[$c])) {
                $errors[] = msg('選択肢のコードが重複しています: {code}', ['code' => $c]);
                continue;
            }
            $seen[$c] = true;
            validate_text($l, '選択肢の表示名', 60, true, $errors);
            $options[] = ['code' => $c, 'label' => $l, 'active' => isset($actives[$i]) && $actives[$i] === '1'];
        }
        foreach (array_keys($existing) as $c) {
            if (isset($seen[$c])) {
                continue;
            }
            if (!isset($deleteRequestedCodeSet[$c])) {
                // 送られなかっただけ（画面の不具合や古い画面）では消さない
                $errors[] = msg('選択肢は削除できません（無効にしてください）: {code}', ['code' => $c]);
            } elseif (form_option_is_used((int) $field['id'], (string) $c)) {
                $errors[] = msg('報告で使われている選択肢は削除できません（無効にしてください）: {code}', ['code' => $c]);
            } else {
                $removedOptionCodeList[] = (string) $c;
            }
        }
        $activeCount = count(array_filter($options, function ($o) { return $o['active']; }));
        if ($activeCount < 1) {
            $errors[] = msg('有効な選択肢を 1 つ以上入れてください');
        }
    }

    if (!$errors) {
        $settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE);
        $savedId = tx(function () use ($field, $deptId, $label, $type, $scope, $conf, $help, $placeholder, $flags, $settingsJson, $options, $newFieldCode, $removedOptionCodeList) {
            if ($field === null) {
                $sort = (int) db_val(
                    'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM report_fields WHERE field_scope = ? AND ' . ($deptId === null ? 'department_id IS NULL' : 'department_id = ?'),
                    $deptId === null ? [$scope] : [$scope, $deptId]
                );
                db_exec(
                    'INSERT INTO report_fields (code, label, field_type, department_id, field_scope, confidentiality, sort_order, is_active, is_required, help_text, placeholder, settings_json, carry_over, show_in_list, is_searchable, include_in_export, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$newFieldCode, $label, $type, $deptId, $scope, $conf, $sort, $flags['is_required'], $help, $placeholder, $settingsJson,
                     $flags['carry_over'], $flags['show_in_list'], $flags['is_searchable'], $flags['include_in_export'], now(), now()]
                );
                $fid = (int) db()->lastInsertId();
            } else {
                $fid = (int) $field['id'];
                db_exec(
                    'UPDATE report_fields SET label = ?, field_type = ?, field_scope = ?, confidentiality = ?, is_required = ?, help_text = ?, placeholder = ?,
                     settings_json = ?, carry_over = ?, show_in_list = ?, is_searchable = ?, include_in_export = ?, updated_at = ? WHERE id = ?',
                    [$label, $type, $scope, $conf, $flags['is_required'], $help, $placeholder, $settingsJson,
                     $flags['carry_over'], $flags['show_in_list'], $flags['is_searchable'], $flags['include_in_export'], now(), $fid]
                );
                // 「限定」の付け外し: 自由記述でない値を暗号化・復号し直し、既存の報告の定義も合わせる
                $wasRestricted = $field['confidentiality'] === 'restricted';
                if ($wasRestricted !== ($conf === 'restricted')) {
                    $free = in_array($type, ['single_text', 'multi_text', 'table'], true);
                    if (!$free) {
                        foreach (db_all('SELECT report_id, report_project_id, value_text, is_encrypted FROM report_field_values WHERE field_id = ?', [$fid]) as $v) {
                            $aad = form_aad((int) $v['report_id'], $fid, (int) $v['report_project_id']);
                            if ($conf === 'restricted' && (int) $v['is_encrypted'] === 0 && $v['value_text'] !== '') {
                                db_exec('UPDATE report_field_values SET value_text = ?, is_encrypted = 1 WHERE report_id = ? AND field_id = ? AND report_project_id = ?',
                                    [enc((string) $v['value_text'], $aad), (int) $v['report_id'], $fid, (int) $v['report_project_id']]);
                            } elseif ($conf !== 'restricted' && (int) $v['is_encrypted'] === 1) {
                                $plain = dec((string) $v['value_text'], $aad);
                                if ($plain === null) {
                                    throw new RuntimeException('decrypt failed while changing confidentiality');
                                }
                                db_exec('UPDATE report_field_values SET value_text = ?, is_encrypted = 0 WHERE report_id = ? AND field_id = ? AND report_project_id = ?',
                                    [$plain, (int) $v['report_id'], $fid, (int) $v['report_project_id']]);
                            }
                        }
                    }
                    db_exec('UPDATE report_record_fields SET confidentiality = ? WHERE field_id = ?', [$conf, $fid]);
                }
            }
            foreach ($removedOptionCodeList as $removedOptionCode) {
                db_exec('DELETE FROM report_field_options WHERE field_id = ? AND code = ?', [$fid, $removedOptionCode]);
            }
            $i = 0;
            foreach ($options as $o) {
                $i += 10;
                db_exec(
                    'INSERT INTO report_field_options (field_id, code, label, sort_order, is_active) VALUES (?, ?, ?, ?, ?)
                     ON CONFLICT(field_id, code) DO UPDATE SET label = excluded.label, sort_order = excluded.sort_order, is_active = excluded.is_active',
                    [$fid, $o['code'], $o['label'], $i, $o['active'] ? 1 : 0]
                );
            }
            return $fid;
        });
        audit_log('admin', 'field:' . $savedId . ':' . ($field === null ? 'create' : 'update'));
        flash('success', msg('保存しました。これから作る報告に反映されます'));
        rk_done('/admin/fields.php?dept=' . rawurlencode($deptParam));
    }
    rk_cancel();
}

// 臨時の調査の質問を、月次の項目へ移すときの初期値（G-11）
$prefill = null;
if (!is_post() && $field === null && isset($_GET['from_q'])) {
    $q = db_one('SELECT f.*, r.department_id FROM adhoc_request_fields f JOIN adhoc_requests r ON r.id = f.request_id WHERE f.id = ?', [input_id('from_q')]);
    if ($q !== null && $deptId !== null && (int) $q['department_id'] === $deptId) {
        $prefill = ['label' => (string) $q['label'], 'field_type' => (string) $q['field_type'], 'field_scope' => 'report',
            'confidentiality' => (string) $q['confidentiality'], 'help_text' => (string) $q['help_text'], 'carry_over' => '1', 'include_in_export' => '1',
            'opt_code' => [], 'opt_label' => [], 'opt_active' => []];
        foreach ((array) json_decode((string) $q['options_json'], true) as $i => $o) {
            $prefill['opt_code'][$i] = (string) $o['code'];
            $prefill['opt_label'][$i] = (string) $o['label'];
            $prefill['opt_active'][$i] = '1';
        }
    }
}

$options = $field === null ? [] : form_field_options((int) $field['id'], false);
$settings = $field === null ? [] : (json_decode((string) $field['settings_json'], true) ?: []);
$columnsText = '';
foreach (($settings['columns'] ?? []) as $c) {
    $columnsText .= $c['key'] . '|' . $c['label'] . '|' . $c['type'] . (isset($c['options']) ? '|' . implode(',', $c['options']) : '') . "\n";
}

// 表示の条件に選べる項目（同じ様式の有効な選択式の項目。区分は画面で絞る）
$showWhenCandidateFieldList = [];
foreach (db_all(
    "SELECT * FROM report_fields WHERE is_active = 1 AND field_type IN ('select', 'radio', 'checkbox') AND id <> ? AND "
        . ($deptId === null ? 'department_id IS NULL' : '(department_id IS NULL OR department_id = ?)') . ' ORDER BY department_id IS NOT NULL, field_scope, sort_order, id',
    $deptId === null ? [$field['id'] ?? 0] : [$field['id'] ?? 0, $deptId]
) as $candidateFieldRow) {
    $showWhenCandidateFieldList[] = form_row_to_def($candidateFieldRow, form_field_options((int) $candidateFieldRow['id'], true));
}

render('admin_field_edit', [
    'showWhenCandidateFieldList' => $showWhenCandidateFieldList,
    'title'       => $field === null ? '項目の追加' : '項目の編集',
    'user'        => $user,
    'field'       => $field,
    'deptId'      => $deptId,
    'deptName'    => $deptId === null ? '共通' : (string) db_val('SELECT name FROM departments WHERE id = ?', [$deptId]),
    'deptParam'   => $deptParam,
    'used'        => $used,
    'errors'      => $errors,
    'post'        => is_post() ? $_POST : $prefill,
    'options'     => $options,
    'settings'    => $settings,
    'columnsText' => $columnsText,
]);
