<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>画面操作説明 | レポート管理システム</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 16px;
            background-color: #f3f4f6;
            color: #333;
            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                "Noto Sans JP",
                sans-serif;
            line-height: 1.8;
        }

        .manual-container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .manual-header {
            margin-bottom: 24px;
            padding: 30px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .manual-header h1 {
            margin: 0 0 10px;
            color: #1f2937;
            font-size: 28px;
        }

        .manual-header p {
            margin: 0;
            color: #6b7280;
        }

        .manual-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
        }

        .button {
            display: inline-block;
            padding: 10px 18px;
            border: 1px solid #2563eb;
            border-radius: 5px;
            background-color: #2563eb;
            color: #fff;
            font-size: 14px;
            text-decoration: none;
            text-align: center;
            transition: background-color 0.2s;
        }

        .button:hover {
            background-color: #1d4ed8;
        }

        .button-secondary {
            background-color: #fff;
            color: #2563eb;
        }

        .button-secondary:hover {
            background-color: #eff6ff;
            color: #1d4ed8;
        }

        .manual-layout {
            display: grid;
            grid-template-columns: 240px minmax(0, 1fr);
            gap: 24px;
            align-items: start;
        }

        .manual-toc {
            position: sticky;
            top: 20px;
            padding: 22px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .manual-toc h2 {
            margin: 0 0 12px;
            font-size: 18px;
        }

        .manual-toc ul {
            margin: 0;
            padding-left: 20px;
        }

        .manual-toc li {
            margin-bottom: 8px;
        }

        .manual-toc a {
            color: #2563eb;
            font-size: 14px;
            text-decoration: none;
        }

        .manual-toc a:hover {
            text-decoration: underline;
        }

        .manual-content {
            min-width: 0;
        }

        .manual-section {
            margin-bottom: 24px;
            padding: 26px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            scroll-margin-top: 20px;
        }

        .manual-section h2 {
            margin: 0 0 18px;
            padding-bottom: 10px;
            border-bottom: 2px solid #2563eb;
            color: #1f2937;
            font-size: 22px;
        }

        .manual-section h3 {
            margin: 22px 0 8px;
            color: #374151;
            font-size: 17px;
        }

        .manual-section p {
            margin: 8px 0;
        }

        .manual-section ul,
        .manual-section ol {
            padding-left: 24px;
        }

        .manual-section li {
            margin-bottom: 6px;
        }

        .note {
            margin: 16px 0;
            padding: 14px 16px;
            border-left: 4px solid #2563eb;
            border-radius: 4px;
            background-color: #eff6ff;
        }

        .warning {
            margin: 16px 0;
            padding: 14px 16px;
            border-left: 4px solid #d97706;
            border-radius: 4px;
            background-color: #fffbeb;
        }

        .step-number {
            display: inline-block;
            min-width: 28px;
            margin-right: 6px;
            padding: 2px 7px;
            border-radius: 4px;
            background-color: #dbeafe;
            color: #1d4ed8;
            font-weight: bold;
            text-align: center;
        }

        .field-list {
            width: 100%;
            margin: 14px 0;
            border-collapse: collapse;
        }

        .field-list th,
        .field-list td {
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            text-align: left;
            vertical-align: top;
        }

        .field-list th {
            background-color: #f3f4f6;
            font-weight: bold;
        }

        .field-list th:first-child,
        .field-list td:first-child {
            width: 30%;
        }

        .manual-footer {
            padding: 20px;
            color: #6b7280;
            font-size: 13px;
            text-align: center;
        }

        a:focus-visible {
            outline: 2px solid #2563eb;
            outline-offset: 3px;
        }

        @media (max-width: 760px) {
            body {
                padding: 16px 10px;
            }

            .manual-header {
                padding: 22px;
            }

            .manual-header h1 {
                font-size: 23px;
            }

            .manual-layout {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .manual-toc {
                position: static;
            }

            .manual-section {
                padding: 20px;
            }

            .manual-section h2 {
                font-size: 20px;
            }

            .field-list th,
            .field-list td {
                padding: 8px;
                font-size: 13px;
            }
        }
    </style>
</head>

<body>

<div class="manual-container">

    <!-- ページ上部 -->
    <header class="manual-header">
        <h1>レポート管理システム</h1>

        <p>
            画面操作説明です。各機能の操作方法を説明します。
        </p>

        <div class="manual-actions">
            <a href="login.php" class="button">
                ログイン画面へ戻る
            </a>
        </div>
    </header>

    <div class="manual-layout">

        <!-- 目次 -->
        <nav class="manual-toc" aria-label="操作説明の目次">
            <h2>目次</h2>

            <ul>
                <li>
                    <a href="#overview">システムの概要</a>
                </li>
                <li>
                    <a href="#login">ログイン</a>
                </li>
                <li>
                    <a href="#list">レポート一覧</a>
                </li>
                <li>
                    <a href="#search">レポートの検索</a>
                </li>
                <li>
                    <a href="#create">レポートの新規登録</a>
                </li>
                <li>
                    <a href="#detail">レポートの詳細表示</a>
                </li>
                <li>
                    <a href="#edit">レポートの編集</a>
                </li>
                <li>
                    <a href="#comments">コメント機能</a>
                </li>
                <li>
                    <a href="#logout">ログアウト</a>
                </li>
                <li>
                    <a href="#trouble">困ったときは</a>
                </li>
            </ul>
        </nav>

        <!-- 操作説明 -->
        <main class="manual-content">

            <!-- システム概要 -->
            <section class="manual-section" id="overview">
                <h2>1. システムの概要</h2>

                <p>
                    レポート管理システムは、レポートの登録・閲覧・編集や、
                    レポートの各項目に対するコメントの管理を行うためのシステムです。
                </p>

                <p>主な機能は次のとおりです。</p>

                <ul>
                    <li>ユーザー認証によるログイン</li>
                    <li>レポートの一覧表示</li>
                    <li>登録済みレポートの検索</li>
                    <li>レポートの新規登録</li>
                    <li>レポートの詳細表示</li>
                    <li>レポートの編集</li>
                    <li>レポートの各項目に対するコメントの登録・編集・削除</li>
                </ul>

                <div class="note">
                    <strong>このマニュアルについて</strong><br>
                    画面の配置やボタン名は、システムの更新によって変更される場合があります。
                    実際の画面に表示されている項目やボタンに従って操作してください。
                </div>
            </section>

            <!-- ログイン -->
            <section class="manual-section" id="login">
                <h2>2. ログイン</h2>

                <p>
                    システムを利用するには、ログイン画面でユーザー名とパスワードを入力します。
                </p>

                <h3>操作手順</h3>

                <p>
                    <span class="step-number">1</span>
                    ログイン画面を開きます。
                </p>

                <p>
                    <span class="step-number">2</span>
                    「ユーザー名」欄にユーザー名を入力します。
                </p>

                <p>
                    <span class="step-number">3</span>
                    「パスワード」欄にパスワードを入力します。
                </p>

                <p>
                    <span class="step-number">4</span>
                    「ログイン」ボタンをクリックします。
                </p>

                <p>
                    認証に成功すると、レポート一覧画面が表示されます。
                </p>

                <div class="warning">
                    ユーザー名またはパスワードが正しくない場合は、
                    入力内容を確認してください。
                </div>
            </section>

            <!-- レポート一覧 -->
            <section class="manual-section" id="list">
                <h2>3. レポート一覧</h2>

                <p>
                    ログイン後は、登録されているレポートを一覧で確認できます。
                    一覧画面から、レポートの検索・詳細表示・編集などを行います。
                </p>

                <h3>一覧画面でできること</h3>

                <ul>
                    <li>登録済みレポートの確認</li>
                    <li>レポートの検索</li>
                    <li>レポートの詳細画面への移動</li>
                    <li>レポートの編集画面への移動</li>
                    <li>新しいレポートの登録画面への移動</li>
                </ul>

                <div class="note">
                    詳細表示や編集を行う場合は、対象となるレポートの行にある
                    リンクやボタンを使用してください。
                </div>
            </section>

            <!-- 検索 -->
            <section class="manual-section" id="search">
                <h2>4. レポートの検索</h2>

                <p>
                    レポート一覧画面では、検索条件を指定して目的のレポートを探せます。
                </p>

                <table class="field-list">
                    <thead>
                        <tr>
                            <th>検索項目</th>
                            <th>説明</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>名前</td>
                            <td>
                                名前に関する条件を指定してレポートを検索します。
                            </td>
                        </tr>
                        <tr>
                            <td>キーワード</td>
                            <td>
                                件名、内容、課題、備考などに含まれる文字列を検索します。
                            </td>
                        </tr>
                    </tbody>
                </table>

                <h3>操作手順</h3>

                <p>
                    <span class="step-number">1</span>
                    レポート一覧画面を開きます。
                </p>

                <p>
                    <span class="step-number">2</span>
                    検索したい名前やキーワードを入力します。
                </p>

                <p>
                    <span class="step-number">3</span>
                    画面に表示されている検索ボタンをクリックします。
                </p>

                <p>
                    条件に一致したレポートが一覧に表示されます。
                </p>

                <div class="note">
                    名前とキーワードを組み合わせて検索することもできます。
                    検索結果が表示されない場合は、入力した文字列を変更して再検索してください。
                </div>
            </section>

            <!-- 新規登録 -->
            <section class="manual-section" id="create">
                <h2>5. レポートの新規登録</h2>

                <p>
                    新しいレポートを登録する場合は、一覧画面から新規登録画面を開きます。
                </p>

                <h3>操作手順</h3>

                <p>
                    <span class="step-number">1</span>
                    レポート一覧画面で新規登録画面へのリンクやボタンをクリックします。
                </p>

                <p>
                    <span class="step-number">2</span>
                    登録画面に表示されている各項目に情報を入力します。
                </p>

                <p>
                    <span class="step-number">3</span>
                    入力内容を確認します。
                </p>

                <p>
                    <span class="step-number">4</span>
                    登録ボタンをクリックして保存します。
                </p>

                <p>
                    保存が完了したら、画面の表示に従って登録結果を確認してください。
                </p>

                <div class="warning">
                    登録ボタンを押す前に、入力内容に誤りがないか確認してください。
                    入力エラーが表示された場合は、メッセージに従って修正してください。
                </div>
            </section>

            <!-- 詳細表示 -->
            <section class="manual-section" id="detail">
                <h2>6. レポートの詳細表示</h2>

                <p>
                    レポート一覧から対象のレポートを選択すると、
                    登録されている情報を詳細画面で確認できます。
                </p>

                <h3>操作手順</h3>

                <p>
                    <span class="step-number">1</span>
                    レポート一覧画面で確認したいレポートを探します。
                </p>

                <p>
                    <span class="step-number">2</span>
                    対象レポートの「詳細」などのリンクをクリックします。
                </p>

                <p>
                    <span class="step-number">3</span>
                    レポートの内容を確認します。
                </p>

                <p>
                    詳細画面では、レポートの各項目と、それぞれに登録されたコメントを確認できます。
                </p>

                <div class="note">
                    コメントの表示方法については、
                    「8. コメント機能」を参照してください。
                </div>
            </section>

            <!-- 編集 -->
            <section class="manual-section" id="edit">
                <h2>7. レポートの編集</h2>

                <p>
                    登録済みレポートの内容を変更する場合は、編集画面を使用します。
                </p>

                <h3>操作手順</h3>

                <p>
                    <span class="step-number">1</span>
                    レポート一覧画面で対象のレポートを探します。
                </p>

                <p>
                    <span class="step-number">2</span>
                    対象レポートの「編集」などのリンクをクリックします。
                </p>

                <p>
                    <span class="step-number">3</span>
                    変更したい項目を修正します。
                </p>

                <p>
                    <span class="step-number">4</span>
                    保存ボタンをクリックして変更を保存します。
                </p>

                <p>
                    保存後、変更内容が反映されていることを確認してください。
                </p>

                <div class="warning">
                    保存する前に、変更内容が正しいことを確認してください。
                </div>
            </section>

            <!-- コメント機能 -->
            <section class="manual-section" id="comments">
                <h2>8. コメント機能</h2>

                <p>
                    コメント機能を使うと、レポートの各項目について、
                    補足説明や質問、確認事項などを記録できます。
                </p>

                <p>
                    Excelのメモのように、項目ごとにコメントを管理するための機能です。
                </p>

                <h3>8-1. コメントを確認する</h3>

                <p>
                    <span class="step-number">1</span>
                    レポートの詳細画面を開きます。
                </p>

                <p>
                    <span class="step-number">2</span>
                    コメントアイコンを確認します。
                </p>

                <p>
                    <span class="step-number">3</span>
                    確認したい項目のコメントアイコンをクリックします。
                </p>

                <p>
                    コメントが登録されている場合は、コメントの内容を確認できます。
                </p>

                <div class="note">
                    コメントの件数やアイコンの色によって、
                    コメントの有無を確認できる場合があります。
                    コメントが表示されない場合は、対象の項目やアイコンを確認してください。
                </div>

                <h3>8-2. コメントを登録する</h3>

                <p>
                    <span class="step-number">1</span>
                    詳細画面で、コメントを追加したい項目のコメント機能を開きます。
                </p>

                <p>
                    <span class="step-number">2</span>
                    コメント入力欄に質問や補足説明を入力します。
                </p>

                <p>
                    <span class="step-number">3</span>
                    画面に表示されている登録ボタンや保存ボタンをクリックします。
                </p>

                <p>
                    保存後、コメントが登録されていることを確認してください。
                </p>

                <h3>8-3. コメントを編集する</h3>

                <p>
                    <span class="step-number">1</span>
                    編集したいコメントを表示します。
                </p>

                <p>
                    <span class="step-number">2</span>
                    コメントの編集操作を選択します。
                </p>

                <p>
                    <span class="step-number">3</span>
                    コメントの内容を修正します。
                </p>

                <p>
                    <span class="step-number">4</span>
                    保存ボタンをクリックします。
                </p>

                <h3>8-4. コメントを削除する</h3>

                <p>
                    <span class="step-number">1</span>
                    削除したいコメントを表示します。
                </p>

                <p>
                    <span class="step-number">2</span>
                    コメントの削除操作を選択します。
                </p>

                <p>
                    <span class="step-number">3</span>
                    確認画面が表示された場合は、内容を確認して削除を実行します。
                </p>

                <div class="warning">
                    削除したコメントは、必要に応じて再登録する必要があります。
                    削除するコメントを間違えないように注意してください。
                </div>
            </section>

            <!-- ログアウト -->
            <section class="manual-section" id="logout">
                <h2>9. ログアウト</h2>

                <p>
                    システムの利用を終了する場合は、画面に表示されている
                    ログアウト操作を使用してください。
                </p>

                <h3>操作手順</h3>

                <p>
                    <span class="step-number">1</span>
                    利用中の画面でログアウトのリンクやボタンを探します。
                </p>

                <p>
                    <span class="step-number">2</span>
                    ログアウトを実行します。
                </p>

                <p>
                    ログイン画面が表示されたら、ログアウト完了です。
                </p>

                <div class="note">
                    共用のパソコンを使用している場合は、
                    利用終了時にログアウトしてください。
                </div>
            </section>

            <!-- トラブルシューティング -->
            <section class="manual-section" id="trouble">
                <h2>10. 困ったときは</h2>

                <h3>ログインできない</h3>

                <ul>
                    <li>ユーザー名に誤りがないか確認してください。</li>
                    <li>パスワードに誤りがないか確認してください。</li>
                    <li>入力欄に不要な空白が含まれていないか確認してください。</li>
                </ul>

                <h3>レポートが見つからない</h3>

                <ul>
                    <li>検索条件が正しいか確認してください。</li>
                    <li>キーワードを短くして再検索してください。</li>
                    <li>検索条件を空にして一覧を確認してください。</li>
                </ul>

                <h3>コメントが登録できない</h3>

                <ul>
                    <li>コメントの入力内容を確認してください。</li>
                    <li>画面にエラーメッセージが表示されていないか確認してください。</li>
                    <li>保存操作が完了しているか確認してください。</li>
                </ul>

                <h3>画面が正しく表示されない</h3>

                <ul>
                    <li>ブラウザの再読み込みを試してください。</li>
                    <li>再度ログインして操作してください。</li>
                    <li>問題が続く場合は、発生した画面と操作内容を記録して管理者に連絡してください。</li>
                </ul>
            </section>

            <!-- ページ下部 -->
            <div class="manual-actions">
                <a href="login.php" class="button">
                    ログイン画面へ戻る
                </a>

                <a href="#top" class="button button-secondary">
                    ページ上部へ戻る
                </a>
            </div>

        </main>
    </div>

    <footer class="manual-footer">
        レポート管理システム　画面操作説明
    </footer>

</div>

</body>
</html>
