<?php

declare(strict_types=1);

// ====================================================================
// 保管期限と後片付け（詳細設計書 DB 編 §5）
// 1 日 1 回、最初の要求のとき（ログイン画面）に実行する。
// ====================================================================

/** 物理削除する（関連のデータも消える。前月の引き継ぎ元の参照は外す） */
function retention_delete_report(int $rid): void
{
    db_exec('UPDATE report_records SET copied_from_id = NULL WHERE copied_from_id = ?', [$rid]);
    db_exec('DELETE FROM report_records WHERE id = ?', [$rid]);
}

function retention_run(): array
{
    $log = [];
    $now = now();

    // 1. 自由記述: 確認完了から一定期間を過ぎた報告の自由記述を消す（選択式の値は残す）
    $months = (int) cfg('free_text_retention_months');
    $limit = date('Y-m-d H:i:s', strtotime('-' . $months . ' months'));
    $targets = db_all(
        "SELECT id FROM report_records WHERE status = 'confirmed' AND confirmed_at IS NOT NULL AND confirmed_at < ? AND free_text_purged_at IS NULL",
        [$limit]
    );
    foreach ($targets as $r) {
        $rid = (int) $r['id'];
        tx(function () use ($rid, $now) {
            $freeIds = [];
            foreach (db_all("SELECT field_id FROM report_record_fields WHERE report_id = ? AND field_type IN ('single_text', 'multi_text', 'table')", [$rid]) as $f) {
                $freeIds[] = (int) $f['field_id'];
            }
            if ($freeIds) {
                $in = implode(',', $freeIds);
                db_exec("UPDATE report_field_values SET value_text = '', is_encrypted = 0, has_warning = 0 WHERE report_id = ? AND field_id IN ({$in})", [$rid]);
                db_exec("UPDATE report_value_history SET old_value = '', new_value = '', is_encrypted = 0 WHERE report_id = ? AND field_id IN ({$in})", [$rid]);
            }
            db_exec("UPDATE report_field_comments SET comment_enc = '' WHERE report_id = ?", [$rid]);
            db_exec('UPDATE report_records SET free_text_purged_at = ? WHERE id = ?', [$now, $rid]);
        });
    }
    $log[] = '自由記述を消した報告: ' . count($targets);

    // 2. 報告: 対象月から一定年数を過ぎたものを消す
    $years = (int) cfg('report_retention_years');
    $oldest = date('Y-m', strtotime('-' . $years . ' years'));
    $old = db_all('SELECT id FROM report_records WHERE period < ?', [$oldest]);
    foreach ($old as $r) {
        tx(function () use ($r) {
            retention_delete_report((int) $r['id']);
        });
    }
    $log[] = '保管期限で消した報告: ' . count($old);

    // 3. 削除したデータ: 一定日数を過ぎたら完全に消す
    $trash = date('Y-m-d H:i:s', strtotime('-' . (int) cfg('trash_days') . ' days'));
    $n = 0;
    foreach (db_all('SELECT id FROM report_records WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$trash]) as $r) {
        tx(function () use ($r) {
            retention_delete_report((int) $r['id']);
        });
        $n++;
    }
    // 削除したコメント: 返信があるものは本文だけ消して残し、ないものは消す
    db_exec("UPDATE report_field_comments SET comment_enc = '' WHERE deleted_at IS NOT NULL AND deleted_at < ?", [$trash]);
    db_exec('DELETE FROM report_field_comments WHERE deleted_at IS NOT NULL AND deleted_at < ? AND id NOT IN (SELECT parent_id FROM report_field_comments WHERE parent_id IS NOT NULL)', [$trash]);
    db_exec('DELETE FROM adhoc_requests WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$trash]);
    $log[] = '完全に消した削除データ（報告）: ' . $n;

    return $log;
}
