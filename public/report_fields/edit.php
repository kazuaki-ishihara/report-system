<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
requireAdmin($pdo);
$id = reportFieldIdFromRequest('id');
$stmt = $pdo->prepare('SELECT id, code, label, field_type, sort_order, is_active, is_required FROM report_fields WHERE id = :id');
$stmt->execute([':id' => $id]);
$field = $stmt->fetch(PDO::FETCH_ASSOC);
if ($field === false) {
    http_response_code(404);
    exit('指定された項目が見つかりません。');
}
$csrfToken = reportFieldCsrfToken();
$error = isset($_GET['error']) && is_string($_GET['error']) ? $_GET['error'] : '';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>レポート項目の編集</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .page { max-width: 680px; margin: 30px auto; padding: 24px; background: #fff; }
        .form-group { margin: 18px 0; }
        .form-group input[type="text"], .form-group input[type="number"], .form-group select { box-sizing: border-box; width: 100%; padding: 10px; }
        .help { color: #666; font-size: 13px; }
        .error { padding: 12px; background: #ffebee; color: #b71c1c; }
        .buttons { display: flex; gap: 10px; margin-top: 24px; }
        @media (max-width: 700px) { .page { margin: 10px; padding: 14px; } }
    </style>
</head>
<body>
<main class="page">
    <h1>レポート項目の編集</h1>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <p>内部コード <code><?= h($field['code']) ?></code> は変更できません。</p>
    <form method="post" action="update.php">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= (int) $field['id'] ?>">
        <div class="form-group">
            <label for="label">表示名</label>
            <input id="label" name="label" type="text" maxlength="300" value="<?= h($field['label']) ?>" required autocomplete="off" autofocus>
            <p class="help">UTF-8で1〜300バイト。制御文字は使用できません。</p>
        </div>
        <div class="form-group">
            <label for="field_type">項目の種類</label>
            <select id="field_type" name="field_type" required>
                <?php foreach (REPORT_FIELD_TYPES as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $field['field_type'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="help">登録済みの値がある場合、項目の種類は変更できません。</p>
        </div>
        <div class="form-group">
            <label for="sort_order">表示順</label>
            <input id="sort_order" name="sort_order" type="number" min="0" max="1000000" step="1" value="<?= (int) $field['sort_order'] ?>" required>
        </div>
        <div class="form-group">
            <label for="is_required"><input id="is_required" name="is_required" type="checkbox" value="1" <?= (int) $field['is_required'] === 1 ? 'checked' : '' ?>> 必須項目にする</label>
        </div>
        <div class="buttons">
            <button type="submit">保存する</button>
            <a class="back-button" href="index.php">キャンセル</a>
        </div>
    </form>
</main>
</body>
</html>
