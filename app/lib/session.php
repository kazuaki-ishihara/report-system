<?php

declare(strict_types=1);

// ====================================================================
// セッションとログイン状態（詳細設計書 共通編 §3・§5）
//
// $_SESSION['stage']:
//   'pw'    パスワード確認済み、2 段階認証のコード待ち（G-01 手順 2）
//   'setup' パスワード確認済み、2 段階認証が未設定（G-16 の設定だけ使える）
//   'full'  ログイン済み
// ====================================================================

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('mr_session');
    $opt = auth_cookie_options(0);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $opt['path'],
        'secure'   => $opt['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

function session_login(array $user, string $stage): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['sv'] = (int) $user['session_version'];
    $_SESSION['stage'] = $stage;
    $_SESSION['login_at'] = time();
    $_SESSION['last_seen'] = time();
    unset($_SESSION['pw_uid'], $_SESSION['pw_at']);
}

function session_logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        session_destroy();
    }
}

/** セッションの利用者。無効・期限切れ・版違いなら null（セッションも破棄） */
function session_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $now = time();
    $idle = 60 * (int) cfg('session_idle_minutes');
    $max = 3600 * (int) cfg('session_max_hours');
    if ($now - (int) ($_SESSION['last_seen'] ?? 0) > $idle || $now - (int) ($_SESSION['login_at'] ?? 0) > $max) {
        session_logout();
        session_boot();
        flash('info', msg('一定時間操作がなかったため、ログアウトしました。'));
        return null;
    }
    $user = db_one('SELECT * FROM users WHERE id = ?', [(int) $_SESSION['uid']]);
    if ($user === null || (int) $user['is_active'] !== 1 || (int) $user['session_version'] !== (int) ($_SESSION['sv'] ?? -1)) {
        session_logout();
        session_boot();
        return null;
    }
    $_SESSION['last_seen'] = $now;
    return $user;
}

/**
 * 画面の最初に呼ぶ共通処理。
 * $opt['auth']  'none' = ログイン不要、'full' = ログイン必須（既定）
 * $opt['allow'] ['setup'] = 2 段階認証が未設定でも使える、['must_change'] = パスワード変更前でも使える
 */
function page_start(array $opt = []): ?array
{
    send_security_headers();
    session_boot();
    db();

    $auth = $opt['auth'] ?? 'full';
    if ($auth === 'none') {
        if (is_post()) {
            csrf_check();
        }
        return null;
    }

    $user = session_user();
    if ($user === null) {
        $next = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $base = cfg('base_path');
        if ($base !== '' && strpos($next, $base) === 0) {
            $next = substr($next, strlen($base));
        }
        $q = is_safe_local_path($next) && !is_post() ? '?next=' . rawurlencode($next) : '';
        redirect('/login.php' . $q);
    }
    $allow = $opt['allow'] ?? [];
    $stage = $_SESSION['stage'] ?? '';
    if ($stage === 'setup' && !in_array('setup', $allow, true)) {
        redirect('/account.php?tab=totp');
    }
    if ($stage !== 'setup' && $stage !== 'full') {
        session_logout();
        redirect('/login.php');
    }
    if ($stage === 'full' && (int) $user['must_change_password'] === 1 && !in_array('must_change', $allow, true)) {
        redirect('/account.php?tab=password');
    }
    // 予約した日が来た組織の改訂を反映する（便利機能 G。定期の処理が使えない環境のため。メンテナンス中は動かさない）
    if (maintenance_state() === null && org_schedules_has_due()) {
        org_schedules_run_due();
        $user = session_user() ?? $user;
    }
    if (is_post()) {
        csrf_check();
        // メンテナンス中は、管理者・補佐以外の保存などの操作を受け付けない（便利機能 F）
        if (maintenance_blocks($user)) {
            error_page(503, maintenance_notice_text());
        }
    }
    return $user;
}
