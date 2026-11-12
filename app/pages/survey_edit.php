<?php

declare(strict_types=1);

// ====================================================================
// G-11 臨時の調査の作成・編集・公開・締め切り・複製・削除（詳細設計書 G11_survey_manage.md）
// ====================================================================

$user = page_start();
$uid = (int) $user['id'];
$depts = final_departments($user);
if (!$depts) {
    error_page(403);
}

$id = isset($_GET['id']) ? input_id('id') : null;
$req = null;
if ($id !== null) {
    $req = survey_find($id);
    if ($req === null) {
        error_page(404);
    }
    if (!survey_can_manage($user, $req)) {
        error_page(403);
    }
}
$editable = $req === null || $req['status'] === 'draft';
$errors = [];

/** 課の有効な利用者（対象の候補） */
$members = function (int $deptId): array {
    return db_all('SELECT id, display_name, login_id FROM users WHERE department_id = ? AND is_active = 1 ORDER BY display_name', [$deptId]);
};

if (is_post()) {
    $action = post_str('action');
    rk_begin('survey.' . $action, $uid);
    $back = function (int $rid): string { return '/surveys/survey_edit.php?id=' . $rid; };

    if ($action === 'save_meta') {
        $title = post_str('title');
        $requester = post_str('requester');
        $purpose = post_str('purpose');
        $due = post_str('due_at');
        $vis = post_str('result_visibility');
        $targetType = post_str('target_type');
        validate_text($title, '題名', 100, true, $errors);
        validate_text($requester, '依頼元', 100, false, $errors);
        validate_text($purpose, '目的', 300, false, $errors, true);
        if ($due !== '' && (DateTime::createFromFormat('!Y-m-d', $due) === false || $due < date('Y-m-d'))) {
            $errors[] = '回答期限は今日以降の日付にしてください';
        }
        if (!in_array($vis, ['summary', 'detail'], true) || !in_array($targetType, ['all', 'users'], true)) {
            error_page(400);
        }
        $deptId = $req === null ? (int) post_str('department_id') : (int) $req['department_id'];
        if (!isset($depts[$deptId])) {
            error_page(403);
        }
        $targets = [];
        if ($targetType === 'users') {
            $raw = $_POST['targets'] ?? [];
            if (!is_array($raw)) {
                error_page(400);
            }
            $allowed = array_map(function ($m) { return (int) $m['id']; }, is_admin($user)
                ? db_all('SELECT id FROM users WHERE is_active = 1') : $members($deptId));
            foreach ($raw as $t) {
                if (!is_string($t) || !in_array((int) $t, $allowed, true)) {
                    error_page(400);
                }
                $targets[] = (int) $t;
            }
        }
        if (!$errors) {
            if ($req !== null && $req['status'] !== 'draft') {
                // 公開後は題名・目的・期限だけ変えられる
                db_exec('UPDATE adhoc_requests SET title = ?, requester = ?, purpose = ?, due_at = ?, updated_at = ? WHERE id = ?', [$title, $requester, $purpose, $due === '' ? null : $due, now(), (int) $req['id']]);
                $rid = (int) $req['id'];
            } else {
                $rid = tx(function () use ($req, $deptId, $title, $requester, $purpose, $due, $vis, $targetType, $targets, $uid) {
                    if ($req === null) {
                        db_exec(
                            "INSERT INTO adhoc_requests (department_id, title, requester, purpose, result_visibility, target_type, status, due_at, created_by, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?)",
                            [$deptId, $title, $requester, $purpose, $vis, $targetType, $due === '' ? null : $due, $uid, now(), now()]
                        );
                        $rid = (int) db()->lastInsertId();
                    } else {
                        $rid = (int) $req['id'];
                        db_exec('UPDATE adhoc_requests SET title = ?, requester = ?, purpose = ?, result_visibility = ?, target_type = ?, due_at = ?, updated_at = ? WHERE id = ?',
                            [$title, $requester, $purpose, $vis, $targetType, $due === '' ? null : $due, now(), $rid]);
                    }
                    db_exec('DELETE FROM adhoc_request_targets WHERE request_id = ?', [$rid]);
                    foreach (array_unique($targets) as $t) {
                        db_exec('INSERT INTO adhoc_request_targets (request_id, user_id) VALUES (?, ?)', [$rid, $t]);
                    }
                    return $rid;
                });
            }
            flash('success', '保存しました');
            rk_done($back($rid));
        }
        rk_cancel();
    } else {
        if ($req === null) {
            error_page(400);
        }
        $rid = (int) $req['id'];
        $needDraft = ['add_source', 'add_custom', 'q_remove', 'q_up', 'q_down', 'publish'];
        if (in_array($action, $needDraft, true) && !$editable) {
            error_page(400, '公開後は質問を変えられません。');
        }
        if ($action === 'add_source') {
            $fid = input_id('field_id', 'POST');
            $f = db_one("SELECT * FROM report_fields WHERE id = ? AND is_active = 1 AND field_type NOT IN ('project', 'table') AND (department_id IS NULL OR department_id = ?)", [$fid, (int) $req['department_id']]);
            if ($f === null) {
                error_page(400);
            }
            $def = form_row_to_def($f, form_field_options($fid, true));
            db_exec(
                'INSERT INTO adhoc_request_fields (request_id, source_field_id, label, field_type, confidentiality, is_required, help_text, settings_json, options_json, sort_order)
                 VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, (SELECT COALESCE(MAX(sort_order), 0) + 10 FROM adhoc_request_fields WHERE request_id = ?))',
                [$rid, $fid, $def['label'], $def['type'], $def['conf'], $def['help'], json_encode($def['settings'], JSON_UNESCAPED_UNICODE), json_encode($def['options'], JSON_UNESCAPED_UNICODE), $rid]
            );
            rk_done($back($rid));
        } elseif ($action === 'add_custom') {
            $label = post_str('q_label');
            $type = post_str('q_type');
            $conf = post_str('q_conf');
            $help = post_str('q_help');
            validate_text($label, '質問', 100, true, $errors);
            validate_text($help, '説明', 300, false, $errors, true);
            if (!in_array($type, SURVEY_TYPES, true) || !isset(FIELD_CONF[$conf])) {
                error_page(400);
            }
            if ($conf === 'restricted' && !is_admin($user) && !is_final_reviewer_of($uid, (int) $req['department_id'])) {
                error_page(403);
            }
            $options = [];
            if (in_array($type, CHOICE_TYPES, true)) {
                $i = 0;
                foreach (preg_split('/\r\n|\r|\n/', post_str('q_options')) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $i++;
                    validate_text($line, '選択肢', 60, true, $errors);
                    $options[] = ['code' => 'o' . $i, 'label' => $line, 'active' => true];
                }
                if (!$options) {
                    $errors[] = '選択肢を 1 行に 1 つずつ入れてください';
                }
            }
            if (!$errors) {
                db_exec(
                    'INSERT INTO adhoc_request_fields (request_id, label, field_type, confidentiality, is_required, help_text, settings_json, options_json, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, (SELECT COALESCE(MAX(sort_order), 0) + 10 FROM adhoc_request_fields WHERE request_id = ?))',
                    [$rid, $label, $type, $conf, ($_POST['q_required'] ?? '') === '1' ? 1 : 0, $help, '{}', json_encode($options, JSON_UNESCAPED_UNICODE), $rid]
                );
                rk_done($back($rid));
            }
            rk_cancel();
        } elseif (in_array($action, ['q_remove', 'q_up', 'q_down'], true)) {
            $qid = input_id('q_id', 'POST');
            $ids = array_map(function ($r) { return (int) $r['id']; }, db_all('SELECT id FROM adhoc_request_fields WHERE request_id = ? ORDER BY sort_order, id', [$rid]));
            $pos = array_search($qid, $ids, true);
            if ($pos === false) {
                error_page(404);
            }
            if ($action === 'q_remove') {
                db_exec('DELETE FROM adhoc_request_fields WHERE id = ? AND request_id = ?', [$qid, $rid]);
            } else {
                $swap = $action === 'q_up' ? $pos - 1 : $pos + 1;
                if ($swap >= 0 && $swap < count($ids)) {
                    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
                    foreach ($ids as $i => $x) {
                        db_exec('UPDATE adhoc_request_fields SET sort_order = ? WHERE id = ?', [($i + 1) * 10, $x]);
                    }
                }
            }
            rk_done($back($rid));
        } elseif ($action === 'publish') {
            if ((int) $req['is_template'] === 1) {
                error_page(400, 'ひな形は公開できません。複製して使ってください。');
            }
            $qCount = (int) db_val('SELECT COUNT(*) FROM adhoc_request_fields WHERE request_id = ?', [$rid]);
            if ($req['due_at'] === null || $req['due_at'] < date('Y-m-d')) {
                $errors[] = '回答期限（今日以降）を入れてから公開してください';
            }
            if ($qCount < 1 || $qCount > 20) {
                $errors[] = '質問は 1〜20 問にしてください';
            }
            if (!$errors) {
                try {
                tx(function () use ($req, $rid, $members) {
                    if ($req['target_type'] === 'all') {
                        db_exec('DELETE FROM adhoc_request_targets WHERE request_id = ?', [$rid]);
                        foreach ($members((int) $req['department_id']) as $m) {
                            db_exec('INSERT INTO adhoc_request_targets (request_id, user_id) VALUES (?, ?)', [$rid, (int) $m['id']]);
                        }
                    }
                    $n = (int) db_val('SELECT COUNT(*) FROM adhoc_request_targets WHERE request_id = ?', [$rid]);
                    if ($n === 0) {
                        throw new DomainException('no targets');
                    }
                    db_exec("UPDATE adhoc_requests SET status = 'open', opened_at = ?, updated_at = ? WHERE id = ?", [now(), now(), $rid]);
                });
                } catch (DomainException $e) {
                    $errors[] = '対象者がいません';
                }
            }
            if (!$errors) {
                audit_log('admin', 'survey:' . $rid . ':open');
                flash('success', '公開しました。対象者のホーム画面に表示されます');
                rk_done($back($rid));
            }
            rk_cancel();
        } elseif ($action === 'close') {
            if ($req['status'] !== 'open') {
                error_page(400);
            }
            db_exec("UPDATE adhoc_requests SET status = 'closed', closed_at = ?, updated_at = ? WHERE id = ?", [now(), now(), $rid]);
            audit_log('admin', 'survey:' . $rid . ':close');
            flash('success', '締め切りました');
            rk_done($back($rid));
        } elseif ($action === 'copy' || $action === 'save_template') {
            $newId = tx(function () use ($req, $uid, $action) {
                return survey_copy($req, $uid, $action === 'save_template');
            });
            flash('success', $action === 'copy' ? '複製しました' : 'ひな形として保存しました');
            rk_done($back($newId));
        } elseif ($action === 'delete') {
            db_exec('UPDATE adhoc_requests SET deleted_at = ?, updated_at = ? WHERE id = ?', [now(), now(), $rid]);
            audit_log('admin', 'survey:' . $rid . ':delete');
            flash('success', '削除しました（30 日間は管理者が戻せます）');
            rk_done('/surveys/manage.php');
        } else {
            error_page(400);
        }
    }
}

if ($req !== null) {
    $req = survey_find((int) $req['id']);
    $editable = $req['status'] === 'draft';
}
$deptForMembers = $req !== null ? (int) $req['department_id'] : (int) array_key_first($depts);
render('survey_edit', [
    'title'     => $req === null ? '臨時の調査を作る' : '臨時の調査',
    'user'      => $user,
    'req'       => $req,
    'depts'     => $depts,
    'editable'  => $editable,
    'errors'    => $errors,
    'questions' => $req === null ? [] : survey_fields((int) $req['id']),
    'members'   => $members($deptForMembers),
    'targets'   => $req === null ? [] : array_map(function ($r) { return (int) $r['user_id']; }, db_all('SELECT user_id FROM adhoc_request_targets WHERE request_id = ?', [(int) $req['id']])),
    'sources'   => $req === null ? [] : array_values(array_filter(form_template_fields((int) $req['department_id']), function ($f) { return !in_array($f['type'], ['project', 'table'], true); })),
    'post'      => is_post() ? $_POST : null,
]);
