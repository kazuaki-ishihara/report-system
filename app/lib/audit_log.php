<?php

declare(strict_types=1);

// ====================================================================
// 記録（詳細設計書 共通編 §13）。中身（値・コメント・パスワード）は入れない。
// ====================================================================

function audit_log(string $action, string $target, ?int $userId = null): void
{
    if ($userId === null) {
        $userId = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }
    // システム管理者補佐の管理の操作・出力には「補佐として」の印を付ける（管理者が管理のトップと記録で確かめる。K-043）
    $isActedAsDeputy = 0;
    if ($userId !== null && in_array($action, ['admin', 'export'], true)) {
        $isActedAsDeputy = (int) db_val('SELECT is_admin_deputy FROM users WHERE id = ?', [$userId]) === 1 ? 1 : 0;
    }
    db_exec(
        'INSERT INTO access_logs (user_id, action, target, ip_address, created_at, acted_as_deputy) VALUES (?, ?, ?, ?, ?, ?)',
        [$userId, $action, $target, client_ip(), now(), $isActedAsDeputy]
    );
}
