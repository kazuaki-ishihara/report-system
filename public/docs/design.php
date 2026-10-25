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
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Noto Sans JP",
                         "Hiragino Kaku Gothic ProN", Meiryo, sans-serif;
            line-height: 1.75;
        }
        a { color: #155d91; }
        .page { max-width: 1180px; margin: 0 auto; padding: 28px 20px 56px; }
        .header {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: #fff; border-radius: 12px; padding: 30px 34px; margin-bottom: 22px;
        }
        .header h1 { margin: 0 0 8px; font-size: 2rem; }
        .header p { margin: 0; opacity: .95; }
        .meta { margin-top: 14px; font-size: .9rem; opacity: .9; }
        .layout { display: grid; grid-template-columns: 260px minmax(0, 1fr); gap: 22px; align-items: start; }
        .toc, .section {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 10px; box-shadow: 0 2px 7px rgba(20, 40, 55, .04);
        }
        .toc { padding: 18px; position: sticky; top: 16px; }
        .toc h2 { margin: 0 0 10px; font-size: 1.05rem; }
        .toc ol { margin: 0; padding-left: 22px; }
        .toc li { margin: 4px 0; }
        .toc a { text-decoration: none; }
        .toc a:hover { text-decoration: underline; }
        main { min-width: 0; }
        .section { padding: 24px 27px; margin-bottom: 18px; scroll-margin-top: 18px; }
        .section h2 {
            margin: 0 0 15px; padding-bottom: 9px; border-bottom: 2px solid var(--accent);
            color: var(--primary-dark); font-size: 1.4rem;
        }
        .section h3 { margin: 22px 0 8px; font-size: 1.08rem; color: var(--primary); }
        p { margin: 8px 0 12px; }
        ul, ol { padding-left: 25px; }
        li { margin: 4px 0; }
        code, pre { font-family: ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace; }
        code { background: #eef2f5; padding: 1px 5px; border-radius: 4px; overflow-wrap: anywhere; }
        pre { background: #f1f4f6; border: 1px solid var(--border); border-radius: 7px; padding: 14px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0 18px; font-size: .95rem; }
        th, td { border: 1px solid var(--border); padding: 9px 11px; text-align: left; vertical-align: top; }
        th { background: var(--accent); }
        .note, .warning { padding: 12px 15px; margin: 14px 0; border-left: 4px solid; border-radius: 5px; }
        .note { background: #eef7fc; border-color: #4388b5; }
        .warning { background: var(--warning-bg); border-color: var(--warning-border); }
        .small { color: var(--muted); font-size: .9rem; }
        .back { display: inline-block; margin-top: 8px; }
        footer { color: var(--muted); font-size: .85rem; text-align: center; margin-top: 24px; }
        @media (max-width: 850px) {
            .layout { grid-template-columns: 1fr; }
            .toc { position: static; }
            .header { padding: 24px 22px; }
            .header h1 { font-size: 1.6rem; }
            .section { padding: 20px; }
        }
        @media print {
            body { background: #fff; }
            .page { max-width: none; padding: 0; }
            .header { background: #fff; color: #000; border: 1px solid #888; }
            .layout { display: block; }
            .toc { position: static; box-shadow: none; margin-bottom: 18px; }
            .section { box-shadow: none; break-inside: avoid; }
            a { color: #000; text-decoration: none; }
        }
    </style>
</head>
<body>
<div class="page">
    <header class="header">
        <h1>システム設計書</h1>
        <p>レポート管理システムの構成、機能、データベース、権限制御の設計を記載します。</p>
        <div class="meta">対象：PHP / SQLite レポート管理システム　｜　更新日：2026年10月25日</div>
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
            <section class="section" id="overview">
                <h2>1. システム概要</h2>
                <p>本システムは、日報・業務レポートを登録、検索、閲覧、編集し、レポート内の各項目にコメントを付けて確認や質問を行うためのWebアプリケーションです。</p>
                <h3>主な目的</h3>
                <ul>
                    <li>レポート情報をデータベースで一元管理する。</li>
                    <li>レポートの項目単位でコメントを登録し、内容の確認や質問を行う。</li>
                    <li>ユーザーと上司・部下の関係に基づき、閲覧範囲を制御する。</li>
                    <li>管理者がユーザーアカウント、役職、管理者権限を管理する。</li>
                </ul>
                <div class="note">本書は現在の実装を基にした設計概要です。サーバーやPHPのバージョンなど、環境ごとに異なる値は実際の稼働環境に合わせて管理してください。</div>
            </section>

            <section class="section" id="environment">
                <h2>2. 動作環境・技術構成</h2>
                <table>
                    <tbody>
                    <tr><th>アプリケーション</th><td>PHPによるサーバーサイドWebアプリケーション</td></tr>
                    <tr><th>データベース</th><td>SQLite（PDO経由で接続）</td></tr>
                    <tr><th>画面</th><td>HTML / CSS。フォーム送信を中心とした画面遷移</td></tr>
                    <tr><th>認証状態</th><td>PHPセッションを利用</td></tr>
                    <tr><th>データベース接続</th><td><code>config/database.php</code> でPDO接続を初期化</td></tr>
                    <tr><th>認証・共通処理</th><td><code>lib/auth.php</code>、<code>lib/functions.php</code></td></tr>
                    </tbody>
                </table>
                <h3>接続先データベース</h3>
                <p>データベースファイルは <code>data/reports.sqlite</code> を使用します。接続設定では例外を有効にし、取得結果は連想配列として扱います。</p>
                <div class="warning">SQLiteファイルをWebから直接取得できる公開ディレクトリに置かないでください。現在の構成では <code>data</code> ディレクトリを <code>public</code> の外に配置する設計です。</div>
            </section>

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
└── public/
    ├── login.php
    ├── logout.php
    ├── index.php
    ├── create.php
    ├── store.php
    ├── detail.php
    ├── edit.php
    ├── update.php
    ├── delete.php
    ├── comment_store.php
    ├── comment_edit.php
    ├── comment_update.php
    ├── comment_delete.php
    ├── users.php
    ├── user_create.php
    ├── user_store.php
    ├── user_edit.php
    ├── user_update.php
    ├── user_delete.php
    ├── manual.php
    └── design.php</pre>
                <p class="small">※上記は機能上の主要ファイルを示します。実際のリポジトリに存在する補助ファイルや、更新時点でのファイル名に合わせて調整してください。</p>
                <table>
                    <thead><tr><th>ファイル・領域</th><th>責務</th></tr></thead>
                    <tbody>
                    <tr><td><code>config/database.php</code></td><td>SQLiteへのPDO接続を生成する。</td></tr>
                    <tr><td><code>lib/auth.php</code></td><td>セッション、ログインユーザー取得、ログイン必須・管理者必須の判定などを共通化する。</td></tr>
                    <tr><td><code>lib/functions.php</code></td><td>HTMLエスケープなどの共通関数を提供する。</td></tr>
                    <tr><td><code>index.php</code></td><td>閲覧可能なレポート一覧、検索、詳細・編集画面への導線を表示する。</td></tr>
                    <tr><td><code>create.php</code> / <code>store.php</code></td><td>レポート登録フォームと登録処理。</td></tr>
                    <tr><td><code>detail.php</code></td><td>レポート詳細と項目別コメントの表示導線。</td></tr>
                    <tr><td><code>edit.php</code> / <code>update.php</code> / <code>delete.php</code></td><td>レポートの編集・更新・削除処理。</td></tr>
                    <tr><td><code>comment_*.php</code></td><td>コメントの登録・編集・削除処理。</td></tr>
                    <tr><td><code>users.php</code> / <code>user_*.php</code></td><td>管理者向けのユーザー一覧、作成、編集、更新、削除。</td></tr>
                    <tr><td><code>manual.php</code> / <code>design.php</code></td><td>画面操作説明とシステム設計書を表示する。</td></tr>
                    </tbody>
                </table>
            </section>

            <section class="section" id="screens">
                <h2>4. 画面・処理一覧</h2>
                <table>
                    <thead><tr><th>画面・処理</th><th>概要</th><th>アクセス制御</th></tr></thead>
                    <tbody>
                    <tr><td>ログイン（<code>login.php</code>）</td><td>ユーザー名とパスワードを使ってログインする。マニュアル・設計書へのリンクを提供する。</td><td>未ログイン利用可</td></tr>
                    <tr><td>レポート一覧（<code>index.php</code>）</td><td>閲覧権限のあるレポートを一覧表示し、氏名・キーワードなどで検索する。</td><td>ログイン必須</td></tr>
                    <tr><td>レポート登録（<code>create.php</code> / <code>store.php</code>）</td><td>レポート入力画面と登録処理。</td><td>ログイン必須</td></tr>
                    <tr><td>レポート詳細（<code>detail.php</code>）</td><td>レポート内容と各項目のコメントを確認する。</td><td>レポート閲覧権限が必要</td></tr>
                    <tr><td>レポート編集・削除</td><td>レポート内容の変更・削除。</td><td>原則として本人のレポートのみ編集操作を表示・許可</td></tr>
                    <tr><td>コメント処理</td><td>項目ごとのコメントを登録・編集・削除する。</td><td>閲覧権限に加え、コメント所有者または管理者の権限を確認</td></tr>
                    <tr><td>ユーザー管理（<code>users.php</code>）</td><td>ユーザー一覧、役職、上司、管理者権限、関連データ件数を確認する。</td><td>管理者のみ</td></tr>
                    <tr><td>ユーザー作成（<code>user_create.php</code> / <code>user_store.php</code>）</td><td>アカウントを新規作成する。</td><td>管理者のみ</td></tr>
                    <tr><td>ユーザー編集（<code>user_edit.php</code> / <code>user_update.php</code>）</td><td>ユーザー情報や所属上司、役職、管理者権限を更新する。</td><td>管理者のみ</td></tr>
                    <tr><td>ユーザー削除（<code>user_delete.php</code>）</td><td>削除条件を満たすアカウントを削除する。</td><td>管理者のみ。POST処理で条件を再検証</td></tr>
                    <tr><td>操作マニュアル（<code>manual.php</code>）</td><td>利用者向けの画面操作方法と注意点を説明する。</td><td>公開設定は実装に従う</td></tr>
                    <tr><td>システム設計書（<code>design.php</code>）</td><td>システム構成・機能・データ設計を説明する。</td><td>公開設定は実装に従う</td></tr>
                    </tbody>
                </table>
            </section>

            <section class="section" id="features">
                <h2>5. 機能設計</h2>
                <h3>5.1 レポート管理</h3>
                <ul>
                    <li>ログインユーザーがレポートを登録する。</li>
                    <li>一覧画面で閲覧可能なレポートを表示する。</li>
                    <li>氏名・キーワードによる検索を行う。キーワードは件名、内容、課題、備考などの項目を対象とする。</li>
                    <li>レポートは作成者（<code>user_id</code>）と関連付けて保存する。</li>
                    <li>一覧で編集導線を表示する対象は原則として本人のレポートとする。サーバー側処理でも権限を確認する。</li>
                </ul>
                <h3>5.2 項目別コメント</h3>
                <ul>
                    <li>コメントは対象レポートと入力項目名に関連付ける。</li>
                    <li>詳細画面ではコメントアイコンからコメントを確認できる。</li>
                    <li>コメント登録時はログインユーザーをコメント作成者として記録する。</li>
                    <li>コメントの編集・削除は、原則として投稿者本人に限定する。管理者は他ユーザーのコメントも編集・削除できる。</li>
                    <li>コメント件数やアイコン表示は、対象項目にコメントが存在することを利用者が把握できるようにする。</li>
                </ul>
                <h3>5.3 ユーザー管理</h3>
                <ul>
                    <li>管理者がユーザーアカウントを作成・編集・削除する。</li>
                    <li>ユーザーにはユーザー名、パスワードハッシュ、役職、上司、管理者フラグなどを保持する。</li>
                    <li>新規作成時のパスワードはハッシュ化して保存する。編集時にパスワード欄が空の場合は、既存パスワードを変更しない。</li>
                    <li>上司を設定することで組織階層を表現し、配下ユーザーのレポート閲覧範囲の判定に利用する。</li>
                    <li>上司候補から本人および本人の配下を除外し、組織階層の循環を防止する。</li>
                    <li>削除処理では、本人のアカウントや管理者が1人しかいない場合などの条件を確認し、関連するレポート・コメントがあるユーザーの削除を拒否する設計とする。</li>
                </ul>
            </section>

            <section class="section" id="permissions">
                <h2>6. 認証・権限制御</h2>
                <h3>6.1 認証</h3>
                <ul>
                    <li>ログイン成功後、セッションにログイン状態とユーザー識別情報を保持する。</li>
                    <li>保護対象ページは共通認証処理を呼び出し、未ログイン利用者をログイン画面へ誘導する。</li>
                    <li>ログアウト時はセッションを終了する。</li>
                </ul>
                <h3>6.2 役職と管理者権限</h3>
                <table>
                    <thead><tr><th>役職</th><th>想定される組織上の位置づけ</th><th>主な制御</th></tr></thead>
                    <tbody>
                    <tr><td>一般</td><td>一般利用者</td><td>本人のレポートを中心に利用する。</td></tr>
                    <tr><td>主任</td><td>一般利用者の上位</td><td>組織階層に従って配下のレポートを閲覧する。</td></tr>
                    <tr><td>課長代理</td><td>主任と課長の間</td><td>組織階層に従って配下のレポートを閲覧する。</td></tr>
                    <tr><td>課長</td><td>管理職</td><td>役職だけでなく、管理者フラグにより管理機能の可否を判定する。</td></tr>
                    <tr><td>部長</td><td>課長の上位</td><td>組織階層に従って配下のレポートを閲覧する。</td></tr>
                    </tbody>
                </table>
                <p>役職名とシステム管理権限は別の概念です。ユーザー管理画面へのアクセスなどの管理者機能は、役職名だけではなく <code>is_admin</code> の値を用いて判定します。</p>
                <h3>6.3 レポート閲覧範囲</h3>
                <ul>
                    <li>一般ユーザーは自分のレポートを閲覧する。</li>
                    <li>管理者は全ユーザーのレポートを閲覧できる。</li>
                    <li>管理者以外は、<code>users.manager_id</code> をたどって取得した配下ユーザーのレポートを閲覧できる。</li>
                    <li>階層検索には再帰CTEなどを利用し、直接の部下だけでなく下位階層も対象にする。</li>
                    <li>画面上でリンクやボタンを非表示にするだけでなく、更新・削除処理側でも対象データへの権限を検証する。</li>
                </ul>
                <div class="warning">権限は画面表示だけでなく、各処理ファイルで必ず再確認します。URLを直接入力しても権限のないデータを参照・更新できないことが必要です。</div>
            </section>

            <section class="section" id="database">
                <h2>7. データベース設計</h2>
                <p>主な業務データは <code>users</code>、<code>reports</code>、<code>comments</code> の3テーブルで管理します。SQLite内部管理用の <code>sqlite_sequence</code> が存在する場合があります。</p>
                <h3>7.1 users（ユーザー）</h3>
                <table>
                    <thead><tr><th>カラム</th><th>役割</th><th>制約・備考</th></tr></thead>
                    <tbody>
                    <tr><td><code>id</code></td><td>ユーザーID</td><td>主キー、自動採番</td></tr>
                    <tr><td><code>username</code></td><td>ログイン名</td><td>ユーザーを識別する値</td></tr>
                    <tr><td><code>password_hash</code></td><td>パスワードハッシュ</td><td>平文パスワードを保存しない</td></tr>
                    <tr><td><code>role_title</code></td><td>役職</td><td>一般、主任、課長代理、課長、部長などの許可値</td></tr>
                    <tr><td><code>manager_id</code></td><td>上司ユーザーID</td><td><code>users.id</code> への自己参照。上司削除時は参照をNULLにする設計</td></tr>
                    <tr><td><code>is_admin</code></td><td>管理者フラグ</td><td>管理機能の認可に利用</td></tr>
                    <tr><td><code>created_at</code></td><td>作成日時</td><td>登録時刻</td></tr>
                    </tbody>
                </table>
                <h3>7.2 reports（レポート）</h3>
                <table>
                    <thead><tr><th>カラム</th><th>役割</th><th>制約・備考</th></tr></thead>
                    <tbody>
                    <tr><td><code>id</code></td><td>レポートID</td><td>主キー、自動採番</td></tr>
                    <tr><td><code>user_id</code></td><td>作成ユーザーID</td><td><code>users.id</code> を参照。ユーザー削除時はRESTRICT</td></tr>
                    <tr><td><code>report_date</code></td><td>レポート日付</td><td>対象日</td></tr>
                    <tr><td><code>name</code></td><td>氏名等の表示項目</td><td>一覧・検索などに利用</td></tr>
                    <tr><td><code>subject</code></td><td>件名</td><td>検索対象</td></tr>
                    <tr><td><code>content</code></td><td>内容</td><td>レポート本文、検索対象</td></tr>
                    <tr><td><code>issues</code></td><td>課題</td><td>検索対象</td></tr>
                    <tr><td><code>remarks</code></td><td>備考</td><td>検索対象</td></tr>
                    <tr><td><code>created_at</code></td><td>作成日時</td><td>登録時刻</td></tr>
                    <tr><td><code>updated_at</code></td><td>更新日時</td><td>更新時刻</td></tr>
                    </tbody>
                </table>
                <h3>7.3 comments（コメント）</h3>
                <table>
                    <thead><tr><th>カラム</th><th>役割</th><th>制約・備考</th></tr></thead>
                    <tbody>
                    <tr><td><code>id</code></td><td>コメントID</td><td>主キー、自動採番</td></tr>
                    <tr><td><code>report_id</code></td><td>対象レポートID</td><td><code>reports.id</code> を参照。レポート削除時はCASCADE</td></tr>
                    <tr><td><code>user_id</code></td><td>コメント投稿者ID</td><td><code>users.id</code> を参照。ユーザー削除時はRESTRICT</td></tr>
                    <tr><td><code>field_name</code></td><td>対象項目名</td><td>どのレポート項目へのコメントかを識別</td></tr>
                    <tr><td><code>comment</code></td><td>コメント本文</td><td>質問・確認事項など</td></tr>
                    <tr><td><code>created_at</code></td><td>作成日時</td><td>登録時刻</td></tr>
                    <tr><td><code>updated_at</code></td><td>更新日時</td><td>更新時刻</td></tr>
                    </tbody>
                </table>
                <h3>7.4 インデックス</h3>
                <p><code>idx_comments_report_field</code> はコメントの <code>report_id</code> と <code>field_name</code> を使った検索を支援します。</p>
                <div class="note">実際のカラム定義・NOT NULL・UNIQUE・CHECK制約は、稼働中DBの <code>.schema</code> 出力を正としてください。DB定義を変更した場合は本書も更新します。</div>
            </section>

            <section class="section" id="relations">
                <h2>8. テーブル関連</h2>
                <pre>users (1) ──────── (多) reports
  │                         │
  │                         └──── (1) ───── (多) comments
  │                                           │
  └─────────────────────── (1) ───────────────┘

users.manager_id ── users.id（上司・部下の自己参照）</pre>
                <ul>
                    <li>1人のユーザーは複数のレポートを登録できる。</li>
                    <li>1つのレポートは複数のコメントを持つことができる。</li>
                    <li>1人のユーザーは複数のコメントを投稿できる。</li>
                    <li>ユーザー同士は <code>manager_id</code> によって上司・部下の階層を表す。</li>
                </ul>
                <h3>削除時の参照整合性</h3>
                <ul>
                    <li>レポートを削除すると、そのレポートに属するコメントも削除される（CASCADE）。</li>
                    <li>レポートまたはコメントから参照されているユーザーは、外部キー制約により削除が制限される（RESTRICT）。</li>
                    <li>上司ユーザーが削除された場合、配下ユーザーの <code>manager_id</code> はNULLとなる設計。</li>
                </ul>
            </section>

            <section class="section" id="security">
                <h2>9. セキュリティ設計</h2>
                <ul>
                    <li><strong>認証：</strong>ログインが必要な画面・処理ではセッションを検証する。</li>
                    <li><strong>認可：</strong>管理者専用処理は管理者フラグを確認し、レポート・コメントの操作権限もサーバー側で確認する。</li>
                    <li><strong>パスワード：</strong>パスワードは安全なハッシュ関数で保存し、ログイン時は検証関数で照合する。</li>
                    <li><strong>SQLインジェクション対策：</strong>PDOのプリペアドステートメントを使用し、ユーザー入力をSQL文字列へ直接連結しない。</li>
                    <li><strong>HTML出力：</strong>画面へ表示するユーザー入力やコメントはHTMLエスケープする。</li>
                    <li><strong>削除・更新：</strong>変更処理はHTTPメソッドを適切に使い、特に削除はPOSTで実行する。必要に応じてCSRFトークンを検証する。</li>
                    <li><strong>入力検証：</strong>必須項目、文字数、ID、役職、上司候補などをサーバー側で検証する。</li>
                    <li><strong>データ保護：</strong>SQLiteファイルと設定ファイルを公開ディレクトリ外に置き、バックアップへのアクセスを制限する。</li>
                </ul>
                <div class="warning">CSRF対策や各処理の入力検証の実装状況は、該当するPHPファイルのコードで確認してください。本書の記載だけで実装済みとは判断しないでください。</div>
            </section>

            <section class="section" id="errors">
                <h2>10. エラー処理・運用</h2>
                <ul>
                    <li>データベース処理で例外が発生した場合は、利用者に内部情報を露出させず、サーバーログで原因を調査できるようにする。</li>
                    <li>入力エラーは、どの項目を修正すべきか利用者が分かる形で表示する。</li>
                    <li>ログイン必須ページへの未認証アクセスはログイン画面へ誘導する。</li>
                    <li>権限のないデータへのアクセスや不正な更新要求は拒否する。</li>
                    <li>削除処理で関連データがある場合は、削除できない理由を明示する。</li>
                    <li>SQLiteデータベースは定期的にバックアップし、復元手順を確認する。</li>
                </ul>
            </section>

            <section class="section" id="maintenance">
                <h2>11. 保守・今後の課題</h2>
                <h3>変更時に確認すること</h3>
                <ol>
                    <li>データベースのスキーマ変更が必要な場合、既存データへの影響を確認してから変更する。</li>
                    <li>認証・権限に関わる変更は、一般ユーザー、管理者、上司・部下それぞれで動作をテストする。</li>
                    <li>レポートとコメントの作成・表示・編集・削除を一通り確認する。</li>
                    <li>ユーザー作成、編集、パスワード未変更、削除条件をテストする。</li>
                    <li>操作マニュアル（<code>manual.php</code>）と本設計書（<code>design.php</code>）を実装に合わせて更新する。</li>
                </ol>
                <h3>運用上の留意点</h3>
                <ul>
                    <li>ユーザー削除の条件は、画面のボタン表示だけでなく削除処理側でも必ず検証する。</li>
                    <li>管理者アカウントを誤ってすべて削除・無効化しないようにする。</li>
                    <li>組織階層の変更後は、上司・部下の閲覧範囲が意図どおりになっているか確認する。</li>
                    <li>PHP、SQLite、Webサーバーのバージョンや設定は、実際の稼働環境を別途記録する。</li>
                </ul>
                <p class="small">文書の更新日：2026年10月25日</p>
                <a class="back" href="../login.php">ログイン画面へ戻る</a>
            </section>
        </main>
    </div>
    <footer>レポート管理システム システム設計書</footer>
</div>
</body>
</html>
