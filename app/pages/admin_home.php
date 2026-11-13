<?php

declare(strict_types=1);

// ====================================================================
// G-21 管理のトップ（詳細設計書 G21_admin_home.md）
// 管理の仕事ごとにカードを並べ、今の状態（気付くべきこと）と入口を出す。報告の中身は出さない。
// ====================================================================

$user = page_start();
$adminMenuItemList = array_values(array_filter(menu_items($user), function (array $menuItem): bool {
    return $menuItem[2] === '管理' && $menuItem[0] !== '/admin/index.php';
}));
if (!$adminMenuItemList) {
    error_page(403);
}
$isAdmin = is_admin($user);
$isOperatorDeputy = !$isAdmin && is_admin_deputy($user);
$canManageSystem = can_manage_system($user);

// ---- メンテナンスの開始・終了（便利機能 F。管理者・補佐） ----
$maintenanceErrorTextList = [];
if (is_post()) {
    if (!$canManageSystem) {
        error_page(403);
    }
    $action = post_str('action');
    if (!in_array($action, ['maintenance_start', 'maintenance_end'], true)) {
        error_page(400);
    }
    rk_begin('admin.' . $action, (int) $user['id']);
    if ($action === 'maintenance_end') {
        maintenance_end();
        audit_log('admin', 'maintenance:end');
        flash('success', msg('メンテナンスを終えました'));
        rk_done('/admin/index.php');
    }
    $untilInputText = post_str('until');   // 日時の部品の値（2026-11-13T18:00）
    $untilDateTime = DateTime::createFromFormat('!Y-m-d\TH:i', $untilInputText);
    $maintenanceMessageText = post_str('message');
    if ($untilDateTime === false) {
        $maintenanceErrorTextList[] = msg('終わる日時を入れてください');
    } elseif ($untilDateTime->format('Y-m-d H:i:s') <= now()) {
        $maintenanceErrorTextList[] = msg('終わる日時は、今より後にしてください');
    } elseif ($untilDateTime->getTimestamp() > time() + 7 * 86400) {
        $maintenanceErrorTextList[] = msg('メンテナンスは 7 日以内にしてください');
    }
    validate_text($maintenanceMessageText, msg('一言'), MAINTENANCE_MESSAGE_MAX_LENGTH, false, $maintenanceErrorTextList);
    if (!$maintenanceErrorTextList) {
        maintenance_start($untilDateTime->format('Y-m-d H:i:s'), $maintenanceMessageText, (int) $user['id']);
        audit_log('admin', 'maintenance:start:' . $untilDateTime->format('Y-m-d H:i'));
        flash('success', msg('メンテナンス中にしました（{until} まで）', ['until' => display_datetime($untilDateTime->format('Y-m-d H:i:s'))]));
        rk_done('/admin/index.php');
    }
    rk_cancel();
}

/**
 * カード: 管理の区分ごとの説明と、今の状態（数・気付くべきこと）。
 * statusList の要素: ['text' => 表示, 'isAttention' => 目立たせるか]
 */
$cardList = [];
foreach ($adminMenuItemList as $adminMenuItem) {
    [$sectionPath, $sectionLabel] = $adminMenuItem;
    $statusList = [];
    $descriptionText = '';
    if ($sectionPath === '/admin/fields.php') {
        $descriptionText = '報告の様式（共通の項目・課の項目・選択肢・課ごとの選択肢）';
        $statusList[] = ['text' => '有効な項目 ' . (int) db_val('SELECT COUNT(*) FROM report_fields WHERE is_active = 1') . ' 件', 'isAttention' => false];
    } elseif ($sectionPath === '/admin/comment_templates.php') {
        $descriptionText = 'コメントで選んで使う定型文（課の定型文・自分の定型文）';
        $statusList[] = ['text' => '自分の定型文 ' . (int) db_val('SELECT COUNT(*) FROM personal_comment_templates WHERE user_id = ?', [(int) $user['id']]) . ' 件', 'isAttention' => false];
    } elseif ($sectionPath === '/admin/messages.php') {
        $descriptionText = '画面に出す文言（全体で 1 つ）';
        $statusList[] = ['text' => '変えた文言 ' . (int) db_val('SELECT COUNT(*) FROM ui_messages') . ' 件', 'isAttention' => false];
    } elseif ($sectionPath === '/admin/notices.php') {
        $descriptionText = 'システムの改善や変更を、全員のホームに知らせる（出す期間の間だけ）';
        $statusList[] = ['text' => '出しているお知らせ ' . count(notices_active(100)) . ' 件', 'isAttention' => false];
    } elseif ($sectionPath === '/admin/department.php') {
        $descriptionText = '自分の課の締め日・管理の仕方・確認者（課代・リーダー）・チェックする人・項目管理者・班';
        $finalDepartmentCount = (int) db_val('SELECT COUNT(*) FROM department_reviewers r JOIN departments d ON d.id = r.department_id AND d.is_active = 1 WHERE r.user_id = ? AND r.is_final = 1', [(int) $user['id']]);
        $statusList[] = ['text' => '管理する課 ' . $finalDepartmentCount, 'isAttention' => false];
    } elseif ($sectionPath === '/requests/index.php') {
        $descriptionText = '課長・確認者からの改善要望・対応依頼・不具合・質問。対応した内容や検討の結果を返信する';
        $supportRequestCounts = support_request_staff_counts();
        $statusList[] = ['text' => '未受付 ' . $supportRequestCounts['open'] . ' 件', 'isAttention' => $supportRequestCounts['open'] > 0];
        if ($supportRequestCounts['waiting_reply'] > 0) {
            $statusList[] = ['text' => '返信待ち ' . $supportRequestCounts['waiting_reply'] . ' 件', 'isAttention' => true];
        }
    } elseif ($sectionPath === '/admin/projects.php') {
        $descriptionText = '案件コードと社内向けの呼び名（客先名は入れない）。案件台帳との突き合わせ用の CSV';
        $statusList[] = ['text' => '有効な案件 ' . (int) db_val('SELECT COUNT(*) FROM projects WHERE is_active = 1') . ' 件', 'isAttention' => false];
    } elseif ($sectionPath === '/admin/org.php') {
        $descriptionText = '課・班・ユーザー・確認者・組織の一括の入れ替え（CSV）';
        $departmentWithoutFinalCount = (int) db_val(
            "SELECT COUNT(*) FROM departments d WHERE d.is_active = 1 AND NOT EXISTS (
                SELECT 1 FROM department_reviewers r JOIN users u ON u.id = r.user_id AND u.is_active = 1 WHERE r.department_id = d.id AND r.is_final = 1)"
        );
        $userWithoutTotpCount = (int) db_val('SELECT COUNT(*) FROM users WHERE is_active = 1 AND totp_enabled_at IS NULL');
        $lockedUserCount = (int) db_val('SELECT COUNT(*) FROM users WHERE is_active = 1 AND locked_until IS NOT NULL AND locked_until > ?', [now()]);
        $statusList[] = ['text' => '有効な課 ' . (int) db_val('SELECT COUNT(*) FROM departments WHERE is_active = 1') . ' ／ 有効なユーザー ' . (int) db_val('SELECT COUNT(*) FROM users WHERE is_active = 1'), 'isAttention' => false];
        if ($departmentWithoutFinalCount > 0) {
            $statusList[] = ['text' => '課長がいない課 ' . $departmentWithoutFinalCount, 'isAttention' => true];
        }
        if ($userWithoutTotpCount > 0) {
            $statusList[] = ['text' => '2 段階認証が未設定の人 ' . $userWithoutTotpCount, 'isAttention' => true];
        }
        if ($lockedUserCount > 0) {
            $statusList[] = ['text' => 'ロック中の人 ' . $lockedUserCount, 'isAttention' => true];
        }
        // 組織の改訂の予約（便利機能 G）
        $nextScheduleDate = db_val("SELECT MIN(apply_on) FROM org_schedules WHERE status = 'scheduled'");
        if ($nextScheduleDate !== null) {
            $statusList[] = ['text' => '組織の改訂の予約 ' . display_date((string) $nextScheduleDate), 'isAttention' => false];
        }
        $failedScheduleCount = (int) db_val("SELECT COUNT(*) FROM org_schedules WHERE status = 'failed' AND finished_at >= ?", [date('Y-m-d H:i:s', strtotime('-30 days'))]);
        if ($failedScheduleCount > 0) {
            $statusList[] = ['text' => '反映できなかった予約 ' . $failedScheduleCount, 'isAttention' => true];
        }
    } elseif ($sectionPath === '/admin/logs.php') {
        $descriptionText = '閲覧・出力・管理の操作の記録と、削除データ（期間内なら戻せる）';
        $trashLimitText = date('Y-m-d H:i:s', strtotime('-' . (int) cfg('trash_days') . ' days'));
        $trashCount = (int) db_val('SELECT COUNT(*) FROM report_records WHERE deleted_at IS NOT NULL AND deleted_at >= ?', [$trashLimitText])
            + (int) db_val("SELECT COUNT(*) FROM report_field_comments WHERE deleted_at IS NOT NULL AND deleted_at >= ? AND comment_enc <> ''", [$trashLimitText])
            + (int) db_val('SELECT COUNT(*) FROM adhoc_requests WHERE deleted_at IS NOT NULL AND deleted_at >= ?', [$trashLimitText]);
        // 補佐は閲覧・出力の記録を見られないので、ログインと管理の操作の数だけ
        $todayLogCountSql = 'SELECT COUNT(*) FROM access_logs WHERE created_at >= ?' . ($isOperatorDeputy ? " AND action IN ('admin', 'auth')" : '');
        $statusList[] = ['text' => '今日の記録 ' . (int) db_val($todayLogCountSql, [date('Y-m-d') . ' 00:00:00']) . ' 件', 'isAttention' => false];
        if ($isAdmin) {
            // 補佐の代行の操作（直近 7 日）。管理者が確かめる（K-043）
            $deputyOperationCount = (int) db_val('SELECT COUNT(*) FROM access_logs WHERE acted_as_deputy = 1 AND created_at >= ?', [date('Y-m-d H:i:s', strtotime('-7 days'))]);
            if ($deputyOperationCount > 0) {
                $statusList[] = ['text' => '補佐の操作（7 日） ' . $deputyOperationCount . ' 件', 'isAttention' => true];
            }
        }
        $statusList[] = ['text' => '戻せる削除データ ' . $trashCount . ' 件', 'isAttention' => false];
        // ログインの失敗（直近 24 時間）。不正なアクセスに気付くため（K-041）
        $loginFailureCount = (int) db_val("SELECT COUNT(*) FROM access_logs WHERE action = 'auth' AND target LIKE 'login:%' AND target <> 'login:ok' AND created_at >= ?",
            [date('Y-m-d H:i:s', strtotime('-1 day'))]);
        if ($loginFailureCount > 0) {
            $statusList[] = ['text' => 'ログインの失敗（24 時間） ' . $loginFailureCount . ' 件', 'isAttention' => true];
        }
    }
    $cardList[] = ['path' => $sectionPath, 'label' => $sectionLabel, 'description' => $descriptionText, 'statusList' => $statusList];
}

render('admin_home', [
    'title'                    => '管理のトップ',
    'user'                     => $user,
    'cardList'                 => $cardList,
    'canManageSystem'          => $canManageSystem,
    'backupStatus'             => $canManageSystem ? backup_status() : null,
    'maintenanceState'         => maintenance_state(),
    'maintenanceErrorTextList' => $maintenanceErrorTextList,
]);
