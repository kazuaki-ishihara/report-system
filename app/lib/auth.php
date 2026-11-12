<?php

declare(strict_types=1);

// ====================================================================
// 認証（パスワード、ロック、2 段階認証、予備のコード、端末の記憶）
// 詳細設計書 G-01・G-02・G-16
// ====================================================================

const REMEMBER_COOKIE = 'mr_device';

function auth_normalize_login_id(string $id): string
{
    return strtolower(trim($id));
}

function auth_is_valid_login_id(string $id): bool
{
    return preg_match('/^[a-z0-9][a-z0-9._-]{2,31}$/', $id) === 1;
}

function auth_find_user(string $loginId): ?array
{
    if (!auth_is_valid_login_id($loginId)) {
        return null;
    }
    return db_one('SELECT * FROM users WHERE login_id = ?', [$loginId]);
}

/** 存在しない ID でも同じ時間がかかるよう、ダミーのハッシュで確かめる */
function auth_dummy_verify(string $password): void
{
    static $dummy = null;
    if ($dummy === null) {
        $dummy = password_hash('dummy-password-for-timing', PASSWORD_DEFAULT);
    }
    password_verify($password, $dummy);
}

function auth_is_locked(array $user): bool
{
    return $user['locked_until'] !== null && $user['locked_until'] > now();
}

/** ログイン・再設定・コードの失敗を数え、上限でロックする。ロックしたら true */
function auth_register_failure(int $userId): bool
{
    $max = (int) cfg('login_max_failures');
    $row = db_one('SELECT failed_count FROM users WHERE id = ?', [$userId]);
    $count = (int) ($row['failed_count'] ?? 0) + 1;
    if ($count >= $max) {
        $until = date('Y-m-d H:i:s', time() + 60 * (int) cfg('login_lock_minutes'));
        db_exec('UPDATE users SET failed_count = 0, locked_until = ? WHERE id = ?', [$until, $userId]);
        return true;
    }
    db_exec('UPDATE users SET failed_count = ? WHERE id = ?', [$count, $userId]);
    return false;
}

function auth_reset_failures(int $userId): void
{
    db_exec('UPDATE users SET failed_count = 0, locked_until = NULL WHERE id = ?', [$userId]);
}

/** 存在しない ID の失敗は IP アドレスごとに数える。ロック中なら true */
function auth_ip_is_locked(): bool
{
    $row = db_one('SELECT locked_until FROM login_throttle WHERE throttle_key = ?', ['ip:' . client_ip()]);
    return $row !== null && $row['locked_until'] !== null && $row['locked_until'] > now();
}

function auth_ip_register_failure(): void
{
    $key = 'ip:' . client_ip();
    $max = (int) cfg('login_max_failures');
    $row = db_one('SELECT failures FROM login_throttle WHERE throttle_key = ?', [$key]);
    $count = (int) ($row['failures'] ?? 0) + 1;
    $until = null;
    if ($count >= $max) {
        $until = date('Y-m-d H:i:s', time() + 60 * (int) cfg('login_lock_minutes'));
        $count = 0;
    }
    db_exec(
        'INSERT INTO login_throttle (throttle_key, failures, locked_until, updated_at) VALUES (?, ?, ?, ?)
         ON CONFLICT(throttle_key) DO UPDATE SET failures = excluded.failures, locked_until = excluded.locked_until, updated_at = excluded.updated_at',
        [$key, $count, $until, now()]
    );
}

function auth_validate_new_password(string $password, string $loginId): ?string
{
    if (str_len($password) < (int) cfg('password_min_length')) {
        return 'パスワードは ' . (int) cfg('password_min_length') . ' 文字以上にしてください';
    }
    if (strlen($password) > 72) {
        return 'パスワードが長すぎます';
    }
    if (auth_normalize_login_id($password) === $loginId) {
        return 'ログイン ID と同じパスワードは使えません';
    }
    return null;
}

function auth_set_password(int $userId, string $password): void
{
    db_exec(
        'UPDATE users SET password_hash = ?, password_changed_at = ?, must_change_password = 0,
         failed_count = 0, locked_until = NULL, session_version = session_version + 1, updated_at = ? WHERE id = ?',
        [password_hash($password, PASSWORD_DEFAULT), now(), now(), $userId]
    );
}

// ---- 2 段階認証 ----------------------------------------------------

function auth_totp_secret(array $user): ?string
{
    if ($user['totp_secret_enc'] === null) {
        return null;
    }
    $b32 = dec((string) $user['totp_secret_enc'], 'totp:' . (int) $user['id']);
    if ($b32 === null) {
        return null;
    }
    return base32_decode($b32);
}

/** TOTP のコードか予備のコードを確かめる。成功なら true（使用済みの記録も行う） */
function auth_verify_second_factor(array $user, string $code): bool
{
    $code = preg_replace('/[\s-]/', '', $code);
    if (preg_match('/^\d{6}$/', $code) === 1) {
        $secret = auth_totp_secret($user);
        if ($secret === null) {
            return false;
        }
        $last = $user['totp_last_step'] !== null ? (int) $user['totp_last_step'] : null;
        $step = totp_verify($secret, $code, $last);
        if ($step === null) {
            return false;
        }
        db_exec('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, (int) $user['id']]);
        return true;
    }
    if (preg_match('/^[a-z0-9]{10}$/i', $code) === 1) {
        $code = strtolower($code);
        $rows = db_all('SELECT id, code_hash FROM user_backup_codes WHERE user_id = ? AND used_at IS NULL', [(int) $user['id']]);
        foreach ($rows as $r) {
            if (password_verify($code, $r['code_hash'])) {
                db_exec('UPDATE user_backup_codes SET used_at = ? WHERE id = ?', [now(), (int) $r['id']]);
                return true;
            }
        }
    }
    return false;
}

/** 予備のコードを作り直す。表示用の値を返す（DB にはハッシュだけを入れる） */
function auth_regenerate_backup_codes(int $userId): array
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $codes = [];
    db_exec('DELETE FROM user_backup_codes WHERE user_id = ?', [$userId]);
    for ($i = 0; $i < (int) cfg('backup_code_count'); $i++) {
        $c = '';
        for ($j = 0; $j < 10; $j++) {
            $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        db_exec(
            'INSERT INTO user_backup_codes (user_id, code_hash, created_at) VALUES (?, ?, ?)',
            [$userId, password_hash($c, PASSWORD_DEFAULT), now()]
        );
        $codes[] = substr($c, 0, 4) . '-' . substr($c, 4);
    }
    return $codes;
}

// ---- 端末の記憶 ----------------------------------------------------

function auth_device_label(): string
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $os = 'その他';
    if (stripos($ua, 'iPhone') !== false) {
        $os = 'iPhone';
    } elseif (stripos($ua, 'iPad') !== false) {
        $os = 'iPad';
    } elseif (stripos($ua, 'Android') !== false) {
        $os = 'Android';
    } elseif (stripos($ua, 'Windows') !== false) {
        $os = 'Windows';
    } elseif (stripos($ua, 'Mac OS') !== false) {
        $os = 'Mac';
    } elseif (stripos($ua, 'Linux') !== false) {
        $os = 'Linux';
    }
    $br = 'ブラウザー';
    if (stripos($ua, 'Edg/') !== false) {
        $br = 'Edge';
    } elseif (stripos($ua, 'Chrome/') !== false || stripos($ua, 'CriOS') !== false) {
        $br = 'Chrome';
    } elseif (stripos($ua, 'Firefox/') !== false) {
        $br = 'Firefox';
    } elseif (stripos($ua, 'Safari/') !== false) {
        $br = 'Safari';
    }
    return $os . ' の ' . $br;
}

function auth_cookie_options(int $expires): array
{
    return [
        'expires'  => $expires,
        'path'     => (cfg('base_path') === '' ? '/' : cfg('base_path') . '/'),
        'secure'   => (bool) cfg('https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function auth_remember_device(int $userId): void
{
    $token = random_token(32);
    $expires = time() + 86400 * (int) cfg('remember_device_days');
    db_exec(
        'INSERT INTO remembered_devices (user_id, token_hash, label, expires_at, last_used_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$userId, hash('sha256', $token), auth_device_label(), date('Y-m-d H:i:s', $expires), now(), now()]
    );
    setcookie(REMEMBER_COOKIE, $token, auth_cookie_options($expires));
}

/** この端末が記憶済みか（同じ利用者・期限内）。記憶済みなら id を返す */
function auth_remembered_device_id(int $userId): ?int
{
    $token = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (!is_string($token) || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
        return null;
    }
    $row = db_one(
        'SELECT id FROM remembered_devices WHERE token_hash = ? AND user_id = ? AND expires_at > ?',
        [hash('sha256', $token), $userId, now()]
    );
    if ($row === null) {
        return null;
    }
    db_exec('UPDATE remembered_devices SET last_used_at = ? WHERE id = ?', [now(), (int) $row['id']]);
    return (int) $row['id'];
}

function auth_forget_all_devices(int $userId): void
{
    db_exec('DELETE FROM remembered_devices WHERE user_id = ?', [$userId]);
    db_exec('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
}

// ---- 1 日 1 回の後片付け（詳細設計書 DB 編 §5 の一部） -------------------

function housekeeping_daily(): void
{
    $today = date('Y-m-d');
    $last = db_val("SELECT value FROM app_state WHERE name = 'housekeeping'");
    if ($last === $today) {
        return;
    }
    db_exec("INSERT INTO app_state (name, value) VALUES ('housekeeping', ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value", [$today]);
    $rkLimit = date('Y-m-d H:i:s', time() - 3600 * (int) cfg('request_key_hours'));
    db_exec('DELETE FROM request_keys WHERE created_at < ?', [$rkLimit]);
    db_exec('DELETE FROM remembered_devices WHERE expires_at < ?', [now()]);
    db_exec('DELETE FROM login_throttle WHERE (locked_until IS NULL OR locked_until < ?) AND updated_at < ?', [now(), $rkLimit]);
}
