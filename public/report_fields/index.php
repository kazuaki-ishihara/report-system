<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/report_fields.php';
require_once __DIR__ . '/../../lib/report_values.php';

// ==================================================
// 項目管理：一覧（管理者のみ）
// ==================================================

requireReportFieldAdmin($pdo);
requireReportFieldTables($pdo);

$csrfToken = getCsrfToken();
list($message, $error) = getFlashMessages();

// 項目の一覧（最終更新者と、選択肢の数を含む）
$stmt = $pdo->query(
    'SELECT
        f.*,
        u.username AS updated_by_name,
        (
            SELECT COUNT(*)
            FROM report_field_options o
            WHERE o.field_id = f.id
              AND o.is_active = 1
        ) AS active_option_count
     FROM report_fields f
     LEFT JOIN users u
        ON f.updated_by = u.id
     ORDER BY f.sort_order ASC, f.id ASC'
);
$fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
$fieldCount = count($fields);

// 削除できるか（値もコメントもない固定以外の項目だけ削除できる）
$deletableIds = [];

foreach ($fields as $field) {
    if (!isReportFieldUsed($pdo, $field)) {
        $deletableIds[] = (int) $field['id'];
    }
}

?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>項目管理</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/report_fields.css">
</head>

<body>

<div class="rf-container">

    <h1>項目管理</h1>

    <?php if ($message !== ''): ?>
        <div class="rf-message" role="status"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="rf-error" role="alert"><?= h($error) ?></div>
    <?php endif; ?>

    <p class="rf-help">
        レポートの登録・編集・詳細画面に出す項目を管理します。
        入力済みのレポートやコメントがある項目は、削除せず「非表示」にします（登録済みの内容は残ります）。
        まだ使われていない項目だけ削除できます。
        <span class="rf-badge rf-badge-system">固定</span>
        の項目は、表示名と並び順だけ変更できます。
    </p>

    <div class="rf-toolbar">
        <a href="create.php" class="rf-button">項目を追加</a>
        <a href="../index.php" class="rf-button rf-button-secondary">レポート一覧へ戻る</a>
    </div>

    <div class="rf-table-wrapper">
        <table class="rf-table">
            <thead>
                <tr>
                    <th scope="col">並び順</th>
                    <th scope="col">表示名</th>
                    <th scope="col">種類</th>
                    <th scope="col">必須</th>
                    <th scope="col">一覧に表示</th>
                    <th scope="col">キーワード検索</th>
                    <th scope="col">入力チェック</th>
                    <th scope="col">状態</th>
                    <th scope="col">最終更新</th>
                    <th scope="col">操作</th>
                </tr>
            </thead>

            <tbody>
            <?php if ($fieldCount === 0): ?>
                <tr>
                    <td colspan="10">項目がありません。</td>
                </tr>
            <?php endif; ?>

            <?php foreach ($fields as $index => $field): ?>
                <?php
                $fieldId = (int) $field['id'];
                $isActive = (int) $field['is_active'] === 1;
                $isSystem = (int) $field['is_system'] === 1;
                $isChoice = isChoiceFieldType((string) $field['field_type']);
                $isText = in_array($field['field_type'], REPORT_FIELD_TEXT_TYPES, true);
                ?>
                <tr class="<?= $isActive ? '' : 'rf-inactive' ?>">

                    <td class="rf-nowrap">
                        <form method="post" action="move.php" class="rf-inline-form">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="id" value="<?= $fieldId ?>">
                            <input type="hidden" name="direction" value="up">
                            <button
                                type="submit"
                                class="rf-button rf-button-secondary rf-button-small"
                                aria-label="<?= h($field['label']) ?> を上へ"
                                <?= $index === 0 ? 'disabled' : '' ?>
                            >↑</button>
                        </form>
                        <form method="post" action="move.php" class="rf-inline-form">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="id" value="<?= $fieldId ?>">
                            <input type="hidden" name="direction" value="down">
                            <button
                                type="submit"
                                class="rf-button rf-button-secondary rf-button-small"
                                aria-label="<?= h($field['label']) ?> を下へ"
                                <?= $index === $fieldCount - 1 ? 'disabled' : '' ?>
                            >↓</button>
                        </form>
                    </td>

                    <td>
                        <?= h($field['label']) ?>
                        <?php if ($isSystem): ?>
                            <span class="rf-badge rf-badge-system">固定</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?= h(reportFieldTypeLabel((string) $field['field_type'])) ?>
                        <?php if ($isChoice): ?>
                            <br>
                            <?php if ((int) $field['active_option_count'] === 0): ?>
                                <span class="rf-badge rf-badge-warning">選択肢なし</span>
                            <?php else: ?>
                                <span class="rf-badge">選択肢 <?= (int) $field['active_option_count'] ?> 件</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>

                    <td><?= (int) $field['is_required'] === 1 ? '必須' : '任意' ?></td>
                    <td><?= (int) $field['show_in_list'] === 1 ? '表示' : '―' ?></td>
                    <td><?= $isText ? ((int) $field['is_searchable'] === 1 ? '対象' : '対象外') : '―' ?></td>
                    <td><?= h(reportFieldRuleSummary($field)) ?></td>
                    <td class="rf-nowrap"><?= $isActive ? '表示中' : '非表示' ?></td>

                    <td class="rf-nowrap">
                        <?= h($field['updated_at']) ?><br>
                        <?= h($field['updated_by_name'] ?? '（不明）') ?>
                    </td>

                    <td class="rf-nowrap">
                        <a href="edit.php?id=<?= $fieldId ?>" class="rf-button rf-button-small">編集</a>

                        <?php if ($isChoice): ?>
                            <a href="options.php?field_id=<?= $fieldId ?>" class="rf-button rf-button-small">選択肢</a>
                        <?php endif; ?>

                        <?php if (!$isSystem): ?>
                            <form
                                method="post"
                                action="toggle.php"
                                class="rf-inline-form"
                                onsubmit="return confirm('<?= $isActive ? 'この項目を非表示にしますか？' : 'この項目を再表示しますか？' ?>');"
                            >
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= $fieldId ?>">
                                <button type="submit" class="rf-button rf-button-secondary rf-button-small">
                                    <?= $isActive ? '非表示にする' : '再表示する' ?>
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if (in_array($fieldId, $deletableIds, true)): ?>
                            <form
                                method="post"
                                action="delete.php"
                                class="rf-inline-form"
                                onsubmit="return confirm('この項目を削除しますか？（元に戻せません）');"
                            >
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= $fieldId ?>">
                                <button type="submit" class="rf-button rf-button-secondary rf-button-small rf-button-danger">
                                    削除
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>

                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>

</body>

</html>
