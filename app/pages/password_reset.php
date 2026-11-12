<?php

declare(strict_types=1);

// ====================================================================
// G-02 パスワードの再設定（詳細設計書 G02_password_reset.md）
// 2 段階認証のコード（または予備のコード）で本人確認する。
// ====================================================================

page_start(['auth' => 'none']);

$errors = [];
$loginId = '';

if (is_post()) {
    $loginId = auth_normalize_login_id(post_str('login_id'));
    $code = post_str('code');
    $new = post_str('new_password', false);
    $confirm = post_str('new_password_confirm', false);
    $fail = 'ログイン ID かコードが違います';

    $user = auth_find_user($loginId);
    if ($user === null || (int) $user['is_active'] !== 1 || $user['totp_enabled_at'] === null) {
        auth_dummy_verify($code);
        if (auth_ip_is_locked()) {
            $errors[] = 'ログインに続けて失敗したため、しばらく操作できません。15 分ほど待ってからお試しください';
        } else {
            auth_ip_register_failure();
            $errors[] = $fail;
        }
        audit_log('auth', 'password:reset_fail', null);
    } elseif (auth_is_locked($user)) {
        $errors[] = 'ログインに続けて失敗したため、しばらく操作できません。15 分ほど待ってからお試しください';
    } else {
        $pwError = auth_validate_new_password($new, $loginId);
        if ($pwError !== null) {
            $errors[] = $pwError;
        } elseif ($new !== $confirm) {
            $errors[] = '確認のパスワードが一致しません';
        } elseif (!auth_verify_second_factor($user, $code)) {
            auth_register_failure((int) $user['id']);
            audit_log('auth', 'password:reset_fail', (int) $user['id']);
            $errors[] = $fail;
        } else {
            auth_set_password((int) $user['id'], $new);
            audit_log('auth', 'password:reset', (int) $user['id']);
            flash('success', 'パスワードを再設定しました。新しいパスワードでログインしてください');
            redirect('/login.php');
        }
    }
}

render('password_reset', [
    'title'   => 'パスワードの再設定',
    'user'    => null,
    'errors'  => $errors,
    'loginId' => $loginId,
]);
