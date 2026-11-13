<?php

declare(strict_types=1);

// ====================================================================
// G-04 報告の作成・編集: 保存・提出・取り下げ・削除・自動保存（詳細設計書 G04_report_edit.md §6）
// ====================================================================

$user = page_start();
$uid = (int) $user['id'];
if (!is_post()) {
    error_page(400);
}

$action = post_str('action');
if (!in_array($action, ['draft', 'submit', 'save', 'withdraw', 'delete', 'autosave', 'unchanged'], true)) {
    error_page(400);
}
$period = post_str('period');
if (!is_valid_period($period)) {
    error_page(400);
}
// 下書きの削除だけは、編集できる期間を過ぎた月でもできる
if (!report_period_editable($period) && !($action === 'delete' && $period <= date('Y-m'))) {
    error_page(400);
}
$isJson = $action === 'autosave';

// 過去の報告から写したときの元（G-04 §4.4）。写す元にできる自分の報告でなければ 400
$copyFrom = null;
if (isset($_POST['copy_from']) && $_POST['copy_from'] !== '') {
    $copyFrom = input_id('copy_from', 'POST');
    $okSource = false;
    foreach (report_copy_sources($uid, $period) as $src) {
        $okSource = $okSource || (int) $src['id'] === $copyFrom;
    }
    if (!$okSource) {
        error_page(400);
    }
}

rk_begin('report.' . $action, $uid);

$report = report_find_own($uid, $period);
if ($report !== null && $report['status'] === 'confirmed') {
    rk_cancel();
    error_page(400, msg('この報告は確認が完了しているため、変更できません。'));
}

// ---- 取り下げ・削除 ----
if ($action === 'withdraw') {
    if ($report === null || $report['status'] !== 'submitted') {
        error_page(400);
    }
    tx(function () use ($report, $uid) {
        db_exec("UPDATE report_records SET status = 'draft', updated_at = ? WHERE id = ?", [now(), (int) $report['id']]);
        db_exec("INSERT INTO report_status_history (report_id, from_status, to_status, user_id, created_at) VALUES (?, 'submitted', 'draft', ?, ?)", [(int) $report['id'], $uid, now()]);
    });
    flash('success', msg('提出を取り下げました'));
    rk_done('/reports/edit.php?period=' . rawurlencode($period));
}
if ($action === 'delete') {
    if ($report === null || $report['status'] !== 'draft') {
        error_page(400);
    }
    db_exec('UPDATE report_records SET deleted_at = ?, updated_at = ? WHERE id = ?', [now(), now(), (int) $report['id']]);
    flash('success', msg('下書きを削除しました'));
    rk_done('/index.php');
}
if ($action === 'autosave' && $report !== null && $report['status'] !== 'draft') {
    rk_done_json(['ok' => false, 'reason' => 'not_draft']);
}

// ---- 入力の受け取りと確認 ----
if ($report === null && $user['department_id'] === null) {
    error_page(400, msg('所属の課が設定されていません。管理者に連絡してください。'));
}
$deptId = $report !== null ? ($report['department_id'] === null ? null : (int) $report['department_id']) : (int) $user['department_id'];
$fields = $report !== null ? form_snapshot_fields((int) $report['id']) : form_template_fields($deptId);

// 「前月から変更なし」: 送られた入力は使わず、前月の内容で提出する（F-20）
$unchanged = false;
if ($action === 'unchanged') {
    if ($report !== null && $report['status'] !== 'draft') {
        error_page(400);
    }
    $carry = report_carry_over($uid, $period, $fields);
    if ($carry['from'] === null) {
        rk_cancel();
        error_page(400, msg('前月の報告がないため、「変更なし」では提出できません。'));
    }
    $toRaw = function (array $f, string $v) {
        if ($f['type'] === 'multi_check' || $f['type'] === 'table') {
            $d = json_decode($v, true);
            return is_array($d) ? $d : [];
        }
        return $v;
    };
    $_POST['b'] = [];
    foreach ($carry['blocks'] as $pid) {
        $rf = [];
        foreach ($fields as $f) {
            if (isset($carry['values'][$pid][$f['id']])) {
                $rf[$f['id']] = $toRaw($f, $carry['values'][$pid][$f['id']]);
            }
        }
        $_POST['b'][] = ['project' => (string) $pid, 'f' => $rf];
    }
    $_POST['r'] = [];
    foreach ($fields as $f) {
        if (isset($carry['values'][0][$f['id']])) {
            $_POST['r'][$f['id']] = $toRaw($f, $carry['values'][0][$f['id']]);
        }
    }
    $action = 'submit';
    $unchanged = true;
}
$checkRequired = $action === 'submit' || ($action === 'save' && $report !== null && $report['status'] === 'submitted');
if ($action === 'save' && ($report === null || $report['status'] !== 'submitted')) {
    error_page(400);
}

$existingBlocks = $report !== null ? report_blocks((int) $report['id']) : [];
$existingProjectIds = array_map(function ($b) { return (int) $b['project_id']; }, $existingBlocks);
$allowedProjects = array_map(function ($p) { return (int) $p['id']; }, selectable_projects($deptId));
$oldValues = $report !== null ? report_values((int) $report['id']) : [];
$rpByProject = [];
foreach ($existingBlocks as $b) {
    $rpByProject[(int) $b['project_id']] = (int) $b['id'];
}

$errors = [];
$rawBlocks = $_POST['b'] ?? [];
$rawReport = $_POST['r'] ?? [];
if (!is_array($rawBlocks) || !is_array($rawReport)) {
    error_page(400);
}

$blockFields = array_values(array_filter($fields, function ($f) { return $f['scope'] === 'project' && $f['type'] !== 'project'; }));
$reportFields = array_values(array_filter($fields, function ($f) { return $f['scope'] === 'report'; }));

/** 項目群の値を確かめる（表示の条件を満たさない欄は空にする） */
$parseGroup = function (array $groupFields, array $raw, string $keyPrefix, array $old) use ($checkRequired, &$errors): array {
    $values = [];
    $byCode = [];
    $localErrors = [];
    foreach ($groupFields as $f) {
        $k = $keyPrefix . $f['id'];
        $values[$f['id']] = form_parse_value($f, $raw[$f['id']] ?? ($f['type'] === 'multi_check' || $f['type'] === 'table' ? [] : ''), $checkRequired, $localErrors, $k, (string) ($old[$f['id']]['value'] ?? ''));
        $byCode[$f['code']] = $values[$f['id']];
    }
    foreach ($groupFields as $f) {
        if (!form_is_visible($f, $byCode)) {
            $values[$f['id']] = '';
            unset($localErrors[$keyPrefix . $f['id']]);
        }
    }
    $errors = array_merge($errors, $localErrors);
    return $values;
};

$blocks = [];      // ['project_id' => int, 'values' => [fid => value]]
$seen = [];
$stateBlocks = []; // 入力エラーで戻すときの画面の状態
foreach (array_values($rawBlocks) as $i => $rb) {
    if (!is_array($rb)) {
        error_page(400);
    }
    $pidRaw = isset($rb['project']) && is_string($rb['project']) ? $rb['project'] : '';
    $remove = ($rb['remove'] ?? '') === '1';
    $rawF = isset($rb['f']) && is_array($rb['f']) ? $rb['f'] : [];
    if ($pidRaw === '' || $remove) {
        // 案件を選んでいない欄・外す欄は保存しない（入力があって案件がないときは知らせる）
        if ($pidRaw === '' && !$remove && $i < count($existingBlocks)) {
            $errors['b' . $i . '_project'] = msg('案件を選んでください（外すときは「この案件を外す」）');
        }
        continue;
    }
    if (preg_match('/^\d+$/', $pidRaw) !== 1) {
        error_page(400);
    }
    $pid = (int) $pidRaw;
    $idx = count($stateBlocks);
    if (!in_array($pid, $allowedProjects, true) && !in_array($pid, $existingProjectIds, true)) {
        $errors['b' . $idx . '_project'] = msg('この案件は選べません');
    }
    if (isset($seen[$pid])) {
        $errors['b' . $idx . '_project'] = msg('同じ案件が 2 つあります');
    }
    $seen[$pid] = true;
    $old = $oldValues[$rpByProject[$pid] ?? -1] ?? [];
    $vals = $parseGroup($blockFields, $rawF, 'b' . $idx . '_', $old);
    $blocks[] = ['project_id' => $pid, 'values' => $vals];
    $stateBlocks[] = ['project_id' => $pid, 'values' => $vals];
}
if (count($blocks) > 10) {
    $errors['general'] = msg('案件は 10 件までにしてください');
}
$reportVals = $parseGroup($reportFields, $rawReport, 'r_', $oldValues[0] ?? []);

if ($errors) {
    rk_cancel();
    if ($isJson) {
        json_response(['ok' => false, 'reason' => 'invalid']);
    }
    $state = ['blocks' => $stateBlocks, 'report' => $reportVals, 'warnings' => [], 'carry_from' => null, 'copy_from' => $copyFrom];
    $notices = $unchanged ? [msg('前月の内容に入力が必要な項目があります。入力して「提出する」を押してください。')] : [];
    report_edit_render($user, $period, $report, $fields, $state, $errors, $notices);
}

// ---- 保存 ----
$fieldsById = [];
foreach ($fields as $f) {
    $fieldsById[$f['id']] = $f;
}

$conflict = false;
try {
    $reportId = tx(function () use ($report, $uid, $deptId, $period, $fields, $fieldsById, $blocks, $reportVals, $rpByProject, $oldValues, $action, $unchanged, $copyFrom, &$conflict) {
        $now = now();
        if ($report === null) {
            // 同じ月の報告が別の画面・端末で先に作られていないか（書き込みの排他の中で確かめる）
            if (report_find_own($uid, $period) !== null) {
                $conflict = true;
                throw new RuntimeException('report already exists');
            }
            $carry = report_carry_over($uid, $period, $fields);
            db_exec(
                "INSERT INTO report_records (user_id, department_id, period, status, copied_from_id, created_at, updated_at) VALUES (?, ?, ?, 'draft', ?, ?, ?)",
                [$uid, $deptId, $period, $copyFrom ?? $carry['from'], $now, $now]
            );
            $rid = (int) db()->lastInsertId();
            form_write_snapshot($rid, $fields);
            $status = 'draft';
            $revision = 0;
        } else {
            $rid = (int) $report['id'];
            $status = (string) $report['status'];
            $revision = (int) $report['revision'];
            if ($copyFrom !== null && $status === 'draft') {
                // 下書きに過去の報告を写して保存した: 写した元を記録し直す
                db_exec('UPDATE report_records SET copied_from_id = ? WHERE id = ?', [$copyFrom, $rid]);
            }
        }
        $track = $status === 'submitted';
        $nextRevision = $revision + 1;
        $changed = false;

        $writeValue = function (int $rpId, array $f, string $new) use ($rid, $oldValues, $track, $nextRevision, $uid, $now, &$changed): void {
            $old = $oldValues[$rpId][$f['id']]['value'] ?? '';
            $old = $old === null ? '' : $old;
            if ($old === $new && isset($oldValues[$rpId][$f['id']])) {
                return;
            }
            if ($old === $new && $new === '') {
                return;
            }
            $encrypted = form_is_encrypted($f);
            $stored = ($encrypted && $new !== '') ? enc($new, form_aad($rid, $f['id'], $rpId)) : $new;
            $warning = form_is_free_text($f) && text_has_warning($new) ? 1 : 0;
            db_exec(
                'INSERT INTO report_field_values (report_id, field_id, report_project_id, value_text, is_encrypted, has_warning, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT(report_id, field_id, report_project_id) DO UPDATE SET value_text = excluded.value_text, is_encrypted = excluded.is_encrypted, has_warning = excluded.has_warning, updated_at = excluded.updated_at',
                [$rid, $f['id'], $rpId, $stored, ($encrypted && $new !== '') ? 1 : 0, $warning, $now]
            );
            if ($track && $old !== $new) {
                $aad = 'rvh:' . $rid . ':' . $f['id'] . ':' . $rpId . ':' . $nextRevision;
                db_exec(
                    'INSERT INTO report_value_history (report_id, field_id, report_project_id, revision, old_value, new_value, is_encrypted, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$rid, $f['id'], $rpId, $nextRevision, $encrypted ? enc($old, $aad . ':old') : $old, $encrypted ? enc($new, $aad . ':new') : $new, $encrypted ? 1 : 0, $uid, $now]
                );
            }
            if ($old !== $new) {
                $changed = true;
            }
        };

        // 案件ブロック: 残すものは同じ行を使い（コメントの紐付けを保つ）、外したものは消す
        $keep = [];
        foreach ($blocks as $order => $b) {
            $pid = $b['project_id'];
            if (isset($rpByProject[$pid])) {
                $rpId = $rpByProject[$pid];
                db_exec('UPDATE report_projects SET sort_order = ? WHERE id = ?', [($order + 1) * 10, $rpId]);
            } else {
                db_exec('INSERT INTO report_projects (report_id, project_id, sort_order) VALUES (?, ?, ?)', [$rid, $pid, ($order + 1) * 10]);
                $rpId = (int) db()->lastInsertId();
                if ($track) {
                    $changed = true;
                }
            }
            $keep[$rpId] = true;
            foreach ($b['values'] as $fid => $v) {
                $writeValue($rpId, $fieldsById[$fid], $v);
            }
        }
        foreach ($rpByProject as $pid => $rpId) {
            if (isset($keep[$rpId])) {
                continue;
            }
            foreach ($oldValues[$rpId] ?? [] as $fid => $ov) {
                if (isset($fieldsById[$fid]) && ($ov['value'] ?? '') !== '') {
                    $writeValue($rpId, $fieldsById[$fid], '');
                }
            }
            db_exec('DELETE FROM report_field_values WHERE report_id = ? AND report_project_id = ?', [$rid, $rpId]);
            db_exec('DELETE FROM report_projects WHERE id = ?', [$rpId]);
            if ($track) {
                $changed = true;
            }
        }
        foreach ($reportVals as $fid => $v) {
            $writeValue(0, $fieldsById[$fid], $v);
        }

        if ($action === 'submit') {
            db_exec("UPDATE report_records SET status = 'submitted', submitted_at = ?, unchanged = ?, updated_at = ? WHERE id = ?", [$now, $unchanged ? 1 : 0, $now, $rid]);
            db_exec('INSERT INTO report_status_history (report_id, from_status, to_status, user_id, created_at) VALUES (?, ?, ?, ?, ?)', [$rid, $status, 'submitted', $uid, $now]);
        } elseif ($track && $changed) {
            db_exec('UPDATE report_records SET revision = ?, updated_at = ? WHERE id = ?', [$nextRevision, $now, $rid]);
        } else {
            db_exec('UPDATE report_records SET updated_at = ? WHERE id = ?', [$now, $rid]);
        }
        return $rid;
    });
} catch (Throwable $e) {
    if (!$conflict && !($report === null && $e instanceof PDOException && report_find_own($uid, $period) !== null)) {
        throw $e;
    }
    // 同じ月の報告が別の画面・端末で作られていた。その報告を使い、この画面の入力を残して確かめてもらう
    rk_cancel();
    if ($isJson) {
        json_response(['ok' => false, 'reason' => 'conflict']);
    }
    $existing = report_find_own($uid, $period);
    if ($existing === null) {
        throw $e;
    }
    if ($existing['status'] === 'confirmed') {
        error_page(400, msg('この報告は確認が完了しているため、変更できません。'));
    }
    $state = ['blocks' => $stateBlocks, 'report' => $reportVals, 'warnings' => [], 'carry_from' => null, 'copy_from' => $copyFrom, 'unsaved' => true];
    report_edit_render($user, $period, $existing, form_snapshot_fields((int) $existing['id']), $state, [], [
        msg('同じ月の報告が別の画面で作られていました。この画面の入力を残しています。内容を確かめて、もう一度保存してください。'),
    ]);
}

if ($isJson) {
    rk_done_json(['ok' => true, 'saved_at' => date('H:i')]);
}
if ($action === 'submit') {
    flash('success', msg('提出しました'));
    rk_done('/reports/view.php?id=' . $reportId);
}
if ($action === 'save') {
    flash('success', msg('保存しました。変更があれば確認者に知らせます'));
    rk_done('/reports/view.php?id=' . $reportId);
}
flash('success', msg('下書きを保存しました'));
rk_done('/reports/edit.php?period=' . rawurlencode($period));
