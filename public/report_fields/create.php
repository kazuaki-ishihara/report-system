<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
requireAdmin($pdo);
$csrfToken = reportFieldCsrfToken();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>レポート項目の追加</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .page { max-width: 680px; margin: 30px auto; padding: 24px; background: #fff; }
        .form-group { margin: 18px 0; }
        .form-group input, .form-group select { box-sizing: border-box; width: 100%; padding: 10px; }
        .help { color: #666; font-size: 13px; }
        .buttons { display: flex; gap: 10px; margin-top: 24px; }
        @media (max-width: 700px) { .page { margin: 10px; padding: 14px; } }
    </style>
</head>
<body>
<main class="page">
    <h1>レポート項目の追加</h1>
    <form method="post" action="store.php">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <div class="form-group">
            <label for="label">表示名</label>
            <input id="label" name="label" type="text" maxlength="300" required>
            <p class="help">表示名は1〜300バイトで入力してください。</p>
        </div>
        <div class="form-group">
            <label for="field_type">項目の種類</label>
            <select id="field_type" name="field_type" required>
                <?php foreach (REPORT_FIELD_TYPES as $value => $label): ?>
                    <option value="<?= h($value) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="sort_order">表示順</label>
            <input id="sort_order" name="sort_order" type="number" min="0" max="1000000" value="100" required>
        </div>
        <div class="form-group">
            <label><input name="is_required" type="checkbox" value="1"> 必須項目にする</label>
        </div>
        <div class="buttons">
            <button type="submit">追加する</button>
            <a class="back-button" href="index.php">キャンセル</a>
        </div>
    </form>
</main>
</body>
</html>
