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

    // 利用者の有無・ロックの有無で文言や確かめる順番を変えない（ID の推測を防ぐ）
    // ロックの文言は、この端末（IP）がロックされたときだけ出す
    $user = auth_find_user($loginId);
    $valid = $user !== null && (int) $user['is_active'] === 1 && $user['totp_enabled_at'] !== null;
    $pwError = auth_validate_new_password($new, $loginId);
    if (auth_ip_is_locked()) {
        auth_dummy_verify($code);
        $errors[] = 'ログインに続けて失敗したため、しばらく操作できません。15 分ほど待ってからお試しください';
    } elseif ($pwError !== null) {
        $errors[] = $pwError;
    } elseif ($new !== $confirm) {
        $errors[] = '確認のパスワードが一致しません';
    } elseif (!$valid) {
        auth_dummy_verify($code);
        auth_ip_register_failure();
        audit_log('auth', 'password:reset_fail', null);
        $errors[] = $fail;
    } elseif (auth_is_locked($user)) {
        // ロック中はコードを確かめない。文言はコードの誤りと同じ
        auth_dummy_verify($code);
        audit_log('auth', 'password:reset_locked_attempt', (int) $user['id']);
        $errors[] = $fail;
    } elseif (!auth_verify_second_factor($user, $code)) {
        $locked = auth_register_failure((int) $user['id']);
        audit_log('auth', $locked ? 'password:reset_locked' : 'password:reset_fail', (int) $user['id']);
        $errors[] = $fail;
    } else {
        auth_set_password((int) $user['id'], $new);
        audit_log('auth', 'password:reset', (int) $user['id']);
        flash('success', 'パスワードを再設定しました。新しいパスワードでログインしてください');
        redirect('/login.php');
    }
}

render('password_reset', [
    'title'   => 'パスワードの再設定',
    'user'    => null,
    'errors'  => $errors,
    'loginId' => $loginId,
]);
