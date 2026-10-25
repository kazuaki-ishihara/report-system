<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>システム設計書 | レポート管理システム</title>
    <style>
        :root {
            --primary: #245a83;
            --primary-dark: #173d5a;
            --accent: #e8f2f9;
            --border: #d5dee6;
            --text: #24313b;
            --muted: #5d6b75;
            --surface: #fff;
            --background: #f3f6f8;
            --warning-bg: #fff7e5;
            --warning-border: #e4b44d;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI",
                "Noto Sans JP", "Hiragino Kaku Gothic ProN",
                Meiryo, sans-serif;
            line-height: 1.75;
        }

        a {
            color: #155d91;
        }

        .page {
            max-width: 1180px;
            margin: 0 auto;
            padding: 28px 20px 56px;
        }

        .header {
            background: linear-gradient(
                135deg,
                var(--primary-dark),
                var(--primary)
            );
            color: #fff;
            border-radius: 12px;
            padding: 30px 34px;
            margin-bottom: 22px;
        }

        .header h1 {
            margin: 0 0 8px;
            font-size: 2rem;
        }

        .header p {
            margin: 0;
            opacity: .95;
        }

        .meta {
            margin-top: 14px;
            font-size: .9rem;
            opacity: .9;
        }

        .layout {
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
            gap: 22px;
            align-items: start;
        }

        .toc,
        .section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: 0 2px 7px rgba(20, 40, 55, .04);
        }

        .toc {
            padding: 18px;
            position: sticky;
            top: 16px;
        }

        .toc h2 {
            margin: 0 0 10px;
            font-size: 1.05rem;
        }

        .toc ol {
            margin: 0;
            padding-left: 22px;
        }

        .toc li {
            margin: 4px 0;
        }

        .toc a {
            text-decoration: none;
        }

        .toc a:hover {
            text-decoration: underline;
        }

        main {
            min-width: 0;
        }

        .section {
            padding: 24px 27px;
            margin-bottom: 18px;
            scroll-margin-top: 18px;
        }

        .section h2 {
            margin: 0 0 15px;
            padding-bottom: 9px;
            border-bottom: 2px solid var(--accent);
            color: var(--primary-dark);
            font-size: 1.4rem;
        }

        .section h3 {
            margin: 22px 0 8px;
            font-size: 1.08rem;
            color: var(--primary);
        }

        p {
            margin: 8px 0 12px;
        }

        ul,
        ol {
            padding-left: 25px;
        }

        li {
            margin: 4px 0;
        }

        code,
        pre {
            font-family: ui-monospace, SFMono-Regular, Consolas,
                "Liberation Mono", monospace;
        }

        code {
            background: #eef2f5;
            padding: 1px 5px;
            border-radius: 4px;
            overflow-wrap: anywhere;
        }

        pre {
            background: #f1f4f6;
            border: 1px solid var(--border);
            border-radius: 7px;
            padding: 14px;
            overflow-x: auto;
            font-size: .9rem;
            line-height: 1.6;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0 18px;
            font-size: .95rem;
        }

        th,
        td {
            border: 1px solid var(--border);
            padding: 9px 11px;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: var(--accent);
        }

        .note,
        .warning {
            padding: 12px 15px;
            margin: 14px 0;
            border-left: 4px solid;
            border-radius: 5px;
        }

        .note {
            background: #eef7fc;
            border-color: #4388b5;
        }

        .warning {
            background: var(--warning-bg);
            border-color: var(--warning-border);
        }

        .small {
            color: var(--muted);
            font-size: .9rem;
        }

        .back {
            display: inline-block;
            margin-top: 8px;
        }

        footer {
            color: var(--muted);
            font-size: .85rem;
            text-align: center;
            margin-top: 24px;
        }

        @media (max-width: 850px) {
            .layout {
                grid-template-columns: 1fr;
            }

            .toc {
                position: static;
            }

            .header {
                padding: 24px 22px;
            }

            .header h1 {
                font-size: 1.6rem;
            }

            .section {
                padding: 20px;
            }
        }

        @media print {
            body {
                background: #fff;
            }

            .page {
                max-width: none;
                padding: 0;
            }

            .header {
                background: #fff;
                color: #000;
                border: 1px solid #888;
            }

            .layout {
                display: block;
            }

            .toc {
                position: static;
                box-shadow: none;
                margin-bottom: 18px;
            }

            .section {
                box-shadow: none;
                break-inside: avoid;
            }

            a {
                color: #000;
                text-decoration: none;
            }
        }
    </style>
</head>

<body>
<div class="page">

    <header class="header">
        <h1>システム設計書</h1>
        <p>
            レポート管理システムの構成、機能、データベース、
            権限制御の設計を記載します。
        </p>
        <div class="meta">
            対象：PHP / SQLite レポート管理システム
            ｜ 更新日：2026年10月25日
        </div>
    </header>

    <div class="layout">

        <nav class="toc" aria-label="目次">
            <h2>目次</h2>
            <ol>
                <li><a href="#overview">システム概要</a></li>
                <li><a href="#environment">動作環境・技術構成</a></li>
                <li><a href="#structure">ディレクトリ・ファイル構成</a></li>
                <li><a href="#screens">画面・処理一覧</a></li>
                <li><a href="#features">機能設計</a></li>
                <li><a href="#permissions">認証・権限制御</a></li>
                <li><a href="#database">データベース設計</a></li>
                <li><a href="#relations">テーブル関連</a></li>
                <li><a href="#security">セキュリティ設計</a></li>
                <li><a href="#errors">エラー処理・運用</a></li>
                <li><a href="#maintenance">保守・今後の課題</a></li>
            </ol>
        </nav>

        <main>

            <!-- 1. システム概要 -->
            <section class="section" id="overview">
                <h2>1. システム概要</h2>

                <p>
                    本システムは、日報・業務レポートを登録、検索、閲覧、
                    編集、削除するためのWebアプリケーションです。
                    レポート内の各項目にコメントを付けることで、
                    内容の確認や質問を行えます。
                </p>

                <h3>主な目的</h3>
                <ul>
                    <li>レポート情報をデータベースで一元管理する。</li>
                    <li>レポートの項目単位でコメントを登録し、確認や質問を行う。</li>
                    <li>上司・部下の組織階層に基づいて閲覧範囲を制御する。</li>
                    <li>管理者がユーザーアカウントや役職、管理者権限を管理する。</li>
                    <li>利用者の権限に応じてレポートの操作範囲を制御する。</li>
                </ul>

                <div class="note">
                    本書はシステムの構成と設計方針を記載したものです。
                    実際のテーブル定義や処理の詳細は、稼働中のプログラムと
                    データベースの定義を正とします。
                </div>
            </section>

            <!-- 2. 動作環境 -->
            <section class="section" id="environment">
                <h2>2. 動作環境・技術構成</h2>

                <table>
                    <tbody>
                    <tr>
                        <th>アプリケーション</th>
                        <td>PHPによるサーバーサイドWebアプリケーション</td>
                    </tr>
                    <tr>
                        <th>データベース</th>
                        <td>SQLite（PDO経由で接続）</td>
                    </tr>
                    <tr>
                        <th>画面</th>
                        <td>HTML / CSS。フォーム送信を中心とした画面遷移</td>
                    </tr>
                    <tr>
                        <th>認証状態</th>
                        <td>PHPセッションを利用</td>
                    </tr>
                    <tr>
                        <th>データベース接続</th>
                        <td><code>config/database.php</code></td>
                    </tr>
                    <tr>
                        <th>認証・共通処理</th>
                        <td>
                            <code>lib/auth.php</code>、
                            <code>lib/functions.php</code>
                        </td>
                    </tr>
                    </tbody>
                </table>

                <h3>データベース</h3>
                <p>
                    データベースファイルには
                    <code>data/reports.sqlite</code> を使用します。
                    接続設定ではPDOを使用してSQLiteへ接続します。
                </p>

                <div class="warning">
                    SQLiteのデータベースファイルは、Webから直接取得できる
                    公開ディレクトリの外に配置してください。
                </div>
            </section>

            <!-- 3. ディレクトリ構成 -->
            <section class="section" id="structure">
                <h2>3. ディレクトリ・ファイル構成</h2>

                <pre>report-system/
├── config/
│   └── database.php
├── data/
│   └── reports.sqlite
├── lib/
│   ├── auth.php
│   └── functions.php
├── public/
│   ├── index.php
│   ├── login.php
│   ├── logout.php
│   ├── setup.php
│   ├── css/
│   │   └── style.css
│   ├── reports/
│   │   ├── create.php
│   │   ├── store.php
│   │   ├── detail.php
│   │   ├── edit.php
│   │   ├── update.php
│   │   └── delete.php
│   ├── comments/
│   │   ├── comment_store.php
│   │   ├── comment_edit.php
│   │   ├── comment_update.php
│   │   └── comment_delete.php
│   ├── users/
│   │   ├── users.php
│   │   ├── user_create.php
│   │   ├── user_store.php
│   │   ├── user_edit.php
│   │   ├── user_update.php
│   │   └── user_delete.php
│   └── docs/
│       ├── manual.php
│       └── design.php
└── setup_admin.php</pre>

                <p class="small">
                    ※主要ファイルを記載しています。
                    補助ファイルや実際の配置が異なる場合は、
                    リポジトリの内容に合わせて更新してください。
                </p>

                <table>
                    <thead>
                    <tr>
                        <th>ファイル・領域</th>
                        <th>責務</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td><code>config/database.php</code></td>
                        <td>SQLiteへのPDO接続を初期化する。</td>
                    </tr>
                    <tr>
                        <td><code>lib/auth.php</code></td>
                        <td>
                            セッション管理、ログインユーザー取得、
                            認証・管理者権限の判定を共通化する。
                        </td>
                    </tr>
                    <tr>
                        <td><code>lib/functions.php</code></td>
                        <td>HTMLエスケープなどの共通関数を提供する。</td>
                    </tr>
                    <tr>
                        <td><code>public/index.php</code></td>
                        <td>
                            レポート一覧、検索、詳細・編集画面への導線を表示する。
                        </td>
                    </tr>
                    <tr>
                        <td><code>public/reports/</code></td>
                        <td>
                            レポートの登録、保存、詳細表示、編集、更新、削除を担当する。
                        </td>
                    </tr>
                    <tr>
                        <td><code>public/comments/</code></td>
                        <td>
                            コメントの登録、編集、更新、削除を担当する。
                        </td>
                    </tr>
                    <tr>
                        <td><code>public/users/</code></td>
                        <td>
                            ユーザー一覧、登録、編集、更新、削除を担当する。
                        </td>
                    </tr>
                    <tr>
                        <td><code>public/docs/manual.php</code></td>
                        <td>利用者向けの画面操作説明を表示する。</td>
                    </tr>
                    <tr>
                        <td><code>public/docs/design.php</code></td>
                        <td>システム設計書を表示する。</td>
                    </tr>
                    <tr>
                        <td><code>public/login.php</code></td>
                        <td>ログイン画面と認証処理を提供する。</td>
                    </tr>
                    <tr>
                        <td><code>public/logout.php</code></td>
                        <td>ログアウト処理を行う。</td>
                    </tr>
                    <tr>
                        <td><code>public/setup.php</code></td>
                        <td>
                            セットアップ関連の処理を担当する。
                            実際の用途と運用方法を確認して使用する。
                        </td>
                    </tr>
                    <tr>
                        <td><code>setup_admin.php</code></td>
                        <td>
                            管理者アカウントの初期設定に使用する補助ファイル。
                        </td>
                    </tr>
                    </tbody>
                </table>
            </section>

            <!-- 4. 画面・処理一覧 -->
            <section class="section" id="screens">
                <h2>4. 画面・処理一覧</h2>

                <table>
                    <thead>
                    <tr>
                        <th>画面・処理</th>
                        <th>概要</th>
                        <th>アクセス制御</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td><code>login.php</code></td>
                        <td>
                            ユーザー名とパスワードでログインする。
                            操作マニュアルと設計書へのリンクを提供する。
                        </td>
                        <td>未ログイン利用可</td>
                    </tr>
                    <tr>
                        <td><code>index.php</code></td>
                        <td>
                            閲覧可能なレポートを一覧表示し、
                            氏名やキーワードなどで検索する。
                        </td>
                        <td>ログイン必須</td>
                    </tr>
                    <tr>
                        <td><code>reports/create.php</code></td>
                        <td>レポート登録フォームを表示する。</td>
                        <td>ログイン必須</td>
                    </tr>
                    <tr>
                        <td><code>reports/store.php</code></td>
                        <td>レポートを登録する。</td>
                        <td>ログイン必須</td>
                    </tr>
                    <tr>
                        <td><code>reports/detail.php</code></td>
                        <td>
                            レポートの詳細と項目別コメントを表示する。
                        </td>
                        <td>対象レポートの閲覧権限が必要</td>
                    </tr>
                    <tr>
                        <td>
                            <code>reports/edit.php</code><br>
                            <code>reports/update.php</code>
                        </td>
                        <td>レポートの編集画面と更新処理。</td>
                        <td>編集権限を確認</td>
                    </tr>
                    <tr>
                        <td><code>reports/delete.php</code></td>
                        <td>レポートを削除する。</td>
                        <td>削除権限を確認</td>
                    </tr>
                    <tr>
                        <td><code>comments/comment_store.php</code></td>
                        <td>項目別コメントを登録する。</td>
                        <td>ログイン必須。対象レポートの権限を確認</td>
                    </tr>
                    <tr>
                        <td><code>comments/comment_edit.php</code></td>
                        <td>コメント編集画面を表示する。</td>
                        <td>コメント投稿者または管理者</td>
                    </tr>
                    <tr>
                        <td><code>comments/comment_update.php</code></td>
                        <td>コメントを更新する。</td>
                        <td>コメント投稿者または管理者</td>
                    </tr>
                    <tr>
                        <td><code>comments/comment_delete.php</code></td>
                        <td>コメントを削除する。</td>
                        <td>コメント投稿者または管理者</td>
                    </tr>
                    <tr>
                        <td><code>users/users.php</code></td>
                        <td>ユーザー一覧とユーザー情報を表示する。</td>
                        <td>管理者のみ</td>
                    </tr>
                    <tr>
                        <td><code>users/user_create.php</code><br>
                            <code>users/user_store.php</code></td>
                        <td>ユーザーを新規登録する。</td>
                        <td>管理者のみ</td>
                    </tr>
                    <tr>
                        <td><code>users/user_edit.php</code><br>
                            <code>users/user_update.php</code></td>
                        <td>ユーザー情報を編集・更新する。</td>
                        <td>管理者のみ</td>
                    </tr>
                    <tr>
                        <td><code>users/user_delete.php</code></td>
                        <td>ユーザーを削除する。</td>
                        <td>管理者のみ</td>
                    </tr>
                    <tr>
                        <td><code>docs/manual.php</code></td>
                        <td>画面操作説明を表示する。</td>
                        <td>公開範囲は実装に従う</td>
                    </tr>
                    <tr>
                        <td><code>docs/design.php</code></td>
                        <td>システム設計書を表示する。</td>
                        <td>公開範囲は実装に従う</td>
                    </tr>
                    </tbody>
                </table>
            </section>

            <!-- 5. 機能設計 -->
            <section class="section" id="features">
                <h2>5. 機能設計</h2>

                <h3>5.1 レポート管理</h3>
                <ul>
                    <li>ログインユーザーがレポートを登録する。</li>
                    <li>レポートには作成者のユーザーIDを関連付ける。</li>
                    <li>一覧画面では閲覧権限のあるレポートを表示する。</li>
                    <li>
                        氏名やキーワードで検索する。
                        キーワード検索は件名、内容、課題、備考などを対象とする。
                    </li>
                    <li>
                        レポートの編集・削除時には、
                        サーバー側でも対象データへの権限を確認する。
                    </li>
                </ul>

                <h3>5.2 項目別コメント</h3>
                <ul>
                    <li>コメントは対象レポートと入力項目名に関連付ける。</li>
                    <li>レポート詳細画面から項目別のコメントを確認できる。</li>
                    <li>コメント登録時には投稿者のユーザーIDを保存する。</li>
                    <li>
                        コメントの編集・削除は原則として投稿者本人に限定する。
                    </li>
                    <li>管理者は他ユーザーが登録したコメントも編集・削除できる。</li>
                    <li>コメントの件数やアイコン表示によって、コメントの有無を把握できる。</li>
                </ul>

                <h3>5.3 ユーザー管理</h3>
                <ul>
                    <li>管理者がユーザーアカウントを作成・編集・削除する。</li>
                    <li>ユーザーには役職と上司を設定できる。</li>
                    <li>管理者権限は役職とは別のフラグで管理する。</li>
                    <li>パスワードはハッシュ化して保存する。</li>
                    <li>
                        上司を設定することで組織階層を表現し、
                        レポート閲覧範囲の判定に利用する。
                    </li>
                    <li>
                        上司・部下の循環関係が発生しないよう、
                        上司の設定・変更時に妥当性を確認する。
                    </li>
                    <li>
                        ユーザー削除時には、管理者アカウントや
                        関連データの状態など、実装で定めた条件を確認する。
                    </li>
                </ul>
            </section>

            <!-- 6. 認証・権限制御 -->
            <section class="section" id="permissions">
                <h2>6. 認証・権限制御</h2>

                <h3>6.1 認証</h3>
                <ul>
                    <li>ログイン成功後、セッションにログイン状態を保持する。</li>
                    <li>
                        保護対象の画面・処理では共通認証処理を利用する。
                    </li>
                    <li>未ログインの場合はログイン画面へ誘導する。</li>
                    <li>ログアウト時にはセッションを終了する。</li>
                </ul>

                <h3>6.2 役職</h3>
                <table>
                    <thead>
                    <tr>
                        <th>役職</th>
                        <th>組織上の位置づけ</th>
                        <th>制御の考え方</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td>一般</td>
                        <td>一般利用者</td>
                        <td>本人のレポートを中心に利用する。</td>
                    </tr>
                    <tr>
                        <td>主任</td>
                        <td>一般利用者の上位</td>
                        <td>上司・部下の関係に応じて閲覧範囲を判定する。</td>
                    </tr>
                    <tr>
                        <td>課長代理</td>
                        <td>主任と課長の間</td>
                        <td>上司・部下の関係に応じて閲覧範囲を判定する。</td>
                    </tr>
                    <tr>
                        <td>課長</td>
                        <td>管理職</td>
                        <td>管理者フラグの有無で管理機能の可否を判定する。</td>
                    </tr>
                    <tr>
                        <td>部長</td>
                        <td>課長の上位</td>
                        <td>上司・部下の関係に応じて閲覧範囲を判定する。</td>
                    </tr>
                    </tbody>
                </table>

                <div class="note">
                    役職名と管理者権限は別の概念です。
                    管理機能へのアクセスは、役職名だけでなく
                    <code>is_admin</code> の値に基づいて判定します。
                </div>

                <h3>6.3 レポートの閲覧範囲</h3>
                <ul>
                    <li>一般ユーザーは自分のレポートを閲覧する。</li>
                    <li>管理者は全ユーザーのレポートを閲覧できる。</li>
                    <li>
                        管理者以外は、<code>users.manager_id</code> による
                        組織階層をたどって配下ユーザーのレポートを閲覧できる。
                    </li>
                    <li>
                        直接の部下だけでなく、下位階層も閲覧対象として扱う。
                    </li>
                    <li>
                        レポートの編集・削除権限は、
                        閲覧権限とは別に確認する。
                    </li>
                    <li>
                        画面上のボタンを非表示にするだけでなく、
                        サーバー側の処理でも権限を確認する。
                    </li>
                </ul>

                <h3>6.4 コメント操作の権限</h3>
                <ul>
                    <li>閲覧権限のあるレポートに対してコメントを操作する。</li>
                    <li>コメントの編集・削除は原則として投稿者本人に限定する。</li>
                    <li>管理者は他ユーザーのコメントを編集・削除できる。</li>
                </ul>

                <div class="warning">
                    URLを直接入力した場合でも、
                    権限のないデータを参照・更新・削除できないよう、
                    サーバー側でアクセス権を検証する必要があります。
                </div>
            </section>

            <!-- 7. データベース設計 -->
            <section class="section" id="database">
                <h2>7. データベース設計</h2>

                <p>
                    主な業務データは
                    <code>users</code>、<code>reports</code>、
                    <code>comments</code> の3テーブルで管理します。
                </p>

                <h3>7.1 users（ユーザー）</h3>
                <table>
                    <thead>
                    <tr>
                        <th>カラム</th>
                        <th>役割</th>
                        <th>備考</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td><code>id</code></td>
                        <td>ユーザーID</td>
                        <td>主キー</td>
                    </tr>
                    <tr>
                        <td><code>username</code></td>
                        <td>ログイン名</td>
                        <td>ユーザー識別に使用</td>
                    </tr>
                    <tr>
                        <td><code>password_hash</code></td>
                        <td>パスワードハッシュ</td>
                        <td>平文パスワードは保存しない</td>
                    </tr>
                    <tr>
                        <td><code>role_title</code></td>
                        <td>役職</td>
                        <td>一般、主任、課長代理、課長、部長など</td>
                    </tr>
                    <tr>
                        <td><code>manager_id</code></td>
                        <td>上司ユーザーID</td>
                        <td><code>users.id</code> への自己参照</td>
                    </tr>
                    <tr>
                        <td><code>is_admin</code></td>
                        <td>管理者フラグ</td>
                        <td>管理機能の認可に利用</td>
                    </tr>
                    <tr>
                        <td><code>created_at</code></td>
                        <td>作成日時</td>
                        <td>登録時刻</td>
                    </tr>
                    </tbody>
                </table>

                <h3>7.2 reports（レポート）</h3>
                <table>
                    <thead>
                    <tr>
                        <th>カラム</th>
                        <th>役割</th>
                        <th>備考</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td><code>id</code></td>
                        <td>レポートID</td>
                        <td>主キー</td>
                    </tr>
                    <tr>
                        <td><code>user_id</code></td>
                        <td>作成ユーザーID</td>
                        <td><code>users.id</code> を参照</td>
                    </tr>
                    <tr>
                        <td><code>report_date</code></td>
                        <td>レポート日付</td>
                        <td>対象日</td>
                    </tr>
                    <tr>
                        <td><code>name</code></td>
                        <td>氏名等の表示項目</td>
                        <td>一覧表示・検索などに利用</td>
                    </tr>
                    <tr>
                        <td><code>subject</code></td>
                        <td>件名</td>
                        <td>検索対象</td>
                    </tr>
                    <tr>
                        <td><code>content</code></td>
                        <td>内容</td>
                        <td>レポート本文</td>
                    </tr>
                    <tr>
                        <td><code>issues</code></td>
                        <td>課題</td>
                        <td>検索対象</td>
                    </tr>
                    <tr>
                        <td><code>remarks</code></td>
                        <td>備考</td>
                        <td>検索対象</td>
                    </tr>
                    <tr>
                        <td><code>created_at</code></td>
                        <td>作成日時</td>
                        <td>登録時刻</td>
                    </tr>
                    <tr>
                        <td><code>updated_at</code></td>
                        <td>更新日時</td>
                        <td>更新時刻</td>
                    </tr>
                    </tbody>
                </table>

                <h3>7.3 comments（コメント）</h3>
                <table>
                    <thead>
                    <tr>
                        <th>カラム</th>
                        <th>役割</th>
                        <th>備考</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td><code>id</code></td>
                        <td>コメントID</td>
                        <td>主キー</td>
                    </tr>
                    <tr>
                        <td><code>report_id</code></td>
                        <td>対象レポートID</td>
                        <td><code>reports.id</code> を参照</td>
                    </tr>
                    <tr>
                        <td><code>user_id</code></td>
                        <td>コメント投稿者ID</td>
                        <td><code>users.id</code> を参照</td>
                    </tr>
                    <tr>
                        <td><code>field_name</code></td>
                        <td>対象項目名</td>
                        <td>コメント対象のレポート項目</td>
                    </tr>
                    <tr>
                        <td><code>comment</code></td>
                        <td>コメント本文</td>
                        <td>質問・確認事項など</td>
                    </tr>
                    <tr>
                        <td><code>created_at</code></td>
                        <td>作成日時</td>
                        <td>登録時刻</td>
                    </tr>
                    <tr>
                        <td><code>updated_at</code></td>
                        <td>更新日時</td>
                        <td>更新時刻</td>
                    </tr>
                    </tbody>
                </table>

                <h3>7.4 インデックス</h3>
                <p>
                    <code>idx_comments_report_field</code> は、
                    コメントの <code>report_id</code> と
                    <code>field_name</code> を使った検索を支援します。
                </p>

                <div class="note">
                    上記は主要カラムの概要です。
                    実際のカラム定義、NOT NULL、UNIQUE、CHECK、
                    外部キー制約については、稼働中データベースの
                    スキーマ定義を正としてください。
                </div>
            </section>

            <!-- 8. テーブル関連 -->
            <section class="section" id="relations">
                <h2>8. テーブル関連</h2>

                <pre>users (1) ───────── (多) reports
  │                       │
  │                       └──── (1) ───── (多) comments
  │                                         │
  └────────────────────── (1) ─────────────┘

users.manager_id ── users.id
（上司・部下の自己参照）</pre>

                <ul>
                    <li>1人のユーザーは複数のレポートを登録できる。</li>
                    <li>1つのレポートは複数のコメントを持つことができる。</li>
                    <li>1人のユーザーは複数のコメントを投稿できる。</li>
                    <li>
                        ユーザー同士は <code>manager_id</code> によって
                        上司・部下の階層を表す。
                    </li>
                </ul>

                <h3>削除時の参照整合性</h3>
                <ul>
                    <li>
                        レポートを削除すると、そのレポートに属するコメントも
                        削除される構成が想定される。
                    </li>
                    <li>
                        レポートやコメントから参照されているユーザーの削除は、
                        外部キー制約やアプリケーション側の条件に従う。
                    </li>
                    <li>
                        上司ユーザーの削除時に部下の
                        <code>manager_id</code> をどのように扱うかは、
                        実際の外部キー制約と削除処理に従う。
                    </li>
                </ul>
            </section>

            <!-- 9. セキュリティ -->
            <section class="section" id="security">
                <h2>9. セキュリティ設計</h2>

                <ul>
                    <li>
                        <strong>認証：</strong>
                        ログインが必要な画面・処理ではセッションを検証する。
                    </li>
                    <li>
                        <strong>認可：</strong>
                        管理者専用処理では管理者権限を確認し、
                        レポート・コメントの操作権限もサーバー側で確認する。
                    </li>
                    <li>
                        <strong>パスワード：</strong>
                        パスワードはハッシュ化して保存し、
                        ログイン時には検証関数で照合する。
                    </li>
                    <li>
                        <strong>SQLインジェクション対策：</strong>
                        PDOのプリペアドステートメントを使用する。
                    </li>
                    <li>
                        <strong>HTML出力：</strong>
                        ユーザー入力やコメントを表示するときは
                        HTMLエスケープを行う。
                    </li>
                    <li>
                        <strong>更新・削除：</strong>
                        サーバー側で対象データへの権限を確認する。
                    </li>
                    <li>
                        <strong>入力検証：</strong>
                        必須項目、文字数、ID、役職、上司候補などを検証する。
                    </li>
                    <li>
                        <strong>データ保護：</strong>
                        SQLiteファイルと設定ファイルを
                        公開ディレクトリの外に配置する。
                    </li>
                </ul>

                <div class="warning">
                    CSRF対策や入力検証などの実装状況は、
                    該当するPHPファイルのコードを確認してください。
                    設計書に記載されていることだけで、
                    実装済みとは判断しません。
                </div>
            </section>

            <!-- 10. エラー処理・運用 -->
            <section class="section" id="errors">
                <h2>10. エラー処理・運用</h2>

                <ul>
                    <li>
                        データベース処理で例外が発生した場合は、
                        利用者に内部情報を露出させないようにする。
                    </li>
                    <li>
                        入力エラーは、利用者が修正すべき内容を
                        理解できる形で表示する。
                    </li>
                    <li>
                        ログイン必須ページへの未認証アクセスは、
                        ログイン画面へ誘導する。
                    </li>
                    <li>
                        権限のないデータへのアクセスや不正な更新要求を拒否する。
                    </li>
                    <li>
                        削除できない場合は、その理由を利用者が確認できるようにする。
                    </li>
                    <li>
                        データベースのバックアップと復元方法を管理する。
                    </li>
                </ul>
            </section>

            <!-- 11. 保守・今後の課題 -->
            <section class="section" id="maintenance">
                <h2>11. 保守・今後の課題</h2>

                <h3>変更時に確認すること</h3>
                <ol>
                    <li>
                        データベースのスキーマ変更が必要な場合、
                        既存データへの影響を確認する。
                    </li>
                    <li>
                        認証・権限に関わる変更は、
                        一般ユーザー、管理者、上司・部下の各条件でテストする。
                    </li>
                    <li>
                        レポートの登録・表示・編集・更新・削除を一通り確認する。
                    </li>
                    <li>
                        コメントの登録・編集・更新・削除を確認する。
                    </li>
                    <li>
                        ユーザー作成、編集、パスワード変更、削除条件を確認する。
                    </li>
                    <li>
                        フォルダやファイルを移動した場合は、
                        リンク先、フォーム送信先、リダイレクト先、
                        PHPの読み込みパスを確認する。
                    </li>
                    <li>
                        操作マニュアル
                        （<code>docs/manual.php</code>）と本設計書
                        （<code>docs/design.php</code>）を
                        実装に合わせて更新する。
                    </li>
                </ol>

                <h3>運用上の留意点</h3>
                <ul>
                    <li>
                        ユーザー削除の条件は、画面表示だけでなく
                        削除処理側でも確認する。
                    </li>
                    <li>
                        管理者アカウントを誤ってすべて削除・無効化しないようにする。
                    </li>
                    <li>
                        組織階層の変更後は、上司・部下の閲覧範囲が
                        意図どおりになっているか確認する。
                    </li>
                    <li>
                        PHP、SQLite、Webサーバーのバージョンや設定は、
                        実際の稼働環境に合わせて管理する。
                    </li>
                </ul>

                <p class="small">
                    文書の更新日：2026年10月25日
                </p>

                <a class="back" href="../login.php">
                    ログイン画面へ戻る
                </a>
            </section>

        </main>
    </div>

    <footer>
        レポート管理システム システム設計書
    </footer>

</div>
</body>
</html>
