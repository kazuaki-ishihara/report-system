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
    db_exec(
        'INSERT INTO access_logs (user_id, action, target, ip_address, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $action, $target, client_ip(), now()]
    );
}
