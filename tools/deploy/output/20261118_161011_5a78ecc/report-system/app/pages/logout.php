<?php

declare(strict_types=1);

// ログアウト（POST のみ。端末の記憶は残す）
page_start(['auth' => 'none']);
if (!is_post()) {
    error_page(400);
}
if (!empty($_SESSION['uid'])) {
    audit_log('auth', 'logout', (int) $_SESSION['uid']);
}
session_logout();
session_boot();
flash('info', msg('ログアウトしました。'));
redirect('/login.php');
