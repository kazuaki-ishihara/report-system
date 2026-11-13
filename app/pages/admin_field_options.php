<?php

declare(strict_types=1);

// ====================================================================
// G-12 項目マスタ: 共通の選択式の項目の、課ごとの選択肢（詳細設計書 G12_fields.md §6.5）
// 課長（課の項目を管理できる人）が、自分の課でだけ、共通の選択肢の表示名を変える・出さない・選択肢を足す・
// 使われていない足した選択肢を削除する。共通の選択肢そのもの（ほかの課）は変わらない。
// ====================================================================

$user = page_start();
$userId = (int) $user['id'];
$fieldId = input_id('field');
$departmentId = input_id('dept');
$commonField = db_one('SELECT * FROM report_fields WHERE id = ? AND department_id IS NULL AND is_active = 1', [$fieldId]);
if ($commonField === null || !in_array($commonField['field_type'], CHOICE_TYPES, true)) {
    error_page(404);
}
$department = db_one('SELECT * FROM departments WHERE id = ? AND is_active = 1', [$departmentId]);
if ($department === null) {
    error_page(404);
}
if (!can_manage_fields_of($user, $departmentId)) {
    error_page(403);
}
$selfPath = '/admin/field_options.php?field=' . $fieldId . '&dept=' . $departmentId;
$commonOptionList = form_field_options($fieldId, true);
$commonOptionCodeSet = array_flip(array_column(form_field_options($fieldId, false), 'code'));

/** @return array<string, array> 課の設定の行（コード => 行） */
$loadDepartmentOptionRowByCode = function () use ($departmentId, $fieldId): array {
    $departmentOptionRowByCode = [];
    foreach (db_all('SELECT * FROM department_field_options WHERE department_id = ? AND field_id = ? ORDER BY sort_order, code', [$departmentId, $fieldId]) as $departmentOptionRow) {
        $departmentOptionRowByCode[(string) $departmentOptionRow['code']] = $departmentOptionRow;
    }
    return $departmentOptionRowByCode;
};
$departmentOptionRowByCode = $loadDepartmentOptionRowByCode();
$errorTextList = [];

if (is_post()) {
    $action = post_str('action');
    if (!in_array($action, ['save', 'delete_added'], true)) {
        error_page(400);
    }
    rk_begin('admin.field_options.' . $action, $userId);

    if ($action === 'delete_added') {
        $deleteCode = post_str('code');
        $deleteRow = $departmentOptionRowByCode[$deleteCode] ?? null;
        if ($deleteRow === null || (int) $deleteRow['is_added'] !== 1) {
            error_page(404);
        }
        if (form_option_is_used($fieldId, $deleteCode, $departmentId)) {
            rk_cancel();
            flash('error', msg('この選択肢は報告で使われているため削除できません（「出す」を外してください）'));
            redirect($selfPath);
        }
        db_exec('DELETE FROM department_field_options WHERE department_id = ? AND field_id = ? AND code = ?', [$departmentId, $fieldId, $deleteCode]);
        audit_log('admin', 'field_options:' . $fieldId . ':' . $departmentId . ':delete');
        flash('success', msg('選択肢を削除しました'));
        rk_done($selfPath);
    }

    // ---- 保存: 共通の選択肢の表示名・出す／出さない、足した選択肢、新しい選択肢 ----
    $overrideLabelByCode = $_POST['override_label'] ?? [];
    $shownFlagByCode = $_POST['shown'] ?? [];
    $addedLabelByCode = $_POST['added_label'] ?? [];
    $newLabelList = $_POST['new_label'] ?? [];
    if (!is_array($overrideLabelByCode) || !is_array($shownFlagByCode) || !is_array($addedLabelByCode) || !is_array($newLabelList)) {
        error_page(400);
    }
    $plannedRowList = [];   // 保存する課の設定の行: ['code', 'label', 'is_added', 'is_hidden', 'sort_order']
    $shownOptionCount = 0;
    foreach ($commonOptionList as $commonOption) {
        $overrideLabel = trim((string) ($overrideLabelByCode[$commonOption['code']] ?? ''));
        validate_text($overrideLabel, msg('この課での表示名'), 60, false, $errorTextList);
        $isShown = ($shownFlagByCode[$commonOption['code']] ?? '') === '1';
        $shownOptionCount += $isShown ? 1 : 0;
        if ($overrideLabel !== '' || !$isShown) {
            $plannedRowList[] = ['code' => $commonOption['code'], 'label' => $overrideLabel, 'is_added' => 0, 'is_hidden' => $isShown ? 0 : 1, 'sort_order' => 0];
        }
    }
    $addedSortOrder = 0;
    foreach ($departmentOptionRowByCode as $addedCode => $departmentOptionRow) {
        if ((int) $departmentOptionRow['is_added'] !== 1) {
            continue;
        }
        $addedLabel = trim((string) ($addedLabelByCode[$addedCode] ?? $departmentOptionRow['label']));
        validate_text($addedLabel, msg('足した選択肢の表示名'), 60, true, $errorTextList);
        $isShown = ($shownFlagByCode[$addedCode] ?? '') === '1';
        $shownOptionCount += $isShown ? 1 : 0;
        $addedSortOrder += 10;
        $plannedRowList[] = ['code' => (string) $addedCode, 'label' => $addedLabel, 'is_added' => 1, 'is_hidden' => $isShown ? 0 : 1, 'sort_order' => $addedSortOrder];
    }
    // 新しい選択肢: コードは d<課ID>_o<番号>（共通の選択肢・この課で足した選択肢と重ならない番号）
    $usedCodeSet = $commonOptionCodeSet + $departmentOptionRowByCode;
    $nextAddedNumber = 1;
    foreach ($newLabelList as $newLabelText) {
        $newLabel = is_string($newLabelText) ? trim($newLabelText) : '';
        if ($newLabel === '') {
            continue;
        }
        validate_text($newLabel, msg('足す選択肢の表示名'), 60, true, $errorTextList);
        while (isset($usedCodeSet['d' . $departmentId . '_o' . $nextAddedNumber])) {
            $nextAddedNumber++;
        }
        $newCode = 'd' . $departmentId . '_o' . $nextAddedNumber;
        $usedCodeSet[$newCode] = true;
        $addedSortOrder += 10;
        $shownOptionCount++;
        $plannedRowList[] = ['code' => $newCode, 'label' => $newLabel, 'is_added' => 1, 'is_hidden' => 0, 'sort_order' => $addedSortOrder];
    }
    if (count($plannedRowList) > 100) {
        $errorTextList[] = msg('選択肢が多すぎます（100 件まで）');
    }
    if ($shownOptionCount < 1) {
        $errorTextList[] = msg('この課で出す選択肢を 1 つ以上にしてください');
    }
    if (!$errorTextList) {
        tx(function () use ($plannedRowList, $departmentId, $fieldId, $userId): void {
            db_exec('DELETE FROM department_field_options WHERE department_id = ? AND field_id = ?', [$departmentId, $fieldId]);
            foreach ($plannedRowList as $plannedRow) {
                db_exec(
                    'INSERT INTO department_field_options (department_id, field_id, code, label, is_added, is_hidden, sort_order, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$departmentId, $fieldId, $plannedRow['code'], $plannedRow['label'], $plannedRow['is_added'], $plannedRow['is_hidden'], $plannedRow['sort_order'], $userId, now()]
                );
            }
        });
        audit_log('admin', 'field_options:' . $fieldId . ':' . $departmentId . ':update');
        flash('success', msg('この課の選択肢を保存しました。これから作る報告に反映されます'));
        rk_done($selfPath);
    }
    rk_cancel();
}

$addedOptionRowList = array_values(array_filter($departmentOptionRowByCode, function (array $departmentOptionRow): bool {
    return (int) $departmentOptionRow['is_added'] === 1;
}));
$isAddedOptionUsedByCode = [];
foreach ($addedOptionRowList as $addedOptionRow) {
    $isAddedOptionUsedByCode[(string) $addedOptionRow['code']] = form_option_is_used($fieldId, (string) $addedOptionRow['code'], $departmentId);
}

render('admin_field_options', [
    'title'                     => '課の選択肢',
    'user'                      => $user,
    'commonField'               => $commonField,
    'department'                => $department,
    'commonOptionList'          => $commonOptionList,
    'departmentOptionRowByCode' => $departmentOptionRowByCode,
    'addedOptionRowList'        => $addedOptionRowList,
    'isAddedOptionUsedByCode'   => $isAddedOptionUsedByCode,
    'previewOptionList'         => form_department_options($departmentId, $fieldId, $commonOptionList),
    'errorTextList'             => $errorTextList,
    'postedValues'              => is_post() ? $_POST : null,
    'selfPath'                  => $selfPath,
]);
