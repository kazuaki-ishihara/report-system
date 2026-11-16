<?php

declare(strict_types=1);

require_once __DIR__ . '/report_fields.php';

/**
 * レポートの値の読み書き（D31：B2 併用）
 *
 * - 既存 6 項目（REPORT_COLUMN_FIELDS）の値は reports テーブルの列に保存する。
 * - 管理画面で追加した項目の値は report_field_values に 1 項目 1 行で保存する。
 *   チェックボックスは選んだ選択肢の数だけ行を持つ（H7）。
 * - 画面はこのファイルの関数だけを使い、保存先の違いを意識しない。
 * - 項目管理のセットアップ前は、既存 6 項目の初期定義で動く。
 */

// ==================================================
// 定数
// ==================================================

/** reports テーブルの列に値を持つ項目（内部コード = 列名） */
const REPORT_COLUMN_FIELDS = ['report_date', 'name', 'subject', 'content', 'issues', 'remarks'];

/** 未入力の値として扱うもの */
const REPORT_EMPTY_VALUES = [null, '', []];


// ==================================================
// 項目定義の取得
// ==================================================

/**
 * reports の列に値を持つ項目か
 */
function isReportColumnField(string $code): bool
{
    return in_array($code, REPORT_COLUMN_FIELDS, true);
}

/**
 * 値テーブル（report_field_values）があるか
 */
function reportValueTableExists(PDO $pdo): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM sqlite_master
         WHERE type = 'table' AND name = 'report_field_values'"
    );
    $stmt->execute();

    return (int) $stmt->fetchColumn() === 1;
}

/**
 * レポート画面で使う項目の定義を取得する
 *
 * 戻り値は内部コードをキーにした配列（並び順どおり）。
 * 選択肢の種類には 'options'（選択肢 ID => 選択肢。非表示のものを含む）を付ける。
 * セットアップ前は既存 6 項目の初期定義を返す。
 *
 * @param bool $activeOnly true なら表示中の項目だけ
 * @return array<string, array<string, mixed>>
 */
function loadReportFields(PDO $pdo, bool $activeOnly = true): array
{
    if (!reportFieldTablesExist($pdo)) {
        $fields = [];

        foreach (reportFieldInitialRows() as $index => $row) {
            $fields[$row['code']] = array_merge($row, [
                'id'            => 0,
                'is_active'     => 1,
                'sort_order'    => ($index + 1) * REPORT_FIELD_SORT_STEP,
                'min_value'     => null,
                'max_value'     => null,
                'allow_decimal' => 0,
                'options'       => [],
            ]);
        }

        return $fields;
    }

    $sql = 'SELECT * FROM report_fields';

    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }

    $sql .= ' ORDER BY sort_order ASC, id ASC';

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // 選択肢をまとめて取得する
    $optionRows = $pdo->query(
        'SELECT * FROM report_field_options ORDER BY sort_order ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $optionsByField = [];

    foreach ($optionRows as $option) {
        $optionsByField[(int) $option['field_id']][(int) $option['id']] = $option;
    }

    $fields = [];

    foreach ($rows as $row) {
        $row['options'] = $optionsByField[(int) $row['id']] ?? [];
        $fields[(string) $row['code']] = $row;
    }

    return $fields;
}

/**
 * コメントで使う項目名の一覧（内部コード => 表示名）
 *
 * @param bool $activeOnly true なら表示中の項目だけ（コメント登録用）。
 *                         false なら非表示の項目も含む（既存コメントの編集・削除用）
 * @return array<string, string>
 */
function loadReportFieldLabels(PDO $pdo, bool $activeOnly): array
{
    $labels = [];

    foreach (loadReportFields($pdo, $activeOnly) as $code => $field) {
        $labels[$code] = (string) $field['label'];
    }

    return $labels;
}


// ==================================================
// 値の取得
// ==================================================

/**
 * 1 件のレポートの値を取得する
 *
 * @param array $report reports テーブルの 1 行
 * @param array $fields loadReportFields() の戻り値
 * @return array<string, mixed> 内部コード => 値
 *   テキスト・日付は string|null、数値は float|null、
 *   プルダウン・ラジオは選択肢 ID（int|null）、チェックボックスは選択肢 ID の配列
 */
function loadReportValues(PDO $pdo, array $report, array $fields): array
{
    $values = [];

    foreach ($fields as $code => $field) {
        if (isReportColumnField($code)) {
            $values[$code] = $report[$code] ?? null;
        } else {
            $values[$code] = (string) $field['field_type'] === 'checkbox' ? [] : null;
        }
    }

    $added = loadAddedFieldValues($pdo, [(int) $report['id']], $fields);

    return array_merge($values, $added[(int) $report['id']] ?? []);
}

/**
 * 追加項目の値を、複数のレポートについてまとめて取得する
 *
 * @param int[] $reportIds
 * @return array<int, array<string, mixed>> レポート ID => (内部コード => 値)
 */
function loadAddedFieldValues(PDO $pdo, array $reportIds, array $fields): array
{
    $result = [];
    $fieldsById = [];

    foreach ($fields as $code => $field) {
        if (!isReportColumnField($code) && (int) $field['id'] > 0) {
            $fieldsById[(int) $field['id']] = $field;
        }
    }

    if (count($reportIds) === 0 || count($fieldsById) === 0 || !reportValueTableExists($pdo)) {
        return $result;
    }

    $params = [];
    $reportPlaceholders = [];
    $fieldPlaceholders = [];

    foreach (array_values($reportIds) as $index => $reportId) {
        $reportPlaceholders[] = ':r' . $index;
        $params[':r' . $index] = (int) $reportId;
    }

    foreach (array_keys($fieldsById) as $index => $fieldId) {
        $fieldPlaceholders[] = ':f' . $index;
        $params[':f' . $index] = $fieldId;
    }

    $stmt = $pdo->prepare(
        'SELECT report_id, field_id, option_id, value_text, value_number
         FROM report_field_values
         WHERE report_id IN (' . implode(', ', $reportPlaceholders) . ')
           AND field_id IN (' . implode(', ', $fieldPlaceholders) . ')
         ORDER BY id ASC'
    );
    $stmt->execute($params);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $reportId = (int) $row['report_id'];
        $field = $fieldsById[(int) $row['field_id']];
        $code = (string) $field['code'];
        $type = (string) $field['field_type'];

        if ($type === 'checkbox') {
            $result[$reportId][$code][] = (int) $row['option_id'];
        } elseif ($type === 'select' || $type === 'radio') {
            $result[$reportId][$code] = $row['option_id'] === null ? null : (int) $row['option_id'];
        } elseif ($type === 'number') {
            $result[$reportId][$code] = $row['value_number'] === null ? null : (float) $row['value_number'];
        } else {
            $result[$reportId][$code] = $row['value_text'];
        }
    }

    return $result;
}


// ==================================================
// 表示
// ==================================================

/**
 * 値を画面表示用の文字にする
 *
 * @param mixed $value
 */
function formatReportValue(array $field, $value): string
{
    $type = (string) $field['field_type'];

    if ($value === null || $value === '' || $value === []) {
        return '';
    }

    if ($type === 'number') {
        return formatReportFieldNumber($value);
    }

    if ($type === 'select' || $type === 'radio') {
        return (string) ($field['options'][(int) $value]['label'] ?? '');
    }

    if ($type === 'checkbox') {
        $labels = [];

        foreach ((array) $value as $optionId) {
            if (isset($field['options'][(int) $optionId])) {
                $labels[] = (string) $field['options'][(int) $optionId]['label'];
            }
        }

        return implode('、', $labels);
    }

    return (string) $value;
}


// ==================================================
// 入力チェック（登録・更新で共通。D35）
// ==================================================

/**
 * 登録・編集フォームの値を読み取ってチェックする
 *
 * @param array $fields 表示中の項目（loadReportFields($pdo, true)）
 * @param array $currentValues 編集時は今の値（非表示になった選択肢を選んだままにできるようにする）
 * @return array{0: array<string, mixed>, 1: string[]} [値, エラーメッセージの一覧]
 */
function readReportFormInput(array $fields, array $currentValues = []): array
{
    $values = [];
    $errors = [];

    foreach ($fields as $code => $field) {
        $type = (string) $field['field_type'];
        $label = (string) $field['label'];
        $required = (int) $field['is_required'] === 1;
        $raw = $_POST[$code] ?? null;

        // ---- チェックボックス（複数選択） ----
        if ($type === 'checkbox') {
            if ($raw !== null && !is_array($raw)) {
                $errors[] = '「' . $label . '」の値が正しくありません。';
                continue;
            }

            $selected = [];

            foreach ((array) $raw as $item) {
                if (!is_string($item) || preg_match('/^[1-9][0-9]*$/', $item) !== 1) {
                    $errors[] = '「' . $label . '」の値が正しくありません。';
                    continue 2;
                }

                $optionId = (int) $item;

                if (!isSelectableOption($field, $optionId, $currentValues[$code] ?? [])) {
                    $errors[] = '「' . $label . '」の選択肢が正しくありません。';
                    continue 2;
                }

                $selected[$optionId] = $optionId;
            }

            if ($required && count($selected) === 0) {
                $errors[] = '「' . $label . '」を選んでください。';
                continue;
            }

            $values[$code] = array_values($selected);
            continue;
        }

        // ---- それ以外は文字列で受け取る ----
        if ($raw !== null && !is_string($raw)) {
            $errors[] = '「' . $label . '」の値が正しくありません。';
            continue;
        }

        $input = (string) $raw;

        // 1 行の入力欄は前後の空白を取り除く。複数行は入力のまま保存する
        if ($type !== 'textarea') {
            $input = trim($input);
        }

        $isEmpty = trim($input) === '';

        if ($isEmpty) {
            if ($required) {
                $errors[] = ($type === 'select' || $type === 'radio')
                    ? '「' . $label . '」を選んでください。'
                    : '「' . $label . '」を入力してください。';
                continue;
            }

            $values[$code] = null;
            continue;
        }

        // ---- テキスト ----
        if ($type === 'text' || $type === 'textarea') {
            $length = countUtf8Chars($input);
            $maxLength = $field['max_length'] === null
                ? REPORT_FIELD_DEFAULT_MAX_LENGTH[$type]
                : (int) $field['max_length'];

            if ($length < 0) {
                $errors[] = '「' . $label . '」の文字が正しくありません。';
                continue;
            }

            if ($length > $maxLength) {
                $errors[] = '「' . $label . '」は' . $maxLength . '文字以内で入力してください（' . $length . '文字）。';
                continue;
            }

            $values[$code] = $input;
            continue;
        }

        // ---- 日付 ----
        if ($type === 'date') {
            if (!isValidReportDate($input)) {
                $errors[] = '「' . $label . '」の日付の形式が正しくありません。';
                continue;
            }

            $values[$code] = $input;
            continue;
        }

        // ---- 数値 ----
        if ($type === 'number') {
            $allowDecimal = (int) $field['allow_decimal'] === 1;
            $pattern = $allowDecimal
                ? '/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/'
                : '/^-?(0|[1-9][0-9]*)$/';

            if (preg_match($pattern, $input) !== 1) {
                $errors[] = $allowDecimal
                    ? '「' . $label . '」は数値で入力してください。'
                    : '「' . $label . '」は整数で入力してください。';
                continue;
            }

            $number = (float) $input;

            if (abs($number) > REPORT_FIELD_NUMBER_LIMIT) {
                $errors[] = '「' . $label . '」の値が大きすぎます。';
                continue;
            }

            if ($field['min_value'] !== null && $number < (float) $field['min_value']) {
                $errors[] = '「' . $label . '」は' . formatReportFieldNumber($field['min_value']) . '以上で入力してください。';
                continue;
            }

            if ($field['max_value'] !== null && $number > (float) $field['max_value']) {
                $errors[] = '「' . $label . '」は' . formatReportFieldNumber($field['max_value']) . '以下で入力してください。';
                continue;
            }

            $values[$code] = $number;
            continue;
        }

        // ---- プルダウン・ラジオ ----
        if ($type === 'select' || $type === 'radio') {
            if (preg_match('/^[1-9][0-9]*$/', $input) !== 1) {
                $errors[] = '「' . $label . '」の選択肢が正しくありません。';
                continue;
            }

            $optionId = (int) $input;
            $current = $currentValues[$code] ?? null;

            if (!isSelectableOption($field, $optionId, $current === null ? [] : [(int) $current])) {
                $errors[] = '「' . $label . '」の選択肢が正しくありません。';
                continue;
            }

            $values[$code] = $optionId;
            continue;
        }

        $errors[] = '「' . $label . '」の種類が正しくありません。';
    }

    return [$values, $errors];
}

/**
 * 選べる選択肢か（表示中の選択肢、または今のレポートで選択済みの選択肢）
 *
 * @param int[] $currentIds
 */
function isSelectableOption(array $field, int $optionId, $currentIds): bool
{
    if (!isset($field['options'][$optionId])) {
        return false;
    }

    if ((int) $field['options'][$optionId]['is_active'] === 1) {
        return true;
    }

    return in_array($optionId, array_map('intval', (array) $currentIds), true);
}

/**
 * YYYY-MM-DD 形式の、存在する日付か
 */
function isValidReportDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}


// ==================================================
// 保存
// ==================================================

/**
 * reports テーブルの 6 列に入れる値を作る
 *
 * 表示中の項目は入力値を使う。非表示・削除済みの項目は今の値を残す（登録時は NULL）。
 *
 * @param array $fields 表示中の項目
 * @param array $values readReportFormInput() の値
 * @param array|null $report 更新時は今の reports の行。登録時は null
 * @return array<string, string|null> 列名 => 値
 */
function buildReportColumnValues(array $fields, array $values, ?array $report): array
{
    $columns = [];

    foreach (REPORT_COLUMN_FIELDS as $code) {
        if (array_key_exists($code, $fields)) {
            $columns[$code] = $values[$code] ?? null;
        } else {
            $columns[$code] = $report === null ? null : ($report[$code] ?? null);
        }
    }

    return $columns;
}

/**
 * 追加項目の値を保存する
 *
 * 表示中の追加項目について、今の値を消してから入れ直す。
 * 非表示の項目の値はそのまま残す。
 * 呼び出し側でトランザクションを開始しておくこと。
 *
 * @param array $fields 表示中の項目
 * @param array $values readReportFormInput() の値
 */
function saveAddedFieldValues(PDO $pdo, int $reportId, array $fields, array $values, string $now): void
{
    if (!reportValueTableExists($pdo)) {
        return;
    }

    $delete = $pdo->prepare(
        'DELETE FROM report_field_values
         WHERE report_id = :report_id AND field_id = :field_id'
    );

    $insert = $pdo->prepare(
        'INSERT INTO report_field_values (
            report_id, field_id, option_id, value_text, value_number, created_at, updated_at
         ) VALUES (
            :report_id, :field_id, :option_id, :value_text, :value_number, :created_at, :updated_at
         )'
    );

    foreach ($fields as $code => $field) {
        if (isReportColumnField($code) || (int) $field['id'] <= 0) {
            continue;
        }

        $fieldId = (int) $field['id'];
        $type = (string) $field['field_type'];
        $value = $values[$code] ?? null;

        $delete->execute([':report_id' => $reportId, ':field_id' => $fieldId]);

        if ($value === null || $value === '' || $value === []) {
            continue;
        }

        $rows = [];

        if ($type === 'checkbox') {
            foreach ((array) $value as $optionId) {
                $rows[] = [(int) $optionId, null, null];
            }
        } elseif ($type === 'select' || $type === 'radio') {
            $rows[] = [(int) $value, null, null];
        } elseif ($type === 'number') {
            $rows[] = [null, null, (float) $value];
        } else {
            $rows[] = [null, (string) $value, null];
        }

        foreach ($rows as $row) {
            $insert->execute([
                ':report_id'    => $reportId,
                ':field_id'     => $fieldId,
                ':option_id'    => $row[0],
                ':value_text'   => $row[1],
                ':value_number' => $row[2],
                ':created_at'   => $now,
                ':updated_at'   => $now,
            ]);
        }
    }
}

/**
 * レポートの追加項目の値を削除する（レポート削除時。D36）
 */
function deleteReportFieldValues(PDO $pdo, int $reportId): void
{
    if (!reportValueTableExists($pdo)) {
        return;
    }

    $stmt = $pdo->prepare('DELETE FROM report_field_values WHERE report_id = :report_id');
    $stmt->execute([':report_id' => $reportId]);
}


// ==================================================
// 一覧・検索
// ==================================================

/**
 * キーワード検索の条件を作る
 *
 * 検索対象の項目（is_searchable = 1）のうち、既存の列は LIKE、
 * 追加項目は値テーブルを EXISTS で探す。
 *
 * @param string $reportAlias reports テーブルの別名（例: 'r'）。別名なしは ''
 * @param array<string, mixed> $params SQL のパラメータ（:keyword を追加する）
 * @return string 'AND (...)' の形の SQL。検索対象がない場合は 'AND 1 = 0'
 */
function buildReportKeywordCondition(array $fields, string $keyword, string $reportAlias, array &$params): string
{
    $prefix = $reportAlias === '' ? '' : $reportAlias . '.';
    $conditions = [];
    $addedFieldIds = [];

    foreach ($fields as $code => $field) {
        if ((int) $field['is_searchable'] !== 1) {
            continue;
        }

        if (!in_array($field['field_type'], REPORT_FIELD_TEXT_TYPES, true)) {
            continue;
        }

        if (isReportColumnField($code)) {
            // 列名は REPORT_COLUMN_FIELDS に含まれるものだけを埋め込む
            $conditions[] = $prefix . $code . ' LIKE :keyword';
        } elseif ((int) $field['id'] > 0) {
            $addedFieldIds[] = (int) $field['id'];
        }
    }

    if (count($addedFieldIds) > 0) {
        $placeholders = [];

        foreach ($addedFieldIds as $index => $fieldId) {
            $placeholders[] = ':kw_field_' . $index;
            $params[':kw_field_' . $index] = $fieldId;
        }

        $conditions[] = 'EXISTS (
            SELECT 1 FROM report_field_values v
            WHERE v.report_id = ' . $prefix . 'id
              AND v.field_id IN (' . implode(', ', $placeholders) . ')
              AND v.value_text LIKE :keyword
        )';
    }

    if (count($conditions) === 0) {
        return ' AND 1 = 0 ';
    }

    $params[':keyword'] = '%' . $keyword . '%';

    return ' AND (' . implode(' OR ', $conditions) . ') ';
}


// ==================================================
// 未使用の判定（D33）
// ==================================================

/**
 * 項目が使われているか（値かコメントが 1 件でもあれば使用中）
 *
 * 固定項目は常に使用中として扱う（削除不可）。
 */
function isReportFieldUsed(PDO $pdo, array $field): bool
{
    if ((int) $field['is_system'] === 1) {
        return true;
    }

    $code = (string) $field['code'];

    // コメント
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM comments WHERE field_name = :code');
    $stmt->execute([':code' => $code]);

    if ((int) $stmt->fetchColumn() > 0) {
        return true;
    }

    // 既存の列（内容・課題・備考など）
    if (isReportColumnField($code)) {
        // 列名は REPORT_COLUMN_FIELDS に含まれるものだけを埋め込む
        $count = $pdo->query(
            'SELECT COUNT(*) FROM reports WHERE ' . $code . " IS NOT NULL AND TRIM(" . $code . ") <> ''"
        )->fetchColumn();

        return (int) $count > 0;
    }

    // 追加項目
    if (!reportValueTableExists($pdo)) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM report_field_values WHERE field_id = :field_id');
    $stmt->execute([':field_id' => (int) $field['id']]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * 選択肢が使われているか（その選択肢を選んだ値が 1 件でもあれば使用中）
 */
function isReportFieldOptionUsed(PDO $pdo, int $optionId): bool
{
    if (!reportValueTableExists($pdo)) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM report_field_values WHERE option_id = :option_id');
    $stmt->execute([':option_id' => $optionId]);

    return (int) $stmt->fetchColumn() > 0;
}
