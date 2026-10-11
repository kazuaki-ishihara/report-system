<?php

require_once '../config/database.php';
require_once '../lib/functions.php';


// 検索条件を取得
$name = trim($_GET['name'] ?? '');
$keyword = trim($_GET['keyword'] ?? '');


// SQL
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


// 並び順
$sql .= "
    ORDER BY report_date DESC, id DESC
";


// SQL実行
$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$reports = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="ja">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>レポート管理</title>

    <link rel="stylesheet" href="css/style.css">

    <style>
        /*
         * 操作列
         */
        .action-cell {
            white-space: nowrap;
        }

        /*
         * 操作ボタン共通
         */
        .action-button {
            display: inline-block;
            padding: 7px 14px;
            margin: 2px;
            border: 1px solid transparent;
            border-radius: 5px;
            font-size: 14px;
            font-family: inherit;
            line-height: 1.4;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
            box-sizing: border-box;
            transition:
                background-color 0.2s,
                transform 0.2s;
        }

        /*
         * 編集ボタン：青色
         */
        .edit-button {
            background-color: #1976d2;
            border-color: #1976d2;
            color: #fff;
        }

        .edit-button:hover {
            background-color: #1565c0;
            transform: translateY(-1px);
        }

        /*
         * 詳細ボタン：緑色
         */
        .detail-button {
            background-color: #2e7d32;
            border-color: #2e7d32;
            color: #fff;
        }

        .detail-button:hover {
            background-color: #1b5e20;
            transform: translateY(-1px);
        }

        /*
         * 削除ボタン：赤色の枠線
         */
        .delete-button {
            background-color: #fff;
            border-color: #d32f2f;
            color: #d32f2f;
        }

        .delete-button:hover {
            background-color: #ffebee;
            transform: translateY(-1px);
        }

        /*
         * 削除フォーム
         */
        .delete-form {
            display: inline;
            margin: 0;
        }

        /*
         * キーボード操作時のフォーカス
         */
        .action-button:focus-visible {
            outline: 2px solid #1976d2;
            outline-offset: 2px;
        }
    </style>

</head>

<body>

<div class="container">

    <h1>レポート管理</h1>


    <!-- 検索フォーム -->

    <div class="form-container">

        <form action="index.php" method="get">

            <div class="form-group">

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


            <div class="form-group">

                <label for="keyword">
                    キーワード
                </label>

                <input
                    type="text"
                    id="keyword"
                    name="keyword"
                    value="<?= h($keyword) ?>"
                    placeholder="件名・内容・課題・備考を検索"
                >

            </div>


            <button type="submit">
                検索
            </button>


            <a
                href="index.php"
                class="back-button"
            >
                クリア
            </a>

        </form>

    </div>


    <!-- 新規登録 -->

    <div class="toolbar">

        <a
            href="create.php"
            class="button"
        >
            新規レポート登録
        </a>

    </div>


    <!-- 検索結果 -->

    <?php if (count($reports) === 0): ?>

        <div class="empty">

            <?php if ($name !== '' || $keyword !== ''): ?>

                検索条件に一致するレポートはありません。

            <?php else: ?>

                レポートはまだ登録されていません。

            <?php endif; ?>

        </div>

    <?php else: ?>

        <p>
            <?= count($reports) ?>件のレポートがあります。
        </p>


        <table>

            <thead>

                <tr>

                    <th>ID</th>

                    <th>日付</th>

                    <th>氏名</th>

                    <th>件名</th>

                    <th>操作</th>

                </tr>

            </thead>


            <tbody>

            <?php foreach ($reports as $report): ?>

                <tr>

                    <td>
                        <?= h($report['id']) ?>
                    </td>

                    <td>
                        <?= h($report['report_date']) ?>
                    </td>

                    <td>
                        <?= h($report['name']) ?>
                    </td>

                    <td>
                        <?= h($report['subject']) ?>
                    </td>

                    <td class="action-cell">

                        <!-- 編集ボタン -->

                        <a
                            href="edit.php?id=<?= h($report['id']) ?>"
                            class="action-button edit-button"
                        >
                            編集
                        </a>


                        <!-- 詳細ボタン -->

                        <a
                            href="detail.php?id=<?= h($report['id']) ?>"
                            class="action-button detail-button"
                        >
                            詳細
                        </a>


                        <!-- 削除ボタン -->

                        <form
                            action="delete.php"
                            method="post"
                            class="delete-form"
                            onsubmit="return confirm('このレポートを削除しますか？');"
                        >

                            <input
                                type="hidden"
                                name="id"
                                value="<?= h($report['id']) ?>"
                            >

                            <button
                                type="submit"
                                class="action-button delete-button"
                            >
                                削除
                            </button>

                        </form>

                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    <?php endif; ?>

</div>

</body>

</html>
