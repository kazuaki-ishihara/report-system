<?php

declare(strict_types=1);

// 公開側の入り口から app/ を探して読み込む。
//   本番: ~/www/report-system/ の中に app/ を置く（さくらは www の外に置けないため）。
//         app/ は app/.htaccess と、この階層の .htaccess の二重で Web から拒否する。
//         反映用の一式は tools/deploy/build_package.py で作る。
//   開発: リポジトリの public/ と app/ が並ぶ。
(function (): void {
    $candidates = [
        __DIR__ . '/app/bootstrap.php',
        __DIR__ . '/../app/bootstrap.php',
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
