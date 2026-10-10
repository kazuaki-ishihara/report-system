<?php

$dbFile = __DIR__ . '/../data/reports.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbFile);

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    $pdo->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );

} catch (PDOException $e) {

    http_response_code(500);

    exit(
        'データベース接続エラー: ' .
        htmlspecialchars(
            $e->getMessage(),
            ENT_QUOTES,
            'UTF-8'
        )
    );
}

