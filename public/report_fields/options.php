<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/csrf.php';
require_once __DIR__ . '/../../lib/report_fields.php';
require_once __DIR__ . '/../../lib/report_values.php';

// ==================================================
// 項目管理：選択肢の一覧・追加・名前の変更（管理者のみ）
//
// 選択肢は物理削除せず、非表示にする。
// 名前を変えると、過去のレポートも新しい名前で表示される（D17）。
// ==================================================

requireReportFieldAdmin($pdo);
requireReportFieldTables($pdo);

$fieldId = getId('field_id');
$field = findReportField($pdo, $fieldId);

if ($field === null) {
    http_response_code(404);
    exit('指定された項目が見つかりません。');
}

if (!isChoiceFieldType((string) $field['field_type'])) {
    redirectWithMessage('index.php', 'error', 'この項目は選択肢の種類ではありません。');
}

$csrfToken = getCsrfToken();
list($message, $error) = getFlashMessages();

$stmt = $pdo->prepare(
    'SELECT
        o.*,
        u.username AS updated_by_name
     FROM report_field_options o
     LEFT JOIN users u
        ON o.updated_by = u.id
     WHERE o.field_id = :field_id
     ORDER BY o.sort_order ASC, o.id ASC'
);
$stmt->execute([':field_id' => $fieldId]);
$options = $stmt->fetchAll(PDO::FETCH_ASSOC);
$optionCount = count($options);

// 削除できるか（その選択肢を選んだレポートがない場合だけ削除できる）
$deletableIds = [];

foreach ($options as $option) {
    if (!isReportFieldOptionUsed($pdo, (int) $option['id'])) {
        $deletableIds[] = (int) $option['id'];
    }
}

?>
<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>選択肢の管理</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/report_fields.css">
</head>

<body>

<div class="rf-container">

    <h1>選択肢の管理：<?= h($field['label']) ?></h1>

    <?php if ($message !== ''): ?>
        <div class="rf-message" role="status"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="rf-error" role="alert"><?= h($error) ?></div>
    <?php endif; ?>

    <p class="rf-help">
        種類：<?= h(reportFieldTypeLabel((string) $field['field_type'])) ?>。
        選択済みのレポートがある選択肢は、削除せず「非表示」にします。まだ使われていない選択肢だけ削除できます。
        名前を変更すると、過去のレポートも新しい名前で表示されます。
    </p>

    <div class="rf-toolbar">
        <a href="index.php" class="rf-button rf-button-secondary">項目管理へ戻る</a>
    </div>

    <div class="rf-table-wrapper">
        <table class="rf-table">
            <thead>
                <tr>
                    <th scope="col">並び順</th>
                    <th scope="col">選択肢の名前</th>
                    <th scope="col">状態</th>
                    <th scope="col">最終更新</th>
                    <th scope="col">操作</th>
                </tr>
            </thead>

            <tbody>
            <?php if ($optionCount === 0): ?>
                <tr>
                    <td colspan="5">選択肢がありません。下の欄から追加してください。</td>
                </tr>
            <?php endif; ?>

            <?php foreach ($options as $index => $option): ?>
                <?php
                $optionId = (int) $option['id'];
                $isActive = (int) $option['is_active'] === 1;
                ?>
                <tr class="<?= $isActive ? '' : 'rf-inactive' ?>">

                    <td class="rf-nowrap">
                        <form method="post" action="option_move.php" class="rf-inline-form">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="id" value="<?= $optionId ?>">
                            <input type="hidden" name="direction" value="up">
                            <button
                                type="submit"
                                class="rf-button rf-button-secondary rf-button-small"
                                aria-label="<?= h($option['label']) ?> を上へ"
                                <?= $index === 0 ? 'disabled' : '' ?>
                            >↑</button>
                        </form>
                        <form method="post" action="option_move.php" class="rf-inline-form">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="id" value="<?= $optionId ?>">
                            <input type="hidden" name="direction" value="down">
                            <button
                                type="submit"
                                class="rf-button rf-button-secondary rf-button-small"
                                aria-label="<?= h($option['label']) ?> を下へ"
                                <?= $index === $optionCount - 1 ? 'disabled' : '' ?>
                            >↓</button>
                        </form>
                    </td>

                    <td>
                        <form method="post" action="option_update.php" class="rf-toolbar" style="margin: 0;">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="id" value="<?= $optionId ?>">
                            <input
                                type="text"
                                name="label"
                                value="<?= h($option['label']) ?>"
                                maxlength="<?= REPORT_FIELD_LABEL_MAX_LENGTH ?>"
                                class="rf-option-label-input"
                                aria-label="選択肢の名前"
                                required
                                style="width: auto; flex: 1;"
                            >
                            <button type="submit" class="rf-button rf-button-small">名前を保存</button>
                        </form>
                    </td>

                    <td class="rf-nowrap"><?= $isActive ? '表示中' : '非表示' ?></td>

                    <td class="rf-nowrap">
                        <?= h($option['updated_at']) ?><br>
                        <?= h($option['updated_by_name'] ?? '（不明）') ?>
                    </td>

                    <td class="rf-nowrap">
                        <form
                            method="post"
                            action="option_toggle.php"
                            class="rf-inline-form"
                            onsubmit="return confirm('<?= $isActive ? 'この選択肢を非表示にしますか？' : 'この選択肢を再表示しますか？' ?>');"
                        >
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="id" value="<?= $optionId ?>">
                            <button type="submit" class="rf-button rf-button-secondary rf-button-small">
                                <?= $isActive ? '非表示にする' : '再表示する' ?>
                            </button>
                        </form>

                        <?php if (in_array($optionId, $deletableIds, true)): ?>
                            <form
                                method="post"
                                action="option_delete.php"
                                class="rf-inline-form"
                                onsubmit="return confirm('この選択肢を削除しますか？（元に戻せません）');"
                            >
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= $optionId ?>">
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

    <h2>選択肢を追加</h2>

    <form method="post" action="option_store.php" class="form-container" style="padding: 0;">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="field_id" value="<?= $fieldId ?>">

        <div class="form-group">
            <label for="new_label">選択肢の名前</label>
            <input
                type="text"
                id="new_label"
                name="label"
                maxlength="<?= REPORT_FIELD_LABEL_MAX_LENGTH ?>"
                required
                autocomplete="off"
            >
            <p class="rf-help"><?= REPORT_FIELD_LABEL_MAX_LENGTH ?>文字以内。同じ項目の中で同じ名前は使えません。</p>
        </div>

        <button type="submit" class="rf-button">追加する</button>
    </form>

</div>

</body>

</html>
