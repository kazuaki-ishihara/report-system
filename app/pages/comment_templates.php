<?php

declare(strict_types=1);

// ====================================================================
// G-19 コメントの定型文（詳細設計書 G19_comment_templates.md）
// 2 つの範囲を同じ画面で扱う。
//   課の定型文（scope=department）: 課長が、自分の課の確認者が使う定型文を用意する（comment_templates）
//   自分の定型文（scope=mine）    : 確認者が、自分だけが使う定型文を用意する（personal_comment_templates。K-014）
// どちらも追加・編集・削除・並べ替えができる。
// ====================================================================

$user = page_start();
$userId = (int) $user['id'];
$manageableDepartmentById = comment_template_manageable_departments($user);
$isReviewer = (bool) reviewer_departments($userId);
if (!$manageableDepartmentById && !$isReviewer) {
    error_page(403);
}
// 範囲: 課長は既定で課の定型文、確認者（課長でない）は自分の定型文だけ
$templateScope = (string) ($_GET['scope'] ?? '');
if ($templateScope !== 'mine' && $templateScope !== 'department') {
    $templateScope = $manageableDepartmentById ? 'department' : 'mine';
}
if ($templateScope === 'department' && !$manageableDepartmentById) {
    error_page(403);
}
if ($templateScope === 'mine' && !$isReviewer) {
    error_page(403);
}
$selectedDepartmentId = null;
if ($templateScope === 'department') {
    $selectedDepartmentId = isset($_GET['dept']) ? input_id('dept') : (int) array_key_first($manageableDepartmentById);
    if (!isset($manageableDepartmentById[$selectedDepartmentId])) {
        error_page(403);
    }
}
$selfPath = '/admin/comment_templates.php?scope=' . $templateScope . ($selectedDepartmentId !== null ? '&dept=' . $selectedDepartmentId : '');
$templateOwnerId = $templateScope === 'department' ? $selectedDepartmentId : $userId;
$maxTemplateCount = 30;
$errorTextList = [];
$typedBodyText = '';

if (is_post()) {
    $action = post_str('action');
    if (!in_array($action, ['add', 'update', 'delete', 'move_up', 'move_down'], true)) {
        error_page(400);
    }
    rk_begin('comment_template.' . $action, $userId);
    $templateList = comment_template_rows($templateScope, $templateOwnerId);
    $auditTargetPrefix = $templateScope === 'department' ? 'comment_template:' : 'personal_comment_template:';

    if ($action === 'add') {
        $typedBodyText = trim(str_replace(["\r\n", "\r"], "\n", post_str('body')));
        validate_text($typedBodyText, msg('定型文'), 300, true, $errorTextList, true);
        if (count($templateList) >= $maxTemplateCount) {
            $errorTextList[] = msg('定型文は {max} 件までです', ['max' => $maxTemplateCount]);
        }
        if (!$errorTextList) {
            $nextSortOrder = $templateList ? (int) end($templateList)['sort_order'] + 10 : 10;
            $newTemplateId = comment_template_insert($templateScope, $templateOwnerId, $typedBodyText, $nextSortOrder, $userId);
            audit_log('admin', $auditTargetPrefix . $newTemplateId . ':create');
            flash('success', msg('定型文を追加しました'));
            rk_done($selfPath);
        }
        rk_cancel();
    } else {
        $templateId = input_id('template_id', 'POST');
        $templateIndex = null;   // 自分の範囲（この課・自分）の定型文の中での位置。なければ 404
        foreach ($templateList as $candidateIndex => $candidateTemplate) {
            if ((int) $candidateTemplate['id'] === $templateId) {
                $templateIndex = $candidateIndex;
            }
        }
        if ($templateIndex === null) {
            error_page(404);
        }
        $templateTableName = comment_template_table_name($templateScope);
        if ($action === 'update') {
            $newBodyText = trim(str_replace(["\r\n", "\r"], "\n", post_str('body')));
            $updateErrorTextList = [];
            validate_text($newBodyText, msg('定型文'), 300, true, $updateErrorTextList, true);
            if ($updateErrorTextList) {
                rk_cancel();
                flash('error', implode(' ', $updateErrorTextList));
                redirect($selfPath);
            }
            db_exec("UPDATE {$templateTableName} SET body = ?, updated_at = ? WHERE id = ?", [$newBodyText, now(), $templateId]);
            audit_log('admin', $auditTargetPrefix . $templateId . ':update');
            flash('success', msg('定型文を保存しました'));
        } elseif ($action === 'delete') {
            db_exec("DELETE FROM {$templateTableName} WHERE id = ?", [$templateId]);
            audit_log('admin', $auditTargetPrefix . $templateId . ':delete');
            flash('success', msg('定型文を削除しました'));
        } else {
            // 並べ替え: 隣と入れ替え、並び順を 10 刻みに振り直す
            $swapIndex = $action === 'move_up' ? $templateIndex - 1 : $templateIndex + 1;
            if (isset($templateList[$swapIndex])) {
                $reorderedTemplateList = $templateList;
                $reorderedTemplateList[$templateIndex] = $templateList[$swapIndex];
                $reorderedTemplateList[$swapIndex] = $templateList[$templateIndex];
                tx(function () use ($reorderedTemplateList, $templateTableName): void {
                    foreach (array_values($reorderedTemplateList) as $orderIndex => $reorderedTemplate) {
                        db_exec("UPDATE {$templateTableName} SET sort_order = ? WHERE id = ?", [($orderIndex + 1) * 10, (int) $reorderedTemplate['id']]);
                    }
                });
            }
        }
        rk_done($selfPath);
    }
}

render('comment_templates', [
    'title'                    => 'コメントの定型文',
    'user'                     => $user,
    'templateScope'            => $templateScope,
    'isReviewer'               => $isReviewer,
    'manageableDepartmentById' => $manageableDepartmentById,
    'selectedDepartmentId'     => $selectedDepartmentId,
    'templateList'             => comment_template_rows($templateScope, $templateOwnerId),
    'maxTemplateCount'         => $maxTemplateCount,
    'errorTextList'            => $errorTextList,
    'typedBodyText'            => $typedBodyText,
    'selfPath'                 => $selfPath,
]);
