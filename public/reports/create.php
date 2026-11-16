<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/report_values.php';

// ========================================
// ログインチェック
// ========================================

// ログインしていない場合はログイン画面へ移動
requireLogin();


// ========================================
// 入力欄（項目管理の定義から作る）
// ========================================

$fields = loadReportFields($pdo, true);

// 初期値：日付は今日
$formValues = [];

if (isset($fields['report_date'])) {
    $formValues['report_date'] = date('Y-m-d');
}

?>
<!DOCTYPE html>
<html lang="ja">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>レポート登録</title>

    <link rel="stylesheet" href="../css/style.css">

</head>

<body>

<div class="container">

    <h1>レポート登録</h1>

    <div class="form-container">

        <form action="store.php" method="post">

            <?php require __DIR__ . '/_fields.php'; ?>


            <!-- ========================================
                 ボタン
            ========================================= -->

            <div class="buttons">

                <button type="submit">
                    登録
                </button>

                <a
                    href="../index.php"
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
