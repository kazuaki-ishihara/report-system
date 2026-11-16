<?php

declare(strict_types=1);

// ==================================================
// レポートの登録・編集フォームの入力欄（create.php / edit.php から読み込む）
//
// 項目管理の定義（表示中の項目）から入力欄を作る。
// 呼び出し側で次の変数を用意する。
//   $fields     : loadReportFields($pdo, true)
//   $formValues : 入力欄に出す値（内部コード => 値）
// ==================================================

// このファイルを URL で直接開いた場合は何も表示しない
if (!isset($fields, $formValues) || !function_exists('formatReportValue')) {
    http_response_code(404);
    exit;
}

foreach ($fields as $code => $field):
    $type = (string) $field['field_type'];
    $label = (string) $field['label'];
    $isRequired = (int) $field['is_required'] === 1;
    $value = $formValues[$code] ?? null;
    $inputId = 'field-' . $code;

    // 選択肢：表示中のもの＋このレポートで選択済みの非表示のもの
    $options = [];

    if (isChoiceFieldType($type)) {
        $selectedIds = $type === 'checkbox'
            ? array_map('intval', (array) $value)
            : ($value === null ? [] : [(int) $value]);

        foreach ($field['options'] as $optionId => $option) {
            $isActiveOption = (int) $option['is_active'] === 1;

            if ($isActiveOption || in_array((int) $optionId, $selectedIds, true)) {
                $options[(int) $optionId] = [
                    'label'    => (string) $option['label'] . ($isActiveOption ? '' : '（非表示の選択肢）'),
                    'selected' => in_array((int) $optionId, $selectedIds, true),
                ];
            }
        }
    }
?>

            <!-- ========================================
                 <?= h($label) ?>

            ========================================= -->

            <div class="form-group">

                <?php if ($type === 'radio' || $type === 'checkbox'): ?>

                    <fieldset class="report-choice-group">
                        <legend>
                            <?= h($label) ?>
                            <?php if ($isRequired): ?><span class="required-mark">（必須）</span><?php endif; ?>
                        </legend>

                        <?php if (count($options) === 0): ?>
                            <p class="choice-empty">選択肢がありません。</p>
                        <?php endif; ?>

                        <?php if ($type === 'radio' && !$isRequired && count($options) > 0): ?>
                            <label class="choice-label">
                                <input
                                    type="radio"
                                    name="<?= h($code) ?>"
                                    value=""
                                    <?= $value === null ? 'checked' : '' ?>
                                >
                                選択しない
                            </label>
                        <?php endif; ?>

                        <?php foreach ($options as $optionId => $option): ?>
                            <label class="choice-label">
                                <input
                                    type="<?= $type === 'radio' ? 'radio' : 'checkbox' ?>"
                                    name="<?= h($code) ?><?= $type === 'checkbox' ? '[]' : '' ?>"
                                    value="<?= (int) $optionId ?>"
                                    <?= $option['selected'] ? 'checked' : '' ?>
                                    <?= $type === 'radio' && $isRequired ? 'required' : '' ?>
                                >
                                <?= h($option['label']) ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                <?php else: ?>

                    <label for="<?= h($inputId) ?>">
                        <?= h($label) ?>
                        <?php if ($isRequired): ?><span class="required-mark">（必須）</span><?php endif; ?>
                    </label>

                    <?php if ($type === 'textarea'): ?>

                        <textarea
                            id="<?= h($inputId) ?>"
                            name="<?= h($code) ?>"
                            <?= $isRequired ? 'required' : '' ?>
                        ><?= h($value === null ? '' : (string) $value) ?></textarea>

                    <?php elseif ($type === 'text'): ?>

                        <input
                            type="text"
                            id="<?= h($inputId) ?>"
                            name="<?= h($code) ?>"
                            value="<?= h($value === null ? '' : (string) $value) ?>"
                            maxlength="<?= $field['max_length'] === null ? REPORT_FIELD_DEFAULT_MAX_LENGTH['text'] : (int) $field['max_length'] ?>"
                            <?= $isRequired ? 'required' : '' ?>
                        >

                    <?php elseif ($type === 'date'): ?>

                        <input
                            type="date"
                            id="<?= h($inputId) ?>"
                            name="<?= h($code) ?>"
                            value="<?= h($value === null ? '' : (string) $value) ?>"
                            <?= $isRequired ? 'required' : '' ?>
                        >

                    <?php elseif ($type === 'number'): ?>

                        <input
                            type="number"
                            id="<?= h($inputId) ?>"
                            name="<?= h($code) ?>"
                            value="<?= h($value === null ? '' : formatReportFieldNumber($value)) ?>"
                            step="<?= (int) $field['allow_decimal'] === 1 ? 'any' : '1' ?>"
                            <?= $field['min_value'] !== null ? 'min="' . h(formatReportFieldNumber($field['min_value'])) . '"' : '' ?>
                            <?= $field['max_value'] !== null ? 'max="' . h(formatReportFieldNumber($field['max_value'])) . '"' : '' ?>
                            <?= $isRequired ? 'required' : '' ?>
                        >

                    <?php elseif ($type === 'select'): ?>

                        <select
                            id="<?= h($inputId) ?>"
                            name="<?= h($code) ?>"
                            class="report-select"
                            <?= $isRequired ? 'required' : '' ?>
                        >
                            <option value=""><?= $isRequired ? '選んでください' : '（選択しない）' ?></option>
                            <?php foreach ($options as $optionId => $option): ?>
                                <option value="<?= (int) $optionId ?>" <?= $option['selected'] ? 'selected' : '' ?>>
                                    <?= h($option['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                    <?php endif; ?>

                <?php endif; ?>

            </div>

<?php endforeach; ?>
