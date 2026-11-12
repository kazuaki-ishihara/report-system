<?php

declare(strict_types=1);

// 稼働の確認（詳細設計書 共通編 §14）。ok / ng だけを返す。
require __DIR__ . '/_boot.php';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
try {
    $pdo = new PDO('sqlite:' . cfg('db_path'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->query('SELECT 1')->fetchColumn();
    echo 'ok';
} catch (Throwable $e) {
    http_response_code(503);
    echo 'ng';
}
