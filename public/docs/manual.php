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
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 30px 16px; background: #f3f4f6; color: #333;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Noto Sans JP", sans-serif;
            line-height: 1.8;
        }
        .manual-container { max-width: 1000px; margin: 0 auto; }
        .manual-header, .manual-toc, .manual-section {
            background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }
        .manual-header { margin-bottom: 24px; padding: 30px; }
        .manual-header h1 { margin: 0 0 10px; color: #1f2937; font-size: 28px; }
        .manual-header p { margin: 0; color: #6b7280; }
        .manual-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 20px; }
        .button {
            display: inline-block; padding: 10px 18px; border: 1px solid #2563eb;
            border-radius: 5px; background: #2563eb; color: #fff; font-size: 14px;
            text-decoration: none; text-align: center;
        }
        .button:hover { background: #1d4ed8; }
        .button-secondary { background: #fff; color: #2563eb; }
        .button-secondary:hover { background: #eff6ff; color: #1d4ed8; }
        .manual-layout { display: grid; grid-template-columns: 240px minmax(0,1fr); gap: 24px; align-items: start; }
        .manual-toc { position: sticky; top: 20px; padding: 22px; }
        .manual-toc h2 { margin: 0 0 12px; font-size: 18px; }
        .manual-toc ul { margin: 0; padding-left: 20px; }
        .manual-toc li { margin-bottom: 8px; }
        .manual-toc a { color: #2563eb; font-size: 14px; text-decoration: none; }
        .manual-toc a:hover { text-decoration: underline; }
        .manual-content { min-width: 0; }
        .manual-section { margin-bottom: 24px; padding: 26px; scroll-margin-top: 20px; }
        .manual-section h2 { margin: 0 0 18px; padding-bottom: 10px; border-bottom: 2px solid #2563eb; color: #1f2937; font-size: 22px; }
        .manual-section h3 { margin: 22px 0 8px; color: #374151; font-size: 17px; }
        .manual-section p { margin: 8px 0; }
        .manual-section ul, .manual-section ol { padding-left: 24px; }
        .manual-section li { margin-bottom: 6px; }
        .note, .warning { margin: 16px 0; padding: 14px 16px; border-radius: 4px; }
        .note { border-left: 4px solid #2563eb; background: #eff6ff; }
        .warning { border-left: 4px solid #d97706; background: #fffbeb; }
        .step-number { display: inline-block; min-width: 28px; margin-right: 6px; padding: 2px 7px; border-radius: 4px; background: #dbeafe; color: #1d4ed8; font-weight: bold; text-align: center; }
        .field-list { width: 100%; margin: 14px 0; border-collapse: collapse; }
        .field-list th, .field-list td { padding: 10px 12px; border: 1px solid #d1d5db; text-align: left; vertical-align: top; }
        .field-list th { background: #f3f4f6; font-weight: bold; }
        .field-list th:first-child, .field-list td:first-child { width: 30%; }
        .manual-footer { padding: 20px; color: #6b7280; font-size: 13px; text-align: center; }
        a:focus-visible { outline: 2px solid #2563eb; outline-offset: 3px; }
        @media (max-width: 760px) {
            body { padding: 16px 10px; }
            .manual-header { padding: 22px; }
            .manual-header h1 { font-size: 23px; }
            .manual-layout { grid-template-columns: 1fr; gap: 16px; }
            .manual-toc { position: static; }
            .manual-section { padding: 20px; }
            .manual-section h2 { font-size: 20px; }
            .field-list th, .field-list td { padding: 8px; font-size: 13px; }
        }
    </style>
</head>
<body>
<div class="manual-container">
    <header class="manual-header">
        <h1>レポート管理システム</h1>
        <p>画面操作説明です。各機能の操作方法を説明します。</p>
        <div class="manual-actions">
            <a href="../login.php" class="button">ログイン画面へ戻る</a>
        </div>
    </header>

    <div class="manual-layout">
        <nav class="manual-toc" aria-label="操作説明の目次">
            <h2>目次</h2>
            <ul>
                <li><a href="#overview">システムの概要</a></li>
                <li><a href="#login">ログイン</a></li>
                <li><a href="#list">レポート一覧</a></li>
                <li><a href="#search">レポートの検索</a></li>
                <li><a href="#create">レポートの新規登録</a></li>
                <li><a href="#detail">レポートの詳細表示</a></li>
                <li><a href="#edit">レポートの編集</a></li>
                <li><a href="#comments">コメント機能</a></li>
                <li><a href="#users">ユーザー管理（管理者向け）</a></li>
                <li><a href="#logout">ログアウト</a></li>
                <li><a href="#trouble">困ったときは</a></li>
            </ul>
        </nav>

        <main class="manual-content">
            <section class="manual-section" id="overview">
                <h2>1. システムの概要</h2>
                <p>レポート管理システムは、レポートの登録・閲覧・編集や、各項目に対するコメントの管理を行うためのシステムです。</p>
                <p>主な機能は次のとおりです。</p>
                <ul>
                    <li>ユーザー認証によるログイン</li>
                    <li>レポートの一覧表示・検索</li>
                    <li>レポートの新規登録・詳細表示・編集</li>
                    <li>レポートの各項目に対するコメントの登録・編集・削除</li>
                    <li>管理者によるユーザーの登録・編集・削除</li>
                    <li>ユーザーの役職と上司・部下の関係に基づく閲覧制御</li>
                </ul>
                <div class="note"><strong>このマニュアルについて</strong><br>画面の配置やボタン名はシステムの更新によって変更される場合があります。実際の画面に表示されている項目やボタンに従って操作してください。</div>
            </section>

            <section class="manual-section" id="login">
                <h2>2. ログイン</h2>
                <p>システムを利用するには、ログイン画面でユーザー名とパスワードを入力します。</p>
                <ol>
                    <li>ログイン画面を開きます。</li>
                    <li>「ユーザー名」欄にユーザー名を入力します。</li>
                    <li>「パスワード」欄にパスワードを入力します。</li>
                    <li>「ログイン」ボタンをクリックします。</li>
                </ol>
                <p>認証に成功すると、レポート一覧画面が表示されます。</p>
                <div class="warning">ユーザー名またはパスワードが正しくない場合は、入力内容を確認してください。</div>
            </section>

            <section class="manual-section" id="list">
                <h2>3. レポート一覧</h2>
                <p>ログイン後は、閲覧権限のあるレポートが一覧に表示されます。一覧画面から検索や詳細表示などを行います。</p>
                <h3>一覧画面でできること</h3>
                <ul>
                    <li>閲覧権限のあるレポートの確認</li>
                    <li>レポートの検索</li>
                    <li>レポートの詳細画面への移動</li>
                    <li>自分が登録したレポートの編集</li>
                    <li>新しいレポートの登録</li>
                </ul>
                <div class="note">上司は、設定された上司・部下の関係に基づいて部下のレポートを閲覧できます。編集ボタンは権限に応じて表示されます。</div>
            </section>

            <section class="manual-section" id="search">
                <h2>4. レポートの検索</h2>
                <p>レポート一覧画面では、検索条件を指定して目的のレポートを探せます。</p>
                <table class="field-list">
                    <thead><tr><th>検索項目</th><th>説明</th></tr></thead>
                    <tbody>
                        <tr><td>名前</td><td>名前に関する条件を指定してレポートを検索します。</td></tr>
                        <tr><td>キーワード</td><td>件名、内容、課題、備考などに含まれる文字列を検索します。</td></tr>
                    </tbody>
                </table>
                <ol>
                    <li>レポート一覧画面を開きます。</li>
                    <li>検索したい名前やキーワードを入力します。</li>
                    <li>画面に表示されている検索ボタンをクリックします。</li>
                </ol>
                <p>条件に一致したレポートが一覧に表示されます。名前とキーワードを組み合わせて検索することもできます。</p>
            </section>

            <section class="manual-section" id="create">
                <h2>5. レポートの新規登録</h2>
                <ol>
                    <li>レポート一覧画面で「新規登録」ボタンをクリックします。</li>
                    <li>登録画面に表示されている各項目に情報を入力します。</li>
                    <li>入力内容を確認します。</li>
                    <li>登録ボタンをクリックして保存します。</li>
                </ol>
                <div class="warning">登録ボタンを押す前に、入力内容に誤りがないか確認してください。入力エラーが表示された場合は、メッセージに従って修正してください。</div>
            </section>

            <section class="manual-section" id="detail">
                <h2>6. レポートの詳細表示</h2>
                <ol>
                    <li>レポート一覧画面で確認したいレポートを探します。</li>
                    <li>対象レポートの詳細リンクやボタンをクリックします。</li>
                    <li>レポートの内容を確認します。</li>
                </ol>
                <p>詳細画面では、レポートの各項目と、それぞれに登録されたコメントを確認できます。</p>
                <div class="note">コメントの表示方法については、「8. コメント機能」を参照してください。</div>
            </section>

            <section class="manual-section" id="edit">
                <h2>7. レポートの編集</h2>
                <ol>
                    <li>レポート一覧画面で対象のレポートを探します。</li>
                    <li>対象レポートの「編集」ボタンをクリックします。</li>
                    <li>変更したい項目を修正します。</li>
                    <li>保存ボタンをクリックして変更を保存します。</li>
                </ol>
                <p>保存後、変更内容が反映されていることを確認してください。</p>
                <div class="note">レポートの編集は、自分が登録したレポートなど、編集権限のあるレポートに対して行えます。</div>
            </section>

            <section class="manual-section" id="comments">
                <h2>8. コメント機能</h2>
                <p>コメント機能を使うと、レポートの各項目について、補足説明や質問、確認事項などを記録できます。Excelのメモのように項目ごとにコメントを管理するための機能です。</p>
                <h3>8-1. コメントを確認する</h3>
                <ol>
                    <li>レポートの詳細画面を開きます。</li>
                    <li>確認したい項目のコメントアイコンをクリックします。</li>
                    <li>表示されたコメントの内容を確認します。</li>
                </ol>
                <div class="note">コメントの件数やアイコンの色で、コメントの有無を確認できる場合があります。</div>
                <h3>8-2. コメントを登録する</h3>
                <ol>
                    <li>詳細画面で、コメントを追加したい項目のコメント機能を開きます。</li>
                    <li>コメント入力欄に質問や補足説明を入力します。</li>
                    <li>登録ボタンまたは保存ボタンをクリックします。</li>
                    <li>コメントが登録されていることを確認します。</li>
                </ol>
                <h3>8-3. コメントを編集する</h3>
                <ol>
                    <li>編集したいコメントを表示します。</li>
                    <li>コメントの編集操作を選択します。</li>
                    <li>コメントの内容を修正し、保存します。</li>
                </ol>
                <h3>8-4. コメントを削除する</h3>
                <ol>
                    <li>削除したいコメントを表示します。</li>
                    <li>コメントの削除操作を選択します。</li>
                    <li>確認画面が表示された場合は、内容を確認して削除します。</li>
                </ol>
                <div class="warning">原則として、自分が登録したコメントを編集・削除できます。管理者には他のユーザーのコメントを管理する権限があります。削除したコメントは必要に応じて再登録してください。</div>
            </section>

            <section class="manual-section" id="users">
                <h2>9. ユーザー管理（管理者向け）</h2>
                <p>ユーザー管理機能では、システムを利用するユーザーの登録・編集・削除、および役職と上司の設定を行います。この機能は管理者のみ利用できます。</p>
                <div class="note">一般ユーザーには「ユーザー管理」ボタンが表示されません。一般ユーザーがURLを直接入力してユーザー管理画面を開こうとしても、アクセスは拒否されます。</div>
                <h3>9-1. ユーザー一覧を開く</h3>
                <ol>
                    <li>管理者アカウントでログインします。</li>
                    <li>レポート一覧画面の「ユーザー管理」ボタンをクリックします。</li>
                    <li>ユーザー一覧で、ユーザー名・役職・上司・管理者権限などを確認します。</li>
                </ol>
                <h3>9-2. ユーザーを新規登録する</h3>
                <ol>
                    <li>ユーザー一覧画面で「新規登録」ボタンをクリックします。</li>
                    <li>ユーザー名と初期パスワードを入力します。</li>
                    <li>役職を選択します。</li>
                    <li>必要に応じて上司を選択します。</li>
                    <li>管理者権限を付与する場合のみ、管理者の設定を有効にします。</li>
                    <li>登録ボタンをクリックします。</li>
                </ol>
                <table class="field-list">
                    <thead><tr><th>項目</th><th>説明</th></tr></thead>
                    <tbody>
                        <tr><td>ユーザー名</td><td>必須項目です。他のユーザーと重複しない名前を入力してください。漢字・ひらがななどの日本語も利用できます。</td></tr>
                        <tr><td>初期パスワード</td><td>必須項目です。画面に表示される条件を満たすパスワードを設定してください。</td></tr>
                        <tr><td>役職</td><td>一般、主任、課長代理、課長、部長から選択します。</td></tr>
                        <tr><td>上司</td><td>組織上の上司を選択します。上司を設定しない場合は未設定にします。</td></tr>
                        <tr><td>管理者権限</td><td>システム管理を行うユーザーにのみ付与してください。</td></tr>
                    </tbody>
                </table>
                <h3>9-3. ユーザー情報を編集する</h3>
                <ol>
                    <li>ユーザー一覧で対象ユーザーの「編集」ボタンをクリックします。</li>
                    <li>ユーザー名、役職、上司、管理者権限などを必要に応じて変更します。</li>
                    <li>パスワードを変更する場合は、新しいパスワードを入力します。変更しない場合はパスワード欄を空欄のままにします。</li>
                    <li>更新ボタンをクリックします。</li>
                </ol>
                <div class="note">上司を変更する際は、組織上の関係を確認してください。自分自身や自分の配下のユーザーを上司に設定することはできません。</div>
                <h3>9-4. ユーザーを削除する</h3>
                <ol>
                    <li>ユーザー一覧で削除対象のユーザーを確認します。</li>
                    <li>削除ボタンが表示されている場合は、対象ユーザーを確認して削除操作を行います。</li>
                    <li>削除処理の結果を画面で確認します。</li>
                </ol>
                <div class="warning">次のユーザーは削除できないよう制限されています。
                    <ul>
                        <li>ログイン中の管理者自身</li>
                        <li>日報が登録されているユーザー</li>
                        <li>コメントが登録されているユーザー</li>
                        <li>部下の上司として設定されているユーザー</li>
                        <li>最後の管理者</li>
                    </ul>
                    削除ボタンが表示されない場合や削除が拒否された場合は、該当ユーザーのデータや上司設定を確認してください。
                </div>
                <h3>9-5. 役職・上司の設定と閲覧範囲</h3>
                <p>上司・部下の関係は、レポートの閲覧範囲に関係します。ユーザーを登録・編集する際は、実際の組織構成に合わせて上司を設定してください。誤った設定は、レポートを閲覧できる範囲に影響する場合があります。</p>
                <div class="warning">管理者権限は、ユーザー管理やデータへのアクセス範囲に影響する重要な設定です。必要なユーザーにだけ付与してください。</div>
            </section>

            <section class="manual-section" id="logout">
                <h2>10. ログアウト</h2>
                <ol>
                    <li>利用中の画面でログアウトのリンクやボタンを探します。</li>
                    <li>ログアウトを実行します。</li>
                    <li>ログイン画面が表示されたら、ログアウト完了です。</li>
                </ol>
                <div class="note">共用のパソコンを使用している場合は、利用終了時にログアウトしてください。</div>
            </section>

            <section class="manual-section" id="trouble">
                <h2>11. 困ったときは</h2>
                <h3>ログインできない</h3>
                <ul>
                    <li>ユーザー名とパスワードに誤りがないか確認してください。</li>
                    <li>入力欄に不要な空白が含まれていないか確認してください。</li>
                </ul>
                <h3>レポートが見つからない</h3>
                <ul>
                    <li>検索条件が正しいか確認してください。</li>
                    <li>キーワードを短くして再検索してください。</li>
                    <li>閲覧権限の範囲外のレポートではないか確認してください。</li>
                </ul>
                <h3>コメントが登録できない</h3>
                <ul>
                    <li>コメントの入力内容を確認してください。</li>
                    <li>エラーメッセージが表示されていないか確認してください。</li>
                    <li>保存操作が完了しているか確認してください。</li>
                </ul>
                <h3>ユーザーを削除できない</h3>
                <ul>
                    <li>対象ユーザーに日報やコメントが登録されていないか確認してください。</li>
                    <li>対象ユーザーが他のユーザーの上司に設定されていないか確認してください。</li>
                    <li>ログイン中の管理者自身や最後の管理者を削除しようとしていないか確認してください。</li>
                </ul>
                <h3>画面が正しく表示されない</h3>
                <ul>
                    <li>ブラウザの再読み込みを試してください。</li>
                    <li>再度ログインして操作してください。</li>
                    <li>問題が続く場合は、発生した画面と操作内容を記録して管理者に連絡してください。</li>
                </ul>
            </section>
        </main>
    </div>
    <footer class="manual-footer">
        レポート管理システム　画面操作説明
    </footer>
</div>
</body>
</html>
