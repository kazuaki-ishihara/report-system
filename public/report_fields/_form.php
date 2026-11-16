<?php

declare(strict_types=1);

// ==================================================
// 項目の追加・編集フォームの入力欄（create.php / edit.php から読み込む）
//
// 呼び出し側で次の変数を用意する。
//   $field     : 編集時は今の項目（配列）。追加時は null
//   $csrfToken : CSRF トークン
// ==================================================

// このファイルを URL で直接開いた場合は何も表示しない
if (!isset($csrfToken) || !function_exists('reportFieldTypeLabel')) {
    http_response_code(404);
    exit;
}

$isEdit = $field !== null;
$isSystem = $isEdit && (int) $field['is_system'] === 1;
$currentType = $isEdit ? (string) $field['field_type'] : '';

// 種類ごとの入力欄を出すか（追加時はすべて出し、見出しで対象の種類を示す）
$showTextRules = !$isEdit || in_array($currentType, REPORT_FIELD_TEXT_TYPES, true);
$showNumberRules = !$isEdit || $currentType === 'number';

$value = function (string $key, string $default = '') use ($field): string {
    if ($field === null || !array_key_exists($key, $field) || $field[$key] === null) {
        return $default;
    }

    return (string) $field[$key];
};

$checked = function (string $key, bool $default = false) use ($field): string {
    if ($field === null) {
        return $default ? 'checked' : '';
    }

    return (int) $field[$key] === 1 ? 'checked' : '';
};
?>
<input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

<div class="form-group">
    <label for="label">表示名</label>
    <input
        type="text"
        id="label"
        name="label"
        value="<?= h($value('label')) ?>"
        maxlength="<?= REPORT_FIELD_LABEL_MAX_LENGTH ?>"
        required
        autocomplete="off"
    >
    <p class="rf-help"><?= REPORT_FIELD_LABEL_MAX_LENGTH ?>文字以内。改行は使えません。</p>
</div>

<div class="form-group">
    <label for="field_type">種類</label>

    <?php if ($isEdit): ?>
        <p><?= h(reportFieldTypeLabel($currentType)) ?></p>
        <p class="rf-help">種類は追加した後に変更できません。</p>
    <?php else: ?>
        <select id="field_type" name="field_type" class="rf-select" required>
            <option value="">選んでください</option>
            <?php foreach (REPORT_FIELD_TYPES as $typeCode => $typeLabel): ?>
                <option value="<?= h($typeCode) ?>"><?= h($typeLabel) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="rf-help">
            種類は追加した後に変更できません。
            選択肢の種類は、追加した後に「選択肢」画面で選択肢を登録します。
        </p>
    <?php endif; ?>
</div>

<?php if ($isSystem): ?>

    <div class="rf-warning">
        この項目は固定の項目のため、表示名だけ変更できます（並び順は一覧画面の ↑↓ で変更します）。
    </div>

<?php else: ?>

    <fieldset class="rf-fieldset">
        <legend>表示と入力</legend>

        <div class="form-group">
            <label class="rf-checkbox-label">
                <input type="checkbox" name="is_required" value="1" <?= $checked('is_required') ?>>
                必須にする
            </label>
        </div>

        <div class="form-group">
            <label class="rf-checkbox-label">
                <input type="checkbox" name="show_in_list" value="1" <?= $checked('show_in_list') ?>>
                レポート一覧の列に表示する
            </label>
        </div>
    </fieldset>

    <?php if ($showTextRules): ?>
        <fieldset class="rf-fieldset">
            <legend>
                テキストの設定
                <?php if (!$isEdit): ?>（1行テキスト・複数行テキストのみ）<?php endif; ?>
            </legend>

            <div class="form-group">
                <label class="rf-checkbox-label">
                    <input type="checkbox" name="is_searchable" value="1" <?= $checked('is_searchable') ?>>
                    レポート一覧のキーワード検索の対象にする
                </label>
            </div>

            <div class="form-group">
                <label for="max_length">最大文字数</label>
                <input
                    type="number"
                    id="max_length"
                    name="max_length"
                    value="<?= h($value('max_length')) ?>"
                    min="1"
                    max="<?= REPORT_FIELD_MAX_LENGTH_LIMIT ?>"
                    step="1"
                >
                <p class="rf-help">
                    空欄の場合は既定値（1行テキスト <?= REPORT_FIELD_DEFAULT_MAX_LENGTH['text'] ?>文字、
                    複数行テキスト <?= REPORT_FIELD_DEFAULT_MAX_LENGTH['textarea'] ?>文字）を使います。
                </p>
            </div>
        </fieldset>
    <?php endif; ?>

    <?php if ($showNumberRules): ?>
        <fieldset class="rf-fieldset">
            <legend>
                数値の設定
                <?php if (!$isEdit): ?>（数値のみ）<?php endif; ?>
            </legend>

            <div class="form-group">
                <label class="rf-checkbox-label">
                    <input type="checkbox" name="allow_decimal" value="1" <?= $checked('allow_decimal') ?>>
                    小数を入力できるようにする
                </label>
            </div>

            <div class="form-group">
                <label for="min_value">最小値</label>
                <input
                    type="text"
                    id="min_value"
                    name="min_value"
                    value="<?= $field !== null && $field['min_value'] !== null ? h(formatReportFieldNumber($field['min_value'])) : '' ?>"
                    inputmode="decimal"
                >
            </div>

            <div class="form-group">
                <label for="max_value">最大値</label>
                <input
                    type="text"
                    id="max_value"
                    name="max_value"
                    value="<?= $field !== null && $field['max_value'] !== null ? h(formatReportFieldNumber($field['max_value'])) : '' ?>"
                    inputmode="decimal"
                >
                <p class="rf-help">最小値・最大値は空欄にすると制限しません。</p>
            </div>
        </fieldset>
    <?php endif; ?>

<?php endif; ?>
