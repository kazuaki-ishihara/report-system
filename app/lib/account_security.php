<?php

declare(strict_types=1);

// ====================================================================
// 予備のコードの確認・復旧コード・アカウントの凍結と再初期化・全員の総入れ替え（G-14 §6.11・K-075）
// 操作できるのは、システム管理者と補佐（開発担当）だけ（has_admin_authority()）。
// 操作した人の名前と役割を、相手（本人）のホームに 30 日出す。知らせを残せないときは、操作もしない（同じトランザクション）。
// ====================================================================

const SECURITY_NOTICE_DAYS = 30;
const RECOVERY_CODE_MINUTES = 30;
const SECURITY_REASON_MAX_LENGTH = 100;
/** 「心当たりがない」を押せる知らせ（総入れ替えは全員に出すので対象にしない） */
const SECURITY_REPORTABLE_KINDS = ['codes_view', 'recovery_issue', 'recovery_used', 'reinit'];

/** 操作した人の役割（知らせに残す。操作の時点の役割） */
function security_actor_role(array $actor): string
{
    return system_role_label($actor);
}

function security_display_name(array $user): string
{
    return (string) $user['display_name'] !== '' ? (string) $user['display_name'] : (string) $user['login_id'];
}

/** 操作する人のパスワードと理由を確かめる（間違いは失敗の回数に数える） */
function security_confirm_operator(array $operator, array &$errors): string
{
    $reason = post_str('reason');
    validate_text($reason, msg('理由'), SECURITY_REASON_MAX_LENGTH, true, $errors);
    if (auth_is_locked($operator)) {
        $errors[] = msg('続けて間違えたため、しばらく操作できません。15 分ほど待ってからお試しください');
    } elseif (!password_verify(post_str('operator_password', false), (string) $operator['password_hash'])) {
        audit_log('auth', auth_register_failure((int) $operator['id']) ? 'login:locked' : 'login:operator_fail', (int) $operator['id']);
        $errors[] = msg('あなたのパスワードが違います');
    }
    return $reason;
}

/** 知らせを残す（tx() の中で呼ぶ）。知らせの ID を返す */
function security_add_notice(int $userId, string $kind, int $actorId, string $actorName, string $actorRole, string $reason): int
{
    db_exec(
        'INSERT INTO security_notices (user_id, kind, actor_id, actor_name, actor_role, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$userId, $kind, $actorId, $actorName, $actorRole, $reason, now()]
    );
    return (int) db()->lastInsertId();
}

/** 列・表のない DB（migrate.php の前）か */
function security_is_supported(): bool
{
    static $isSupported = null;
    if ($isSupported === null) {
        $isSupported = db_val("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'security_notices'") !== null;
    }
    return $isSupported;
}

/** 本人のホームに出す知らせ（新しい順。30 日） */
function security_notices_of(int $userId): array
{
    if (!security_is_supported()) {
        return [];
    }
    return db_all(
        'SELECT * FROM security_notices WHERE user_id = ? AND created_at > ? ORDER BY id DESC',
        [$userId, date('Y-m-d H:i:s', time() - SECURITY_NOTICE_DAYS * 86400)]
    );
}

function security_notice_text(array $notice): string
{
    $values = [
        'date' => display_datetime((string) $notice['created_at']), 'name' => (string) $notice['actor_name'],
        'role' => (string) $notice['actor_role'], 'reason' => (string) $notice['reason'],
    ];
    switch ((string) $notice['kind']) {
        case 'codes_view':
            return msg('{date} に、{name} さん（{role}）が、あなたの予備のコードを確認しました。理由: {reason}。予備のコードを作り直してください。', $values);
        case 'recovery_issue':
            return msg('{date} に、{name} さん（{role}）が、あなたの復旧コードを発行しました。理由: {reason}。', $values);
        case 'recovery_used':
            return msg('{date} に、復旧コードが使われました。発行した人: {name} さん（{role}）。理由: {reason}。', $values);
        case 'reinit':
            return msg('{date} に、{name} さん（{role}）が、あなたのアカウントを再初期化しました。理由: {reason}。同じパスワードをほかのサービスで使っているときは、そちらも変えてください。', $values);
        default:
            return msg('{date} に、{name} さん（{role}）が、全員のログインの情報を総入れ替えしました。理由: {reason}。', $values);
    }
}

// ---- 予備のコードの確認 ---------------------------------------------

/**
 * 未使用の予備のコード（暗号文のあるもの）。
 *
 * @return string[]
 */
function security_readable_backup_codes(int $userId): array
{
    $codes = [];
    foreach (db_all('SELECT id, code_enc FROM user_backup_codes WHERE user_id = ? AND used_at IS NULL AND code_enc IS NOT NULL ORDER BY id', [$userId]) as $row) {
        $code = dec((string) $row['code_enc'], 'bkcode:' . $userId . ':' . (int) $row['id']);
        if ($code !== null && $code !== '') {
            $codes[] = substr($code, 0, 4) . '-' . substr($code, 4);
        }
    }
    return $codes;
}

/** 予備のコードの中に、確認できない古い形のものがあるか（作り直しを促す） */
function security_has_unreadable_backup_codes(int $userId): bool
{
    return security_is_supported()
        && db_val('SELECT 1 FROM user_backup_codes WHERE user_id = ? AND used_at IS NULL AND code_enc IS NULL', [$userId]) !== null;
}

// ---- 復旧コード -----------------------------------------------------

/** 復旧コードを発行する（tx() の中で呼ぶ）。前のコードは使えなくなる */
function security_issue_recovery_code(int $userId, int $noticeId): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $code = '';
    for ($i = 0; $i < 12; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    db_exec('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);
    db_exec(
        'INSERT INTO user_recovery_codes (user_id, code_hash, notice_id, expires_at, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, password_hash($code, PASSWORD_DEFAULT), $noticeId, date('Y-m-d H:i:s', time() + 60 * RECOVERY_CODE_MINUTES), now()]
    );
    return $code;
}

/** 復旧コードを使う。成功なら、2 段階認証・予備のコード・端末を消して true（呼び出し側は設定のやり直しに進める） */
function security_use_recovery_code(array $user, string $inputText): bool
{
    $code = strtolower((string) preg_replace('/[\s-]/', '', $inputText));
    if (!security_is_supported() || preg_match('/\A[a-z0-9]{12}\z/', $code) !== 1) {
        return false;
    }
    $userId = (int) $user['id'];
    return tx(function () use ($userId, $code): bool {
        $row = db_one('SELECT * FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL AND expires_at > ?', [$userId, now()]);
        if ($row === null || !password_verify($code, (string) $row['code_hash'])) {
            return false;
        }
        db_exec('UPDATE user_recovery_codes SET used_at = ? WHERE id = ?', [now(), (int) $row['id']]);
        security_clear_second_factor($userId);
        $issued = db_one('SELECT * FROM security_notices WHERE id = ?', [(int) $row['notice_id']]);
        security_add_notice($userId, 'recovery_used', (int) $issued['actor_id'], (string) $issued['actor_name'], (string) $issued['actor_role'], (string) $issued['reason']);
        return true;
    });
}

/** 2 段階認証・予備のコード・記憶した端末・ログイン中の端末を消す（tx() の中で呼ぶ） */
function security_clear_second_factor(int $userId): void
{
    db_exec('UPDATE users SET totp_secret_enc = NULL, totp_enabled_at = NULL, totp_last_step = NULL, updated_at = ? WHERE id = ?', [now(), $userId]);
    db_exec('DELETE FROM user_backup_codes WHERE user_id = ?', [$userId]);
    auth_forget_all_devices($userId);
}

// ---- 使えないパスワード（抜かれたおそれのあるもの） ------------------

/** 今のパスワードを、これから使えないパスワードに加える（期限なし） */
function security_block_current_password(int $userId): void
{
    db_exec('INSERT INTO user_blocked_passwords (user_id, password_hash, created_at) SELECT id, password_hash, ? FROM users WHERE id = ?', [now(), $userId]);
}

function security_password_is_blocked(int $userId, string $password): bool
{
    foreach (security_is_supported() ? db_all('SELECT password_hash FROM user_blocked_passwords WHERE user_id = ?', [$userId]) : [] as $row) {
        if (password_verify($password, (string) $row['password_hash'])) {
            return true;
        }
    }
    return false;
}

// ---- 凍結と再初期化 -------------------------------------------------

function security_is_frozen(array $user): bool
{
    return ($user['frozen_at'] ?? null) !== null;
}

/** 凍結していない、有効な管理者・補佐（開発担当）の ID */
function security_authority_ids(): array
{
    return array_map('intval', array_column(
        db_all('SELECT id FROM users WHERE is_active = 1 AND frozen_at IS NULL AND (is_admin = 1 OR (is_admin_deputy = 1 AND is_admin_deputy_lead = 1))'),
        'id'
    ));
}

/**
 * 本人が「心当たりがない」を押した: 本人のアカウントを凍結し、管理者と補佐（開発担当）に知らせる。
 * 最後の 1 人の管理者は、解く人がいなくなるのでログインは止めない（コードと端末だけ無効にする）。
 *
 * @return bool ログインを止めたか
 */
function security_freeze(array $user, array $notice): bool
{
    $userId = (int) $user['id'];
    return tx(function () use ($userId, $notice): bool {
        $isLoginStopped = array_diff(security_authority_ids(), [$userId]) !== [];
        security_block_current_password($userId);
        db_exec('DELETE FROM user_backup_codes WHERE user_id = ?', [$userId]);
        db_exec('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);
        auth_forget_all_devices($userId);
        db_exec('UPDATE security_notices SET reported_at = ? WHERE id = ?', [now(), (int) $notice['id']]);
        db_exec(
            'INSERT INTO account_freezes (user_id, notice_id, operator_id, is_login_stopped, created_at, resolved_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, (int) $notice['id'], (int) $notice['actor_id'], $isLoginStopped ? 1 : 0, now(), $isLoginStopped ? null : now()]
        );
        if ($isLoginStopped) {
            db_exec('UPDATE users SET frozen_at = ?, updated_at = ? WHERE id = ?', [now(), now(), $userId]);
        }
        audit_log('auth', 'account:freeze;notice=' . (int) $notice['id'], $userId);
        return $isLoginStopped;
    });
}

/** まだ再初期化していない凍結（古い順） */
function security_open_freezes(): array
{
    if (!security_is_supported()) {
        return [];
    }
    return db_all(
        'SELECT f.*, n.kind, n.actor_name, n.actor_role, n.reason, n.created_at AS notice_at, u.display_name, u.login_id
           FROM account_freezes f JOIN security_notices n ON n.id = f.notice_id JOIN users u ON u.id = f.user_id
          WHERE f.resolved_at IS NULL ORDER BY f.id'
    );
}

/** 凍結のもとになった操作をした人は、自分が関わった凍結を解けない（ほかに管理者・開発担当がいないときだけ例外） */
function security_can_reinit(array $operator, int $frozenUserId): bool
{
    $operatorId = (int) $operator['id'];
    if (!has_admin_authority($operator) || $operatorId === $frozenUserId) {
        return false;
    }
    // 補佐（開発担当）は、管理者だけでは再初期化できない（2 人での復旧を使う）
    $frozenUser = db_one('SELECT * FROM users WHERE id = ?', [$frozenUserId]);
    if ($frozenUser === null || security_is_protected($frozenUser)) {
        return false;
    }
    $involvedIdList = array_map('intval', array_column(
        db_all('SELECT DISTINCT operator_id FROM account_freezes WHERE user_id = ? AND resolved_at IS NULL', [$frozenUserId]),
        'operator_id'
    ));
    if (!in_array($operatorId, $involvedIdList, true)) {
        return true;
    }
    return array_diff(security_authority_ids(), $involvedIdList, [$frozenUserId]) === [];
}

/** 再初期化して凍結を解く。仮のパスワードを返す（報告・課・役割・権限は変えない） */
function security_reinit(array $operator, int $userId, string $reason): string
{
    $temp = make_temp_password();
    tx(function () use ($operator, $userId, $reason, $temp): void {
        security_apply_reinit($userId, $temp, (int) $operator['id']);
        security_add_notice($userId, 'reinit', (int) $operator['id'], security_display_name($operator), security_actor_role($operator), $reason);
        audit_log('admin', 'user:' . $userId . ':freeze_reinit');
    });
    return $temp;
}

// ---- 全員の総入れ替え -----------------------------------------------

/**
 * 総入れ替えを考える状態か: 前の総入れ替えのあとに、同じ人が 2 回凍結したか、30 日に 2 人以上が凍結した。
 *
 * @return array{isNeeded: bool, operatorIdList: int[]}
 */
function security_mass_reset_state(): array
{
    if (!security_is_supported()) {
        return ['isNeeded' => false, 'operatorIdList' => []];
    }
    $lastResetAt = (string) (db_val('SELECT MAX(created_at) FROM mass_credential_resets') ?? '');
    $freezeList = db_all('SELECT user_id, operator_id, created_at FROM account_freezes WHERE created_at > ?', [$lastResetAt]);
    $countByUserId = array_count_values(array_map('intval', array_column($freezeList, 'user_id')));
    $recentSince = date('Y-m-d H:i:s', time() - 30 * 86400);
    $recentUserIdList = array_unique(array_column(array_filter($freezeList, function (array $freeze) use ($recentSince): bool {
        return (string) $freeze['created_at'] > $recentSince;
    }), 'user_id'));
    return [
        'isNeeded'       => ($countByUserId && max($countByUserId) >= 2) || count($recentUserIdList) >= 2,
        'operatorIdList' => array_values(array_unique(array_map('intval', array_column($freezeList, 'operator_id')))),
    ];
}

/** 総入れ替えの申請の期限（時間） */
const MASS_RESET_REQUEST_HOURS = 24;

/** 承認を待っている総入れ替えの申請（期限の中。申請した人が今も管理者か補佐（開発担当）で、凍結していないもの） */
function security_mass_reset_request(): ?array
{
    if (!security_is_supported()) {
        return null;
    }
    $request = db_one(
        "SELECT * FROM mass_reset_requests WHERE status = 'pending' AND created_at > ? ORDER BY id DESC LIMIT 1",
        [date('Y-m-d H:i:s', time() - MASS_RESET_REQUEST_HOURS * 3600)]
    );
    $requester = $request === null ? null : db_one('SELECT * FROM users WHERE id = ? AND is_active = 1 AND frozen_at IS NULL', [(int) $request['requester_id']]);
    return $requester !== null && security_is_mass_reset_member($requester) ? $request : null;
}

/**
 * 総入れ替えの 2 人のうちの 1 人になれるか: システム管理者と補佐（開発担当）。
 * 補佐（開発担当）がいない・凍結中のときは、別の補佐が代わりになる（K-075）
 */
function security_is_mass_reset_member(array $operator): bool
{
    if (has_admin_authority($operator)) {
        return true;
    }
    $hasLead = db_val('SELECT 1 FROM users WHERE is_admin_deputy = 1 AND is_admin_deputy_lead = 1 AND is_active = 1 AND frozen_at IS NULL') !== null;
    return is_admin_deputy($operator) && !$hasLead;
}

/** 総入れ替えを申請できるか（総入れ替えを考える状態で、承認を待つ申請がないとき） */
function security_can_request_mass_reset(array $operator): bool
{
    return security_is_supported() && security_is_mass_reset_member($operator) && security_mass_reset_state()['isNeeded'] && security_mass_reset_request() === null;
}

/** 総入れ替えを承認できるか: 申請した人と別の人で、システム管理者と補佐（開発担当。いないときは別の補佐）が 1 人ずつそろうこと */
function security_can_approve_mass_reset(array $operator, ?array $request): bool
{
    if ($request === null || !security_is_mass_reset_member($operator) || !security_mass_reset_state()['isNeeded'] || (int) $operator['id'] === (int) $request['requester_id']) {
        return false;
    }
    return is_admin(db_one('SELECT * FROM users WHERE id = ?', [(int) $request['requester_id']])) !== is_admin($operator);
}

/** 総入れ替えを申請する（まだ実施しない） */
function security_request_mass_reset(array $operator, string $reason): void
{
    tx(function () use ($operator, $reason): void {
        db_exec("UPDATE mass_reset_requests SET status = 'expired' WHERE status = 'pending'");
        db_exec(
            "INSERT INTO mass_reset_requests (requester_id, requester_name, requester_role, reason, status, created_at) VALUES (?, ?, ?, ?, 'pending', ?)",
            [(int) $operator['id'], security_display_name($operator), security_actor_role($operator), $reason, now()]
        );
        audit_log('admin', 'security:mass_reset_request');
    });
}

/**
 * 2 人目が承認して、全員の総入れ替えを実施する: 端末・ログイン・予備のコード・復旧コードを無効にし、次のログインでパスワードの変更と 2 段階認証の設定し直しを求める。
 * 仮のパスワードは配らない（今のパスワードと今の認証アプリで本人を確かめる）。
 */
function security_mass_reset(array $operator, array $request): int
{
    return tx(function () use ($operator, $request): int {
        // 取り消しや二重の承認と重ならないよう、承認待ちのものだけを実施する
        db_exec("UPDATE mass_reset_requests SET status = 'approved', approver_id = ?, decided_at = ? WHERE id = ? AND status = 'pending'", [(int) $operator['id'], now(), (int) $request['id']]);
        if ((int) db_val('SELECT changes()') !== 1) {
            throw new DomainException('mass reset: not pending');
        }
        $actorNameText = $request['requester_name'] . ' さん（' . $request['requester_role'] . '）と ' . security_display_name($operator);
        $userIdList = array_map('intval', array_column(db_all('SELECT id FROM users WHERE is_active = 1'), 'id'));
        foreach ($userIdList as $userId) {
            security_block_current_password($userId);
            db_exec('DELETE FROM user_backup_codes WHERE user_id = ?', [$userId]);
            db_exec('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);
            auth_forget_all_devices($userId);
            // 仮のパスワードの人は、そのまま仮のパスワードの変更を求める（今のパスワードは聞かない）
            db_exec('UPDATE users SET is_credential_reset = CASE WHEN must_change_password = 1 AND is_credential_reset = 0 THEN 0 ELSE 1 END, must_change_password = 1, updated_at = ? WHERE id = ?', [now(), $userId]);
            security_add_notice($userId, 'mass_reset', (int) $operator['id'], $actorNameText, security_actor_role($operator), (string) $request['reason']);
        }
        db_exec('INSERT INTO mass_credential_resets (request_id, user_count, created_at) VALUES (?, ?, ?)', [(int) $request['id'], count($userIdList), now()]);
        audit_log('admin', 'security:mass_reset;count=' . count($userIdList));
        return count($userIdList);
    });
}

// ---- 補佐（開発担当）の保護と、2 人での復旧 ----------------------------
// システム管理者が開発担当のアカウントに入れないようにする。開発担当だった人も守る（印を外してから入る道を塞ぐ）

/** 守るアカウントか（今、または前に補佐（開発担当）） */
function security_is_protected(array $user): bool
{
    return (int) ($user['was_admin_deputy_lead'] ?? 0) === 1 || is_admin_deputy_lead($user);
}

/** 守るアカウントのうち、有効な人 */
function security_protected_users(): array
{
    return security_is_supported() ? db_all('SELECT * FROM users WHERE is_active = 1 AND (was_admin_deputy_lead = 1 OR is_admin_deputy_lead = 1) ORDER BY id') : [];
}

/** 承認を待っている復旧の申請（24 時間。申請した人が今も管理者で、凍結していないもの） */
function security_lead_recovery_request(): ?array
{
    if (!security_is_supported()) {
        return null;
    }
    return db_one(
        "SELECT r.*, t.display_name AS target_display_name, t.login_id AS target_login_id
           FROM lead_recovery_requests r JOIN users t ON t.id = r.user_id AND t.is_active = 1
           JOIN users q ON q.id = r.requester_id AND q.is_admin = 1 AND q.is_active = 1 AND q.frozen_at IS NULL
          WHERE r.status = 'pending' AND r.created_at > ? ORDER BY r.id DESC LIMIT 1",
        [date('Y-m-d H:i:s', time() - MASS_RESET_REQUEST_HOURS * 3600)]
    );
}

/** 承認できない日数（ログインの情報を触られた・新しく付いた補佐。管理者が補佐になりすまして自分の申請を承認する道を塞ぐ） */
const LEAD_RECOVERY_APPROVER_WAIT_DAYS = 7;

/** 直前に、ほかの人がこの補佐のログインの情報を触ったか、補佐にしたか（記録から見る） */
function security_is_recently_touched(int $userId): bool
{
    $targetList = [];
    foreach (['temp_password', 'reset_2fa', 'recovery_issue', 'view_backup_codes', 'freeze_reinit', 'deputy_on', 'create'] as $operationName) {
        $targetList[] = 'user:' . $userId . ':' . $operationName;
    }
    return db_val(
        "SELECT 1 FROM access_logs WHERE action = 'admin' AND created_at > ? AND target IN (" . implode(',', array_fill(0, count($targetList), '?')) . ') LIMIT 1',
        array_merge([date('Y-m-d H:i:s', time() - LEAD_RECOVERY_APPROVER_WAIT_DAYS * 86400)], $targetList)
    ) !== null;
}

/** 復旧を承認できるか: 開発担当でも管理者でもない補佐で、復旧する本人でなく、直前 7 日にログインの情報を触られていない人 */
function security_can_approve_lead_recovery(array $operator, ?array $request): bool
{
    return $request !== null && is_admin_deputy($operator) && !is_admin_deputy_lead($operator) && !is_admin($operator)
        && (int) $operator['id'] !== (int) $request['user_id'] && (int) $operator['id'] !== (int) $request['requester_id']
        && !security_is_recently_touched((int) $operator['id']);
}

/** 管理者が復旧を申請する。仮のパスワードの前半を返す（申請した人にだけ出す） */
function security_request_lead_recovery(array $operator, int $userId, string $reason): string
{
    $firstHalf = make_temp_password();
    tx(function () use ($operator, $userId, $reason, $firstHalf): void {
        db_exec("UPDATE lead_recovery_requests SET status = 'expired', first_half_enc = NULL WHERE status = 'pending'");
        db_exec(
            "INSERT INTO lead_recovery_requests (user_id, requester_id, requester_name, requester_role, reason, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', ?)",
            [$userId, (int) $operator['id'], security_display_name($operator), security_actor_role($operator), $reason, now()]
        );
        $requestId = (int) db()->lastInsertId();
        db_exec('UPDATE lead_recovery_requests SET first_half_enc = ? WHERE id = ?', [enc($firstHalf, 'leadrec:' . $requestId), $requestId]);
        audit_log('admin', 'user:' . $userId . ':lead_recovery_request');
    });
    return $firstHalf;
}

/** 補佐が承認して再初期化する。仮のパスワードの後半を返す（承認した人にだけ出す） */
function security_approve_lead_recovery(array $operator, array $request): string
{
    $secondHalf = make_temp_password();
    tx(function () use ($operator, $request, $secondHalf): void {
        $requestId = (int) $request['id'];
        $userId = (int) $request['user_id'];
        db_exec("UPDATE lead_recovery_requests SET status = 'approved', approver_id = ?, decided_at = ?, first_half_enc = NULL WHERE id = ? AND status = 'pending'", [(int) $operator['id'], now(), $requestId]);
        $firstHalf = dec((string) $request['first_half_enc'], 'leadrec:' . $requestId);
        if ((int) db_val('SELECT changes()') !== 1 || $firstHalf === null || $firstHalf === '') {
            throw new DomainException('lead recovery: not pending');
        }
        security_apply_reinit($userId, $firstHalf . $secondHalf, (int) $request['requester_id']);
        security_add_notice($userId, 'reinit', (int) $request['requester_id'], $request['requester_name'] . ' さん（' . $request['requester_role'] . '）と ' . security_display_name($operator), security_actor_role($operator), (string) $request['reason']);
        audit_log('admin', 'user:' . $userId . ':lead_recovery');
    });
    return $secondHalf;
}

/** 再初期化の中身（tx() の中で呼ぶ） */
function security_apply_reinit(int $userId, string $tempPassword, int $resolvedBy): void
{
    security_clear_second_factor($userId);
    db_exec('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);
    db_exec(
        'UPDATE users SET password_hash = ?, must_change_password = 1, is_credential_reset = 0, failed_count = 0, locked_until = NULL, frozen_at = NULL,
                session_version = session_version + 1, updated_at = ? WHERE id = ?',
        [password_hash($tempPassword, PASSWORD_DEFAULT), now(), $userId]
    );
    db_exec('UPDATE account_freezes SET resolved_at = ?, resolved_by = ? WHERE user_id = ? AND resolved_at IS NULL', [now(), $resolvedBy, $userId]);
}
