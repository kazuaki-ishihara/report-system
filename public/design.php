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

        .design-container {
            max-width: 1100px;
            margin: 0 auto;
        }

        .design-header {
            margin-bottom: 24px;
            padding: 30px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .design-header h1 {
            margin: 0 0 10px;
            color: #1f2937;
            font-size: 28px;
        }

        .design-header p {
            margin: 0;
            color: #6b7280;
        }

        .design-actions {
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
            text-align: center;
            text-decoration: none;
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
        }

        .design-layout {
            display: grid;
            grid-template-columns: 250px minmax(0, 1fr);
            gap: 24px;
            align-items: start;
        }

        .design-toc {
            position: sticky;
            top: 20px;
            padding: 22px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .design-toc h2 {
            margin: 0 0 12px;
            font-size: 18px;
        }

        .design-toc ul {
            margin: 0;
            padding-left: 20px;
        }

        .design-toc li {
            margin-bottom: 8px;
        }

        .design-toc a {
            color: #2563eb;
            font-size: 14px;
            text-decoration: none;
        }

        .design-toc a:hover {
            text-decoration: underline;
        }

        .design-content {
            min-width: 0;
        }

        .design-section {
            margin-bottom: 24px;
            padding: 26px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            scroll-margin-top: 20px;
        }

        .design-section h2 {
            margin: 0 0 18px;
            padding-bottom: 10px;
            border-bottom: 2px solid #2563eb;
            color: #1f2937;
            font-size: 22px;
        }

        .design-section h3 {
            margin: 24px 0 10px;
            color: #374151;
            font-size: 17px;
        }

        .design-section p {
            margin: 8px 0;
        }

        .design-section ul,
        .design-section ol {
            padding-left: 24px;
        }

        .design-section li {
            margin-bottom: 6px;
        }

        .design-table-wrapper {
            width: 100%;
            margin: 16px 0;
            overflow-x: auto;
        }

        .design-table {
            width: 100%;
            border-collapse: collapse;
            background-color: #fff;
        }

        .design-table th,
        .design-table td {
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            text-align: left;
            vertical-align: top;
            overflow-wrap: anywhere;
        }

        .design-table th {
            background-color: #f3f4f6;
            white-space: nowrap;
        }

        .design-table code {
            overflow-wrap: anywhere;
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

        .code-block {
            margin: 16px 0;
            padding: 18px;
            overflow-x: auto;
            border: 1px solid #d1d5db;
            border-radius: 5px;
            background-color: #f8fafc;
            font-family: monospace;
            font-size: 13px;
            line-height: 1.7;
            white-space: pre;
        }

        .design-footer {
            padding: 20px;
            color: #6b7280;
            font-size: 13px;
            text-align: center;
        }

        a:focus-visible {
            outline: 2px solid #2563eb;
            outline-offset: 3px;
        }

        @media (max-width: 800px) {
            body {
                padding: 16px 10px;
            }

            .design-header {
                padding: 22px;
            }

            .design-header h1 {
                font-size: 23px;
            }

            .design-layout {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .design-toc {
                position: static;
            }

            .design-section {
                padding: 20px;
            }

            .design-section h2 {
                font-size: 20px;
            }

            .design-table th,
            .design-table td {
                padding: 8px;
                font-size: 13px;
            }
        }
    </style>
</head>

<body id="top">

<div class="design-container">

    <!-- ヘッダー -->
    <header class="design-header">
        <h1>レポート管理システム</h1>

        <p>システム設計書</p>

        <p>
            本書では、システムの構成、主要機能、画面、
            データベースおよびセキュリティについて説明します。
        </p>

        <div class="design-actions">
            <a href="login.php" class="button">
                ログイン画面へ戻る
            </a>

            <a href="manual.php" class="button button-secondary">
                画面操作説明
            </a>
        </div>
    </header>

    <div class="design-layout">

        <!-- 目次 -->
        <nav class="design-toc" aria-label="設計書の目次">
            <h2>目次</h2>

            <ul>
                <li><a href="#overview">システム概要</a></li>
                <li><a href="#environment">システム構成</a></li>
                <li><a href="#files">ファイル構成</a></li>
                <li><a href="#screens">画面設計</a></li>
                <li><a href="#functions">機能設計</a></li>
                <li><a href="#database">データベース設計</a></li>
                <li><a href="#relationships">テーブル関連</a></li>
                <li><a href="#security">セキュリティ設計</a></li>
                <li><a href="#errors">エラー処理</a></li>
                <li><a href="#maintenance">運用・保守</a></li>
                <li><a href="#future">今後の拡張</a></li>
            </ul>
        </nav>

        <main class="design-content">

            <!-- 1. システム概要 -->
            <section class="design-section" id="overview">
                <h2>1. システム概要</h2>

                <h3>1-1. システム名称</h3>
                <p>レポート管理システム</p>

                <h3>1-2. システムの目的</h3>
                <p>
                    レポート情報をWeb画面から登録・閲覧・検索・編集し、
                    情報を一元管理することを目的とします。
                </p>

                <p>
                    また、レポートの各項目に対してコメントを登録できるようにし、
                    質問や補足説明、確認事項などを管理します。
                </p>

                <h3>1-3. 主な機能</h3>

                <ul>
                    <li>ユーザー認証</li>
                    <li>レポート一覧表示</li>
                    <li>名前・キーワードによる検索</li>
                    <li>レポートの新規登録</li>
                    <li>レポートの詳細表示</li>
                    <li>レポートの編集</li>
                    <li>項目ごとのコメント登録</li>
                    <li>コメントの編集・削除</li>
                    <li>ユーザーの管理階層に応じた閲覧制御</li>
                </ul>
            </section>

            <!-- 2. システム構成 -->
            <section class="design-section" id="environment">
                <h2>2. システム構成</h2>

                <h3>2-1. 使用技術</h3>

                <div class="design-table-wrapper">
                    <table class="design-table">
                        <thead>
                            <tr>
                                <th>項目</th>
                                <th>技術・構成</th>
                                <th>役割</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>フロントエンド</td>
                                <td>HTML / CSS / JavaScript</td>
                                <td>画面表示、入力フォーム、画面操作</td>
                            </tr>
                            <tr>
                                <td>バックエンド</td>
                                <td>PHP</td>
                                <td>認証、入力処理、検索、データベース操作</td>
                            </tr>
                            <tr>
                                <td>データベース</td>
                                <td>SQLite</td>
                                <td>ユーザー、レポート、コメントの保存</td>
                            </tr>
                            <tr>
                                <td>データベース接続</td>
                                <td>PDO</td>
                                <td>PHPからSQLiteへの接続・SQL実行</td>
                            </tr>
                            <tr>
                                <td>実行環境</td>
                                <td>PHP実行環境を備えたWebサーバー</td>
                                <td>PHPプログラムの実行と画面の提供</td>
                            </tr>
                            <tr>
                                <td>バージョン管理</td>
                                <td>Git / GitHub</td>
                                <td>ソースコードの履歴管理</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h3>2-2. システム構成図</h3>

                <div class="code-block">利用者
  |
  | Webブラウザ
  v
PHP Webアプリケーション
  |
  +-- ログイン・認証処理
  |
  +-- レポート管理処理
  |
  +-- コメント管理処理
  |
  +-- アクセス権限チェック
  |
  v
PDO
  |
  v
SQLite データベース
  |
  +-- users
  +-- reports
  +-- comments</div>

                <div class="note">
                    この図は論理的な構成を示しています。
                    実際のサーバー配置やネットワーク構成を示すものではありません。
                </div>
            </section>

            <!-- 3. ファイル構成 -->
            <section class="design-section" id="files">
                <h2>3. ディレクトリ・ファイル構成</h2>

                <p>
                    主なディレクトリとファイルの役割を示します。
                </p>

                <div class="code-block">report-system/
├── config/
│   └── database.php
├── data/
│   └── reports.sqlite
├── lib/
│   ├── auth.php
│   └── functions.php
└── public/
    ├── login.php
    ├── manual.php
    ├── design.php
    ├── index.php
    ├── create.php
    ├── store.php
    ├── detail.php
    ├── edit.php
    ├── update.php
    ├── comment_edit.php
    ├── comment_update.php
    ├── comment_delete.php
    └── css/
        └── style.css</div>

                <div class="warning">
                    上記は主要ファイルの構成例です。
                    実際のリポジトリにあるファイルをすべて列挙したものではありません。
                    ファイルを追加・削除した場合は、この一覧も更新してください。
                </div>

                <div class="design-table-wrapper">
                    <table class="design-table">
                        <thead>
                            <tr>
                                <th>ファイル</th>
                                <th>主な役割</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>config/database.php</code></td>
                                <td>SQLiteへの接続とPDOの設定</td>
                            </tr>
                            <tr>
                                <td><code>lib/auth.php</code></td>
                                <td>セッション管理、ログイン確認、ユーザー情報取得</td>
                            </tr>
                            <tr>
                                <td><code>lib/functions.php</code></td>
                                <td>共通処理や共通関数</td>
                            </tr>
                            <tr>
                                <td><code>public/login.php</code></td>
                                <td>ログイン画面と認証処理</td>
                            </tr>
                            <tr>
                                <td><code>public/manual.php</code></td>
                                <td>画面操作マニュアル</td>
                            </tr>
                            <tr>
                                <td><code>public/design.php</code></td>
                                <td>システム設計書</td>
                            </tr>
                            <tr>
                                <td><code>public/index.php</code></td>
                                <td>レポート一覧と検索</td>
                            </tr>
                            <tr>
                                <td><code>public/create.php</code></td>
                                <td>レポート登録画面</td>
                            </tr>
                            <tr>
                                <td><code>public/store.php</code></td>
                                <td>レポート登録処理</td>
                            </tr>
                            <tr>
                                <td><code>public/detail.php</code></td>
                                <td>レポート詳細表示、項目ごとのコメント表示</td>
                            </tr>
                            <tr>
                                <td><code>public/edit.php</code></td>
                                <td>レポート編集画面</td>
                            </tr>
                            <tr>
                                <td><code>public/update.php</code></td>
                                <td>レポート更新処理</td>
                            </tr>
                            <tr>
                                <td><code>public/comment_edit.php</code></td>
                                <td>コメント編集画面</td>
                            </tr>
                            <tr>
                                <td><code>public/comment_update.php</code></td>
                                <td>コメント更新処理</td>
                            </tr>
                            <tr>
                                <td><code>public/comment_delete.php</code></td>
                                <td>コメント削除処理</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- 4. 画面設計 -->
            <section class="design-section" id="screens">
                <h2>4. 画面設計</h2>

                <div class="design-table-wrapper">
                    <table class="design-table">
                        <thead>
                            <tr>
                                <th>画面名</th>
                                <th>ファイル</th>
                                <th>概要</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>ログイン画面</td>
                                <td><code>login.php</code></td>
                                <td>ユーザー名・パスワードによる認証</td>
                            </tr>
                            <tr>
                                <td>操作マニュアル</td>
                                <td><code>manual.php</code></td>
                                <td>利用者向けの操作説明</td>
                            </tr>
                            <tr>
                                <td>システム設計書</td>
                                <td><code>design.php</code></td>
                                <td>システム構成・仕様の説明</td>
                            </tr>
                            <tr>
                                <td>レポート一覧</td>
                                <td><code>index.php</code></td>
                                <td>一覧表示、検索、詳細・編集画面への移動</td>
                            </tr>
                            <tr>
                                <td>レポート登録</td>
                                <td><code>create.php</code></td>
                                <td>新規レポートの入力</td>
                            </tr>
                            <tr>
                                <td>レポート詳細</td>
                                <td><code>detail.php</code></td>
                                <td>登録情報と項目ごとのコメントの表示</td>
                            </tr>
                            <tr>
                                <td>レポート編集</td>
                                <td><code>edit.php</code></td>
                                <td>登録済みレポートの修正</td>
                            </tr>
                            <tr>
                                <td>コメント編集</td>
                                <td><code>comment_edit.php</code></td>
                                <td>登録済みコメントの修正</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="note">
                    各画面のURLやファイル名は、実際の実装に合わせて管理します。
                    画面の追加・変更時には、本設計書も更新してください。
                </div>
            </section>

            <!-- 5. 機能設計 -->
            <section class="design-section" id="functions">
                <h2>5. 機能設計</h2>

                <h3>5-1. ログイン機能</h3>

                <ol>
                    <li>ログイン画面からユーザー名とパスワードを受け取る。</li>
                    <li>入力値を確認する。</li>
                    <li>データベースからユーザー情報を取得する。</li>
                    <li>パスワードを検証する。</li>
                    <li>認証成功時はセッションを設定する。</li>
                    <li>認証成功後、レポート一覧画面へ移動する。</li>
                </ol>

                <h3>5-2. レポート一覧・検索機能</h3>

                <ul>
                    <li>データベースからレポートを取得する。</li>
                    <li>名前を条件として検索できる。</li>
                    <li>件名、内容、課題、備考をキーワード検索できる。</li>
                    <li>レポートの日付とIDを基準に並べ替える。</li>
                    <li>ユーザーの権限に応じて閲覧対象を制限する。</li>
                </ul>

                <h3>5-3. レポート登録機能</h3>

                <p>登録画面では、主に次の情報を入力します。</p>

                <ul>
                    <li>日付</li>
                    <li>氏名</li>
                    <li>件名</li>
                    <li>内容</li>
                    <li>課題</li>
                    <li>備考</li>
                </ul>

                <p>
                    登録画面から送信された情報をサーバー側で検証し、
                    データベースに保存します。
                </p>

                <h3>5-4. レポート詳細・編集機能</h3>

                <ul>
                    <li>指定されたレポートIDを使用してレポートを取得する。</li>
                    <li>対象レポートの存在を確認する。</li>
                    <li>閲覧権限を確認する。</li>
                    <li>レポートの各項目を表示する。</li>
                    <li>編集画面で変更した情報を保存する。</li>
                </ul>

                <h3>5-5. コメント機能</h3>

                <p>
                    コメントはレポート全体ではなく、レポート内の各項目に紐づけて管理します。
                </p>

                <div class="design-table-wrapper">
                    <table class="design-table">
                        <thead>
                            <tr>
                                <th>対象項目</th>
                                <th>用途</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>日付</td>
                                <td>日付に関する確認事項</td>
                            </tr>
                            <tr>
                                <td>氏名</td>
                                <td>氏名に関する確認事項</td>
                            </tr>
                            <tr>
                                <td>件名</td>
                                <td>件名に関する質問や補足</td>
                            </tr>
                            <tr>
                                <td>内容</td>
                                <td>内容に関する質問や補足</td>
                            </tr>
                            <tr>
                                <td>課題</td>
                                <td>課題に関する確認事項</td>
                            </tr>
                            <tr>
                                <td>備考</td>
                                <td>備考に関する補足</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p>主な処理は次のとおりです。</p>

                <ul>
                    <li>レポートIDと項目名に基づいてコメントを取得する。</li>
                    <li>項目ごとのコメント件数を表示する。</li>
                    <li>コメントアイコンから内容を表示する。</li>
                    <li>コメントを新規登録する。</li>
                    <li>登録済みコメントを編集する。</li>
                    <li>不要なコメントを削除する。</li>
                </ul>
            </section>

            <!-- 6. DB設計 -->
            <section class="design-section" id="database">
                <h2>6. データベース設計</h2>

                <p>
                    データベースにはSQLiteを使用します。
                    PHPからPDOを利用してデータベースに接続します。
                </p>

                <div class="warning">
                    以下は、現在のプログラムから確認できる主要なカラムを整理したものです。
                    データ型、NOT NULL制約、初期値、インデックスなどを含む
                    完全な物理設計書ではありません。
                    正式なDB定義は、実際のSQLiteデータベースのテーブル定義を確認して確定してください。
                </div>

                <h3>6-1. users（ユーザー）</h3>

                <p>ユーザー認証とユーザー間の管理階層を管理します。</p>

                <div class="design-table-wrapper">
                    <table class="design-table">
                        <thead>
                            <tr>
                                <th>カラム名</th>
                                <th>用途</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>id</code></td>
                                <td>ユーザーID</td>
                            </tr>
                            <tr>
                                <td><code>username</code></td>
                                <td>ログインに使用するユーザー名</td>
                            </tr>
                            <tr>
                                <td><code>password_hash</code></td>
                                <td>パスワードのハッシュ値</td>
                            </tr>
                            <tr>
                                <td><code>role_title</code></td>
                                <td>役職・役割に関する情報</td>
                            </tr>
                            <tr>
                                <td><code>manager_id</code></td>
                                <td>上位ユーザーを示すID</td>
                            </tr>
                            <tr>
                                <td><code>is_admin</code></td>
                                <td>管理者かどうかを示す値</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h3>6-2. reports（レポート）</h3>

                <p>登録されたレポートの情報を管理します。</p>

                <div class="design-table-wrapper">
                    <table class="design-table">
                        <thead>
                            <tr>
                                <th>カラム名</th>
                                <th>用途</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>id</code></td>
                                <td>レポートID</td>
                            </tr>
                            <tr>
                                <td><code>user_id</code></td>
                                <td>レポート登録者のユーザーID</td>
                            </tr>
                            <tr>
                                <td><code>report_date</code></td>
                                <td>レポートの日付</td>
                            </tr>
                            <tr>
                                <td><code>name</code></td>
                                <td>氏名</td>
                            </tr>
                            <tr>
                                <td><code>subject</code></td>
                                <td>件名</td>
                            </tr>
                            <tr>
                                <td><code>content</code></td>
                                <td>レポートの内容</td>
                            </tr>
                            <tr>
                                <td><code>issues</code></td>
                                <td>課題</td>
                            </tr>
                            <tr>
                                <td><code>remarks</code></td>
                                <td>備考</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h3>6-3. comments（コメント）</h3>

                <p>
                    レポートの各項目に対して登録されたコメントを管理します。
                </p>

                <div class="design-table-wrapper">
                    <table class="design-table">
                        <thead>
                            <tr>
                                <th>カラム名</th>
                                <th>用途</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>id</code></td>
                                <td>コメントID</td>
                            </tr>
                            <tr>
                                <td><code>report_id</code></td>
                                <td>コメント対象のレポートID</td>
                            </tr>
                            <tr>
                                <td><code>field_name</code></td>
                                <td>コメント対象の項目名</td>
                            </tr>
                            <tr>
                                <td><code>comment</code></td>
                                <td>コメント本文</td>
                            </tr>
                            <tr>
                                <td><code>created_at</code></td>
                                <td>コメントの登録日時</td>
                            </tr>
                            <tr>
                                <td><code>updated_at</code></td>
                                <td>コメントの更新日時</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- 7. テーブル関連 -->
            <section class="design-section" id="relationships">
                <h2>7. テーブル関連</h2>

                <div class="code-block">users
  |
  | users.id
  |
  +-------- reports.user_id
  |              |
  |              | reports.id
  |              |
  |              +-------- comments.report_id
  |
  +-------- users.manager_id
             （管理階層）</div>

                <h3>関連の説明</h3>

                <ul>
                    <li>
                        <code>reports.user_id</code> は、
                        レポートを登録したユーザーを識別します。
                    </li>
                    <li>
                        <code>comments.report_id</code> は、
                        コメント対象のレポートを識別します。
                    </li>
                    <li>
                        <code>comments.field_name</code> は、
                        レポート内のどの項目に対するコメントかを識別します。
                    </li>
                    <li>
                        <code>users.manager_id</code> は、
                        ユーザーの管理階層を表します。
                    </li>
                </ul>

                <div class="note">
                    上図はプログラム上の論理的な関連を示しています。
                    外部キー制約が実際のSQLiteに設定されているかどうかは、
                    テーブル定義で別途確認が必要です。
                </div>
            </section>

            <!-- 8. セキュリティ -->
            <section class="design-section" id="security">
                <h2>8. セキュリティ設計</h2>

                <h3>8-1. ログイン認証</h3>

                <ul>
                    <li>ログインが必要な画面では認証状態を確認する。</li>
                    <li>未ログインの場合はログイン画面へ移動する。</li>
                    <li>パスワードはハッシュ値を利用して検証する。</li>
                    <li>ログイン成功時はセッションIDを再生成する。</li>
                </ul>

                <h3>8-2. アクセス権限</h3>

                <ul>
                    <li>管理者はすべてのレポートを閲覧できる設計とする。</li>
                    <li>一般ユーザーは本人および配下のユーザーのレポートを閲覧する。</li>
                    <li>レポートIDを指定した場合も閲覧権限を確認する。</li>
                </ul>

                <h3>8-3. SQLインジェクション対策</h3>

                <p>
                    SQLを実行する際は、プレースホルダーを利用した
                    プリペアドステートメントを使用します。
                </p>

                <h3>8-4. 出力時のエスケープ</h3>

                <p>
                    HTML画面にユーザー入力値などを出力する際は、
                    HTMLエスケープ処理を行い、意図しないHTMLやJavaScriptの実行を防ぎます。
                </p>

                <h3>8-5. 設計書の公開範囲</h3>

                <div class="warning">
                    現在の構成では、設計書ページをログイン画面から参照できるようにしています。
                    その場合、URLを知っている人がログイン前に設計情報を閲覧できる可能性があります。
                    実運用時は、設計書をログイン後の管理者向け画面に移すなど、
                    公開範囲を検討してください。
                </div>
            </section>

            <!-- 9. エラー処理 -->
            <section class="design-section" id="errors">
                <h2>9. エラー処理</h2>

                <div class="design-table-wrapper">
                    <table class="design-table">
                        <thead>
                            <tr>
                                <th>発生条件</th>
                                <th>想定される対応</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>ログイン情報が不正</td>
                                <td>エラーメッセージを表示する</td>
                            </tr>
                            <tr>
                                <td>ログインしていない</td>
                                <td>ログイン画面へ移動する</td>
                            </tr>
                            <tr>
                                <td>レポートIDが不正</td>
                                <td>エラー応答を返す</td>
                            </tr>
                            <tr>
                                <td>レポートが存在しない</td>
                                <td>対象が存在しないことを通知する</td>
                            </tr>
                            <tr>
                                <td>閲覧権限がない</td>
                                <td>アクセスを拒否する</td>
                            </tr>
                            <tr>
                                <td>データベース接続エラー</td>
                                <td>エラーを検知し、適切に処理する</td>
                            </tr>
                            <tr>
                                <td>入力値に問題がある</td>
                                <td>入力内容を確認するよう案内する</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="note">
                    この表はエラー処理の設計方針です。
                    実際のエラーメッセージ、HTTPステータスコード、
                    ログ出力方法は各処理の実装を確認して確定します。
                </div>
            </section>

            <!-- 10. 運用保守 -->
            <section class="design-section" id="maintenance">
                <h2>10. 運用・保守</h2>

                <h3>10-1. データベースのバックアップ</h3>

                <p>
                    SQLiteのデータベースファイルには、
                    レポートやユーザーなどの重要なデータが保存されます。
                </p>

                <ul>
                    <li>定期的にバックアップを取得する。</li>
                    <li>バックアップファイルを適切に保管する。</li>
                    <li>必要に応じて復元できることを確認する。</li>
                    <li>バックアップにアクセスできるユーザーを制限する。</li>
                </ul>

                <h3>10-2. ソースコードの管理</h3>

                <ul>
                    <li>Gitで変更履歴を管理する。</li>
                    <li>変更前に対象ファイルと影響範囲を確認する。</li>
                    <li>修正後はログイン、検索、登録、編集、コメント機能を確認する。</li>
                    <li>動作確認後にGitHubへ反映する。</li>
                </ul>

                <h3>10-3. 設計書の更新</h3>

                <p>
                    次の変更を行った場合は、本設計書も更新します。
                </p>

                <ul>
                    <li>画面の追加・変更</li>
                    <li>新しい機能の追加</li>
                    <li>データベースのテーブル・カラム変更</li>
                    <li>アクセス権限の変更</li>
                    <li>システム構成の変更</li>
                </ul>
            </section>

            <!-- 11. 今後の拡張 -->
            <section class="design-section" id="future">
                <h2>11. 今後の拡張候補</h2>

                <p>
                    システムの利用状況に応じて、次の機能を追加することが考えられます。
                </p>

                <ul>
                    <li>レポートの削除機能</li>
                    <li>ユーザー管理画面</li>
                    <li>パスワード変更機能</li>
                    <li>操作履歴の記録</li>
                    <li>CSV出力機能</li>
                    <li>データベースのバックアップ・復元機能</li>
                    <li>設計書へのアクセス制限</li>
                </ul>

                <div class="note">
                    ここに記載した項目は今後の拡張候補であり、
                    現在すべてが実装されているという意味ではありません。
                </div>
            </section>

            <!-- ページ下部 -->
            <div class="design-actions">
                <a href="login.php" class="button">
                    ログイン画面へ戻る
                </a>

                <a href="manual.php" class="button button-secondary">
                    画面操作説明
                </a>

                <a href="#top" class="button button-secondary">
                    ページ上部へ戻る
                </a>
            </div>

        </main>
    </div>

    <footer class="design-footer">
        レポート管理システム　システム設計書
    </footer>

</div>

</body>
</html>
