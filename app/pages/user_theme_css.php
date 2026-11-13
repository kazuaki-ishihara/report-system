<?php

declare(strict_types=1);

// ====================================================================
// 自分の色の CSS（G-16 §4.6）。ログインしている人の色の決まりを返す。ほかの人の色は返さない。
// ====================================================================

page_start(['auth' => 'none']);
$user = session_user();
header('Content-Type: text/css; charset=UTF-8');
header('Cache-Control: private, max-age=86400');
if ($user === null || ($_SESSION['stage'] ?? '') !== 'full' || (string) ($user['ui_theme'] ?? '') !== 'custom') {
    echo "/* 自分の色は使っていません */\n";
    exit;
}
echo user_theme_css(user_theme_colors($user));
exit;
