<?php

declare(strict_types=1);

// 公開側の入り口から app/ を探して読み込む。
// 開発: リポジトリの public/ と app/ が並ぶ。本番: ~/www/report-system/ と ~/report-system-app/app/
(function (): void {
    $candidates = [
        __DIR__ . '/../app/bootstrap.php',
        __DIR__ . '/../../report-system-app/app/bootstrap.php',
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
    http_response_code(500);
    exit('ただいま利用できません。');
})();
