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

                    <td>

                        <a
                            href="edit.php?id=<?= h($report['id']) ?>"
                        >
                            編集
                        </a>


                        <form
                            action="delete.php"
                            method="post"
                            style="display:inline;"
                            onsubmit="return confirm('このレポートを削除しますか？');"
                        >

                            <input
                                type="hidden"
                                name="id"
                                value="<?= h($report['id']) ?>"
                            >

                            <button type="submit">
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

