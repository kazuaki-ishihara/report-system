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

/**
 * このサイトの印。同じサーバーの別のサイト（デモ用と本番など）でできたセッションを見分ける。
 * 利用者の番号（uid）は DB ごとのものなので、DB の場所も入れる。
 */
function session_site_stamp(): string
{
    try {
        $encryptionKey = crypto_key();
    } catch (RuntimeException $e) {
        if ($e instanceof PDOException) {
            throw $e; // DB の一時の失敗で印が変わり、ログアウトさせないようにする
        }
        $encryptionKey = ''; // 鍵を作る前（初期設定）でも、ログイン画面は開けるようにする
    }
    return hash_hmac('sha256', implode("\n", ['session-site', APP_DIR, (string) cfg('base_path'), (string) cfg('db_path')]), $encryptionKey);
}

/** セッションの置き場所をこのサイトだけのものにする。使えなければ PHP の既定の場所で続ける（そのときもサイトの印で断る） */
function session_use_site_dir(): void
{
    // 設定になければ app/data/sessions（config.php はサイトごとに直して置かれているので、差分で上げなくて済むように）
    $sessionDir = (string) ($GLOBALS['APP_CONFIG']['session_dir'] ?? APP_DIR . '/data/sessions');
    if ($sessionDir === '' || ini_get('session.save_handler') !== 'files') {
        return;
    }
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0700, true);
    }
    if (!is_dir($sessionDir) || !is_writable($sessionDir)) {
        app_log('WARN セッションの置き場所が使えません（PHP の既定の場所を使います）: ' . $sessionDir);
        return;
    }
    session_save_path($sessionDir);
    // 自分の置き場所は、サーバーの定期の掃除の対象にならないので、ここで古いものが消えるようにする
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.gc_maxlifetime', (string) (3600 * (int) cfg('session_max_hours')));
}

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
    session_use_site_dir();
    session_start();
    $siteStamp = session_site_stamp();
    if (!hash_equals($siteStamp, (string) ($_SESSION['site'] ?? ''))) {
        if ($_SESSION !== []) {
            // 別のサイトのセッション: 中身は変えずに手放し（相手のログインは切らない）、新しいセッションにする
            session_abort();
            session_id((string) session_create_id());
            session_start();
        }
        $_SESSION = ['site' => $siteStamp];
    }
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
    if ($user === null || (int) $user['is_active'] !== 1 || security_is_frozen($user) || (int) $user['session_version'] !== (int) ($_SESSION['sv'] ?? -1)) {
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
    if ($user === null && is_json_request()) {
        // 画面の中からの問い合わせ（チャットなど）: ログインの画面に転送すると、受け取る側が読めず「通信エラー」になるため、JSON で知らせる
        app_log('WARN JSON の問い合わせで、ログインが切れていた: ' . request_path_for_log());
        json_response(['ok' => false, 'reason' => 'login'], 401);
    }
    if ($user === null) {
        if (is_post()) {
            // ふだんは、送る前に画面の JavaScript がログインの状態を確かめる（共通編 §11.3.1）。ここに来たときは、保存していないことを知らせる
            flash('error', msg('ログインが切れていたため、送信した内容は保存されていません。ログインしてから、もう一度入力してください。'));
        }
        $next = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $base = cfg('base_path');
        if ($base !== '' && strpos($next, $base) === 0) {
            $next = substr($next, strlen($base));
        }
        $q = is_safe_local_path($next) && !is_post() ? '?next=' . rawurlencode($next) : '';
        redirect('/login.php' . $q);
    }
    // メンテナンス中は、管理者・補佐のほかの人をログアウトさせる（ログインの画面にメンテナンス中の知らせが出る。2026-11-26 指示）
    if (maintenance_blocks($user)) {
        audit_log('auth', 'logout:maintenance', (int) $user['id']);
        session_logout();
        if (is_json_request()) {
            json_response(['ok' => false, 'reason' => 'login'], 401);
        }
        redirect('/login.php');
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
