<?php

declare(strict_types=1);

// ====================================================================
// G-16 アカウント設定（詳細設計書 G16_account.md）
// 2 段階認証の設定、予備のコード、記憶した端末、パスワードの変更
// ====================================================================

$user = page_start(['allow' => ['setup', 'must_change']]);
$uid = (int) $user['id'];
$stage = $_SESSION['stage'];

$tab = (string) ($_GET['tab'] ?? 'totp');
if (!in_array($tab, ['totp', 'codes', 'devices', 'password', 'theme'], true)) {
    $tab = 'totp';
}
if ($stage === 'setup') {
    $tab = 'totp';
} elseif ((int) $user['must_change_password'] === 1 && !($tab === 'codes' && isset($_SESSION['show_backup_codes']))) {
    // 仮のパスワードの人はパスワードの変更だけ。ただし設定直後の予備のコードの表示は先に見せる
    $tab = 'password';
}

$errors = [];

/** 仮の秘密の値（確認が済むまで DB に入れない） */
$pendingSecret = function (): string {
    if (empty($_SESSION['totp_pending']) || !is_string($_SESSION['totp_pending'])) {
        $_SESSION['totp_pending'] = base32_encode(random_bytes(20));
    }
    return $_SESSION['totp_pending'];
};

$requireCurrentPassword = function (array $user) use (&$errors): bool {
    $pw = post_str('current_password', false);
    if (!password_verify($pw, (string) $user['password_hash'])) {
        $locked = auth_register_failure((int) $user['id']);
        if ($locked) {
            audit_log('auth', 'login:locked', (int) $user['id']);
        }
        $errors[] = msg('今のパスワードが違います');
        return false;
    }
    return true;
};

if (is_post()) {
    $action = post_str('action');

    // ---- 2 段階認証の設定（初回・設定し直し） ----
    if ($action === 'totp_redo') {
        $tab = 'totp';
        if ($requireCurrentPassword($user)) {
            unset($_SESSION['totp_pending']);
            $_SESSION['totp_redo'] = true;
            redirect('/account.php?tab=totp');
        }
    } elseif ($action === 'totp_confirm') {
        $tab = 'totp';
        if ($stage !== 'setup' && empty($_SESSION['totp_redo'])) {
            error_page(400);
        }
        $b32 = $pendingSecret();
        $code = post_str('code');
        $step = totp_verify((string) base32_decode($b32), $code, null);
        if ($step === null) {
            $errors[] = msg('コードが違います。認証アプリに表示されている最新の 6 桁を入れてください。時計がずれていると合わないことがあります');
        } else {
            $codes = tx(function () use ($uid, $b32, $step) {
                db_exec(
                    'UPDATE users SET totp_secret_enc = ?, totp_enabled_at = ?, totp_last_step = ?, session_version = session_version + 1, updated_at = ? WHERE id = ?',
                    [enc($b32, 'totp:' . $uid), now(), $step, now(), $uid]
                );
                return auth_regenerate_backup_codes($uid);
            });
            unset($_SESSION['totp_pending'], $_SESSION['totp_redo']);
            $fresh = db_one('SELECT * FROM users WHERE id = ?', [$uid]);
            $next = (string) ($_SESSION['after_setup_next'] ?? '/index.php');
            unset($_SESSION['after_setup_next']);
            session_login($fresh, 'full');
            if (($_POST['remember'] ?? '') === '1') {
                auth_remember_device($uid);
            }
            auth_reset_failures($uid);
            audit_log('auth', 'totp:setup', $uid);
            $_SESSION['show_backup_codes'] = $codes;
            $_SESSION['after_codes_next'] = is_safe_local_path($next) ? $next : '/index.php';
            flash('success', msg('2 段階認証を設定しました。次からはログインのときに 6 桁のコードを入れます'));
            redirect('/account.php?tab=codes');
        }
    } elseif ($action === 'codes_regen') {
        $tab = 'codes';
        if ($stage !== 'full') {
            error_page(403);
        }
        if ($requireCurrentPassword($user)) {
            $codes = tx(function () use ($uid) {
                return auth_regenerate_backup_codes($uid);
            });
            audit_log('auth', 'totp:backup_regenerated', $uid);
            $_SESSION['show_backup_codes'] = $codes;
            redirect('/account.php?tab=codes');
        }

    // ---- 記憶した端末（2 段階認証の設定と、仮のパスワードの変更が済んでから） ----
    } elseif ($action === 'device_revoke' || $action === 'logout_all') {
        if ($stage !== 'full' || (int) $user['must_change_password'] === 1) {
            error_page(403);
        }
        if ($action === 'logout_all') {
            auth_forget_all_devices($uid);
            audit_log('auth', 'logout_all', $uid);
            session_logout();
            session_boot();
            flash('info', msg('すべての端末からログアウトしました。もう一度ログインしてください'));
            redirect('/login.php');
        }
        $id = input_id('device_id', 'POST');
        db_exec('DELETE FROM remembered_devices WHERE id = ? AND user_id = ?', [$id, $uid]);
        audit_log('auth', 'device:revoke', $uid);
        flash('success', msg('端末の記憶を解除しました'));
        redirect('/account.php?tab=devices');

    // ---- 画面のテーマ（G-16 §4.5） ----
    } elseif ($action === 'theme_save') {
        if ($stage !== 'full' || (int) $user['must_change_password'] === 1) {
            error_page(403);
        }
        $selectedThemeName = post_str('ui_theme');
        if (!isset(UI_THEMES[$selectedThemeName])) {
            error_page(400);
        }
        if ($selectedThemeName === 'custom') {
            // 自分の色（K-046）: 土台・強調の色・背景の色。文字が読めない組み合わせは保存しない
            $customColors = [
                'base'       => post_str('custom_base') === 'dark' ? 'dark' : 'light',
                'accent'     => strtolower(post_str('custom_accent')),
                'background' => strtolower(post_str('custom_background')),
            ];
            $customColorErrorList = user_theme_format_errors($customColors);
            if ($customColorErrorList) {
                $_SESSION['custom_color_input'] = $customColors;
                flash('error', implode("\n", $customColorErrorList));
                redirect('/account.php?tab=theme#custom-colors');
            }
            db_exec('UPDATE users SET ui_custom_colors = ? WHERE id = ?', [json_encode($customColors), $uid]);
            // 読みにくい組み合わせは、保存したうえで注意を出す（K-046 決定）
            $readabilityWarningList = user_theme_readability_warnings($customColors);
            if ($readabilityWarningList) {
                flash('error', msg('保存しましたが、読みにくい組み合わせです。') . "\n" . implode("\n", $readabilityWarningList));
            }
        }
        db_exec('UPDATE users SET ui_theme = ?, updated_at = ? WHERE id = ?', [$selectedThemeName, now(), $uid]);
        flash('success', msg('画面のテーマを「{theme}」にしました', ['theme' => UI_THEMES[$selectedThemeName]]));
        redirect('/account.php?tab=theme');

    // ---- パスワードの変更 ----
    } elseif ($action === 'password_change') {
        $tab = 'password';
        if ($stage !== 'full') {
            error_page(403);
        }
        $mustChange = (int) $user['must_change_password'] === 1;
        $ok = $mustChange ? true : $requireCurrentPassword($user);
        if ($ok) {
            $new = post_str('new_password', false);
            $confirm = post_str('new_password_confirm', false);
            $pwError = auth_validate_new_password($new, (string) $user['login_id']);
            if ($pwError === null && password_verify($new, (string) $user['password_hash'])) {
                $pwError = msg('今と同じパスワードは使えません');
            }
            if ($pwError !== null) {
                $errors[] = $pwError;
            } elseif ($new !== $confirm) {
                $errors[] = msg('確認のパスワードが一致しません');
            } else {
                auth_set_password($uid, $new);
                session_login(db_one('SELECT * FROM users WHERE id = ?', [$uid]), 'full');
                audit_log('auth', 'password:change', $uid);
                flash('success', msg('パスワードを変更しました。ほかの端末はログアウトしました'));
                redirect('/index.php');
            }
        }
    } else {
        error_page(400);
    }
}

// ---- 表示 ----
$vars = [
    'title'  => 'アカウント設定',
    'user'   => $user,
    'tab'    => $tab,
    'stage'  => $stage,
    'errors' => $errors,
];

if ($tab === 'totp') {
    $settingUp = ($stage === 'setup') || !empty($_SESSION['totp_redo']);
    $vars['settingUp'] = $settingUp;
    if ($settingUp) {
        $b32 = $pendingSecret();
        $vars['secretDisplay'] = trim(chunk_split($b32, 4, ' '));
        $vars['totpUri'] = totp_uri($b32, (string) $user['login_id']);
    }
} elseif ($tab === 'codes') {
    $vars['codes'] = $_SESSION['show_backup_codes'] ?? null;
    unset($_SESSION['show_backup_codes']);
    $vars['afterNext'] = (string) ($_SESSION['after_codes_next'] ?? '');
    unset($_SESSION['after_codes_next']);
    $vars['remaining'] = (int) db_val('SELECT COUNT(*) FROM user_backup_codes WHERE user_id = ? AND used_at IS NULL', [$uid]);
} elseif ($tab === 'devices') {
    $vars['devices'] = db_all('SELECT id, label, expires_at, last_used_at FROM remembered_devices WHERE user_id = ? AND expires_at > ? ORDER BY last_used_at DESC', [$uid, now()]);
    $vars['currentDeviceId'] = auth_remembered_device_id($uid);
}

$customColorInput = $_SESSION['custom_color_input'] ?? null;   // 保存できなかった色（入れ直しのため一度だけ出す）
unset($_SESSION['custom_color_input']);
$vars['customColors'] = is_array($customColorInput) ? $customColorInput : user_theme_colors($user);
render('account', $vars);
