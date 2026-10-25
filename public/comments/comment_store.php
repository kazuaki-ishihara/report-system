<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';

// ========================================
// 1. ログインチェック
// ========================================

requireLogin();

// ログインユーザー情報を取得
$loginUser = getLoginUser($pdo);

if ($loginUser === null) {
    header('Location: ../login.php');
    exit;
}

$loginUserId = (int) $loginUser['id'];
$isAdmin = (int) $loginUser['is_admin'] === 1;

// ========================================
// 2. POSTリクエストの確認
// ========================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

// ========================================
// 3. 入力値の取得
// ========================================

// 配列などの不正な入力を文字列として扱わない
$getPostString = static function (string $key): string {
    $value = $_POST[$key] ?? '';

    return is_string($value) ? $value : '';
};

$reportIdValue = filter_input(
    INPUT_POST,
    'report_id',
    FILTER_VALIDATE_INT
);

$fieldName = $getPostString('field_name');
$commentText = trim($getPostString('comment'));

// ========================================
// 4. 入力チェック
// ========================================

// コメントを登録できる項目
$allowedFields = [
    'report_date',
    'name',
    'subject',
    'content',
    'issues',
    'remarks',
];

// レポートIDの確認
if (
    $reportIdValue === false
    || $reportIdValue === null
    || $reportIdValue <= 0
) {
    http_response_code(400);
    exit('レポートIDが正しくありません。');
}

$reportId = (int) $reportIdValue;

// 項目名の確認
if (!in_array($fieldName, $allowedFields, true)) {
    http_response_code(400);
    exit('コメント対象の項目が正しくありません。');
}

// コメントの空チェック
if ($commentText === '') {
    http_response_code(400);
    exit('コメントを入力してください。');
}

// mbstringが利用できない環境を考慮し、バイト数で制限
if (strlen($commentText) > 15000) {
    http_response_code(400);
    exit('コメントは15000バイト以内で入力してください。');
}

// ========================================
// 5. 対象レポートの取得
// ========================================

$stmt = $pdo->prepare(
    'SELECT id, user_id
     FROM reports
     WHERE id = :id'
);

$stmt->execute([
    ':id' => $reportId,
]);

$report = $stmt->fetch(PDO::FETCH_ASSOC);

if ($report === false) {
    http_response_code(404);
    exit('対象のレポートが見つかりません。');
}

// ========================================
// 6. レポートの参照権限チェック
// ========================================

// 管理者以外は、自分と配下のユーザーのレポートだけ登録可能
if (!$isAdmin) {

    $stmt = $pdo->prepare(
        'WITH RECURSIVE subordinate_users(id) AS (
            SELECT id
            FROM users
            WHERE id = :user_id

            UNION

            SELECT u.id
            FROM users u
            INNER JOIN subordinate_users s
                ON u.manager_id = s.id
        )
        SELECT id
        FROM subordinate_users'
    );

    $stmt->execute([
        ':user_id' => $loginUserId,
    ]);

    $allowedUserIds = array_map(
        'intval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );

    $reportUserId = (int) $report['user_id'];

    if (!in_array($reportUserId, $allowedUserIds, true)) {
        http_response_code(403);
        exit('このレポートにコメントを登録する権限がありません。');
    }
}

// ========================================
// 7. コメントを登録
// ========================================

$now = date('Y-m-d H:i:s');

$sql = '
    INSERT INTO comments (
        report_id,
        user_id,
        field_name,
        comment,
        created_at,
        updated_at
    )
    VALUES (
        :report_id,
        :user_id,
        :field_name,
        :comment,
        :created_at,
        :updated_at
    )
';

$stmt = $pdo->prepare($sql);

$stmt->execute([
    ':report_id'  => $reportId,
    ':user_id'    => $loginUserId,
    ':field_name' => $fieldName,
    ':comment'    => $commentText,
    ':created_at' => $now,
    ':updated_at' => $now,
]);

// ========================================
// 8. 登録後、詳細画面へ戻る
// ========================================

header(
    'Location: ../reports/detail.php?id=' . $reportId
    . '#comments-' . rawurlencode($fieldName)
);

exit;
