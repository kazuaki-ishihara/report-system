<?php

declare(strict_types=1);

// ====================================================================
// G-01 ログイン（詳細設計書 G01_login.md）
// ====================================================================

page_start(['auth' => 'none']);
housekeeping_daily();

const LOGIN_MSG_FAIL = 'ログイン ID またはパスワードが違います';
const LOGIN_MSG_FROZEN = 'このアカウントは一時凍結しています。システム管理者か補佐（開発担当）に連絡してください';
const LOGIN_MSG_LOCK = 'ログインに続けて失敗したため、しばらくログインできません。15 分ほど待ってからお試しください';

if (($_SESSION['stage'] ?? '') === 'full' && session_user() !== null) {
    redirect('/index.php');
}

$next = $_POST['next'] ?? ($_GET['next'] ?? '/index.php');
if (!is_string($next) || !is_safe_local_path($next) || strpos($next, '/login.php') === 0) {
    $next = '/index.php';
}

$step = (($_GET['step'] ?? '') === '2') ? 2 : 1;
$errors = [];
$loginId = '';

/** 手順 2 の状態が有効か（5 分以内） */
$pwUser = function (): ?array {
    $uid = $_SESSION['pw_uid'] ?? null;
    $at = (int) ($_SESSION['pw_at'] ?? 0);
    if (!is_int($uid) || time() - $at > 60 * (int) cfg('pw_stage_minutes')) {
        return null;
    }
    $u = db_one('SELECT * FROM users WHERE id = ?', [$uid]);
    return ($u !== null && (int) $u['is_active'] === 1 && !security_is_frozen($u)) ? $u : null;
};

$finishLogin = function (array $user, string $next): void {
    // 失敗の回数は、2 段階認証まで済んだときにだけ戻す（パスワードだけ知る人がコードを総当たりできないように）
    auth_reset_failures((int) $user['id']);
    session_login($user, 'full');
    audit_log('auth', 'login:ok', (int) $user['id']);
    redirect($next);
};

if (is_post()) {
    $action = post_str('action');

    if ($action === 'password') {
        $step = 1;
        $loginId = auth_normalize_login_id(post_str('login_id'));
        $password = post_str('password', false);
        if ($loginId === '' || $password === '') {
            $errors[] = 'ログイン ID とパスワードを入れてください';
        } else {
            $user = auth_find_user($loginId);
            if ($user === null || (int) $user['is_active'] !== 1) {
                auth_dummy_verify($password);
                if (auth_ip_is_locked()) {
                    $errors[] = LOGIN_MSG_LOCK;
                } else {
                    auth_ip_register_failure();
                    $errors[] = LOGIN_MSG_FAIL;
                }
                // 無い ID での失敗: 入れた ID も残す（ID の形に合わないものは、パスワードを誤って入れた恐れがあるので伏せる。K-041）
                audit_log('auth', 'login:fail;id=' . (auth_is_valid_login_id($loginId) ? $loginId : '(形が違う)'), null);
            } elseif (auth_is_locked($user)) {
                auth_dummy_verify($password);
                $errors[] = LOGIN_MSG_LOCK;
                audit_log('auth', 'login:locked_attempt', (int) $user['id']);
            } elseif (!password_verify($password, (string) $user['password_hash'])) {
                $locked = auth_register_failure((int) $user['id']);
                audit_log('auth', $locked ? 'login:locked' : 'login:fail', (int) $user['id']);
                $errors[] = $locked ? LOGIN_MSG_LOCK : LOGIN_MSG_FAIL;
            } elseif (security_is_frozen($user)) {
                // 凍結中（K-075）。パスワードが合った人にだけ知らせる
                audit_log('auth', 'login:frozen_attempt', (int) $user['id']);
                $errors[] = LOGIN_MSG_FROZEN;
            } else {
                if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
                    db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
                }
                // 開発用: 2 段階認証を飛ばす（config.local.php の dev_skip_2fa。HTTPS の本番では効かない）
                if (cfg('dev_skip_2fa') === true && !cfg('https')) {
                    $finishLogin($user, $next);
                }
                if ($user['totp_enabled_at'] === null) {
                    // 2 段階認証が未設定: 設定の画面だけ使える状態にする
                    session_login($user, 'setup');
                    $_SESSION['after_setup_next'] = $next;
                    audit_log('auth', 'login:pw_ok_setup', (int) $user['id']);
                    redirect('/account.php?tab=totp');
                }
                if (auth_remembered_device_id((int) $user['id']) !== null) {
                    $finishLogin($user, $next);
                }
                session_regenerate_id(true);
                $_SESSION['pw_uid'] = (int) $user['id'];
                $_SESSION['pw_at'] = time();
                $_SESSION['pw_next'] = $next;
                redirect('/login.php?step=2');
            }
        }
    } elseif ($action === 'code') {
        $step = 2;
        $user = $pwUser();
        if ($user === null) {
            unset($_SESSION['pw_uid'], $_SESSION['pw_at']);
            flash('info', '時間が経ったため、最初からやり直してください');
            redirect('/login.php');
        }
        if (auth_is_locked($user)) {
            unset($_SESSION['pw_uid'], $_SESSION['pw_at']);
            flash('error', LOGIN_MSG_LOCK);
            redirect('/login.php');
        }
        $code = post_str('code');
        if (auth_verify_second_factor($user, $code)) {
            $remember = ($_POST['remember'] ?? '') === '1';
            $next = (string) ($_SESSION['pw_next'] ?? '/index.php');
            unset($_SESSION['pw_next']);
            if ($remember) {
                auth_remember_device((int) $user['id']);
            }
            $finishLogin($user, is_safe_local_path($next) ? $next : '/index.php');
        }
        if (security_use_recovery_code($user, $code)) {
            // 復旧コード（K-075）: 2 段階認証の設定からやり直す
            $next = (string) ($_SESSION['pw_next'] ?? '/index.php');
            unset($_SESSION['pw_next']);
            auth_reset_failures((int) $user['id']);
            session_login(db_one('SELECT * FROM users WHERE id = ?', [(int) $user['id']]), 'setup');
            $_SESSION['after_setup_next'] = is_safe_local_path($next) ? $next : '/index.php';
            audit_log('auth', 'login:recovery_code', (int) $user['id']);
            redirect('/account.php?tab=totp');
        }
        $locked = auth_register_failure((int) $user['id']);
        audit_log('auth', $locked ? 'login:locked' : 'login:2fa_fail', (int) $user['id']);
        if ($locked) {
            unset($_SESSION['pw_uid'], $_SESSION['pw_at']);
            flash('error', LOGIN_MSG_LOCK);
            redirect('/login.php');
        }
        $errors[] = 'コードが違います。認証アプリに表示されている最新の 6 桁を入れてください';
    } else {
        error_page(400);
    }
}

if ($step === 2 && $pwUser() === null) {
    redirect('/login.php');
}

render('login', [
    'title'   => 'ログイン',
    'user'    => null,
    'step'    => $step,
    'errors'  => $errors,
    'loginId' => $loginId,
    'next'    => $next,
]);
