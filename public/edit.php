<?php

require_once '../config/database.php';
require_once '../lib/functions.php';


// IDを取得
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    exit('不正なIDです。');
}


// レポートを取得
$stmt = $pdo->prepare("
    SELECT *
    FROM reports
    WHERE id = :id
");

$stmt->execute([
    ':id' => $id
]);

$report = $stmt->fetch();


// レポートが存在しない場合
if (!$report) {
    exit('指定されたレポートが見つかりません。');
}

?>

<!DOCTYPE html>
<html lang="ja">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>レポート編集</title>

<link rel="stylesheet" href="css/style.css">

</head>

<body>

<div class="container">

    <h1>レポート編集</h1>

    <div class="form-container">

        <form action="update.php" method="post">

            <input
                type="hidden"
                name="id"
                value="<?= h($report['id']) ?>"
            >


            <div class="form-group">

                <label for="report_date">
                    日付
                </label>

                <input
                    type="date"
                    id="report_date"
                    name="report_date"
                    value="<?= h($report['report_date']) ?>"
                    required
                >

            </div>


            <div class="form-group">

                <label for="name">
                    氏名
                </label>

                <input
                    type="text"
                    id="name"
                    name="name"
                    maxlength="100"
                    value="<?= h($report['name']) ?>"
                    required
                >

            </div>


            <div class="form-group">

                <label for="subject">
                    件名
                </label>

                <input
                    type="text"
                    id="subject"
                    name="subject"
                    maxlength="200"
                    value="<?= h($report['subject']) ?>"
                    required
                >

            </div>


            <div class="form-group">

                <label for="content">
                    内容
                </label>

                <textarea
                    id="content"
                    name="content"
                ><?= h($report['content']) ?></textarea>

            </div>


            <div class="form-group">

                <label for="issues">
                    課題
                </label>

                <textarea
                    id="issues"
                    name="issues"
                ><?= h($report['issues']) ?></textarea>

            </div>


            <div class="form-group">

                <label for="remarks">
                    備考
                </label>

                <textarea
                    id="remarks"
                    name="remarks"
                ><?= h($report['remarks']) ?></textarea>

            </div>


            <div class="buttons">

                <button type="submit">
                    更新
                </button>

                <a
                    href="index.php"
                    class="back-button"
                >
                    戻る
                </a>

            </div>

        </form>

    </div>

</div>

</body>

</html>

