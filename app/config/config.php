<?php

declare(strict_types=1);

// ====================================================================
// 設定（詳細設計書 共通編 §12）
// 環境ごとの違いは config.local.php（リポジトリに入れない）で上書きする。
// ====================================================================

$appDir = dirname(__DIR__);

$config = [
    // 場所
    'db_path'   => $appDir . '/data/reports.sqlite',
    'key_dir'   => $appDir . '/keys',
    'log_dir'   => $appDir . '/logs',
    // 公開側の URL の先頭（例: /report-system）。ローカル検証環境では ''
    'base_path' => '/report-system',
    // HTTPS で動いているか（Cookie の Secure）。ローカル検証環境では false
    'https'     => true,

    // ログイン
    'session_idle_minutes'  => 30,
    'session_max_hours'     => 12,
    'login_max_failures'    => 10,
    'login_lock_minutes'    => 15,
    'remember_device_days'  => 90,
    'backup_code_count'     => 8,
    'password_min_length'   => 10,
    'pw_stage_minutes'      => 5,

    // 仕組み
    'request_key_hours'     => 24,
    'trash_days'            => 30,
    'free_text_retention_months' => 12,
    'report_retention_years'     => 3,
    'search_decrypt_limit'  => 2000,

    // 報告の対象月の決め方: previous = 締め日まで前月
    'report_period_rule'    => 'previous',
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) {
        $config = array_merge($config, $override);
    }
}

return $config;
