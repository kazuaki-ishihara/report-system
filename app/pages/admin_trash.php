<?php

declare(strict_types=1);

// ====================================================================
// G-15 削除データ: 削除した報告・コメント・臨時の調査を戻す（詳細設計書 G15_logs.md §6.2）
// 本文は復号しない。
// ====================================================================

$user = page_start();
require_admin($user);
$limit = date('Y-m-d H:i:s', strtotime('-' . (int) cfg('trash_days') . ' days'));

if (is_post()) {
    $action = post_str('action');
    rk_begin('admin.trash.' . $action, (int) $user['id']);
    $id = input_id('id', 'POST');
    if ($action === 'restore_report') {
        $r = db_one('SELECT * FROM report_records WHERE id = ? AND deleted_at IS NOT NULL', [$id]);
        if ($r === null) {
            error_page(404);
        }
        if ((int) $r['is_legacy'] === 0 && report_find_own((int) $r['user_id'], (string) $r['period']) !== null) {
            flash('error', '同じ人・同じ月の報告が新しく作られているため、戻せません');
            rk_done('/admin/trash.php');
        }
        db_exec('UPDATE report_records SET deleted_at = NULL, updated_at = ? WHERE id = ?', [now(), $id]);
    } elseif ($action === 'restore_comment') {
        $c = db_one('SELECT c.*, r.deleted_at AS report_deleted FROM report_field_comments c JOIN report_records r ON r.id = c.report_id WHERE c.id = ? AND c.deleted_at IS NOT NULL', [$id]);
        if ($c === null) {
            error_page(404);
        }
        if ($c['report_deleted'] !== null || $c['comment_enc'] === '') {
            flash('error', '報告が削除されているか、本文が残っていないため、戻せません');
            rk_done('/admin/trash.php');
        }
        db_exec('UPDATE report_field_comments SET deleted_at = NULL, updated_at = ? WHERE id = ?', [now(), $id]);
    } elseif ($action === 'restore_survey') {
        if (db_val('SELECT 1 FROM adhoc_requests WHERE id = ? AND deleted_at IS NOT NULL', [$id]) === null) {
            error_page(404);
        }
        db_exec('UPDATE adhoc_requests SET deleted_at = NULL, updated_at = ? WHERE id = ?', [now(), $id]);
    } else {
        error_page(400);
    }
    audit_log('admin', 'restore:' . substr($action, 8) . ':' . $id);
    flash('success', '戻しました');
    rk_done('/admin/trash.php');
}

$reports = db_all('SELECT r.id, r.period, r.status, r.deleted_at, u.display_name FROM report_records r JOIN users u ON u.id = r.user_id WHERE r.deleted_at IS NOT NULL AND r.deleted_at >= ? ORDER BY r.deleted_at DESC', [$limit]);
$comments = db_all(
    "SELECT c.id, c.report_id, c.deleted_at, u.display_name, COALESCE(f.label, '報告全体') AS field_label
       FROM report_field_comments c JOIN users u ON u.id = c.user_id
       LEFT JOIN report_record_fields f ON f.report_id = c.report_id AND f.field_id = c.field_id
      WHERE c.deleted_at IS NOT NULL AND c.deleted_at >= ? AND c.comment_enc <> '' ORDER BY c.deleted_at DESC",
    [$limit]
);
$surveys = db_all('SELECT q.id, q.title, q.deleted_at, u.display_name FROM adhoc_requests q JOIN users u ON u.id = q.created_by WHERE q.deleted_at IS NOT NULL AND q.deleted_at >= ? ORDER BY q.deleted_at DESC', [$limit]);

render('admin_trash', ['title' => '削除データ', 'user' => $user, 'reports' => $reports, 'comments' => $comments, 'surveys' => $surveys]);
