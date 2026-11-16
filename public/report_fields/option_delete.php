<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/report_fields.php';
require_once __DIR__ . '/../../lib/report_values.php';

// ==================================================
// 項目管理：未使用の選択肢の削除（管理者のみ。U26・D33）
//
// その選択肢を選んだレポートがない場合だけ削除できる。使用中の選択肢は非表示にする。
// ==================================================

requireReportFieldAdmin($pdo);
requireReportFieldTables($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

verifyCsrfToken();

$id = postId('id');

try {
    // 確認から削除までの間に値が登録されないよう、書き込みを止める
    $pdo->exec('BEGIN IMMEDIATE');

    $option = findReportFieldOption($pdo, $id);

    if ($option === null) {
        $pdo->exec('ROLLBACK');
        http_response_code(404);
        exit('指定された選択肢が見つかりません。');
    }

    $backTo = 'options.php?field_id=' . (int) $option['field_id'];

    if (isReportFieldOptionUsed($pdo, $id)) {
        $pdo->exec('ROLLBACK');
        redirectWithMessage(
            $backTo,
            'error',
            '選択肢「' . $option['label'] . '」は選択済みのレポートがあるため削除できません。非表示にしてください。'
        );
    }

    $stmt = $pdo->prepare('DELETE FROM report_field_options WHERE id = :id');
    $stmt->execute([':id' => $id]);

    $pdo->exec('COMMIT');
} catch (PDOException $e) {
    try {
        $pdo->exec('ROLLBACK');
    } catch (PDOException $rollbackError) {
        // トランザクションが開始されていない場合は何もしない
    }

    error_log('選択肢の削除エラー: ' . $e->getMessage());

    redirectWithMessage('index.php', 'error', '選択肢を削除できませんでした。');
}

redirectWithMessage($backTo, 'message', '選択肢「' . $option['label'] . '」を削除しました。');
