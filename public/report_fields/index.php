<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
requireAdmin($pdo);

$stmt = $pdo->query(
    'SELECT id, code, label, field_type, sort_order, is_active, is_required
     FROM report_fields
     ORDER BY sort_order ASC, id ASC'
);
$fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
$message = isset($_GET['message']) && is_string($_GET['message']) ? $_GET['message'] : '';
$error = isset($_GET['error']) && is_string($_GET['error']) ? $_GET['error'] : '';
$csrfToken = reportFieldCsrfToken();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>レポート項目管理</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .page { max-width: 1100px; margin: 30px auto; padding: 24px; background: #fff; }
        .toolbar, .actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .toolbar { margin: 18px 0; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #f2f4f7; white-space: nowrap; }
        .inactive { color: #777; }
        .message { padding: 12px; background: #e8f5e9; color: #1b5e20; }
        .error { padding: 12px; background: #ffebee; color: #b71c1c; }
        .small-form { display: inline; margin: 0; }
        .button-link, .actions button { display: inline-block; padding: 7px 12px; border: 0; border-radius: 4px; background: #1976d2; color: #fff; text-decoration: none; cursor: pointer; font: inherit; }
        .secondary { background: #666 !important; }
        .actions button:focus-visible, .button-link:focus-visible { outline: 3px solid #ffbf47; outline-offset: 2px; }
        @media (max-width: 700px) { .page { margin: 10px; padding: 12px; } }
    </style>
</head>
<body>
<main class="page">
    <h1>レポート項目管理</h1>
    <p>項目を非表示にしても、登録済みの値やコメントは保持されます。項目は物理削除できません。</p>
    <?php if ($message !== ''): ?><p class="message" role="status"><?= h($message) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <nav class="toolbar" aria-label="項目管理の操作">
        <a class="button-link" href="create.php">項目を追加</a>
        <a class="button-link secondary" href="../index.php">レポート一覧へ戻る</a>
    </nav>
    <div class="table-wrap">
        <table>
            <caption>レポート項目一覧</caption>
            <thead>
                <tr>
                    <th scope="col">表示名</th>
                    <th scope="col">内部コード</th>
                    <th scope="col">種類</th>
                    <th scope="col">必須</th>
                    <th scope="col">表示順</th>
                    <th scope="col">状態</th>
                    <th scope="col">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$fields): ?>
                <tr><td colspan="7">項目はありません。</td></tr>
            <?php else: foreach ($fields as $field): ?>
                <?php $isActive = (int) $field['is_active'] === 1; ?>
                <tr class="<?= $isActive ? '' : 'inactive' ?>">
                    <td><?= h($field['label']) ?></td>
                    <td><code><?= h($field['code']) ?></code></td>
                    <td><?= h(reportFieldLabel($field)) ?></td>
                    <td><?= (int) $field['is_required'] === 1 ? '必須' : '任意' ?></td>
                    <td><?= (int) $field['sort_order'] ?></td>
                    <td><?= $isActive ? '表示中' : '非表示' ?></td>
                    <td>
                        <div class="actions">
                            <a class="button-link" href="edit.php?id=<?= (int) $field['id'] ?>">編集</a>
                            <form
                                class="small-form field-toggle-form"
                                method="post"
                                action="toggle.php"
                                data-field-label="<?= h($field['label']) ?>"
                                data-action-label="<?= $isActive ? '非表示にする' : '再表示する' ?>"
                            >
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= (int) $field['id'] ?>">
                                <button
                                    class="secondary"
                                    type="submit"
                                    aria-label="<?= $isActive ? '項目を非表示にする' : '項目を再表示する' ?>: <?= h($field['label']) ?>"
                                >
                                    <?= $isActive ? '非表示にする' : '再表示する' ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</main>
<script>
document.querySelectorAll('.field-toggle-form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        const fieldLabel = form.dataset.fieldLabel || '';
        const actionLabel = form.dataset.actionLabel || '変更';
        if (!window.confirm('項目「' + fieldLabel + '」を' + actionLabel + 'しますか？')) {
            event.preventDefault();
        }
    });
});
</script>
</body>
</html>
