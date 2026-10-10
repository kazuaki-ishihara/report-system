<?php

require_once '../config/database.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        report_date TEXT NOT NULL,
        name TEXT NOT NULL,
        subject TEXT NOT NULL,
        content TEXT,
        issues TEXT,
        remarks TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )
");

echo '<h1>データベース初期化完了</h1>';
echo '<p>reportsテーブルを作成しました。</p>';
echo '<p><a href="index.php">レポート一覧へ</a></p>';

