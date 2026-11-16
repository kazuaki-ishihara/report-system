<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/report_fields.php';
require_once __DIR__ . '/../../lib/report_values.php';

// ==================================================
// 項目管理：未使用の項目の削除（管理者のみ。D8・D33）
//
// 値もコメントもない項目だけ削除できる。使用中の項目は非表示にする。
// 固定の項目（日付・氏名・件名）は削除できない。
// 項目と一緒に、その項目の選択肢も削除する。
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

    $field = findReportField($pdo, $id);

    if ($field === null) {
        $pdo->exec('ROLLBACK');
        http_response_code(404);
        exit('指定された項目が見つかりません。');
    }

    if ((int) $field['is_system'] === 1) {
        $pdo->exec('ROLLBACK');
        redirectWithMessage('index.php', 'error', '固定の項目は削除できません。');
    }

    if (isReportFieldUsed($pdo, $field)) {
        $pdo->exec('ROLLBACK');
        redirectWithMessage(
            'index.php',
            'error',
            '項目「' . $field['label'] . '」は入力済みのレポートかコメントがあるため削除できません。非表示にしてください。'
        );
    }

    $deleteOptions = $pdo->prepare('DELETE FROM report_field_options WHERE field_id = :field_id');
    $deleteOptions->execute([':field_id' => $id]);

    $deleteField = $pdo->prepare('DELETE FROM report_fields WHERE id = :id AND is_system = 0');
    $deleteField->execute([':id' => $id]);

    $pdo->exec('COMMIT');
} catch (PDOException $e) {
    try {
        $pdo->exec('ROLLBACK');
    } catch (PDOException $rollbackError) {
        // トランザクションが開始されていない場合は何もしない
    }

    error_log('項目の削除エラー: ' . $e->getMessage());

    redirectWithMessage('index.php', 'error', '項目を削除できませんでした。');
}

redirectWithMessage('index.php', 'message', '項目「' . $field['label'] . '」を削除しました。');
