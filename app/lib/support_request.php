<?php

declare(strict_types=1);

// ====================================================================
// G-22 要望・依頼（課長・確認者 → システム管理者・補佐）（詳細設計書 G22_support_requests.md）
// 改善要望・対応依頼・不具合・質問を出し、管理者側が状態を変えて返信する。件名・本文は暗号化して保存する。
// ====================================================================

/** 種類（コード => 表示名） */
const SUPPORT_REQUEST_KIND_LABELS = [
    'improvement' => '改善要望',
    'task'        => '対応依頼',
    'bug'         => '不具合',
    'question'    => '質問',
];

/**
 * 状態（コード => 表示名）。並びは進む順。
 * 続いている: 未受付・対応検討中・対応中・検証中・リリース待ち。閉じた: リリース済み・完了・見送り・取り下げ
 */
const SUPPORT_REQUEST_STATUS_LABELS = [
    'open'            => '未受付',
    'reviewing'       => '対応検討中',
    'in_progress'     => '対応中',
    'testing'         => '検証中',
    'release_waiting' => 'リリース待ち',
    'released'        => 'リリース済み',
    'done'            => '完了',
    'declined'        => '見送り',
    'withdrawn'       => '取り下げ',
];
const SUPPORT_REQUEST_ACTIVE_STATUSES = ['open', 'reviewing', 'in_progress', 'testing', 'release_waiting'];
/** 管理者側がボタンで変えられる状態（取り下げは出した人だけ） */
const SUPPORT_REQUEST_STAFF_STATUSES = ['open', 'reviewing', 'in_progress', 'testing', 'release_waiting', 'released', 'done', 'declined'];
/** 返信を書かずに変えられない状態（見送りは、検討の結果を必ず書く） */
const SUPPORT_REQUEST_REPLY_REQUIRED_STATUSES = ['declined'];

/** SQL の IN に入れる、続いている状態の並び */
function support_request_active_status_sql(): string
{
    return "'" . implode("', '", SUPPORT_REQUEST_ACTIVE_STATUSES) . "'";
}

/**
 * 状態をボタンで変えたときに、返信が空なら送る定型の返信（G-17 で文言を変えられる）。
 */
function support_request_default_reply_text(string $statusCode): string
{
    switch ($statusCode) {
        case 'open':
            return msg('受付前の状態に戻しました。');
        case 'reviewing':
            return msg('ご要望を受け付けました。対応するかどうかを検討しています。');
        case 'in_progress':
            return msg('対応を始めました。');
        case 'testing':
            return msg('対応が終わり、検証しています。');
        case 'release_waiting':
            return msg('検証が終わりました。リリースを待っています。');
        case 'released':
            return msg('リリースしました。画面で確かめてください。');
        case 'done':
            return msg('対応が終わりました。');
        default:
            return '';
    }
}

/** 急ぎ（コード => 表示名） */
const SUPPORT_REQUEST_URGENCY_LABELS = [
    'normal' => '通常',
    'urgent' => '急ぎ',
];

/** 関係する画面の選択肢（画面の番号 => 表示名）。ボタンで選ぶ。空は「指定しない」 */
const SUPPORT_REQUEST_SCREEN_LABELS = [
    ''       => '（指定しない）',
    'system' => '全体的な要望（システム全体）',
    'G-02' => 'G-02 ログイン・パスワード',
    'G-03' => 'G-03 ホーム',
    'G-04' => 'G-04 報告の作成',
    'G-05' => 'G-05 報告の詳細・コメント',
    'G-06' => 'G-06 自分の報告',
    'G-08' => 'G-08 提出状況',
    'G-09' => 'G-09 横並び',
    'G-10' => 'G-10 出力',
    'G-11' => 'G-11 臨時の調査',
    'G-12' => 'G-12 項目マスタ',
    'G-13' => 'G-13 案件の管理',
    'G-14' => 'G-14 組織・ユーザー',
    'G-15' => 'G-15 記録・削除データ',
    'G-16' => 'G-16 アカウント設定',
    'G-17' => 'G-17 メッセージ',
    'G-18' => 'G-18 コメントの一覧',
    'G-19' => 'G-19 コメントの定型文',
    'G-20' => 'G-20 検索',
    'G-21' => 'G-21 管理のトップ',
    'G-22' => 'G-22 要望・依頼',
    'G-23' => 'G-23 課の管理',
    'G-24' => 'G-24 更新のお知らせ',
    'G-25' => 'G-25 よくある質問',
    'other' => 'そのほか',
];

/**
 * 画面のテンプレート => 画面の番号（各画面の「この画面について要望・依頼」で、画面を自動で選ぶ）。
 * 載っていない画面（ログイン・エラーなど）にはリンクを出さない。
 */
const SUPPORT_REQUEST_SCREEN_CODE_BY_TEMPLATE = [
    'home' => 'G-03', 'report_edit' => 'G-04', 'report_view' => 'G-05', 'report_mine' => 'G-06',
    'survey_answer' => 'G-11', 'survey_edit' => 'G-11', 'survey_manage' => 'G-11', 'survey_result' => 'G-11',
    'status_index' => 'G-08', 'status_compare' => 'G-09', 'export_index' => 'G-10',
    'admin_fields' => 'G-12', 'admin_field_edit' => 'G-12', 'admin_field_options' => 'G-12', 'admin_field_preview' => 'G-12',
    'admin_projects' => 'G-13', 'admin_org' => 'G-14', 'admin_user_edit' => 'G-14', 'admin_logs' => 'G-15', 'admin_trash' => 'G-15',
    'account' => 'G-16', 'admin_messages' => 'G-17', 'comment_list' => 'G-18', 'comment_templates' => 'G-19', 'search' => 'G-20',
    'admin_home' => 'G-21', 'department_manage' => 'G-23', 'admin_notices' => 'G-24', 'faq' => 'G-25',
];

/** 開いていた画面の URL に残す問い合わせの項目（番号・月・課など。検索のキーワードなど自由な文字は残さない） */
const SUPPORT_REQUEST_SOURCE_QUERY_KEYS = ['id', 'period', 'dept', 'tab', 'scope', 'edit', 'field', 'status', 'filter', 'preview', 'seq'];

/**
 * 開いていた画面の URL を、記録してよい形にする（同じサイトのパスと、決まった問い合わせの項目だけ。200 文字まで）。
 * 形が違えば空。
 */
function support_request_clean_source_path(string $sourcePathText): string
{
    if ($sourcePathText === '' || $sourcePathText[0] !== '/' || strpos($sourcePathText, '//') === 0 || strlen($sourcePathText) > 1000
        || preg_match('/[\x00-\x1F\x7F\\\\]/', $sourcePathText) === 1) {
        return '';
    }
    $pathPart = (string) parse_url($sourcePathText, PHP_URL_PATH);
    if ($pathPart === '' || preg_match('#^/[A-Za-z0-9_./-]*$#', $pathPart) !== 1 || strpos($pathPart, '..') !== false) {
        return '';
    }
    parse_str((string) parse_url($sourcePathText, PHP_URL_QUERY), $queryValueByKey);
    $keptQueryValueByKey = [];
    foreach (SUPPORT_REQUEST_SOURCE_QUERY_KEYS as $queryKey) {
        if (isset($queryValueByKey[$queryKey]) && is_string($queryValueByKey[$queryKey]) && preg_match('/^[A-Za-z0-9_-]{1,20}$/', $queryValueByKey[$queryKey]) === 1) {
            $keptQueryValueByKey[$queryKey] = $queryValueByKey[$queryKey];
        }
    }
    $cleanedPathText = $pathPart . ($keptQueryValueByKey ? '?' . http_build_query($keptQueryValueByKey) : '');
    return strlen($cleanedPathText) <= 200 ? $cleanedPathText : $pathPart;
}

/** 出したときの端末（PC／スマートフォンと、ブラウザー・OS の名前だけ。版などは残さない） */
function support_request_client_text(): string
{
    $userAgentText = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $deviceText = is_mobile_request() ? 'スマートフォン' : 'PC';
    $browserText = 'そのほかのブラウザー';
    foreach (['Edg/' => 'Edge', 'Chrome/' => 'Chrome', 'Firefox/' => 'Firefox', 'Safari/' => 'Safari'] as $userAgentMarker => $browserName) {
        if (strpos($userAgentText, $userAgentMarker) !== false) {
            $browserText = $browserName;
            break;
        }
    }
    $osText = '';
    foreach (['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Mac OS' => 'Mac', 'Linux' => 'Linux'] as $userAgentMarker => $osName) {
        if (strpos($userAgentText, $userAgentMarker) !== false) {
            $osText = $osName;
            break;
        }
    }
    return $deviceText . '（' . $browserText . ($osText !== '' ? '・' . $osText : '') . '）';
}

const SUPPORT_REQUEST_TITLE_MAX_LENGTH = 60;
const SUPPORT_REQUEST_BODY_MAX_LENGTH = 2000;
/** 1 人が続いている要望・依頼を持てる数（出しすぎを防ぐ） */
const SUPPORT_REQUEST_ACTIVE_MAX_COUNT = 30;

/** 要望・依頼を出せるか: 課長と確認者（課代・リーダー）。部下は課長を通す（K-044） */
function support_request_can_submit(array $user): bool
{
    return (bool) reviewer_departments((int) $user['id']);
}

/** 要望・依頼を受けて対応できるか: システム管理者と補佐 */
function support_request_is_staff(array $user): bool
{
    return can_manage_system($user);
}

/** その要望・依頼を見られるか: 出した本人と、管理者・補佐 */
function support_request_can_view(array $user, array $requestRow): bool
{
    return support_request_is_staff($user) || (int) $requestRow['user_id'] === (int) $user['id'];
}

/** 件名・本文の暗号化の鍵の文脈（行ごとに変える） */
function support_request_title_aad(int $requestId): string
{
    return 'sreq_title:' . $requestId;
}

function support_request_body_aad(int $requestId): string
{
    return 'sreq_body:' . $requestId;
}

function support_request_message_aad(int $requestId, int $messageId): string
{
    return 'sreq_msg:' . $requestId . ':' . $messageId;
}

/** 件名を復号する（読めないときは伏せ字） */
function support_request_title_text(array $requestRow): string
{
    $titleText = dec((string) $requestRow['title_enc'], support_request_title_aad((int) $requestRow['id']));
    return $titleText ?? msg('（読み出せません）');
}

/**
 * 出した人に知らせる件数: 管理者側の返信や状態の変更が、本人が最後に開いたあとにある要望・依頼の数。
 */
function support_request_answered_count(int $userId): int
{
    return (int) db_val(
        'SELECT COUNT(*) FROM support_requests q
          WHERE q.user_id = ? AND EXISTS (
            SELECT 1 FROM support_request_messages m
             WHERE m.request_id = q.id AND m.is_staff = 1 AND m.id > q.requester_seen_message_id)',
        [$userId]
    );
}

/**
 * 管理者側に知らせる件数。
 *
 * @return array{open: int, waiting_reply: int} 未受付の数と、出した人の返信が最後になっている（受付後の）続いているものの数
 */
function support_request_staff_counts(): array
{
    $openCount = (int) db_val("SELECT COUNT(*) FROM support_requests WHERE status = 'open'");
    $waitingReplyCount = (int) db_val(
        "SELECT COUNT(*) FROM support_requests q
          WHERE q.status IN (" . support_request_active_status_sql() . ") AND q.status <> 'open' AND (
            SELECT m.is_staff FROM support_request_messages m WHERE m.request_id = q.id ORDER BY m.id DESC LIMIT 1) = 0"
    );
    return ['open' => $openCount, 'waiting_reply' => $waitingReplyCount];
}

/**
 * やり取りを 1 件足す（本文を暗号化する）。状態を変えたときは statusChange に変えた先の状態を入れる。
 */
function support_request_add_message(int $requestId, int $userId, bool $isStaff, string $bodyText, ?string $statusChange): int
{
    db_exec(
        "INSERT INTO support_request_messages (request_id, user_id, is_staff, body_enc, status_change, created_at) VALUES (?, ?, ?, '', ?, ?)",
        [$requestId, $userId, $isStaff ? 1 : 0, $statusChange, now()]
    );
    $messageId = (int) db()->lastInsertId();
    if ($bodyText !== '') {
        db_exec('UPDATE support_request_messages SET body_enc = ? WHERE id = ?', [enc($bodyText, support_request_message_aad($requestId, $messageId)), $messageId]);
    }
    db_exec('UPDATE support_requests SET last_activity_at = ?, updated_at = ? WHERE id = ?', [now(), now(), $requestId]);
    return $messageId;
}

/** 出した人が、今あるやり取りをすべて見たことにする（ホームの「回答あり」を消す） */
function support_request_mark_seen_by_requester(int $requestId): void
{
    db_exec(
        'UPDATE support_requests SET requester_seen_message_id = COALESCE((SELECT MAX(id) FROM support_request_messages WHERE request_id = ?), 0) WHERE id = ?',
        [$requestId, $requestId]
    );
}

// ---- 便利機能 B: 返信の定型文 ----
const SUPPORT_REPLY_TEMPLATE_MAX_COUNT = 30;
const SUPPORT_REPLY_TEMPLATE_MAX_LENGTH = 500;

/**
 * 返信の定型文（K-047 決定: 要望の種類ごと）。$kind を渡すと、その種類とすべての種類の定型文だけ。
 *
 * @return array<int, array{id: int|string, kind: string, body: string}> 種類（すべて → 種類の順）・並び順
 */
function support_reply_templates(?string $kind = null): array
{
    if ($kind === null) {
        return db_all("SELECT id, kind, body FROM support_reply_templates ORDER BY kind <> '', kind, sort_order, id");
    }
    return db_all("SELECT id, kind, body FROM support_reply_templates WHERE kind = '' OR kind = ? ORDER BY kind <> '', sort_order, id", [$kind]);
}

// ---- 便利機能 D: 対応の予定 ----
const SUPPORT_REQUEST_PLAN_MAX_LENGTH = 40;

// ---- 便利機能 C: 重複をまとめる ----
/**
 * まとめた先に管理者側が返信したとき、まとめた要望（出した人どうしはお互いの要望を見られない）にも同じ返信を届ける。
 */
function support_request_copy_reply_to_merged(int $targetRequestId, int $userId, string $bodyText, ?string $statusChange): void
{
    foreach (db_all('SELECT id FROM support_requests WHERE merged_into_id = ?', [$targetRequestId]) as $mergedRow) {
        $copiedText = msg('（同じ内容の要望 #{target} での返信）', ['target' => $targetRequestId])
            . ($statusChange !== null ? msg('状態: {status}', ['status' => SUPPORT_REQUEST_STATUS_LABELS[$statusChange] ?? $statusChange]) : '')
            . ($bodyText !== '' ? "\n" . $bodyText : '');
        support_request_add_message((int) $mergedRow['id'], $userId, true, $copiedText, null);
    }
}
