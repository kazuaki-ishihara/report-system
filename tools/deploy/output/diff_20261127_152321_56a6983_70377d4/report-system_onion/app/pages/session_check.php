<?php

declare(strict_types=1);

// ====================================================================
// ログインの状態の確認（詳細設計書 共通編 §11.3.1）
// 画面の JavaScript が、フォームを送る直前と、自動保存がログイン切れになったあとに呼ぶ。
// 切れていれば page_start() が 401 の JSON（reason: login）を返す（Accept: application/json で呼ぶ）。
// 別のタブでログインし直したときに、開いたままの画面の CSRF トークンを新しくするため、今のトークンを返す。
// ====================================================================

$user = page_start(['allow' => ['setup', 'must_change']]);
if (is_post()) {
    error_page(400);
}
json_response(['ok' => true, 'user_id' => (int) $user['id'], 'csrf_token' => csrf_token()]);
