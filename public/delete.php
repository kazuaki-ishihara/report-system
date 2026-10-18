<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

// POST以外のアクセスは一覧画面へ戻す
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// ログイン確認
requireLogin();

// ログインユーザーを取得
$loginUser = getLoginUser($pdo);

if ($loginUser === null) {
    header('Location: login.php');
    exit;
}

$loginUserId = (int) $loginUser['id'];
$isAdmin = (int) $loginUser['is_admin'] === 1;

// レポートIDを取得・検証
$id = filter_input(
    INPUT_POST,
    'id',
    FILTER_VALIDATE_INT
);

if ($id === false || $id === null || $id <= 0) {
    http_response_code(400);
    exit('レポートIDが正しくありません。');
}

$id = (int) $id;

// 削除対象のレポートを取得
$stmt = $pdo->prepare(
    'SELECT id, user_id
     FROM reports
     WHERE id = :id'
);

$stmt->execute([
    ':id' => $id,
]);

$report = $stmt->fetch(PDO::FETCH_ASSOC);

// レポートが存在しない場合
if ($report === false) {
    http_response_code(404);
    exit('指定されたレポートが見つかりません。');
}

// 削除権限を確認
$reportUserId = (int) $report['user_id'];

// 管理者以外は、自分のレポートだけ削除可能
if (!$isAdmin && $reportUserId !== $loginUserId) {
    http_response_code(403);
    exit('このレポートを削除する権限がありません。');
}

// レポートを削除
$stmt = $pdo->prepare(
    'DELETE FROM reports
     WHERE id = :id'
);

$stmt->execute([
    ':id' => $id,
]);

// 一覧画面へ戻る
header('Location: index.php');
exit;
