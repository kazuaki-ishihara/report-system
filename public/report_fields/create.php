<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/report_fields.php';

// ==================================================
// 項目管理：項目の追加画面（管理者のみ）
// ==================================================

requireReportFieldAdmin($pdo);
requireReportFieldTables($pdo);

$field = null;

// 入力エラーで戻ってきた場合は、入力した値を入力欄に入れ直す
$oldInput = takeFormInput('report_field_create');

$csrfToken = getCsrfToken();
list($message, $error) = getFlashMessages();

?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>項目の追加</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/report_fields.css">
</head>

<body>

<div class="rf-container rf-narrow">

    <h1>項目の追加</h1>

    <?php if ($error !== ''): ?>
        <div class="rf-error" role="alert"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="store.php" class="rf-field-form">
        <?php require __DIR__ . '/_form.php'; ?>

        <div class="rf-toolbar">
            <button type="submit" class="rf-button">追加する</button>
            <a href="index.php" class="rf-button rf-button-secondary">キャンセル</a>
        </div>
    </form>

</div>

</body>

</html>
