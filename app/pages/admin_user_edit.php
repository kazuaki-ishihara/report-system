<?php

declare(strict_types=1);

// ====================================================================
// G-14 ユーザーの追加・編集（詳細設計書 G14_org_users.md）
// ====================================================================

$user = page_start();
require_system_manager($user);
$me = (int) $user['id'];
// 補佐は、管理者・補佐の役を付け外しできず、管理者・補佐の利用者（自分を含む）も変えられない（K-043）
$isOperatorDeputy = !is_admin($user);

$id = isset($_GET['id']) ? input_id('id') : null;
$target = null;
if ($id !== null) {
    $target = db_one('SELECT * FROM users WHERE id = ?', [$id]);
    if ($target === null) {
        error_page(404);
    }
    if ($isOperatorDeputy && user_row_is_system_manager($target)) {
        error_page(403, msg('管理者と補佐の利用者は、管理者だけが変えられます'));
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
        $isAdminDeputy = ($_POST['is_admin_deputy'] ?? '') === '1' ? 1 : 0;
        if ($isOperatorDeputy) {
            // 補佐が追加・変更する利用者は、管理者にも補佐にもならない（ここに来るのは管理者・補佐でない利用者だけ）
            $isAdmin = 0;
            $isAdminDeputy = 0;
        }
        $isActive = ($_POST['is_active'] ?? '') === '1' ? 1 : 0;
        validate_text($display, '表示名', 20, true, $errors);
        if (!in_array($roleTitle, $roleTitles, true)) {
            $errors[] = msg('役職が正しくありません');
        }
        $deptId = null;
        if ($deptRaw !== '') {
            if (preg_match('/^\d+$/', $deptRaw) !== 1 || db_one('SELECT id FROM departments WHERE id = ? AND is_active = 1', [(int) $deptRaw]) === null) {
                $errors[] = msg('課が正しくありません');
            } else {
                $deptId = (int) $deptRaw;
            }
        }
        // 班は課長が「課の管理」で決める（K-042）。同じ課のままなら今の班を残し、課を移したら班を外す
        $teamId = ($target !== null && $target['team_id'] !== null && $deptId !== null && (int) $target['department_id'] === $deptId) ? (int) $target['team_id'] : null;
        if ($target === null) {
            $loginId = auth_normalize_login_id(post_str('login_id'));
            if (strpos($loginId, '@') !== false) {
                $errors[] = msg('ログイン ID にメールアドレスは使えません');
            } elseif (!auth_is_valid_login_id($loginId)) {
                $errors[] = msg('ログイン ID は英小文字・数字・. _ - の 3〜32 文字にしてください');
            } elseif (db_val('SELECT 1 FROM users WHERE login_id = ? OR username = ?', [$loginId, $loginId]) !== null) {
                $errors[] = msg('同じログイン ID があります');
            }
            $isActive = 1;
        } else {
            if ((int) $target['id'] === $me && $isAdmin === 0) {
                $errors[] = msg('自分自身の管理者は外せません');
            }
            if ((int) $target['id'] === $me && $isActive === 0) {
                $errors[] = msg('自分自身は無効にできません');
            }
            if ((int) $target['is_admin'] === 1 && ($isAdmin === 0 || $isActive === 0) && admin_count() <= 1) {
                $errors[] = msg('管理者が 1 人もいなくなるため、変更できません');
            }
        }
        if ($isAdmin === 1 && $isAdminDeputy === 1) {
            $errors[] = msg('管理者と補佐は兼ねられません。どちらか一方にしてください');
        }
        if ($isAdminDeputy === 1 && ($isActive === 1 || $target === null)
            && admin_deputy_count($target === null ? null : (int) $target['id']) >= ADMIN_DEPUTY_MAX_COUNT) {
            $errors[] = msg('補佐は {max} 名までです。ほかの人の補佐を外してから付けてください', ['max' => ADMIN_DEPUTY_MAX_COUNT]);
        }

        if (!$errors) {
            if ($target === null) {
                $temp = make_temp_password();
                db_exec(
                    'INSERT INTO users (username, password_hash, role_title, is_admin, is_admin_deputy, created_at, login_id, display_name, department_id, team_id, must_change_password, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
                    [$loginId, password_hash($temp, PASSWORD_DEFAULT), $roleTitle, $isAdmin, $isAdminDeputy, now(), $loginId, $display, $deptId, $teamId, now()]
                );
                $newId = (int) db()->lastInsertId();
                audit_log('admin', 'user:' . $newId . ':create');
                if ($isAdminDeputy === 1) {
                    audit_log('admin', 'user:' . $newId . ':deputy_on');
                }
                $_SESSION['show_temp_password'] = ['id' => $newId, 'password' => $temp];
                rk_done('/admin/user_edit.php?id=' . $newId);
            }
            $tid = (int) $target['id'];
            // 無効にするときに外す役割（知らせるため、外す前に読む）
            $removedRoleTextList = [];
            if ($isActive === 0 && (int) $target['is_active'] === 1) {
                foreach (db_all('SELECT d.name, r.is_final FROM department_reviewers r JOIN departments d ON d.id = r.department_id WHERE r.user_id = ?', [$tid]) as $removedRoleRow) {
                    $removedRoleTextList[] = $removedRoleRow['name'] . '（' . ((int) $removedRoleRow['is_final'] === 1 ? msg('課長') : msg('確認者')) . '）';
                }
                foreach (db_all('SELECT d.name FROM department_admins a JOIN departments d ON d.id = a.department_id WHERE a.user_id = ?', [$tid]) as $removedRoleRow) {
                    $removedRoleTextList[] = $removedRoleRow['name'] . '（' . msg('項目管理者') . '）';
                }
            }
            tx(function () use ($tid, $display, $roleTitle, $deptId, $teamId, $isAdmin, $isAdminDeputy, $isActive, $target) {
                db_exec(
                    'UPDATE users SET display_name = ?, role_title = ?, department_id = ?, team_id = ?, is_admin = ?, is_admin_deputy = ?, is_active = ?, updated_at = ? WHERE id = ?',
                    [$display, $roleTitle, $deptId, $teamId, $isAdmin, $isAdminDeputy, $isActive, now(), $tid]
                );
                if ((int) $target['is_admin_deputy'] !== $isAdminDeputy || (int) $target['is_admin'] !== $isAdmin) {
                    // 役が変わったら、今のログインを切る（新しい権限で入り直す）
                    db_exec('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$tid]);
                }
                if ($isActive === 0 && (int) $target['is_active'] === 1) {
                    auth_forget_all_devices($tid);
                    // 退職・異動などで無効にした人は、確認者・課長・項目管理者の役割から外す（チェックの人数に数えない。記録は残る）
                    db_exec('DELETE FROM department_reviewers WHERE user_id = ?', [$tid]);
                    db_exec('DELETE FROM department_admins WHERE user_id = ?', [$tid]);
                }
            });
            if ($isActive === 0 && (int) $target['is_active'] === 1 && $removedRoleTextList) {
                flash('info', msg('確認者・項目管理者の役割を外しました: {roles}。課長がいなくなった課は、組織・ユーザーの画面で新しい課長を設定してください', ['roles' => implode('、', $removedRoleTextList)]));
            }
            if ((int) $target['is_admin'] !== $isAdmin) {
                audit_log('admin', 'user:' . $tid . ':' . ($isAdmin === 1 ? 'admin_on' : 'admin_off'));
            }
            if ((int) $target['is_admin_deputy'] !== $isAdminDeputy) {
                audit_log('admin', 'user:' . $tid . ':' . ($isAdminDeputy === 1 ? 'deputy_on' : 'deputy_off'));
            }
            audit_log('admin', 'user:' . $tid . ':' . ($isActive === 0 && (int) $target['is_active'] === 1 ? 'deactivate' : 'update'));
            flash('success', msg('保存しました'));
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
            flash('success', msg('2 段階認証を初期化しました。次のログインで設定し直します'));
        } elseif ($action === 'unlock') {
            auth_reset_failures($tid);
            flash('success', msg('ロックを解除しました'));
        } else {
            auth_forget_all_devices($tid);
            flash('success', msg('すべての端末からログアウトさせました'));
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

// 班の選択肢（課ごと。画面では課を選ぶと、その課の班だけを出す）
$teamOptionList = db_all('SELECT t.id, t.name, t.department_id FROM department_teams t JOIN departments d ON d.id = t.department_id
                            WHERE t.is_active = 1 AND d.is_active = 1 ORDER BY d.sort_order, t.sort_order, t.id');

render('admin_user_edit', [
    'teamOptionList' => $teamOptionList,
    'title'       => $target === null ? 'ユーザーの追加' : 'ユーザーの編集',
    'user'        => $user,
    'target'      => $target,
    'temp'        => $temp,
    'errors'      => $errors,
    'departments' => departments_all(true),
    'roleTitles'  => $roleTitles,
    'post'        => $_POST,
    'isOperatorDeputy' => $isOperatorDeputy,
    'adminDeputyCount' => admin_deputy_count($target === null ? null : (int) $target['id']),
    'lastLogin'   => $target === null ? null : db_val("SELECT MAX(created_at) FROM access_logs WHERE user_id = ? AND action = 'auth' AND target = 'login:ok'", [(int) $target['id']]),
]);
