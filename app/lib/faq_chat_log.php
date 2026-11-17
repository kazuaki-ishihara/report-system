<?php

declare(strict_types=1);

// ====================================================================
// よくある質問のチャットの記録（G-25 §2.7。操作の記録 access_logs とは別に持つ）。
// 聞かれた質問と、返した答え（結果・出した質問・反応）を残し、よくある質問の改善に使う。
// 聞いた人も残す（不適切な表現への対応のため。K-051）。記録は管理者・補佐とも、聞いた人を含めて見られる（K-043 の例外）。
// 質問の文は暗号化する。1 年を過ぎた記録は消す。
// ====================================================================

/** 記録を残す日数（これより古い記録は、新しく記録するときに消す） */
const FAQ_CHAT_LOG_KEEP_DAYS = 365;
/** 記録の画面で読む件数の上限（新しい順） */
const FAQ_CHAT_LOG_READ_MAX_COUNT = 2000;
/** 反応を送れる記録として、セッションに覚えておく数 */
const FAQ_CHAT_LOG_SESSION_MAX_COUNT = 100;

/** 結果（コード => 表示名） */
const FAQ_CHAT_LOG_RESULT_LABELS = [
    'matched'   => '答えた',
    'no_match'  => '見つからない',
    'vague'     => '曖昧（分野を聞き返した）',
    'unrelated' => '関係のない質問',
    'abusive'   => '不適切な表現',
    'distress'  => '心の不調らしい文',
    'sensitive' => '要配慮の内容（文は記録しない）',
    'emergency' => '命・体の緊急の状態（119 番を案内）',
    'retirement' => '自分の退職（相談を促す）',
    'category'  => '分野を選んだ',
];

/** 反応（コード => 表示名） */
const FAQ_CHAT_LOG_FEEDBACK_LABELS = [
    ''         => '（なし）',
    'solved'   => '解決した',
    'unsolved' => '解決しない',
    'asked'    => '質問として送った',
];

/** 知らせる理由（コード => 表示名）。列の名前は distress_level（心の不調の段階として始めたため） */
const FAQ_CHAT_LOG_DISTRESS_LABELS = [
    'urgent'     => '命に関わる言葉',
    'concern'    => '心配な言葉',
    'retirement' => '退職に関わる質問',
    'credential' => 'ID・パスワードを求める質問',
    'violence'   => '人への暴力を書いた文',
];

/** 役割（コード => 表示名） */
const FAQ_CHAT_LOG_ROLE_LABELS = [
    'member'   => '部下',
    'reviewer' => '確認者',
    'final'    => '課長',
    'staff'    => '管理者・補佐',
];

/**
 * 記録の表が無ければ作る（本番で migrate.php を流す前でも動くように。schema.php にも同じものを載せる）。
 */
function faq_chat_log_ensure_table(): void
{
    static $isChecked = false;
    if ($isChecked) {
        return;
    }
    $isChecked = true;
    $existingTableSql = (string) db_val("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'faq_chat_logs'");
    if ($existingTableSql !== '' && strpos($existingTableSql, FAQ_CHAT_LOG_SCHEMA_MARK) === false) {
        // 結果の種類を足す前の表: 結果の決まり（CHECK）を変えられないので、作り直して、共通の列の記録を写す（番号は変えない。暗号の文脈に使うため）
        tx(function (): void {
            $oldColumnNameList = array_column(db()->query('PRAGMA table_info(faq_chat_logs)')->fetchAll(), 'name');
            db()->exec('ALTER TABLE faq_chat_logs RENAME TO faq_chat_logs_before_update');
            db()->exec(FAQ_CHAT_LOG_CREATE_SQL);
            $newColumnNameList = array_column(db()->query('PRAGMA table_info(faq_chat_logs)')->fetchAll(), 'name');
            $copiedColumnList = implode(', ', array_intersect($newColumnNameList, $oldColumnNameList));
            db()->exec("INSERT INTO faq_chat_logs ({$copiedColumnList}) SELECT {$copiedColumnList} FROM faq_chat_logs_before_update");
            db()->exec('DROP TABLE faq_chat_logs_before_update');
        });
    }
    db()->exec(FAQ_CHAT_LOG_CREATE_SQL);
    db()->exec(FAQ_CHAT_LOG_INDEX_SQL);
}

const FAQ_CHAT_LOG_CREATE_SQL = "CREATE TABLE IF NOT EXISTS faq_chat_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    asked_at TEXT NOT NULL,
    user_id INTEGER REFERENCES users(id),
    role TEXT NOT NULL CHECK (role IN ('member', 'reviewer', 'final', 'staff')),
    screen_code TEXT NOT NULL DEFAULT '',
    question_enc TEXT NOT NULL DEFAULT '',
    category TEXT,
    result TEXT NOT NULL CHECK (result IN ('matched', 'no_match', 'vague', 'unrelated', 'abusive', 'distress', 'sensitive', 'emergency', 'retirement', 'category')),
    is_ignored INTEGER NOT NULL DEFAULT 0 CHECK (is_ignored IN (0, 1)),
    distress_level TEXT CHECK (distress_level IN ('urgent', 'concern', 'retirement', 'credential', 'violence')),
    handled_at TEXT,
    handled_by INTEGER REFERENCES users(id),
    shown_faq_ids TEXT NOT NULL DEFAULT '',
    feedback TEXT NOT NULL DEFAULT '' CHECK (feedback IN ('', 'solved', 'unsolved', 'asked')),
    feedback_faq_id INTEGER,
    request_id INTEGER REFERENCES support_requests(id),
    feedback_at TEXT
)";
/** 今の表の形の印（結果の決まりに、いちばん新しく足した種類）。無い表は作り直す */
const FAQ_CHAT_LOG_SCHEMA_MARK = "'credential', 'violence'";
const FAQ_CHAT_LOG_INDEX_SQL = 'CREATE INDEX IF NOT EXISTS ix_faq_chat_logs_asked_at ON faq_chat_logs (asked_at)';

/** 心の不調・退職に関わる質問の知らせを受け、見られるか: システム管理者だけ（補佐は対象外。K-054） */
function faq_chat_log_can_receive_notice(array $user): bool
{
    return is_admin($user);
}

/**
 * その人が知らせを受け、見られる理由（distress_level）の並び。
 * システム管理者: すべて。補佐: ID・パスワードを求める質問と人への暴力を書いた文（心の不調・退職は対象外。K-054・K-057）。ほか: なし。
 *
 * @return string[]
 */
function faq_chat_log_notice_levels(array $user): array
{
    if (is_admin($user)) {
        return array_keys(FAQ_CHAT_LOG_DISTRESS_LABELS);
    }
    return is_admin_deputy($user) ? ['credential', 'violence'] : [];
}

/** 記録を見られるか: システム管理者と補佐（どちらも聞いた人を見られる。K-051） */
function faq_chat_log_can_view(array $user): bool
{
    return can_manage_system($user);
}

/** 記録に残す役割（課長 → 確認者 → 管理者・補佐 → 部下の順に当てる） */
function faq_chat_log_role(array $user): string
{
    $isFinalByReviewerDepartmentId = reviewer_departments((int) $user['id']);
    if (in_array(true, $isFinalByReviewerDepartmentId, true)) {
        return 'final';
    }
    if ($isFinalByReviewerDepartmentId) {
        return 'reviewer';
    }
    return can_manage_system($user) ? 'staff' : 'member';
}

/** 質問の文の暗号化の鍵の文脈（行ごとに変える） */
function faq_chat_log_question_aad(int $logId): string
{
    return 'faq_chat:' . $logId;
}

/**
 * 1 件記録する。反応を送れるよう、記録の番号をセッションに覚える。
 *
 * @param int[] $shownFaqIdList 出した質問の番号（出した順）
 * @param bool $isIgnored 返事をしなかったか（関係のない質問・不適切な表現が続いた）
 * @return int 記録の番号
 */
function faq_chat_log_add(array $user, string $screenCode, string $questionText, ?string $categoryCode, string $resultCode, array $shownFaqIdList, bool $isIgnored = false, string $distressLevel = ''): int
{
    faq_chat_log_ensure_table();
    $logId = tx(function () use ($user, $screenCode, $questionText, $categoryCode, $resultCode, $shownFaqIdList, $isIgnored, $distressLevel): int {
        db_exec('DELETE FROM faq_chat_logs WHERE asked_at < ?', [date('Y-m-d H:i:s', time() - FAQ_CHAT_LOG_KEEP_DAYS * 86400)]);
        db_exec(
            'INSERT INTO faq_chat_logs (asked_at, user_id, role, screen_code, category, result, is_ignored, distress_level, shown_faq_ids) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [now(), (int) $user['id'], faq_chat_log_role($user), $screenCode, $categoryCode, $resultCode, $isIgnored ? 1 : 0,
             $distressLevel === '' ? null : $distressLevel, implode(',', array_map('intval', $shownFaqIdList))]
        );
        $newLogId = (int) db()->lastInsertId();
        if ($questionText !== '') {
            db_exec('UPDATE faq_chat_logs SET question_enc = ? WHERE id = ?', [enc($questionText, faq_chat_log_question_aad($newLogId)), $newLogId]);
        }
        return $newLogId;
    });
    $ownLogIdList = array_slice(array_merge((array) ($_SESSION['faq_chat_log_ids'] ?? []), [$logId]), -FAQ_CHAT_LOG_SESSION_MAX_COUNT);
    $_SESSION['faq_chat_log_ids'] = $ownLogIdList;
    return $logId;
}

/** この人（セッション）が残した記録か（ほかの人の記録に反応を付けさせない） */
function faq_chat_log_is_own(int $logId): bool
{
    return in_array($logId, array_map('intval', (array) ($_SESSION['faq_chat_log_ids'] ?? [])), true);
}

/**
 * 反応を残す（解決した・解決しない・質問として送った）。あとの反応で上書きする。
 */
function faq_chat_log_set_feedback(int $logId, string $feedbackCode, ?int $feedbackFaqId, ?int $requestId = null): void
{
    faq_chat_log_ensure_table();
    db_exec('UPDATE faq_chat_logs SET feedback = ?, feedback_faq_id = ?, request_id = COALESCE(?, request_id), feedback_at = ? WHERE id = ?',
        [$feedbackCode, $feedbackFaqId, $requestId, now(), $logId]);
}

/**
 * まだ対応していない、知らせる質問の数（ホーム・管理のトップの知らせ。§2.8・§2.10・§2.13）。$levels に入っていない理由は 0
 *
 * @param string[] $levels 数える理由（faq_chat_log_notice_levels()）
 * @return array{urgent: int, concern: int, retirement: int, credential: int}
 */
function faq_chat_log_unhandled_distress_counts(array $levels): array
{
    faq_chat_log_ensure_table();
    $countByLevel = ['urgent' => 0, 'concern' => 0, 'retirement' => 0, 'credential' => 0, 'violence' => 0];
    foreach (db_all('SELECT distress_level, COUNT(*) AS level_count FROM faq_chat_logs WHERE distress_level IS NOT NULL AND handled_at IS NULL GROUP BY distress_level') as $countRow) {
        if (isset($countByLevel[$countRow['distress_level']]) && in_array($countRow['distress_level'], $levels, true)) {
            $countByLevel[$countRow['distress_level']] = (int) $countRow['level_count'];
        }
    }
    return $countByLevel;
}

/** 質問の文を復号する（分野を選んだ記録は空。読めないときは伏せ字）。画面には faq_chat_log_display_text() を使う */
function faq_chat_log_question_text(array $logRow): string
{
    if ((string) $logRow['question_enc'] === '') {
        return in_array($logRow['result'], ['sensitive', 'emergency'], true) ? msg('（要配慮の内容のため、文は記録していません）') : '';
    }
    return dec((string) $logRow['question_enc'], faq_chat_log_question_aad((int) $logRow['id'])) ?? msg('（読み出せません）');
}

/**
 * 画面に出してよくない記録か: 要配慮個人情報に触れる文・命や体の緊急の状態の文（K-053・K-055）。
 * 文は記録するが、システム管理者・補佐の画面にも出さない。心の不調・退職として知らせるものは、対応のため出す。
 */
function faq_chat_log_is_hidden(array $logRow): bool
{
    return in_array($logRow['result'], ['sensitive', 'emergency'], true) && ($logRow['distress_level'] ?? null) === null;
}

/** 画面に出す質問の文（要配慮の内容は伏せる） */
function faq_chat_log_display_text(array $logRow): string
{
    if (faq_chat_log_is_hidden($logRow)) {
        return msg('（要配慮の内容のため表示しません）');
    }
    return faq_chat_log_question_text($logRow);
}
