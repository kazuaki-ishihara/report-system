<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/report_fields.php';

// ==================================================
// 項目管理：項目の並び順を 1 つ上・下へ移動（管理者のみ）
// ==================================================

$loginUserId = requireReportFieldAdmin($pdo);
requireReportFieldTables($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

verifyCsrfToken();

$id = postId('id');
$direction = postString('direction');

if (!in_array($direction, ['up', 'down'], true)) {
    http_response_code(400);
    exit('移動の向きが正しくありません。');
}

if (findReportField($pdo, $id) === null) {
    http_response_code(404);
    exit('指定された項目が見つかりません。');
}

try {
    $pdo->beginTransaction();

    moveReportFieldSortOrder(
        $pdo,
        'report_fields',
        $id,
        $direction,
        null,
        $loginUserId,
        date('Y-m-d H:i:s')
    );

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('項目の並び替えエラー: ' . $e->getMessage());

    redirectWithMessage('index.php', 'error', '並び順を変更できませんでした。');
}

header('Location: index.php');
exit;
