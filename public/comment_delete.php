<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/auth.php';

// ==================================================
// 1. ログイン確認
// ==================================================

requireLogin();

$loginUser = getLoginUser($pdo);

if ($loginUser === null) {
    header('Location: login.php');
    exit;
}

$loginUserId = (int) $loginUser['id'];
$isAdmin = (int) $loginUser['is_admin'] === 1;

// ==================================================
// 2. POSTリクエストの確認
// ==================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('許可されていないアクセスです。');
}

// ==================================================
// 3. コメントIDの取得・チェック
// ==================================================

$commentId = filter_input(
    INPUT_POST,
    'comment_id',
    FILTER_VALIDATE_INT
);

if (
    $commentId === false
    || $commentId === null
    || $commentId <= 0
) {
    http_response_code(400);
    exit('コメントIDが正しくありません。');
}

$commentId = (int) $commentId;

// ==================================================
// 4. コメントと対象レポートを取得
// ==================================================

$sql = '
    SELECT
        c.id,
        c.report_id,
        c.user_id AS comment_user_id,
        c.field_name,
        r.user_id AS report_user_id
    FROM comments c
    INNER JOIN reports r
        ON r.id = c.report_id
    WHERE c.id = :comment_id
';

$stmt = $pdo->prepare($sql);

$stmt->execute([
    ':comment_id' => $commentId,
]);

$comment = $stmt->fetch(PDO::FETCH_ASSOC);

if ($comment === false) {
    http_response_code(404);
    exit('指定されたコメントが見つかりません。');
}

$reportId = (int) $comment['report_id'];
$fieldName = $comment['field_name'];

// ==================================================
// 5. レポートの閲覧権限を確認
//
// システム管理者：すべてのレポート
// 一般ユーザー：自分と配下のユーザーのレポート
// ==================================================

if (!$isAdmin) {
    $sql = '
        WITH RECURSIVE subordinate_users(id) AS (
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
        FROM subordinate_users
    ';

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':user_id' => $loginUserId,
    ]);

    $allowedUserIds = array_map(
        'intval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );

    $reportUserId = (int) $comment['report_user_id'];

    if (!in_array($reportUserId, $allowedUserIds, true)) {
        http_response_code(403);
        exit('このレポートのコメントを削除する権限がありません。');
    }
}

// ==================================================
// 6. コメントの削除権限を確認
//
// システム管理者：すべてのコメント
// 一般ユーザー：自分が登録したコメントのみ
// ==================================================

$commentUserId = (int) $comment['comment_user_id'];

if (!$isAdmin && $commentUserId !== $loginUserId) {
    http_response_code(403);
    exit('このコメントを削除する権限がありません。');
}

// ==================================================
// 7. コメント対象の項目名を確認
// ==================================================

$allowedFields = [
    'report_date',
    'name',
    'subject',
    'content',
    'issues',
    'remarks',
];

if (!in_array($fieldName, $allowedFields, true)) {
    http_response_code(400);
    exit('コメント対象の項目が正しくありません。');
}

// ==================================================
// 8. コメントを削除
// ==================================================

$sql = '
    DELETE FROM comments
    WHERE id = :comment_id
';

$stmt = $pdo->prepare($sql);

$stmt->execute([
    ':comment_id' => $commentId,
]);

// ==================================================
// 9. 詳細画面に戻る
// ==================================================

header(
    'Location: detail.php?id='
    . $reportId
    . '#comments-'
    . rawurlencode($fieldName)
);

exit;
