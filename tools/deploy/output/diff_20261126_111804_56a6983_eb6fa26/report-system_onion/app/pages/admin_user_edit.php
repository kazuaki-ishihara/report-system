<?php

declare(strict_types=1);

// ====================================================================
// G-14 ユーザーの追加・編集（詳細設計書 G14_org_users.md）
// ====================================================================

$user = page_start();
require_system_manager($user);
$me = (int) $user['id'];
// 補佐は、管理者・補佐の役を付け外しできず、管理者・補佐の利用者（自分を含む）も変えられない（K-043）
$isOperatorDeputy = !has_admin_authority($user);   // 補佐（開発担当）は管理者と同じ（K-059）

$id = isset($_GET['id']) ? input_id('id') : null;
$target = null;
if ($id !== null) {
    $target = db_one('SELECT * FROM users WHERE id = ?', [$id]);
    if ($target === null) {
        error_page(404);
    }
}
if ($target !== null && $isOperatorDeputy && user_row_is_system_manager($target)) {
    error_page(403, msg('管理者と補佐の利用者は、管理者だけが変えられます'));
}
$errors = [];
$roleTitles = ['一般', '主任', '課長代理', '課長', '部長'];

if (is_post()) {
    $action = post_str('action');
    if ($target !== null && security_is_frozen($target) && $action !== 'save') {
        // 凍結中は、再初期化（アカウントの凍結の画面）でしか戻せない（K-075）
        error_page(400, msg('凍結中のアカウントです。「アカウントの凍結」の画面で再初期化してください。'));
    }
    // 補佐（開発担当）だった人を含めて、管理者だけではアカウントに入れないようにする（K-075。2 人での復旧を使う）
    if ($target !== null && security_is_protected($target) && in_array($action, ['temp_password', 'reset_2fa', 'view_backup_codes', 'recovery_issue'], true)) {
        error_page(403, msg('補佐（開発担当）のパスワードと 2 段階認証は、管理者だけでは初期化できません。「アカウントの凍結」の画面で、復旧を申請してください。'));
    }
    rk_begin('admin.user.' . $action, $me);

    if ($action === 'save') {
        $display = post_str('display_name');
        $roleTitle = post_str('role_title');
        $deptRaw = post_str('department_id');
        // 専門職（K-074）: 表示する役職。付けると、課長・確認者・項目管理者の役割を外す（DB のトリガーでも外す）
        $specialistTitle = post_str('specialist_title');
        if ($specialistTitle !== '' && !in_array($specialistTitle, SPECIALIST_TITLE_LIST, true)) {
            error_page(400);
        }
        $isAdmin = ($_POST['is_admin'] ?? '') === '1' ? 1 : 0;
        $isAdminDeputy = ($_POST['is_admin_deputy'] ?? '') === '1' ? 1 : 0;
        if ($isOperatorDeputy) {
            // 補佐が追加・変更する利用者は、管理者にも補佐にもならない（ここに来るのは管理者・補佐でない利用者だけ）
            $isAdmin = 0;
            $isAdminDeputy = 0;
        }
        $isActive = ($_POST['is_active'] ?? '') === '1' ? 1 : 0;
        // 部長以上: 全課共通の調査の結果（集計だけ）を見られる印。管理の権限ではないので、補佐も付けられる（K-067）
        $isExecutive = ($_POST['is_executive'] ?? '') === '1' ? 1 : 0;
        // 人財管理者: 全課共通の調査を作り、結果（集計だけ）を見られる印。管理の権限ではないので、補佐も付けられる（K-071）
        $isHrManager = ($_POST['is_hr_manager'] ?? '') === '1' ? 1 : 0;
        $isAdminDeputyLead = (!$isOperatorDeputy && $isAdminDeputy === 1 && ($_POST['is_admin_deputy_lead'] ?? '') === '1') ? 1 : 0;
        validate_text($display, '表示名', 20, true, $errors);
        if (!in_array($roleTitle, $roleTitles, true)) {
            $errors[] = msg('役職が正しくありません');
        }
        $deptId = null;
        if ($deptRaw !== '') {
            if (preg_match('/\A\d+\z/', $deptRaw) !== 1 || db_one('SELECT id FROM departments WHERE id = ? AND is_active = 1', [(int) $deptRaw]) === null) {
                $errors[] = msg('課が正しくありません');
            } else {
                $deptId = (int) $deptRaw;
            }
        }
        // ラインは課長が「課の管理」で決める（K-042）。同じ課のままなら今のラインを残し、課を移したらラインを外す
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
            if ((int) $target['id'] === $me && (int) $target['is_admin'] === 1 && $isAdmin === 0) {
                $errors[] = msg('自分自身の管理者は外せません');
            }
            if ((int) $target['id'] === $me && $isActive === 0) {
                $errors[] = msg('自分自身は無効にできません');
            }
            if ((int) $target['is_admin'] === 1 && ($isAdmin === 0 || $isActive === 0) && admin_count() <= 1) {
                $errors[] = msg('管理者が 1 人もいなくなるため、変更できません');
            }
        }
        // 管理者と、開発担当でない補佐は、1 名ずつ以上置く（K-075。開発担当が不在・凍結中のときの代わり）。最後の 1 人は、外す・無効にする・開発担当にすることができない
        if ($target !== null && (int) $target['is_admin_deputy'] === 1 && (int) ($target['is_admin_deputy_lead'] ?? 0) === 0 && (int) $target['is_active'] === 1
            && ($isAdminDeputy === 0 || $isActive === 0 || $isAdminDeputyLead === 1) && admin_deputy_plain_count((int) $target['id']) === 0) {
            $errors[] = msg('開発担当のほかに補佐が 1 人もいなくなるため、変更できません。先に、ほかの人を補佐にしてください');
        }
        if ($isAdmin === 1 && $isAdminDeputy === 1) {
            $errors[] = msg('管理者と補佐は兼ねられません。どちらか一方にしてください');
        }
        if ($isAdminDeputy === 1 && ($isActive === 1 || $target === null)
            && admin_deputy_count($target === null ? null : (int) $target['id']) >= ADMIN_DEPUTY_MAX_COUNT) {
            $errors[] = msg('補佐は {max} 名までです。ほかの人の補佐を外してから付けてください', ['max' => ADMIN_DEPUTY_MAX_COUNT]);
        }
        if ($isAdminDeputyLead === 1 && ($isActive === 1 || $target === null)
            && admin_deputy_lead_count($target === null ? null : (int) $target['id']) >= 1) {
            $errors[] = msg('補佐（開発担当）は 1 名だけです。ほかの人の印を外してから付けてください');
        }

        if (!$errors) {
            if ($target === null) {
                $temp = make_temp_password();
                db_exec(
                    'INSERT INTO users (username, password_hash, role_title, is_admin, is_admin_deputy, is_admin_deputy_lead, is_executive, is_hr_manager, specialist_title, created_at, login_id, display_name, department_id, team_id, must_change_password, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
                    [$loginId, password_hash($temp, PASSWORD_DEFAULT), $roleTitle, $isAdmin, $isAdminDeputy, $isAdminDeputyLead, $isExecutive, $isHrManager, $specialistTitle, now(), $loginId, $display, $deptId, $teamId, now()]
                );
                $newId = (int) db()->lastInsertId();
                audit_log('admin', 'user:' . $newId . ':create');
                if ($isAdminDeputy === 1) {
                    audit_log('admin', 'user:' . $newId . ':deputy_on');
                }
                if ($isAdminDeputyLead === 1) {
                    audit_log('admin', 'user:' . $newId . ':deputy_lead_on');
                }
                if ($isExecutive === 1) {
                    audit_log('admin', 'user:' . $newId . ':executive_on');
                }
                if ($specialistTitle !== '') {
                    audit_log('admin', 'user:' . $newId . ':specialist_on');
                }
                if ($isHrManager === 1) {
                    audit_log('admin', 'user:' . $newId . ':hr_manager_on');
                }
                $_SESSION['show_temp_password'] = ['id' => $newId, 'password' => $temp];
                rk_done('/admin/user_edit.php?id=' . $newId);
            }
            $tid = (int) $target['id'];
            // 無効にするときに外す役割（知らせるため、外す前に読む）
            $removedRoleTextList = [];
            $isLineRoleRemoved = ($isActive === 0 && (int) $target['is_active'] === 1) || $specialistTitle !== '';
            if ($isLineRoleRemoved) {
                foreach (db_all('SELECT d.name, r.is_final FROM department_reviewers r JOIN departments d ON d.id = r.department_id WHERE r.user_id = ?', [$tid]) as $removedRoleRow) {
                    $removedRoleTextList[] = $removedRoleRow['name'] . '（' . ((int) $removedRoleRow['is_final'] === 1 ? msg('課長') : msg('確認者')) . '）';
                }
                foreach (db_all('SELECT d.name FROM department_admins a JOIN departments d ON d.id = a.department_id WHERE a.user_id = ?', [$tid]) as $removedRoleRow) {
                    $removedRoleTextList[] = $removedRoleRow['name'] . '（' . msg('項目管理者') . '）';
                }
            }
            tx(function () use ($tid, $display, $roleTitle, $deptId, $teamId, $isAdmin, $isAdminDeputy, $isAdminDeputyLead, $isExecutive, $isHrManager, $specialistTitle, $isActive, $target) {
                db_exec(
                    'UPDATE users SET display_name = ?, role_title = ?, department_id = ?, team_id = ?, is_admin = ?, is_admin_deputy = ?, is_admin_deputy_lead = ?, is_executive = ?, is_hr_manager = ?, specialist_title = ?, is_active = ?, updated_at = ? WHERE id = ?',
                    [$display, $roleTitle, $deptId, $teamId, $isAdmin, $isAdminDeputy, $isAdminDeputyLead, $isExecutive, $isHrManager, $specialistTitle, $isActive, now(), $tid]
                );
                if ((int) $target['is_admin_deputy'] !== $isAdminDeputy || (int) $target['is_admin'] !== $isAdmin
                    || (int) ($target['is_admin_deputy_lead'] ?? 0) !== $isAdminDeputyLead) {
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
            if ($isLineRoleRemoved && $removedRoleTextList) {
                flash('info', msg('確認者・項目管理者の役割を外しました: {roles}。課長がいなくなった課は、組織・ユーザーの画面で新しい課長を設定してください', ['roles' => implode('、', $removedRoleTextList)]));
            }
            if ((int) $target['is_admin'] !== $isAdmin) {
                audit_log('admin', 'user:' . $tid . ':' . ($isAdmin === 1 ? 'admin_on' : 'admin_off'));
            }
            if ((int) $target['is_admin_deputy'] !== $isAdminDeputy) {
                audit_log('admin', 'user:' . $tid . ':' . ($isAdminDeputy === 1 ? 'deputy_on' : 'deputy_off'));
            }
            if ((int) ($target['is_admin_deputy_lead'] ?? 0) !== $isAdminDeputyLead) {
                audit_log('admin', 'user:' . $tid . ':' . ($isAdminDeputyLead === 1 ? 'deputy_lead_on' : 'deputy_lead_off'));
            }
            if ((int) ($target['is_executive'] ?? 0) !== $isExecutive) {
                audit_log('admin', 'user:' . $tid . ':' . ($isExecutive === 1 ? 'executive_on' : 'executive_off'));
            }
            if ((int) ($target['is_hr_manager'] ?? 0) !== $isHrManager) {
                audit_log('admin', 'user:' . $tid . ':' . ($isHrManager === 1 ? 'hr_manager_on' : 'hr_manager_off'));
            }
            if ((string) ($target['specialist_title'] ?? '') !== $specialistTitle) {
                audit_log('admin', 'user:' . $tid . ':' . ($specialistTitle !== '' ? 'specialist_on' : 'specialist_off'));
            }
            audit_log('admin', 'user:' . $tid . ':' . ($isActive === 0 && (int) $target['is_active'] === 1 ? 'deactivate' : 'update'));
            flash('success', msg('保存しました'));
            rk_done('/admin/user_edit.php?id=' . $tid);
        }
        rk_cancel();

    } elseif ($target !== null && in_array($action, ['view_backup_codes', 'recovery_issue'], true)) {
        // 予備のコードの確認・復旧コードの発行（システム管理者と補佐（開発担当）だけ。自分の分は対象外。K-075）
        $tid = (int) $target['id'];
        if (!has_admin_authority($user)) {
            error_page(403);
        }
        if ($tid === $me || (int) $target['is_active'] !== 1 || $target['totp_enabled_at'] === null) {
            error_page(400);
        }
        $reason = security_confirm_operator($user, $errors);
        $readableCodeList = $errors || $action !== 'view_backup_codes' ? [] : security_readable_backup_codes($tid);
        if (!$errors && $action === 'view_backup_codes' && !$readableCodeList) {
            $errors[] = msg('確認できる予備のコードがありません（作り直す前の古いコードは確認できません）。本人に作り直してもらうか、復旧コードを発行してください');
        }
        if (!$errors) {
            // 本人への知らせと記録を残してから見せる（残せなければ見せない）
            $recoveryCode = tx(function () use ($tid, $action, $user, $reason): ?string {
                $noticeId = security_add_notice($tid, $action === 'recovery_issue' ? 'recovery_issue' : 'codes_view', (int) $user['id'], security_display_name($user), security_actor_role($user), $reason);
                audit_log('admin', 'user:' . $tid . ':' . $action);
                return $action === 'recovery_issue' ? security_issue_recovery_code($tid, $noticeId) : null;
            });
            $_SESSION['show_security_codes'] = ['id' => $tid, 'backup' => $readableCodeList, 'recovery' => $recoveryCode];
            rk_done('/admin/user_edit.php?id=' . $tid);
        }
        rk_cancel();
    } elseif ($target !== null && $action === 'reset_backup_codes') {
        // 予備のコードの初期化: 本人がコードをメール・チャットに貼った、なくした、などのとき。
        // 今のコードをすべて使えなくする（認証アプリと端末の記憶はそのまま）。本人はアカウント設定で作り直す（ホームで促す）
        $tid = (int) $target['id'];
        if ($target['totp_enabled_at'] === null) {
            flash('error', msg('2 段階認証が未設定のため、予備のコードはありません'));
            rk_cancel();
            redirect('/admin/user_edit.php?id=' . $tid);
        }
        db_exec('DELETE FROM user_backup_codes WHERE user_id = ?', [$tid]);
        audit_log('admin', 'user:' . $tid . ':reset_backup_codes');
        flash('success', msg('予備のコードを初期化しました。今のコードは使えません。本人に、アカウント設定の「予備のコード」で作り直すよう伝えてください'));
        rk_done('/admin/user_edit.php?id=' . $tid);
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
                if (security_is_supported()) {
                    db_exec('DELETE FROM user_recovery_codes WHERE user_id = ?', [$tid]);
                }
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
$shownBackupCodes = null;
$shownRecoveryCode = null;
if (isset($_SESSION['show_security_codes']) && $target !== null && (int) $_SESSION['show_security_codes']['id'] === (int) $target['id']) {
    $shownBackupCodes = $_SESSION['show_security_codes']['backup'] ?: null;
    $shownRecoveryCode = $_SESSION['show_security_codes']['recovery'];
}
unset($_SESSION['show_security_codes']);

if ($target !== null) {
    $target = db_one('SELECT * FROM users WHERE id = ?', [(int) $target['id']]);
}

// ラインの選択肢（課ごと。画面では課を選ぶと、その課のラインだけを出す）
$teamOptionList = db_all('SELECT t.id, t.name, t.department_id FROM department_teams t JOIN departments d ON d.id = t.department_id
                            WHERE t.is_active = 1 AND d.is_active = 1 ORDER BY d.sort_order, t.sort_order, t.id');

render('admin_user_edit', [
    'teamOptionList' => $teamOptionList,
    'shownBackupCodes'  => $shownBackupCodes,
    'shownRecoveryCode' => $shownRecoveryCode,
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
    'backupCodeRemaining' => $target === null ? 0 : (int) db_val('SELECT COUNT(*) FROM user_backup_codes WHERE user_id = ? AND used_at IS NULL', [(int) $target['id']]),
]);
