# Copilot 向けリポジトリ指示書（report-system）

このファイルは GitHub Copilot がこのリポジトリで提案・回答するときの前提です。
**回答・コメント・コミットメッセージは日本語**で書いてください。

## システム概要

- 日報（レポート）を登録・検索・閲覧・編集・削除し、レポートの**項目ごとにコメント**できる社内向け Web アプリ。
- 上司・部下の階層（`users.manager_id`）で閲覧範囲を制御する。管理者（`users.is_admin = 1`）はすべて閲覧・編集できる。
- 役職（`role_title`: 一般／主任／課長代理／課長／部長）は肩書きのみで、**権限判定には使わない**。
- 詳しい仕様は `docs/basic_design.md`（基本設計書）と `docs/detailed_design.md`（詳細設計書）にある。改修前に該当箇所を読むこと。

## 技術構成

- PHP（フレームワークなし。1画面・1処理＝1ファイル）
- SQLite 3（PDO 経由、`data/reports.sqlite`）
- HTML / CSS のサーバーサイドレンダリング。JavaScript は最小限（`reports/detail.php` のコメント吹き出しと削除確認のみ）
- 本番: さくらのレンタルサーバ（`https://toyama-mai.sakura.ne.jp/report-system/public/`）
- 文字数制限は `mbstring` に依存せず `strlen()`（バイト数）で判定している

## ディレクトリ構成

```text
config/database.php   $pdo（PDO）を生成。全処理が require する
lib/auth.php          startSession / requireLogin / getLoginUser / isAdmin / requireAdmin
lib/functions.php     h()（HTML エスケープ）
data/                 SQLite DB（Web からのアクセスは .htaccess で拒否）
public/               公開ディレクトリ
  index.php           レポート一覧・検索
  reports/            create(画面) → store(処理), detail, edit(画面) → update(処理), delete
  comments/           comment_store, comment_edit(画面) → comment_update, comment_delete
  users/              管理者専用のユーザー管理
docs/                 設計書（サーバーには置かない）
tools/audit/          監査ツール（Python 3 標準ライブラリのみ）
```

## コーディング規約（既存コードに合わせる）

- ファイル先頭は `<?php` + `declare(strict_types=1);`
- `require_once __DIR__ . '/../../config/database.php';` のように **`__DIR__` 基準**で読み込む
- 画面（GET）と更新処理（POST）は別ファイルに分ける。処理が終わったら `header('Location: ...'); exit;` でリダイレクトする（PRG パターン）
- ログインユーザーの取得は次の定型に従う

  ```php
  requireLogin();
  $loginUser = getLoginUser($pdo);
  if ($loginUser === null) { header('Location: ../login.php'); exit; }
  $loginUserId = (int) $loginUser['id'];
  $isAdmin = (int) $loginUser['is_admin'] === 1;
  ```

- ID は `filter_input(INPUT_GET|INPUT_POST, 'id', FILTER_VALIDATE_INT)` で受け取り、`false` / `null` / `<= 0` は 400
- エラー時のステータス: 入力不正 400、権限なし 403、データなし 404
- コメントは日本語。処理のまとまりごとに `// ====` の見出しコメントを付ける既存スタイルに合わせる

## セキュリティのルール（必ず守る）

1. **SQL は必ずプリペアドステートメント**（`$pdo->prepare()` + `execute([...])`）。文字列連結や変数展開で SQL を組み立てない。
2. **画面に出す値は必ず `h()` を通す**。数値は `(int)` でキャストしてから出力してよい。
3. **権限チェックはサーバー側で行う**。ボタンを非表示にするだけでは不十分。URL を直接打たれても拒否されること。
   - 閲覧: 管理者、または「自分＋全配下」のレポート（`WITH RECURSIVE` で配下を取得。**`UNION ALL` ではなく `UNION`** を使う）
   - レポートの編集・削除: 管理者、または作成者本人のみ（配下のレポートでも不可）
   - コメントの編集・削除: 管理者、または投稿者本人のみ
   - ユーザー管理: `requireAdmin($pdo)`
4. **POST の更新処理には CSRF トークンを付ける**。`public/users/` の実装（`$_SESSION['csrf_token']` + `hash_equals()`）と同じ方式にする。
5. パスワードは `password_hash()` / `password_verify()`。平文で保存・ログ出力しない。
6. DB 例外の詳細は画面に出さず `error_log()` に記録し、画面には汎用メッセージを出す。
7. 秘密情報（パスワード、本番 DB）をコミットしない。

## 現在わかっている問題（改修時に注意）

監査ツールで検出済み。直すときは関連箇所をまとめて直すこと。一覧は `docs/detailed_design.md` の「8. 課題一覧」。

| 問題 | 箇所 | 注意点 |
|---|---|---|
| 未ログイン時のリダイレクト先が相対パス `login.php` で、サブディレクトリから呼ぶと 404 になる | `lib/auth.php` の `requireLogin()` | 呼び出し元の階層に依存しない URL にする |
| `PRAGMA foreign_keys = ON` を実行していないため、外部キー（CASCADE 等）が効かない | `config/database.php` | 有効化すると、レポート削除時にコメントも削除されるようになる |
| レポート・コメントの POST に CSRF 対策がない | `public/reports/*`, `public/comments/*` | フォーム側（hidden）と処理側の両方に必要 |
| 登録（store.php）と更新（update.php）で入力チェックが違う | `public/reports/` | 共通化が望ましい |
| `public/setup.php` が認証なしで公開され、旧スキーマを持つ | `public/setup.php` | 使っていない。削除候補 |

## レポートの「項目」について（重要）

レポートの項目は **`reports` テーブルの固定カラム**で、項目マスタは存在しない。

| field_name | 表示名 | 入力 | 必須 |
|---|---|---|---|
| report_date | 日付 | date | ○ |
| name | 氏名 | text | ○ |
| subject | 件名 | text | ○ |
| content | 内容 | textarea | |
| issues | 課題 | textarea | |
| remarks | 備考 | textarea | |

**項目の一覧が複数のファイルに重複して直書きされている**。項目を追加・変更するときは、次をすべて確認すること（1か所だけ直すと、コメントが登録できない・表示されない等の不具合になる）。

- `public/reports/detail.php`（`$fields`、`$multilineFields`）
- `public/comments/comment_store.php`、`comment_edit.php`、`comment_update.php`、`comment_delete.php`（許可リスト）
- `public/reports/create.php`、`edit.php`（入力フォーム）
- `public/reports/store.php`、`update.php`（INSERT / UPDATE）
- `public/index.php`（キーワード検索の対象カラム）
- `comments.field_name` に保存済みのデータ

「項目を管理する機能」を作る場合は、まず項目定義を1か所（共通定義または項目マスタテーブル）に集約し、上記ファイルがそれを参照するようにする。

役職リスト（一般〜部長）も `public/users/` の4ファイルと DB の CHECK 制約に重複しているので、同様に注意する。

## データベース

テーブル定義は `docs/detailed_design.md` の「1. データベース物理設計」を参照。要点:

- `users`（id, username UNIQUE, password_hash, role_title CHECK, manager_id → users.id, is_admin 0/1, created_at）
- `reports`（id, user_id → users.id, report_date, name, subject, content, issues, remarks, created_at, updated_at）
- `comments`（id, report_id → reports.id, user_id → users.id, field_name, comment, created_at, updated_at）
- 日時は TEXT 型で `Y-m-d H:i:s`、日付は `Y-m-d`
- スキーマを変更するときは、既存データの移行方法も提案すること（SQLite は `ALTER TABLE` の機能が限られる）

## 変更後の確認

1. 一般ユーザー・上司・管理者のそれぞれで、閲覧・編集・削除の可否が仕様どおりか確認する。
2. 監査ツールを実行して、新しい指摘が出ていないことを確認する（Ubuntu では `python3` だけで動く）。

   ```sh
   cd tools/audit && ./run_audit.sh
   ```

3. 画面や処理を追加・変更したら、`docs/` の設計書と `public/docs/`（操作説明・設計書の画面）も更新する。

## ブランチ

- `main` に直接コミットしない。作業ブランチを作って変更する。
