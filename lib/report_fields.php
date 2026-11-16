<?php

declare(strict_types=1);

/**
 * レポート項目（項目定義・選択肢）の共通処理
 *
 * 項目の定義は report_fields、選択肢は report_field_options に保存する。
 * レポートの値をどこに保存するかは未決定（保留中）のため、
 * このファイルでは項目の「定義」だけを扱う。
 */

// ==================================================
// 定数
// ==================================================

/** 項目の種類（コード => 表示名） */
const REPORT_FIELD_TYPES = [
    'text'     => '1行テキスト',
    'textarea' => '複数行テキスト',
    'number'   => '数値',
    'date'     => '日付',
    'select'   => '選択肢（プルダウン）',
    'radio'    => '選択肢（ラジオボタン）',
    'checkbox' => '選択肢（チェックボックス・複数選択）',
];

/** 選択肢を持つ種類 */
const REPORT_FIELD_CHOICE_TYPES = ['select', 'radio', 'checkbox'];

/** 文字数を持つ種類 */
const REPORT_FIELD_TEXT_TYPES = ['text', 'textarea'];

/** 最大文字数の既定値（未設定のときに使う） */
const REPORT_FIELD_DEFAULT_MAX_LENGTH = [
    'text'     => 200,
    'textarea' => 10000,
];

/** 最大文字数として設定できる上限 */
const REPORT_FIELD_MAX_LENGTH_LIMIT = 100000;

/** 数値の最小・最大として設定できる範囲（絶対値） */
const REPORT_FIELD_NUMBER_LIMIT = 1000000000000;

/** 表示名・選択肢名の最大文字数 */
const REPORT_FIELD_LABEL_MAX_LENGTH = 100;

/** 表示順の間隔（追加時は最後の項目の値にこの数を足す） */
const REPORT_FIELD_SORT_STEP = 10;


// ==================================================
// 文字数
// ==================================================

/**
 * UTF-8の文字数を数える
 *
 * mbstring がない環境でも動くように正規表現で数える。
 * 不正なUTF-8の場合は -1 を返す。
 */
function countUtf8Chars(string $value): int
{
    if ($value === '') {
        return 0;
    }

    $count = preg_match_all('/./su', $value);

    return $count === false ? -1 : $count;
}


// ==================================================
// テーブルの有無
// ==================================================

/**
 * 項目定義のテーブルが作成済みか確認する
 */
function reportFieldTablesExist(PDO $pdo): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM sqlite_master
         WHERE type = 'table'
           AND name IN ('report_fields', 'report_field_options', 'report_field_values')"
    );
    $stmt->execute();

    return (int) $stmt->fetchColumn() === 3;
}

/**
 * 項目定義のテーブルがない場合はセットアップ画面へ移動する
 *
 * public/report_fields/ の画面から呼ぶ。
 */
function requireReportFieldTables(PDO $pdo): void
{
    if (!reportFieldTablesExist($pdo)) {
        header(
            'Location: setup.php?error=' .
            rawurlencode('項目管理を使う前に、セットアップを実行してください。')
        );
        exit;
    }
}


// ==================================================
// テーブル定義と初期データ（セットアップ画面で使う）
// ==================================================

/**
 * 項目定義のテーブルを作る SQL
 *
 * 何度実行しても同じ結果になるように IF NOT EXISTS を付ける。
 * 外部キーは PRAGMA foreign_keys が無効なため現状は効かない（既存の課題）。
 * 項目・選択肢は物理削除しない方針のため、整合性はアプリ側で守る。
 *
 * @return string[]
 */
function reportFieldSchemaSql(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS report_fields (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL,
            field_type TEXT NOT NULL
                CHECK (field_type IN ('text', 'textarea', 'number', 'date', 'select', 'radio', 'checkbox')),
            is_system INTEGER NOT NULL DEFAULT 0
                CHECK (is_system IN (0, 1)),
            is_active INTEGER NOT NULL DEFAULT 1
                CHECK (is_active IN (0, 1)),
            is_required INTEGER NOT NULL DEFAULT 0
                CHECK (is_required IN (0, 1)),
            show_in_list INTEGER NOT NULL DEFAULT 0
                CHECK (show_in_list IN (0, 1)),
            is_searchable INTEGER NOT NULL DEFAULT 0
                CHECK (is_searchable IN (0, 1)),
            sort_order INTEGER NOT NULL DEFAULT 0,
            max_length INTEGER,
            min_value REAL,
            max_value REAL,
            allow_decimal INTEGER NOT NULL DEFAULT 0
                CHECK (allow_decimal IN (0, 1)),
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            updated_by INTEGER,
            FOREIGN KEY (updated_by)
                REFERENCES users(id)
                ON DELETE SET NULL
        )",
        "CREATE INDEX IF NOT EXISTS idx_report_fields_order
            ON report_fields (sort_order, id)",
        "CREATE TABLE IF NOT EXISTS report_field_options (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            field_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1
                CHECK (is_active IN (0, 1)),
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            updated_by INTEGER,
            FOREIGN KEY (field_id)
                REFERENCES report_fields(id)
                ON DELETE RESTRICT,
            FOREIGN KEY (updated_by)
                REFERENCES users(id)
                ON DELETE SET NULL
        )",
        "CREATE INDEX IF NOT EXISTS idx_report_field_options_field_order
            ON report_field_options (field_id, sort_order, id)",

        // 追加した項目の値（D31：B2 併用）。既存 6 項目の値は reports の列のまま。
        // 未入力の項目は行を作らない。チェックボックスは選んだ選択肢の数だけ行を持つ（H7）。
        "CREATE TABLE IF NOT EXISTS report_field_values (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL,
            field_id INTEGER NOT NULL,
            option_id INTEGER,
            value_text TEXT,
            value_number REAL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (report_id)
                REFERENCES reports(id)
                ON DELETE CASCADE,
            FOREIGN KEY (field_id)
                REFERENCES report_fields(id)
                ON DELETE RESTRICT,
            FOREIGN KEY (option_id)
                REFERENCES report_field_options(id)
                ON DELETE RESTRICT
        )",
        "CREATE INDEX IF NOT EXISTS idx_report_field_values_report_field
            ON report_field_values (report_id, field_id)",
        "CREATE INDEX IF NOT EXISTS idx_report_field_values_field
            ON report_field_values (field_id)",
        "CREATE INDEX IF NOT EXISTS idx_report_field_values_option
            ON report_field_values (option_id)",
    ];
}

/**
 * 初期登録する項目（既存の reports テーブルの 6 列）
 *
 * code は既存の列名をそのまま使う。
 * 日付・氏名・件名は固定扱い（is_system = 1）で、表示名と並び順だけ変更できる。
 * 最大文字数は既存のバイト数の上限（氏名 300、件名 600）を 3 で割って文字数にした。
 *
 * @return array<int, array<string, mixed>>
 */
function reportFieldInitialRows(): array
{
    return [
        ['code' => 'report_date', 'label' => '日付', 'field_type' => 'date',     'is_system' => 1, 'is_required' => 1, 'show_in_list' => 1, 'is_searchable' => 0, 'max_length' => null],
        ['code' => 'name',        'label' => '氏名', 'field_type' => 'text',     'is_system' => 1, 'is_required' => 1, 'show_in_list' => 1, 'is_searchable' => 0, 'max_length' => 100],
        ['code' => 'subject',     'label' => '件名', 'field_type' => 'text',     'is_system' => 1, 'is_required' => 1, 'show_in_list' => 1, 'is_searchable' => 1, 'max_length' => 200],
        ['code' => 'content',     'label' => '内容', 'field_type' => 'textarea', 'is_system' => 0, 'is_required' => 0, 'show_in_list' => 0, 'is_searchable' => 1, 'max_length' => null],
        ['code' => 'issues',      'label' => '課題', 'field_type' => 'textarea', 'is_system' => 0, 'is_required' => 0, 'show_in_list' => 0, 'is_searchable' => 1, 'max_length' => null],
        ['code' => 'remarks',     'label' => '備考', 'field_type' => 'textarea', 'is_system' => 0, 'is_required' => 0, 'show_in_list' => 0, 'is_searchable' => 1, 'max_length' => null],
    ];
}


// ==================================================
// 取得
// ==================================================

/**
 * 項目を1件取得する。見つからない場合は null
 */
function findReportField(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM report_fields
         WHERE id = :id'
    );
    $stmt->execute([':id' => $id]);
    $field = $stmt->fetch(PDO::FETCH_ASSOC);

    return $field === false ? null : $field;
}

/**
 * 選択肢を1件取得する。見つからない場合は null
 */
function findReportFieldOption(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM report_field_options
         WHERE id = :id'
    );
    $stmt->execute([':id' => $id]);
    $option = $stmt->fetch(PDO::FETCH_ASSOC);

    return $option === false ? null : $option;
}

/**
 * 選択肢を持つ種類か
 */
function isChoiceFieldType(string $fieldType): bool
{
    return in_array($fieldType, REPORT_FIELD_CHOICE_TYPES, true);
}

/**
 * 種類の表示名
 */
function reportFieldTypeLabel(string $fieldType): string
{
    return REPORT_FIELD_TYPES[$fieldType] ?? '不明な種類';
}

/**
 * 入力チェックの内容を一覧表示用の文にする
 */
function reportFieldRuleSummary(array $field): string
{
    $type = (string) $field['field_type'];

    if (in_array($type, REPORT_FIELD_TEXT_TYPES, true)) {
        if ($field['max_length'] === null) {
            return REPORT_FIELD_DEFAULT_MAX_LENGTH[$type] . '文字まで（既定値）';
        }

        return (int) $field['max_length'] . '文字まで';
    }

    if ($type === 'number') {
        $parts = [];

        if ($field['min_value'] !== null) {
            $parts[] = formatReportFieldNumber($field['min_value']) . '以上';
        }

        if ($field['max_value'] !== null) {
            $parts[] = formatReportFieldNumber($field['max_value']) . '以下';
        }

        $parts[] = (int) $field['allow_decimal'] === 1 ? '小数可' : '整数のみ';

        return implode('、', $parts);
    }

    return '';
}

/**
 * 数値を画面表示用の文字にする（整数なら小数点を付けない）
 *
 * @param mixed $value
 */
function formatReportFieldNumber($value): string
{
    $number = (float) $value;

    if ($number == floor($number) && abs($number) < 1e15) {
        return number_format($number, 0, '.', '');
    }

    return rtrim(rtrim(sprintf('%.6F', $number), '0'), '.');
}


// ==================================================
// 入力値の取得・チェック（項目管理画面の POST）
// ==================================================

/**
 * POST の文字列を取得する（配列などは空文字として扱う）
 */
function postString(string $key): string
{
    $value = $_POST[$key] ?? '';

    return is_string($value) ? $value : '';
}

/**
 * POST のチェックボックス（値 1）を 0/1 で取得する
 */
function postFlag(string $key): int
{
    return postString($key) === '1' ? 1 : 0;
}

/**
 * POST の ID を取得する。不正な場合は 400 で終了する
 */
function postId(string $key): int
{
    $id = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);

    if ($id === false || $id === null || $id <= 0) {
        http_response_code(400);
        exit('IDが正しくありません。');
    }

    return $id;
}

/**
 * GET の ID を取得する。不正な場合は 400 で終了する
 */
function getId(string $key): int
{
    $id = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);

    if ($id === false || $id === null || $id <= 0) {
        http_response_code(400);
        exit('IDが正しくありません。');
    }

    return $id;
}

/**
 * 表示名・選択肢名をチェックする
 *
 * @return string|null エラーメッセージ。問題なければ null
 */
function validateReportFieldLabel(string $label, string $name): ?string
{
    if ($label === '') {
        return $name . 'を入力してください。';
    }

    $length = countUtf8Chars($label);

    if ($length < 0) {
        return $name . 'の文字が正しくありません。';
    }

    if ($length > REPORT_FIELD_LABEL_MAX_LENGTH) {
        return $name . 'は' . REPORT_FIELD_LABEL_MAX_LENGTH . '文字以内で入力してください。';
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
        return $name . 'に改行や制御文字は使えません。';
    }

    return null;
}

/**
 * 数値の入力欄を読み取る
 *
 * @return array{0: float|null, 1: string|null} [値, エラーメッセージ]
 */
function parseReportFieldNumberInput(string $input, string $name): array
{
    $input = trim($input);

    if ($input === '') {
        return [null, null];
    }

    if (preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/', $input) !== 1) {
        return [null, $name . 'は数値で入力してください。'];
    }

    $value = (float) $input;

    if (abs($value) > REPORT_FIELD_NUMBER_LIMIT) {
        return [null, $name . 'は±' . number_format(REPORT_FIELD_NUMBER_LIMIT) . 'の範囲で入力してください。'];
    }

    return [$value, null];
}

/**
 * 項目の追加・編集フォームの値を読み取ってチェックする
 *
 * 固定項目（is_system = 1）は表示名だけを受け付け、それ以外は今の値を使う。
 *
 * @param array|null $current 編集時は今の項目。追加時は null
 * @return array{0: array<string, mixed>, 1: string|null} [保存する値, エラーメッセージ]
 */
function readReportFieldForm(?array $current): array
{
    $label = trim(postString('label'));
    $error = validateReportFieldLabel($label, '表示名');

    if ($error !== null) {
        return [[], $error];
    }

    // 固定項目は表示名だけ変更できる
    if ($current !== null && (int) $current['is_system'] === 1) {
        return [
            [
                'label'         => $label,
                'field_type'    => $current['field_type'],
                'is_required'   => (int) $current['is_required'],
                'show_in_list'  => (int) $current['show_in_list'],
                'is_searchable' => (int) $current['is_searchable'],
                'max_length'    => $current['max_length'] === null ? null : (int) $current['max_length'],
                'min_value'     => $current['min_value'] === null ? null : (float) $current['min_value'],
                'max_value'     => $current['max_value'] === null ? null : (float) $current['max_value'],
                'allow_decimal' => (int) $current['allow_decimal'],
            ],
            null,
        ];
    }

    // 種類は追加時だけ選べる（編集では変更しない）
    if ($current === null) {
        $fieldType = postString('field_type');

        if (!array_key_exists($fieldType, REPORT_FIELD_TYPES)) {
            return [[], '項目の種類を選んでください。'];
        }
    } else {
        $fieldType = (string) $current['field_type'];
    }

    $values = [
        'label'         => $label,
        'field_type'    => $fieldType,
        'is_required'   => postFlag('is_required'),
        'show_in_list'  => postFlag('show_in_list'),
        'is_searchable' => 0,
        'max_length'    => null,
        'min_value'     => null,
        'max_value'     => null,
        'allow_decimal' => 0,
    ];

    // キーワード検索はテキストの項目だけ
    if (in_array($fieldType, REPORT_FIELD_TEXT_TYPES, true)) {
        $values['is_searchable'] = postFlag('is_searchable');

        $maxLengthInput = trim(postString('max_length'));

        if ($maxLengthInput !== '') {
            if (preg_match('/^[1-9][0-9]*$/', $maxLengthInput) !== 1) {
                return [[], '最大文字数は1以上の整数で入力してください。'];
            }

            $maxLength = (int) $maxLengthInput;

            if ($maxLength > REPORT_FIELD_MAX_LENGTH_LIMIT) {
                return [[], '最大文字数は' . REPORT_FIELD_MAX_LENGTH_LIMIT . '以下で入力してください。'];
            }

            $values['max_length'] = $maxLength;
        }
    }

    if ($fieldType === 'number') {
        $values['allow_decimal'] = postFlag('allow_decimal');

        list($minValue, $error) = parseReportFieldNumberInput(postString('min_value'), '最小値');

        if ($error !== null) {
            return [[], $error];
        }

        list($maxValue, $error) = parseReportFieldNumberInput(postString('max_value'), '最大値');

        if ($error !== null) {
            return [[], $error];
        }

        if ($values['allow_decimal'] === 0) {
            if (
                ($minValue !== null && $minValue != floor($minValue))
                || ($maxValue !== null && $maxValue != floor($maxValue))
            ) {
                return [[], '整数のみの項目では、最小値・最大値も整数で入力してください。'];
            }
        }

        if ($minValue !== null && $maxValue !== null && $minValue > $maxValue) {
            return [[], '最小値は最大値以下にしてください。'];
        }

        $values['min_value'] = $minValue;
        $values['max_value'] = $maxValue;
    }

    return [$values, null];
}


// ==================================================
// 並び順
// ==================================================

/**
 * 並び順を1つ上（$direction = 'up'）または下（'down'）に移動する
 *
 * 隣の行と sort_order を入れ替える。同じ値の行があっても崩れないよう、
 * 先に全体を 10 刻みで振り直してから入れ替える。
 * 呼び出し側でトランザクションを開始しておくこと。
 *
 * @param string $table 'report_fields' または 'report_field_options'
 * @param int|null $fieldId 選択肢のときは項目ID（その項目の選択肢だけを対象にする）
 * @return bool 移動した場合 true。端にあって移動できない場合 false
 */
function moveReportFieldSortOrder(
    PDO $pdo,
    string $table,
    int $id,
    string $direction,
    ?int $fieldId,
    int $userId,
    string $now
): bool {
    // テーブル名は SQL に埋め込むため、許可した名前だけを受け付ける
    if (!in_array($table, ['report_fields', 'report_field_options'], true)) {
        throw new InvalidArgumentException('対象のテーブルが正しくありません。');
    }

    if ($fieldId === null) {
        $stmt = $pdo->query(
            'SELECT id FROM ' . $table . ' ORDER BY sort_order ASC, id ASC'
        );
    } else {
        $stmt = $pdo->prepare(
            'SELECT id FROM ' . $table . ' WHERE field_id = :field_id ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':field_id' => $fieldId]);
    }

    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $index = array_search($id, $ids, true);

    if ($index === false) {
        return false;
    }

    $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;

    if ($swapIndex < 0 || $swapIndex >= count($ids)) {
        return false;
    }

    $ids[$index] = $ids[$swapIndex];
    $ids[$swapIndex] = $id;

    $update = $pdo->prepare(
        'UPDATE ' . $table . '
         SET sort_order = :sort_order
         WHERE id = :id'
    );

    foreach ($ids as $position => $rowId) {
        $update->execute([
            ':sort_order' => ($position + 1) * REPORT_FIELD_SORT_STEP,
            ':id'         => $rowId,
        ]);
    }

    // 動かした2行は最終更新者・更新日時を記録する
    $touch = $pdo->prepare(
        'UPDATE ' . $table . '
         SET updated_at = :updated_at, updated_by = :updated_by
         WHERE id IN (:id1, :id2)'
    );
    $touch->execute([
        ':updated_at' => $now,
        ':updated_by' => $userId,
        ':id1'        => $id,
        ':id2'        => $ids[$index],
    ]);

    return true;
}

/**
 * 次に追加する行の表示順を求める
 */
function nextReportFieldSortOrder(PDO $pdo, string $table, ?int $fieldId): int
{
    if (!in_array($table, ['report_fields', 'report_field_options'], true)) {
        throw new InvalidArgumentException('対象のテーブルが正しくありません。');
    }

    if ($fieldId === null) {
        $stmt = $pdo->query('SELECT MAX(sort_order) FROM ' . $table);
    } else {
        $stmt = $pdo->prepare('SELECT MAX(sort_order) FROM ' . $table . ' WHERE field_id = :field_id');
        $stmt->execute([':field_id' => $fieldId]);
    }

    return (int) $stmt->fetchColumn() + REPORT_FIELD_SORT_STEP;
}


// ==================================================
// 画面遷移
// ==================================================

/**
 * メッセージ付きで画面へ移動する（PRG パターン）
 *
 * @param string $kind 'message' または 'error'
 */
function redirectWithMessage(string $location, string $kind, string $message): void
{
    $separator = strpos($location, '?') === false ? '?' : '&';

    header(
        'Location: ' . $location . $separator .
        rawurlencode($kind) . '=' . rawurlencode($message)
    );
    exit;
}

/**
 * GET のメッセージを取得する
 *
 * @return array{0: string, 1: string} [message, error]
 */
function getFlashMessages(): array
{
    $message = $_GET['message'] ?? '';
    $error = $_GET['error'] ?? '';

    return [
        is_string($message) ? trim($message) : '',
        is_string($error) ? trim($error) : '',
    ];
}

/**
 * 管理画面の共通の前処理
 *
 * 管理者のみ利用可。ログインユーザーIDを返す。
 */
function requireReportFieldAdmin(PDO $pdo): int
{
    requireAdmin($pdo);

    $userId = getLoginUserId();

    if ($userId === null) {
        http_response_code(403);
        exit('この操作を実行する権限がありません。');
    }

    return $userId;
}
