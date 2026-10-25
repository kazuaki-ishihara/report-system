<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';

// 管理者以外は利用不可
requireAdmin($pdo);
startSession();

/**
 * ユーザー一覧画面へメッセージを付けて戻る
 */
function redirectUserList(string $parameter, string $message): void
{
    header(
        'Location: users.php?'
        . $parameter
        . '='
        . rawurlencode($message)
    );
    exit;
}

// POSTリクエスト以外は拒否
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: users.php');
    exit;
}

// CSRFトークン検証
$sessionToken = $_SESSION['csrf_token'] ?? '';
$postToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($sessionToken)
    || !is_string($postToken)
    || $sessionToken === ''
    || !hash_equals($sessionToken, $postToken)
) {
    http_response_code(403);
    exit(
        '不正なリクエストです。'
        . 'ユーザー一覧画面を開き直して再度お試しください。'
    );
}

// ユーザーID検証
$idInput = $_POST['id'] ?? '';

if (
    !is_string($idInput)
    || !ctype_digit($idInput)
    || (int) $idInput <= 0
) {
    redirectUserList('error', 'ユーザーIDが正しくありません。');
}

$userId = (int) $idInput;

// ログイン中のユーザーID
$loginUserId = getLoginUserId();

// 自分自身の削除を禁止
if ($userId === $loginUserId) {
    redirectUserList(
        'error',
        'ログイン中のユーザー自身は削除できません。'
    );
}

try {
    $pdo->beginTransaction();

    // 削除対象ユーザーを取得
    $userStmt = $pdo->prepare(
        'SELECT id, username, is_admin
         FROM users
         WHERE id = :id'
    );

    $userStmt->execute([
        ':id' => $userId,
    ]);

    $user = $userStmt->fetch();

    if ($user === false) {
        $pdo->rollBack();

        redirectUserList(
            'error',
            '削除対象のユーザーが見つかりません。'
        );
    }

    // 報告書数を確認
    $reportStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM reports
         WHERE user_id = :user_id'
    );

    $reportStmt->execute([
        ':user_id' => $userId,
    ]);

    $reportCount = (int) $reportStmt->fetchColumn();

    if ($reportCount > 0) {
        $pdo->rollBack();

        redirectUserList(
            'error',
            'このユーザーには報告書が登録されているため削除できません。'
        );
    }

    // コメント数を確認
    $commentStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM comments
         WHERE user_id = :user_id'
    );

    $commentStmt->execute([
        ':user_id' => $userId,
    ]);

    $commentCount = (int) $commentStmt->fetchColumn();

    if ($commentCount > 0) {
        $pdo->rollBack();

        redirectUserList(
            'error',
            'このユーザーにはコメントが登録されているため削除できません。'
        );
    }

    // 他のユーザーの上司として設定されているか確認
    $subordinateStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM users
         WHERE manager_id = :manager_id'
    );

    $subordinateStmt->execute([
        ':manager_id' => $userId,
    ]);

    $subordinateCount = (int) $subordinateStmt->fetchColumn();

    if ($subordinateCount > 0) {
        $pdo->rollBack();

        redirectUserList(
            'error',
            'このユーザーを上司に設定しているユーザーがいるため削除できません。先に上司の設定を変更してください。'
        );
    }

    // 最後の管理者を削除することを禁止
    if ((int) $user['is_admin'] === 1) {
        $adminStmt = $pdo->query(
            'SELECT COUNT(*)
             FROM users
             WHERE is_admin = 1'
        );

        $adminCount = (int) $adminStmt->fetchColumn();

        if ($adminCount <= 1) {
            $pdo->rollBack();

            redirectUserList(
                'error',
                '最後の管理者は削除できません。'
            );
        }
    }

    // 削除直前に条件を確認したうえで削除
    $deleteStmt = $pdo->prepare(
        'DELETE FROM users
         WHERE id = :id'
    );

    $deleteStmt->execute([
        ':id' => $userId,
    ]);

    if ($deleteStmt->rowCount() !== 1) {
        $pdo->rollBack();

        redirectUserList(
            'error',
            'ユーザーを削除できませんでした。'
        );
    }

    $pdo->commit();

    redirectUserList(
        'message',
        'ユーザー「' . $user['username'] . '」を削除しました。'
    );

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // 詳細なDBエラーは画面に表示しない
    error_log('ユーザー削除エラー: ' . $e->getMessage());

    redirectUserList(
        'error',
        'ユーザーを削除できませんでした。データベースの制約などを確認してください。'
    );
}
