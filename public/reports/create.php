<?php

declare(strict_types=1);

require_once __DIR__ . '/../../lib/functions.php';
require_once __DIR__ . '/../../lib/auth.php';

// ========================================
// ログインチェック
// ========================================

// ログインしていない場合はログイン画面へ移動
requireLogin();

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

            <!-- ========================================
                 日付
            ========================================= -->

            <div class="form-group">

                <label for="report_date">
                    日付
                </label>

                <input
                    type="date"
                    id="report_date"
                    name="report_date"
                    value="<?= date('Y-m-d') ?>"
                    required
                >

            </div>


            <!-- ========================================
                 氏名
            ========================================= -->

            <div class="form-group">

                <label for="name">
                    氏名
                </label>

                <input
                    type="text"
                    id="name"
                    name="name"
                    maxlength="100"
                    required
                >

            </div>


            <!-- ========================================
                 件名
            ========================================= -->

            <div class="form-group">

                <label for="subject">
                    件名
                </label>

                <input
                    type="text"
                    id="subject"
                    name="subject"
                    maxlength="200"
                    required
                >

            </div>


            <!-- ========================================
                 内容
            ========================================= -->

            <div class="form-group">

                <label for="content">
                    内容
                </label>

                <textarea
                    id="content"
                    name="content"
                ></textarea>

            </div>


            <!-- ========================================
                 課題
            ========================================= -->

            <div class="form-group">

                <label for="issues">
                    課題
                </label>

                <textarea
                    id="issues"
                    name="issues"
                ></textarea>

            </div>


            <!-- ========================================
                 備考
            ========================================= -->

            <div class="form-group">

                <label for="remarks">
                    備考
                </label>

                <textarea
                    id="remarks"
                    name="remarks"
                ></textarea>

            </div>


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
