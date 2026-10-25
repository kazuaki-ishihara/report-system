<?php

require_once '../config/database.php';
require_once '../lib/functions.php';
require_once '../lib/auth.php';

// ログイン必須
requireLogin();

// ログインユーザーを取得
$loginUser = getLoginUser($pdo);

if (!$loginUser) {
    header('Location: login.php');
    exit;
}

// ログインユーザー情報
$loginUserId = (int) $loginUser['id'];
$isAdmin = (int) $loginUser['is_admin'] === 1;

// 検索条件
$name = trim($_GET['name'] ?? '');
$keyword = trim($_GET['keyword'] ?? '');

// --------------------------------------------------
// 閲覧可能なユーザーIDを取得
//
// 管理者：すべてのレポートを閲覧可能
// 一般ユーザー：本人と配下のユーザーのレポートを閲覧可能
// --------------------------------------------------

$visibleUserIds = null;

if (!$isAdmin) {

    // 自分自身を閲覧対象に含める
    $visibleUserIds = [$loginUserId];

    // 配下のユーザーを再帰的に取得
    $sql = "
        WITH RECURSIVE subordinates(id) AS (

            SELECT id
            FROM users
            WHERE manager_id = :manager_id

            UNION ALL

            SELECT u.id
            FROM users u
            INNER JOIN subordinates s
                ON u.manager_id = s.id
        )

        SELECT id
        FROM subordinates
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':manager_id' => $loginUserId
    ]);

    $subordinateIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 取得した配下のユーザーIDを追加
    foreach ($subordinateIds as $subordinateId) {
        $visibleUserIds[] = (int) $subordinateId;
    }

    // 重複を除去
    $visibleUserIds = array_unique($visibleUserIds);
}

// --------------------------------------------------
// レポート一覧を取得
// --------------------------------------------------

$sql = "
    SELECT *
    FROM reports
    WHERE 1 = 1
";

$params = [];

// 氏名検索
if ($name !== '') {

    $sql .= "
        AND name LIKE :name
    ";

    $params[':name'] = '%' . $name . '%';
}

// キーワード検索
if ($keyword !== '') {

    $sql .= "
        AND (
            subject LIKE :keyword
            OR content LIKE :keyword
            OR issues LIKE :keyword
            OR remarks LIKE :keyword
        )
    ";

    $params[':keyword'] = '%' . $keyword . '%';
}

// 閲覧権限による絞り込み
if ($visibleUserIds !== null) {

    if (count($visibleUserIds) > 0) {

        $placeholders = [];

        foreach ($visibleUserIds as $index => $userId) {

            $placeholder = ':user_id_' . $index;

            $placeholders[] = $placeholder;

            $params[$placeholder] = (int) $userId;
        }

        $sql .= "
            AND user_id IN (
                " . implode(', ', $placeholders) . "
            )
        ";

    } else {

        // 閲覧対象が存在しない場合
        $sql .= "
            AND 1 = 0
        ";
    }
}

// 日付の降順。同じ日付の場合はIDの降順
$sql .= "
    ORDER BY report_date DESC, id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="ja">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>レポート一覧</title>

    <style>

        /* ----------------------------------------
           全体
        ---------------------------------------- */

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 20px;
            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
            background-color: #f5f7fa;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: #fff;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        h1 {
            margin-top: 0;
            margin-bottom: 25px;
            font-size: 26px;
        }

        /* ----------------------------------------
           ヘッダー
        ---------------------------------------- */

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .header h1 {
            margin: 0;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .login-user {
            font-size: 14px;
            color: #555;
        }

        /* ----------------------------------------
           共通ボタン
        ---------------------------------------- */

        .button {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            padding: 9px 14px;
            border: none;
            border-radius: 4px;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
            white-space: nowrap;
        }

        .new-button {
            background-color: #198754;
            color: #fff;
        }

        .new-button:hover {
            background-color: #146c43;
        }

        .logout-button {
            background-color: #6c757d;
            color: #fff;
        }

        .logout-button:hover {
            background-color: #565e64;
        }

        /* ----------------------------------------
           検索フォーム
        ---------------------------------------- */

        .search-container {
            margin-bottom: 25px;
            padding: 18px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
        }

        .search-form {
            display: flex;
            align-items: flex-end;
            gap: 15px;
            flex-wrap: wrap;
        }

        .search-item {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .search-item label {
            font-size: 14px;
            font-weight: bold;
        }

        .search-item input {
            width: 230px;
            max-width: 100%;
            padding: 9px 10px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 14px;
        }

        .search-button {
            background-color: #0d6efd;
            color: #fff;
        }

        .search-button:hover {
            background-color: #0b5ed7;
        }

        .clear-button {
            background-color: #6c757d;
            color: #fff;
        }

        .clear-button:hover {
            background-color: #565e64;
        }

        /* ----------------------------------------
           一覧情報
        ---------------------------------------- */

        .list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            gap: 10px;
            flex-wrap: wrap;
        }

        .report-count {
            font-size: 14px;
            color: #555;
        }

        /* ----------------------------------------
           テーブル
        ---------------------------------------- */

        .table-container {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background-color: #fff;
        }

        th,
        td {
            padding: 12px 10px;
            border: 1px solid #dee2e6;
            text-align: left;
            vertical-align: middle;
        }

        th {
            background-color: #e9ecef;
            font-weight: bold;
            white-space: nowrap;
        }

        tbody tr:hover {
            background-color: #f8f9fa;
        }

        .id-column {
            width: 60px;
            text-align: center;
        }

        .date-column {
            width: 120px;
            white-space: nowrap;
        }

        .name-column {
            min-width: 120px;
        }

        .subject-column {
            min-width: 200px;
        }

        .action-column {
            min-width: 250px;
            white-space: nowrap;
        }

        /* ----------------------------------------
           編集・コメント・削除ボタン
        ---------------------------------------- */

        .action-cell {
            display: flex;
            align-items: center;
            gap: 6px;
            min-height: 48px;
        }

        .action-button {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            min-height: 34px;
            padding: 7px 10px;
            border: none;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
            cursor: pointer;
            white-space: nowrap;
            flex-shrink: 0;
        }

        /*
         * 編集ボタンと空白の幅を統一する。
         * 編集ボタンが非表示の場合でも、
         * コメント・削除ボタンの位置を揃える。
         */
        .edit-button,
        .edit-button-space {
            width: 60px;
            flex: 0 0 60px;
        }

        .edit-button {
            background-color: #0d6efd;
            color: #fff;
        }

        .edit-button:hover {
            background-color: #0b5ed7;
        }

        .detail-button {
            min-width: 76px;
            background-color: #198754;
            color: #fff;
        }

        .detail-button:hover {
            background-color: #146c43;
        }

        .delete-form {
            display: inline-flex;
            align-items: center;
            margin: 0;
            padding: 0;
            flex-shrink: 0;
        }

        /* 削除ボタンと空白の幅を統一する */
        .delete-button,
        .delete-button-space {
            width: 60px;
            flex: 0 0 60px;
        }

        .delete-button {
            background-color: #dc3545;
            color: #fff;
        }

        .delete-button:hover {
            background-color: #bb2d3b;
        }

        /* 削除ボタンが非表示の場合も位置を確保する */
        .delete-button-space {
            visibility: hidden;
            pointer-events: none;
        }

        /* ----------------------------------------
           データなし
        ---------------------------------------- */

        .no-data {
            padding: 30px 15px;
            text-align: center;
            color: #666;
        }

        /* ----------------------------------------
           スマートフォン対応
        ---------------------------------------- */

        @media (max-width: 768px) {

            body {
                padding: 10px;
            }

            .container {
                padding: 15px;
            }

            .header {
                align-items: flex-start;
            }

            .header h1 {
                font-size: 22px;
            }

            .search-form {
                align-items: stretch;
                flex-direction: column;
            }

            .search-item input {
                width: 100%;
            }

            .search-item {
                width: 100%;
            }

            .search-form .button {
                align-self: flex-start;
            }

            th,
            td {
                padding: 9px 8px;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <!-- ヘッダー -->
    <div class="header">

        <h1>レポート一覧</h1>

        <div class="header-actions">

            <span class="login-user">
                <?= h($loginUser['username']) ?> さん
            </span>
<?php if ($isAdmin): ?>

<a href="users/users.php" class="button">
    ユーザー管理
</a>

<?php endif; ?>
            <a href="reports/create.php" class="button new-button">
                新規登録
            </a>

            <a href="logout.php" class="button logout-button">
                ログアウト
            </a>

        </div>

    </div>

    <!-- 検索フォーム -->
    <div class="search-container">

        <form method="get" action="index.php" class="search-form">

            <div class="search-item">

                <label for="name">
                    氏名
                </label>

                <input
                    type="text"
                    id="name"
                    name="name"
                    value="<?= h($name) ?>"
                    placeholder="氏名を入力"
                >

            </div>

            <div class="search-item">

                <label for="keyword">
                    キーワード
                </label>

                <input
                    type="text"
                    id="keyword"
                    name="keyword"
                    value="<?= h($keyword) ?>"
                    placeholder="件名・内容・課題・備考"
                >

            </div>

            <button type="submit" class="button search-button">
                検索
            </button>

            <a href="index.php" class="button clear-button">
                クリア
            </a>

        </form>

    </div>

    <!-- レポート件数 -->
    <div class="list-header">

        <div class="report-count">
            レポート件数：<?= count($reports) ?> 件
        </div>

    </div>

    <!-- レポート一覧 -->
    <div class="table-container">

        <table>

            <thead>

                <tr>

                    <th class="id-column">
                        ID
                    </th>

                    <th class="date-column">
                        日付
                    </th>

                    <th class="name-column">
                        氏名
                    </th>

                    <th class="subject-column">
                        件名
                    </th>

                    <th class="action-column">
                        操作
                    </th>

                </tr>

            </thead>

            <tbody>

                <?php if (empty($reports)): ?>

                    <tr>

                        <td colspan="5" class="no-data">
                            該当するレポートはありません。
                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($reports as $report): ?>

                        <?php
                        // 編集できるのは管理者または本人のレポート
                        $canEdit = $isAdmin
                            || (int) $report['user_id'] === $loginUserId;
                        ?>

                        <tr>

                            <td class="id-column">
                                <?= h($report['id']) ?>
                            </td>

                            <td class="date-column">
                                <?= h($report['report_date']) ?>
                            </td>

                            <td class="name-column">
                                <?= h($report['name']) ?>
                            </td>

                            <td class="subject-column">
                                <?= h($report['subject']) ?>
                            </td>

                            <td class="action-column">

                                <div class="action-cell">

                                    <!-- 編集ボタン -->
                                    <?php if ($canEdit): ?>

                                        <a
                                            href="reports/edit.php?id=<?= (int) $report['id'] ?>"
                                            class="action-button edit-button"
                                        >
                                            編集
                                        </a>

                                    <?php else: ?>

                                        <!--
                                            編集ボタンが非表示でも
                                            同じ幅の空白を確保する
                                        -->
                                        <span
                                            class="edit-button-space"
                                            aria-hidden="true"
                                        ></span>

                                    <?php endif; ?>

                                    <!-- コメントボタン -->
                                    <a
                                        href="reports/detail.php?id=<?= (int) $report['id'] ?>"
                                        class="action-button detail-button"
                                    >
                                        コメント
                                    </a>

                                    <!-- 削除ボタン -->
                                    <?php if ($canEdit): ?>

                                        <form
                                            method="post"
                                            action="reports/delete.php"
                                            class="delete-form"
                                            onsubmit="return confirm('このレポートを削除しますか？');"
                                        >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int) $report['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="action-button delete-button"
                                            >
                                                削除
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <!-- 削除ボタンが非表示でも同じ幅の空白を確保する -->
                                        <span
                                            class="delete-button-space"
                                            aria-hidden="true"
                                        ></span>

                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

</body>

</html>
