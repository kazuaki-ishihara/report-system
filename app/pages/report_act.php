<?php

declare(strict_types=1);

// ====================================================================
// G-05 報告の詳細: コメント・返信・解決・削除、チェック、確認完了（詳細設計書 G05_report_detail.md §6）
// ====================================================================

$user = page_start();
$uid = (int) $user['id'];
if (!is_post()) {
    error_page(400);
}
$action = post_str('action');
$id = input_id('report_id', 'POST');
$report = report_find($id);
if ($report === null || !can_view_report($user, $report)) {
    error_page(404);
}
if ($report['status'] === 'draft') {
    error_page(404);
}
rk_begin('report.act.' . $action, $uid);

$isOwner = (int) $report['user_id'] === $uid;
$deptId = $report['department_id'] === null ? 0 : (int) $report['department_id'];
// 確認者としてこの報告を見られるか（引き継ぎ・課を移った人・班ごとの課を含む。共通編 §10.2）
$isReviewer = !$isOwner && reviewer_can_see_report($uid, $report, false);
// チェックを求められている確認者か（報告を作ったときの課と、それを引き継いだ課の確認者で、班が合う人）
$isExpectedReviewer = !$isOwner && in_array($uid, report_expected_reviewer_ids($report), true);
$isFinal = reviewer_can_see_report($uid, $report, true, false);   // 報告の課（と引き継いだ課）の課長だけ。管理者でも課長でなければ確認完了にできない
$back = '/reports/view.php?id=' . $id;

/** コメントの本文を確かめる */
$readComment = function (): string {
    $text = str_replace(["\r\n", "\r"], "\n", post_str('comment'));
    if ($text === '' || str_len($text) > 1000 || has_control_chars($text, true)) {
        rk_cancel();
        flash('error', msg('コメントは 1〜1000 文字で入れてください'));
        redirect('/reports/view.php?id=' . (int) $_POST['report_id']);
    }
    return $text;
};

/** コメントを保存する（本文は暗号化。付加データにコメントの ID を入れるため、先に行を作る） */
$insertComment = function (array $cols, string $text) use ($id): int {
    db_exec(
        'INSERT INTO report_field_comments (report_id, field_id, report_project_id, user_id, parent_id, comment_type, thread_status, comment_enc, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, $cols['field_id'], $cols['rp'], $cols['user_id'], $cols['parent_id'], $cols['type'], $cols['status'], '', now(), now()]
    );
    $cid = (int) db()->lastInsertId();
    db_exec('UPDATE report_field_comments SET comment_enc = ? WHERE id = ?', [enc($text, 'cmt:' . $id . ':' . $cid), $cid]);
    return $cid;
};

switch ($action) {
    case 'comment':
        // 本人は最初のコメントを付けない（管理者でも同じ）
        if ($isOwner || !$isReviewer) {
            error_page(403);
        }
        $type = post_str('comment_type');
        if (!in_array($type, ['request', 'confirm', 'note'], true)) {
            error_page(400);
        }
        $fid = (int) post_str('field_id');
        $rp = (int) post_str('report_project_id');
        if ($fid !== 0) {
            $f = db_one('SELECT confidentiality, field_scope FROM report_record_fields WHERE report_id = ? AND field_id = ?', [$id, $fid]);
            if ($f === null || ($f['confidentiality'] === 'restricted' && !can_view_restricted($user, $report))) {
                error_page(400);
            }
            // 案件ごとの項目は案件ブロックに、報告全体の項目は報告全体（0）に付ける（画面に出ない位置には付けない）
            if (($f['field_scope'] === 'project') !== ($rp !== 0)) {
                error_page(400);
            }
        }
        if ($rp !== 0 && db_val('SELECT 1 FROM report_projects WHERE id = ? AND report_id = ?', [$rp, $id]) === null) {
            error_page(400);
        }
        $text = $readComment();
        $cid = tx(function () use ($insertComment, $fid, $rp, $uid, $type, $text) {
            return $insertComment(['field_id' => $fid === 0 ? null : $fid, 'rp' => $rp, 'user_id' => $uid, 'parent_id' => null,
                'type' => $type, 'status' => $type === 'note' ? null : 'open'], $text);
        });
        flash('success', msg('コメントしました'));
        rk_done($back . '#comment-' . $cid);

    case 'reply':
    case 'resolve':
    case 'reopen':
        $parentId = input_id('parent_id', 'POST');
        $head = db_one('SELECT * FROM report_field_comments WHERE id = ? AND report_id = ? AND parent_id IS NULL', [$parentId, $id]);
        if ($head === null) {
            error_page(404);
        }
        if ($head['field_id'] !== null) {
            $f = db_one('SELECT confidentiality FROM report_record_fields WHERE report_id = ? AND field_id = ?', [$id, (int) $head['field_id']]);
            if ($f !== null && $f['confidentiality'] === 'restricted' && !can_view_restricted($user, $report)) {
                error_page(404);
            }
        }
        if ($action === 'reply') {
            $text = $readComment();
            $cid = tx(function () use ($insertComment, $head, $uid, $text, $isOwner) {
                $cid = $insertComment(['field_id' => $head['field_id'], 'rp' => (int) $head['report_project_id'], 'user_id' => $uid,
                    'parent_id' => (int) $head['id'], 'type' => 'note', 'status' => null], $text);
                if ($isOwner && $head['thread_status'] === 'open') {
                    db_exec("UPDATE report_field_comments SET thread_status = 'answered', updated_at = ? WHERE id = ?", [now(), (int) $head['id']]);
                }
                return $cid;
            });
            flash('success', msg('返信しました'));
            rk_done($back . '#comment-' . $cid);
        }
        if ((int) $head['user_id'] !== $uid && !$isFinal) {
            error_page(403);
        }
        if ($head['thread_status'] === null) {
            error_page(400);
        }
        if ($action === 'resolve') {
            db_exec("UPDATE report_field_comments SET thread_status = 'resolved', resolved_by = ?, resolved_at = ?, updated_at = ? WHERE id = ?", [$uid, now(), now(), (int) $head['id']]);
            flash('success', msg('解決にしました'));
        } else {
            db_exec("UPDATE report_field_comments SET thread_status = 'open', resolved_by = NULL, resolved_at = NULL, updated_at = ? WHERE id = ?", [now(), (int) $head['id']]);
            flash('success', msg('回答待ちに戻しました'));
        }
        rk_done($back . '#comment-' . (int) $head['id']);

    case 'comment_delete':
        $cid = input_id('comment_id', 'POST');
        $c = db_one('SELECT * FROM report_field_comments WHERE id = ? AND report_id = ?', [$cid, $id]);
        if ($c === null || $c['deleted_at'] !== null) {
            error_page(404);
        }
        if ((int) $c['user_id'] !== $uid) {
            error_page(403);
        }
        // 「（削除されました）」として表示する（返信のつながりを保つ）。本文は暗号化のまま 30 日残し、管理者が戻せる
        db_exec('UPDATE report_field_comments SET deleted_at = ?, updated_at = ? WHERE id = ?', [now(), now(), $cid]);
        if ((int) $c['user_id'] !== $uid) {
            audit_log('admin', 'comment:' . $cid . ':delete');
        }
        flash('success', msg('コメントを削除しました'));
        rk_done($back);

    case 'check':
        if (!$isExpectedReviewer) {
            error_page(403);
        }
        db_exec(
            'INSERT INTO report_reviews (report_id, user_id, checked_revision, checked_at) VALUES (?, ?, ?, ?)
             ON CONFLICT(report_id, user_id) DO UPDATE SET checked_revision = excluded.checked_revision, checked_at = excluded.checked_at',
            [$id, $uid, (int) $report['revision'], now()]
        );
        // 「未チェックを順に見る」: 同じ課・同じ月の次の未チェックへ
        if (($_POST['seq'] ?? '') === '1') {
            // 次の未チェック: 同じ課・同じ月の、自分が見られる（班ごとの課では担当の班の）報告
            $nextId = null;
            foreach (db_all(
                "SELECT r.* FROM report_records r LEFT JOIN report_reviews v ON v.report_id = r.id AND v.user_id = ?
                  WHERE r.department_id = ? AND r.period = ? AND r.status IN ('submitted', 'confirmed') AND r.deleted_at IS NULL
                    AND r.user_id <> ? AND (v.report_id IS NULL OR v.checked_revision < r.revision)
                  ORDER BY r.submitted_at, r.id",
                [$uid, $deptId, (string) $report['period'], $uid]
            ) as $nextCandidateReport) {
                if (can_view_report($user, $nextCandidateReport)) {
                    $nextId = (int) $nextCandidateReport['id'];
                    break;
                }
            }
            flash('success', msg('チェック済みにしました'));
            if ($nextId !== null) {
                rk_done('/reports/view.php?id=' . (int) $nextId . '&seq=1');
            }
            flash('info', msg('未チェックの報告はもうありません'));
            rk_done('/status/index.php?period=' . rawurlencode((string) $report['period']) . '&dept=' . $deptId);
        }
        flash('success', msg('チェック済みにしました'));
        rk_done($back);

    case 'confirm':
    case 'unconfirm':
        if (!$isFinal || $isOwner) {
            error_page(403);
        }
        if ($action === 'confirm' && $report['status'] !== 'submitted') {
            error_page(400);
        }
        if ($action === 'unconfirm' && $report['status'] !== 'confirmed') {
            error_page(400);
        }
        tx(function () use ($action, $id, $uid, $report, $isOwner) {
            $now = now();
            if ($action === 'confirm') {
                db_exec("UPDATE report_records SET status = 'confirmed', confirmed_by = ?, confirmed_at = ?, updated_at = ? WHERE id = ?", [$uid, $now, $now, $id]);
                if (!$isOwner) {
                    db_exec(
                        'INSERT INTO report_reviews (report_id, user_id, checked_revision, checked_at) VALUES (?, ?, ?, ?)
                         ON CONFLICT(report_id, user_id) DO UPDATE SET checked_revision = excluded.checked_revision, checked_at = excluded.checked_at',
                        [$id, $uid, (int) $report['revision'], $now]
                    );
                }
                db_exec("INSERT INTO report_status_history (report_id, from_status, to_status, user_id, created_at) VALUES (?, 'submitted', 'confirmed', ?, ?)", [$id, $uid, $now]);
            } else {
                db_exec("UPDATE report_records SET status = 'submitted', confirmed_by = NULL, confirmed_at = NULL, updated_at = ? WHERE id = ?", [$now, $id]);
                db_exec("INSERT INTO report_status_history (report_id, from_status, to_status, user_id, created_at) VALUES (?, 'confirmed', 'submitted', ?, ?)", [$id, $uid, $now]);
            }
        });
        flash('success', $action === 'confirm' ? msg('確認を完了しました') : msg('確認完了を取り消しました'));
        rk_done($back);

    default:
        error_page(400);
}
