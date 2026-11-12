<?php

declare(strict_types=1);

// ====================================================================
// G-14 ユーザーの追加・編集（詳細設計書 G14_org_users.md）
// ====================================================================

$user = page_start();
require_admin($user);
$me = (int) $user['id'];

$id = isset($_GET['id']) ? input_id('id') : null;
$target = null;
if ($id !== null) {
    $target = db_one('SELECT * FROM users WHERE id = ?', [$id]);
    if ($target === null) {
        error_page(404);
    }
}
$errors = [];
$roleTitles = ['一般', '主任', '課長代理', '課長', '部長'];

if (is_post()) {
    $action = post_str('action');
    rk_begin('admin.user.' . $action, $me);

    if ($action === 'save') {
        $display = post_str('display_name');
        $roleTitle = post_str('role_title');
        $deptRaw = post_str('department_id');
        $isAdmin = ($_POST['is_admin'] ?? '') === '1' ? 1 : 0;
        $isActive = ($_POST['is_active'] ?? '') === '1' ? 1 : 0;
        validate_text($display, '表示名', 20, true, $errors);
        if (!in_array($roleTitle, $roleTitles, true)) {
            $errors[] = '役職が正しくありません';
        }
        $deptId = null;
        if ($deptRaw !== '') {
            if (preg_match('/^\d+$/', $deptRaw) !== 1 || db_one('SELECT id FROM departments WHERE id = ? AND is_active = 1', [(int) $deptRaw]) === null) {
                $errors[] = '課が正しくありません';
            } else {
                $deptId = (int) $deptRaw;
            }
        }
        if ($target === null) {
            $loginId = auth_normalize_login_id(post_str('login_id'));
            if (strpos($loginId, '@') !== false) {
                $errors[] = 'ログイン ID にメールアドレスは使えません';
            } elseif (!auth_is_valid_login_id($loginId)) {
                $errors[] = 'ログイン ID は英小文字・数字・. _ - の 3〜32 文字にしてください';
            } elseif (db_val('SELECT 1 FROM users WHERE login_id = ? OR username = ?', [$loginId, $loginId]) !== null) {
                $errors[] = '同じログイン ID があります';
            }
            $isActive = 1;
        } else {
            if ((int) $target['id'] === $me && $isAdmin === 0) {
                $errors[] = '自分自身の管理者は外せません';
            }
            if ((int) $target['id'] === $me && $isActive === 0) {
                $errors[] = '自分自身は無効にできません';
            }
            if ((int) $target['is_admin'] === 1 && ($isAdmin === 0 || $isActive === 0) && admin_count() <= 1) {
                $errors[] = '管理者が 1 人もいなくなるため、変更できません';
            }
            if ($isActive === 0 && db_val('SELECT 1 FROM department_reviewers WHERE user_id = ?', [(int) $target['id']]) !== null) {
                $errors[] = '確認者に設定されているため、無効にできません。先に課の確認者から外してください';
            }
        }

        if (!$errors) {
            if ($target === null) {
                $temp = make_temp_password();
                db_exec(
                    'INSERT INTO users (username, password_hash, role_title, is_admin, created_at, login_id, display_name, department_id, must_change_password, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
                    [$loginId, password_hash($temp, PASSWORD_DEFAULT), $roleTitle, $isAdmin, now(), $loginId, $display, $deptId, now()]
                );
                $newId = (int) db()->lastInsertId();
                audit_log('admin', 'user:' . $newId . ':create');
                $_SESSION['show_temp_password'] = ['id' => $newId, 'password' => $temp];
                rk_done('/admin/user_edit.php?id=' . $newId);
            }
            $tid = (int) $target['id'];
            tx(function () use ($tid, $display, $roleTitle, $deptId, $isAdmin, $isActive, $target) {
                db_exec(
                    'UPDATE users SET display_name = ?, role_title = ?, department_id = ?, is_admin = ?, is_active = ?, updated_at = ? WHERE id = ?',
                    [$display, $roleTitle, $deptId, $isAdmin, $isActive, now(), $tid]
                );
                if ($isActive === 0 && (int) $target['is_active'] === 1) {
                    auth_forget_all_devices($tid);
                }
            });
            if ((int) $target['is_admin'] !== $isAdmin) {
                audit_log('admin', 'user:' . $tid . ':' . ($isAdmin === 1 ? 'admin_on' : 'admin_off'));
            }
            audit_log('admin', 'user:' . $tid . ':' . ($isActive === 0 && (int) $target['is_active'] === 1 ? 'deactivate' : 'update'));
            flash('success', '保存しました');
            rk_done('/admin/user_edit.php?id=' . $tid);
        }
        rk_cancel();

    } elseif ($target !== null && in_array($action, ['temp_password', 'reset_2fa', 'unlock', 'logout_all'], true)) {
        $tid = (int) $target['id'];
        if ($action === 'temp_password') {
            $temp = make_temp_password();
            db_exec(
                'UPDATE users SET password_hash = ?, must_change_password = 1, failed_count = 0, locked_until = NULL, session_version = session_version + 1, updated_at = ? WHERE id = ?',
                [password_hash($temp, PASSWORD_DEFAULT), now(), $tid]
            );
            $_SESSION['show_temp_password'] = ['id' => $tid, 'password' => $temp];
        } elseif ($action === 'reset_2fa') {
            tx(function () use ($tid) {
                db_exec('UPDATE users SET totp_secret_enc = NULL, totp_enabled_at = NULL, totp_last_step = NULL, updated_at = ? WHERE id = ?', [now(), $tid]);
                db_exec('DELETE FROM user_backup_codes WHERE user_id = ?', [$tid]);
                auth_forget_all_devices($tid);
            });
            flash('success', '2 段階認証を初期化しました。次のログインで設定し直します');
        } elseif ($action === 'unlock') {
            auth_reset_failures($tid);
            flash('success', 'ロックを解除しました');
        } else {
            auth_forget_all_devices($tid);
            flash('success', 'すべての端末からログアウトさせました');
        }
        audit_log('admin', 'user:' . $tid . ':' . $action);
        rk_done('/admin/user_edit.php?id=' . $tid);
    } else {
        error_page(400);
    }
}

$temp = null;
if (isset($_SESSION['show_temp_password']) && $target !== null && (int) $_SESSION['show_temp_password']['id'] === (int) $target['id']) {
    $temp = $_SESSION['show_temp_password']['password'];
}
unset($_SESSION['show_temp_password']);

if ($target !== null) {
    $target = db_one('SELECT * FROM users WHERE id = ?', [(int) $target['id']]);
}

render('admin_user_edit', [
    'title'       => $target === null ? 'ユーザーの追加' : 'ユーザーの編集',
    'user'        => $user,
    'target'      => $target,
    'temp'        => $temp,
    'errors'      => $errors,
    'departments' => departments_all(true),
    'roleTitles'  => $roleTitles,
    'post'        => $_POST,
    'lastLogin'   => $target === null ? null : db_val("SELECT MAX(created_at) FROM access_logs WHERE user_id = ? AND action = 'auth' AND target = 'login:ok'", [(int) $target['id']]),
]);
