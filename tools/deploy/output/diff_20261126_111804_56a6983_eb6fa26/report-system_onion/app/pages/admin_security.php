<?php

declare(strict_types=1);

// ====================================================================
// G-14 §6.11 アカウントの凍結・全員の総入れ替え（システム管理者・補佐（開発担当）だけ。K-075）
// ====================================================================

$user = page_start();
$isAuthority = has_admin_authority($user);
// 補佐（開発担当）がいない・凍結中のとき、別の補佐は総入れ替えの欄だけを使う（凍結したアカウントの一覧・再初期化は使えない）
$leadRecovery = security_lead_recovery_request();
$hasHalfPassword = isset($_SESSION['show_half_password']);
// 開発担当の復旧の承認（別の補佐）と、後半の表示
if (!$isAuthority && !$hasHalfPassword && !security_can_approve_lead_recovery($user, $leadRecovery) && !(security_is_supported() && security_is_mass_reset_member($user) && security_mass_reset_state()['isNeeded'])) {
    error_page(403);
}
$errors = [];
$temp = null;
$tempUser = null;

if (is_post()) {
    $action = post_str('action');
    if (!in_array($action, ['freeze_reinit', 'lead_recovery_request', 'lead_recovery_approve', 'lead_recovery_cancel', 'mass_request', 'mass_approve', 'mass_cancel'], true)) {
        error_page(400);
    }
    rk_begin('security.' . $action, (int) $user['id']);
    if ($action === 'freeze_reinit') {
        if (!$isAuthority) {
            error_page(403);
        }
        $frozenUser = db_one('SELECT * FROM users WHERE id = ?', [input_id('user_id', 'POST')]);
        if ($frozenUser === null || !security_is_frozen($frozenUser)) {
            error_page(404);
        }
        if (!security_can_reinit($user, (int) $frozenUser['id'])) {
            error_page(403, msg('この凍結のもとになった操作をした人は、再初期化できません。ほかの管理者か開発担当が行ってください。'));
        }
        $reason = security_confirm_operator($user, $errors);
        if (!$errors) {
            $_SESSION['show_reinit_password'] = ['id' => (int) $frozenUser['id'], 'password' => security_reinit($user, (int) $frozenUser['id'], $reason)];
            rk_done('/admin/security.php');
        }
        rk_cancel();
    } elseif (in_array($action, ['lead_recovery_request', 'lead_recovery_approve', 'lead_recovery_cancel'], true)) {
        $leadRecovery = security_lead_recovery_request();
        if ($action === 'lead_recovery_request') {
            $protectedUser = db_one('SELECT * FROM users WHERE id = ? AND is_active = 1', [input_id('user_id', 'POST')]);
            if (!is_admin($user) || $protectedUser === null || !security_is_protected($protectedUser) || (int) $protectedUser['id'] === (int) $user['id']) {
                error_page(403);
            }
            $reason = security_confirm_operator($user, $errors);
            if (!$errors) {
                $_SESSION['show_half_password'] = ['label' => msg('仮のパスワードの前半'), 'password' => security_request_lead_recovery($user, (int) $protectedUser['id'], $reason)];
                rk_done('/admin/security.php#lead-recovery');
            }
        } else {
            if ($leadRecovery === null || (int) $leadRecovery['id'] !== input_id('request_id', 'POST')) {
                error_page(404);
            }
            if ($action === 'lead_recovery_cancel') {
                if (!is_admin($user) && !security_can_approve_lead_recovery($user, $leadRecovery) && (int) $user['id'] !== (int) $leadRecovery['user_id']) {
                    error_page(403);
                }
                db_exec("UPDATE lead_recovery_requests SET status = 'cancelled', decided_at = ?, approver_id = ?, first_half_enc = NULL WHERE id = ? AND status = 'pending'", [now(), (int) $user['id'], (int) $leadRecovery['id']]);
                audit_log('admin', 'user:' . (int) $leadRecovery['user_id'] . ':lead_recovery_cancel');
                flash('success', msg('復旧の申請を取り消しました'));
                rk_done('/index.php');
            }
            if (!security_can_approve_lead_recovery($user, $leadRecovery)) {
                error_page(403);
            }
            security_confirm_operator($user, $errors);
            if (!$errors) {
                $_SESSION['show_half_password'] = ['label' => msg('仮のパスワードの後半'), 'password' => security_approve_lead_recovery($user, $leadRecovery)];
                rk_done('/admin/security.php?half=1');
            }
        }
        rk_cancel();
    } elseif ($action === 'mass_request') {
        if (!security_can_request_mass_reset($user)) {
            error_page(403);
        }
        $reason = security_confirm_operator($user, $errors);
        if (!$errors) {
            security_request_mass_reset($user, $reason);
            flash('success', msg('総入れ替えを申請しました。もう 1 人が承認すると実施します（24 時間以内）'));
            rk_done('/admin/security.php#mass-reset');
        }
        rk_cancel();
    } else {
        if (!security_is_mass_reset_member($user)) {
            error_page(403);
        }
        $massResetRequest = security_mass_reset_request();
        if ($massResetRequest === null || (int) $massResetRequest['id'] !== input_id('request_id', 'POST')) {
            error_page(404);
        }
        if ($action === 'mass_cancel') {
            db_exec("UPDATE mass_reset_requests SET status = 'cancelled', decided_at = ?, approver_id = ? WHERE id = ? AND status = 'pending'", [now(), (int) $user['id'], (int) $massResetRequest['id']]);
            audit_log('admin', 'security:mass_reset_cancel');
            flash('success', msg('総入れ替えの申請を取り消しました'));
            rk_done('/admin/security.php');
        }
        if (!security_can_approve_mass_reset($user, $massResetRequest)) {
            error_page(403);
        }
        security_confirm_operator($user, $errors);
        if (!$errors) {
            $count = security_mass_reset($user, $massResetRequest);
            flash('success', msg('{count} 人のログインの情報を総入れ替えにしました。あなたも、ログインしてパスワードの変更と 2 段階認証の設定し直しをしてください', ['count' => $count]));
            rk_done('/login.php');
        }
        rk_cancel();
    }
}

if (isset($_SESSION['show_reinit_password'])) {
    $temp = (string) $_SESSION['show_reinit_password']['password'];
    $tempUser = db_one('SELECT * FROM users WHERE id = ?', [(int) $_SESSION['show_reinit_password']['id']]);
    unset($_SESSION['show_reinit_password']);
}

$halfPassword = $_SESSION['show_half_password'] ?? null;
unset($_SESSION['show_half_password']);

render('admin_security', [
    'halfPassword' => $halfPassword, 'leadRecovery' => security_lead_recovery_request(), 'canRequestLeadRecovery' => is_admin($user),
    'protectedUserList' => array_values(array_filter(security_protected_users(), function (array $protectedUser) use ($user): bool {
        return (int) $protectedUser['id'] !== (int) $user['id'];
    })),
    'title' => 'アカウントの凍結', 'user' => $user, 'errors' => $errors, 'temp' => $temp, 'tempUser' => $tempUser,
    'isAuthority' => $isAuthority, 'freezeList' => $isAuthority ? security_open_freezes() : [], 'massResetState' => security_mass_reset_state(), 'massResetRequest' => security_mass_reset_request(),
    'canApproveMassReset' => security_can_approve_mass_reset($user, security_mass_reset_request()),
]);
