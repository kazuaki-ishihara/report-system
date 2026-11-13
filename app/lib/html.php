<?php

declare(strict_types=1);

// ====================================================================
// 画面の出力（エスケープ、共通のヘッダー、テンプレート、メッセージ）
// ====================================================================

function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 共通のヘッダー（詳細設計書 共通編 §4） */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
}

/**
 * 管理画面のテンプレート => その画面が属する管理の区分（メニューのパス）。layout.php が道しるべを出すのに使う（G-21）。
 */
const ADMIN_SECTION_PATH_BY_TEMPLATE = [
    'admin_home'          => '/admin/index.php',
    'admin_fields'        => '/admin/fields.php',
    'admin_field_edit'    => '/admin/fields.php',
    'admin_field_options' => '/admin/fields.php',
    'admin_field_preview' => '/admin/fields.php',
    'comment_templates'   => '/admin/comment_templates.php',
    'admin_messages'      => '/admin/messages.php',
    'admin_projects'      => '/admin/projects.php',
    'admin_org'           => '/admin/org.php',
    'department_manage'   => '/admin/department.php',
    'admin_notices'       => '/admin/notices.php',
    'admin_user_edit'     => '/admin/org.php',
    'admin_logs'          => '/admin/logs.php',
    'admin_trash'         => '/admin/logs.php',
];

/**
 * テンプレートを出力する。$vars はテンプレートの中で変数として使える。
 * テンプレートの中の値は必ず h() を通す。
 */
function render(string $__template, array $__vars = [], bool $__withLayout = true): void
{
    // テンプレートに渡す値の名前（view、content など）とぶつからないよう、ここの変数名には __ を付ける
    send_security_headers();
    header('Content-Type: text/html; charset=UTF-8');
    extract($__vars, EXTR_SKIP);
    ob_start();
    require APP_DIR . '/views/' . $__template . '.php';
    $__content = (string) ob_get_clean();
    if ($__withLayout) {
        $title = $__vars['title'] ?? '月次報告';
        $user = $__vars['user'] ?? null;
        $content = $__content;
        $adminSectionPath = ADMIN_SECTION_PATH_BY_TEMPLATE[$__template] ?? null;   // 管理画面なら道しるべを出す
        $supportRequestScreenCode = SUPPORT_REQUEST_SCREEN_CODE_BY_TEMPLATE[$__template] ?? null;   // 「この画面について要望・依頼」（G-22）
        $screenHelpText = $user !== null ? screen_help_text($__template) : '';   // 画面ごとの使い方（便利機能 I）
        require APP_DIR . '/views/layout.php';
    } else {
        echo $__content;
    }
    exit;
}

/** 画面のテーマ（G-16 §4.5）。キー => 表示名。CSS は public/css/app.css の [data-theme="…"] */
const UI_THEMES = [
    'auto'   => '自動（端末の明るさの設定に合わせる）',
    'light'  => '既定（明るい）',
    'dark'   => 'ダーク',
    'sakura' => 'さくら',
    'wakaba' => '若葉',
    'custom' => '自分の色',
];

/** テーマの色を文字で（色見本の並びと同じ順: 背景・面・強調。G-16 §4.5） */
const UI_THEME_COLOR_NAMES = [
    'auto'   => '既定かダークに切り替わる',
    'light'  => '灰・白・青',
    'dark'   => '黒・濃い灰・青',
    'sakura' => '淡い桜色・白・紅',
    'wakaba' => '淡い緑・白・緑',
    'custom' => '土台・強調の色・背景の色を自分で選ぶ',
];

/**
 * html の data-theme に入れる値。'auto'・未ログイン・知らない値なら空（CSS は端末の設定に合わせる）。
 *
 * @param array|null $user ログインしている利用者（users の行）
 */
function ui_theme_attribute(?array $user): string
{
    $themeName = $user !== null ? (string) ($user['ui_theme'] ?? 'auto') : 'auto';
    if ($themeName === 'custom' && $user !== null) {
        // 自分の色: 土台（明るい／暗い）のテーマに、自分の色の CSS を重ねる（G-16 §4.6）
        return user_theme_colors($user)['base'];
    }
    return ($themeName === 'auto' || !isset(UI_THEMES[$themeName])) ? '' : $themeName;
}

/** 自分の色を使っているときの CSS の URL（使っていなければ空） */
function user_theme_stylesheet_url(?array $user): string
{
    if ($user === null || (string) ($user['ui_theme'] ?? '') !== 'custom') {
        return '';
    }
    return url('/css/user_theme.php?v=' . user_theme_version(user_theme_colors($user)));
}

/** 文言を、改行を <br> にして出す（課長が G-17 で改行を入れた文言のため。K-007） */
/**
 * 画面に出す日時（APP-042・K-045）。DB の「2026-11-13 14:44:05」を「2026/11/13 14:44」にする。
 * 日付だけ（2026-11-13）は「2026/11/13」。形が違えばそのまま。記録の画面・CSV では使わない。
 */
function display_datetime(?string $storedDateTimeText): string
{
    $storedDateTimeText = (string) $storedDateTimeText;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/', $storedDateTimeText, $dateParts) !== 1) {
        return $storedDateTimeText;
    }
    return $dateParts[1] . '/' . $dateParts[2] . '/' . $dateParts[3] . (isset($dateParts[4]) ? ' ' . $dateParts[4] . ':' . $dateParts[5] : '');
}

/** 画面に出す日付（時刻は出さない）。「2026-11-13 14:44:05」も「2026/11/13」にする */
function display_date(?string $storedDateTimeText): string
{
    return display_datetime(substr((string) $storedDateTimeText, 0, 10));
}

function h_lines(string $text): string
{
    return nl2br(h($text), false);
}

/** 共通のエラー画面（詳細設計書 共通編 §11.2） */
function error_page(int $code, ?string $message = null): void
{
    $messages = [
        400 => msg('要求の内容が正しくありません。画面を開き直してください。'),
        403 => msg('この操作を行う権限がありません。'),
        404 => msg('対象のデータが見つかりません。'),
        500 => msg('ただいま利用できません。時間をおいてもう一度お試しください。'),
        503 => msg('ただいま利用できません。時間をおいてもう一度お試しください。'),
    ];
    if (!headers_sent()) {
        http_response_code($code);
    }
    // 処理の途中で止まったときは、送信の番号の控え（処理中）を消す。直して送り直せば処理できるように
    if (!empty($GLOBALS['RK_CURRENT']) && $code !== 409) {
        try {
            db_exec("DELETE FROM request_keys WHERE request_key = ? AND status = 'processing'", [$GLOBALS['RK_CURRENT']]);
        } catch (Throwable $e) {
            // DB に届かないときは何もしない（控えは 1 日で消える）
        }
        $GLOBALS['RK_CURRENT'] = null;
    }
    $msg = $message ?? ($messages[$code] ?? $messages[500]);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    render('error', ['title' => 'エラー', 'message' => $msg, 'code' => $code, 'user' => null], true);
}

/** 次の画面で出すメッセージ */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : [];
}

/** フォームの共通の隠し項目（CSRF と送信の番号） */
function form_hidden(bool $withRequestKey = true): string
{
    $html = '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
    if ($withRequestKey) {
        $html .= '<input type="hidden" name="request_key" value="' . h(random_token(16)) . '">';
    }
    return $html;
}
