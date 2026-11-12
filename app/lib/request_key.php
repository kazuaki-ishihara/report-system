<?php

declare(strict_types=1);

// ====================================================================
// 送信の番号（二重登録の防止。詳細設計書 共通編 §7）
//
// 使い方:
//   rk_begin('report.save', $uid);      // 2 回目なら 1 回目の結果へ転送して終わる
//   ... 処理 ...
//   rk_done('/reports/view.php?id=1');  // 結果を控えて転送する
//   入力エラーで同じ画面に戻すときは rk_cancel()
// ====================================================================

function rk_begin(string $action, int $userId): void
{
    $key = $_POST['request_key'] ?? '';
    if (!is_string($key) || preg_match('/^[0-9a-f]{32}$/', $key) !== 1) {
        error_page(400);
    }
    $GLOBALS['RK_CURRENT'] = $key;

    $row = db_one('SELECT * FROM request_keys WHERE request_key = ?', [$key]);
    if ($row !== null) {
        if ((int) $row['user_id'] !== $userId || $row['action'] !== $action) {
            error_page(400);
        }
        if ($row['status'] === 'done' && $row['result_url'] !== null) {
            flash('info', '前の送信で処理済みです。');
            redirect($row['result_url']);
        }
        error_page(409, '前の送信をまだ処理しています。しばらく待ってから画面を読み直してください。');
    }
    try {
        db_exec(
            "INSERT INTO request_keys (request_key, user_id, action, status, created_at) VALUES (?, ?, ?, 'processing', ?)",
            [$key, $userId, $action, now()]
        );
    } catch (PDOException $e) {
        // 同時に同じ番号が来た
        error_page(409, '前の送信をまだ処理しています。しばらく待ってから画面を読み直してください。');
    }
}

function rk_done(string $resultUrl): void
{
    $key = $GLOBALS['RK_CURRENT'] ?? null;
    if ($key !== null) {
        db_exec("UPDATE request_keys SET status = 'done', result_url = ? WHERE request_key = ?", [$resultUrl, $key]);
    }
    redirect($resultUrl);
}

/** JSON で返す処理（自動保存など）の完了 */
function rk_done_json(array $data): void
{
    $key = $GLOBALS['RK_CURRENT'] ?? null;
    if ($key !== null) {
        db_exec("UPDATE request_keys SET status = 'done', result_url = '' WHERE request_key = ?", [$key]);
    }
    json_response($data);
}

/** 入力エラーなどで処理しなかった。直して送り直せば処理する */
function rk_cancel(): void
{
    $key = $GLOBALS['RK_CURRENT'] ?? null;
    if ($key !== null) {
        db_exec('DELETE FROM request_keys WHERE request_key = ?', [$key]);
        $GLOBALS['RK_CURRENT'] = null;
    }
}
