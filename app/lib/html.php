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
        require APP_DIR . '/views/layout.php';
    } else {
        echo $__content;
    }
    exit;
}

/** 共通のエラー画面（詳細設計書 共通編 §11.2） */
function error_page(int $code, ?string $message = null): void
{
    $messages = [
        400 => '要求の内容が正しくありません。画面を開き直してください。',
        403 => 'この操作を行う権限がありません。',
        404 => '対象のデータが見つかりません。',
        500 => 'ただいま利用できません。時間をおいてもう一度お試しください。',
        503 => 'ただいま利用できません。時間をおいてもう一度お試しください。',
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
