# レポート管理システム 詳細設計書

| 項目 | 内容 |
|---|---|
| 対象システム | レポート管理システム（report-system） |
| 対象ブランチ / コミット | `main` / `9ffe3e5`（作業ブランチ `dev_check_report` 作成時点） |
| 作成日 | 2026-11-12 |
| 更新日 | 2026-11-16：レポート項目管理機能を追加（ブランチ `feature/report-field-management`）。1、2、3、4.1、8 を更新し、9 を追加 |
| 作成方法 | ソースコードおよび `data/reports.sqlite` のスキーマ定義からの現状分析（リバース） |
| 関連文書 | [基本設計書](basic_design.md) |

> 画面・処理 ID（S-xx / P-xx）は基本設計書「4.1 画面・処理一覧」と対応します。

---

## 1. データベース物理設計

DB ファイル: `data/reports.sqlite`（SQLite 3）。日時はすべて `TEXT` 型で `Y-m-d H:i:s`（PHP の `date()`、サーバーのタイムゾーン）形式。

### 1.1 users

| No | カラム | 型 | NULL | 既定値 | 制約 | 説明 |
|---|---|---|---|---|---|---|
| 1 | id | INTEGER | × | AUTOINCREMENT | PK | ユーザーID |
| 2 | username | TEXT | × | | UNIQUE | ログイン名 |
| 3 | password_hash | TEXT | × | | | `password_hash()` の結果 |
| 4 | role_title | TEXT | × | `'一般'` | CHECK IN ('一般','主任','課長代理','課長','部長') | 役職 |
| 5 | manager_id | INTEGER | ○ | | FK → users.id ON DELETE SET NULL | 上司 |
| 6 | is_admin | INTEGER | × | 0 | CHECK IN (0,1) | 管理者フラグ |
| 7 | created_at | TEXT | × | | | 作成日時 |

インデックス: `sqlite_autoindex_users_1`（username の UNIQUE）

### 1.2 reports

| No | カラム | 型 | NULL | 制約 | 説明 |
|---|---|---|---|---|---|
| 1 | id | INTEGER | × | PK AUTOINCREMENT | レポートID |
| 2 | user_id | INTEGER | × | FK → users.id ON DELETE RESTRICT | 作成者 |
| 3 | report_date | TEXT | × | | 日付 `YYYY-MM-DD` |
| 4 | name | TEXT | × | | 氏名（自由入力。users とは連動しない） |
| 5 | subject | TEXT | × | | 件名 |
| 6 | content | TEXT | ○ | | 内容 |
| 7 | issues | TEXT | ○ | | 課題 |
| 8 | remarks | TEXT | ○ | | 備考 |
| 9 | created_at | TEXT | × | | 作成日時 |
| 10 | updated_at | TEXT | × | | 更新日時 |

### 1.3 comments

| No | カラム | 型 | NULL | 制約 | 説明 |
|---|---|---|---|---|---|
| 1 | id | INTEGER | × | PK AUTOINCREMENT | コメントID |
| 2 | report_id | INTEGER | × | FK → reports.id ON DELETE CASCADE | 対象レポート |
| 3 | user_id | INTEGER | × | FK → users.id ON DELETE RESTRICT | 投稿者 |
| 4 | field_name | TEXT | × | | 対象項目の内部コード（`report_fields.code`。既存 6 項目は reports のカラム名、追加項目は `f_7` など） |
| 5 | comment | TEXT | × | | 本文 |
| 6 | created_at | TEXT | × | | 作成日時 |
| 7 | updated_at | TEXT | × | | 更新日時 |

インデックス: `idx_comments_report_field (report_id, field_name)`

### 1.4 外部キーの実効性

`config/database.php` で `PRAGMA foreign_keys = ON` を実行していないため、上記の FK 制約はすべて**無効**（SQLite の既定値は OFF）。
そのため、次の動作はアプリケーション側で担保されているもの以外は発生しない。

| 定義 | 期待動作 | 実動作 |
|---|---|---|
| comments.report_id ON DELETE CASCADE | レポート削除でコメントも削除 | **削除されない（孤立コメントが残る）** |
| reports.user_id / comments.user_id RESTRICT | 参照中ユーザーの削除を拒否 | `user_delete.php` のアプリ側チェックで拒否 |
| users.manager_id SET NULL | 上司削除で部下の上司を NULL に | `user_delete.php` が部下のいるユーザーの削除自体を拒否 |

### 1.4.1 項目管理のテーブル（2026-11-16 追加）

項目管理のセットアップ画面（S-60）を実行したときに作る。定義は `lib/report_fields.php` の `reportFieldSchemaSql()`。外部キーは 1.4 と同じく無効のため、整合性はアプリ側で守る（項目・選択肢は使用中なら物理削除しない。レポート削除時は `delete.php` で値も削除する）。

**report_fields（項目定義）**

| No | カラム | 型 | NULL | 制約 | 説明 |
|---|---|---|---|---|---|
| 1 | id | INTEGER | × | PK AUTOINCREMENT | 項目ID |
| 2 | code | TEXT | × | UNIQUE | 内部コード。既存 6 項目は列名、追加項目は `f_{id}`。変更不可 |
| 3 | label | TEXT | × | | 表示名（100 文字以内） |
| 4 | field_type | TEXT | × | CHECK（text / textarea / number / date / select / radio / checkbox） | 種類。追加後は変更不可 |
| 5 | is_system | INTEGER | × | 0/1 | 固定項目（日付・氏名・件名） |
| 6 | is_active | INTEGER | × | 0/1 | 表示中 |
| 7 | is_required | INTEGER | × | 0/1 | 必須 |
| 8 | show_in_list | INTEGER | × | 0/1 | 一覧に表示 |
| 9 | is_searchable | INTEGER | × | 0/1 | キーワード検索の対象（テキストのみ） |
| 10 | sort_order | INTEGER | × | | 並び順（10 刻み） |
| 11 | max_length | INTEGER | ○ | | 最大文字数。NULL は既定値（1 行 200、複数行 10000） |
| 12 | min_value / max_value | REAL | ○ | | 数値の最小・最大。NULL は制限なし |
| 13 | allow_decimal | INTEGER | × | 0/1 | 数値で小数を許す |
| 14 | created_at / updated_at | TEXT | × | | 作成日時 / 最終更新日時 |
| 15 | updated_by | INTEGER | ○ | FK → users.id ON DELETE SET NULL | 最終更新者 |

インデックス: `idx_report_fields_order (sort_order, id)`

**report_field_options（選択肢）**

| No | カラム | 型 | NULL | 制約 | 説明 |
|---|---|---|---|---|---|
| 1 | id | INTEGER | × | PK AUTOINCREMENT | 選択肢ID |
| 2 | field_id | INTEGER | × | FK → report_fields.id ON DELETE RESTRICT | 項目 |
| 3 | label | TEXT | × | | 名前（同じ項目内で重複不可。アプリ側で確認） |
| 4 | sort_order | INTEGER | × | | 並び順 |
| 5 | is_active | INTEGER | × | 0/1 | 表示中 |
| 6 | created_at / updated_at / updated_by | | | | 1.4.1 report_fields と同じ |

インデックス: `idx_report_field_options_field_order (field_id, sort_order, id)`

**report_field_values（追加項目の値）**

| No | カラム | 型 | NULL | 制約 | 説明 |
|---|---|---|---|---|---|
| 1 | id | INTEGER | × | PK AUTOINCREMENT | |
| 2 | report_id | INTEGER | × | FK → reports.id ON DELETE CASCADE | レポート |
| 3 | field_id | INTEGER | × | FK → report_fields.id ON DELETE RESTRICT | 項目 |
| 4 | option_id | INTEGER | ○ | FK → report_field_options.id ON DELETE RESTRICT | 選んだ選択肢（プルダウン・ラジオ・チェックボックス） |
| 5 | value_text | TEXT | ○ | | テキスト・日付の値 |
| 6 | value_number | REAL | ○ | | 数値の値 |
| 7 | created_at / updated_at | TEXT | × | | |

- 既存 6 項目の値は reports の列に保存し、このテーブルには入れない（併用方式）。
- 未入力の項目は行を作らない。チェックボックスは選んだ選択肢の数だけ行を持つ。
- インデックス: `(report_id, field_id)`、`(field_id)`、`(option_id)`

### 1.5 データ件数（2026-11-12 時点の同梱 DB）

users 8 件 / reports 9 件 / comments 4 件

---

## 2. 共通モジュール

### 2.1 config/database.php

| 項目 | 内容 |
|---|---|
| 役割 | グローバル変数 `$pdo` に SQLite 接続を生成する |
| DSN | `sqlite:` + `__DIR__ . '/../data/reports.sqlite'` |
| 属性 | `ERRMODE_EXCEPTION`、`DEFAULT_FETCH_MODE = FETCH_ASSOC` |
| エラー時 | HTTP 500 + `データベース接続エラー: {例外メッセージ}` を出力して終了 |

### 2.2 lib/auth.php

| 関数 | 引数 / 戻り値 | 処理 |
|---|---|---|
| `startSession(): void` | | セッション未開始なら `session_start()` |
| `isLoggedIn(): bool` | | `$_SESSION['user_id']` が設定されているか |
| `requireLogin(): void` | | 未ログインなら `Location: login.php`（**相対パス**）で終了 |
| `getLoginUserId(): ?int` | | セッションのユーザーID |
| `getLoginUser(PDO): ?array` | id, username, role_title, manager_id, is_admin | DB からユーザー取得。存在しなければセッションを空にし ID 再生成して null |
| `isAdmin(PDO): bool` | | `is_admin === 1` |
| `requireAdmin(PDO): void` | | `requireLogin()` 後、管理者でなければ HTTP 403 `この操作を実行する権限がありません。` |

### 2.3 lib/functions.php

| 関数 | 処理 |
|---|---|
| `h($value)` | `htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8')` |

### 2.3.1 lib/csrf.php（2026-11-16 追加）

| 関数 | 処理 |
|---|---|
| `getCsrfToken(): string` | `$_SESSION['csrf_token']` を返す（なければ `random_bytes(32)` で作る）。ユーザー管理と同じキー |
| `verifyCsrfToken(): void` | POST `csrf_token` と `hash_equals()` で比較し、不一致は 403 |

### 2.3.2 lib/report_fields.php（2026-11-16 追加）

項目定義の共通処理。主な定数・関数:

| 名前 | 内容 |
|---|---|
| `REPORT_FIELD_TYPES` ほか | 種類、既定の最大文字数、上限値などの定数 |
| `countUtf8Chars()` | 文字数を正規表現で数える（mbstring 非依存） |
| `reportFieldTablesExist()` / `requireReportFieldTables()` | 3 テーブルの有無確認 / なければセットアップ画面へ |
| `reportFieldSchemaSql()` / `reportFieldInitialRows()` | テーブル定義 / 既存 6 項目の初期データ |
| `readReportFieldForm()` | 項目の追加・編集フォームの入力チェック（固定項目は表示名のみ） |
| `moveReportFieldSortOrder()` | 隣の行と並び順を入れ替える（全体を 10 刻みで振り直す） |
| `requireReportFieldAdmin()` | `requireAdmin()` ＋ログインユーザーID の取得 |

### 2.3.3 lib/report_values.php（2026-11-16 追加）

レポートの値の読み書き。既存 6 項目（`REPORT_COLUMN_FIELDS`）は reports の列、追加項目は `report_field_values` を使う。

| 関数 | 内容 |
|---|---|
| `loadReportFields($pdo, $activeOnly)` | 項目定義（選択肢つき）。セットアップ前は既存 6 項目の初期定義を返す |
| `loadReportFieldLabels()` | コメント用の 内部コード => 表示名 |
| `loadReportValues()` / `loadAddedFieldValues()` | 1 件 / 複数件の値の取得 |
| `formatReportValue()` | 表示用の文字にする（数値の整形、選択肢名への変換。チェックボックスは「、」区切り） |
| `readReportFormInput()` | 登録・更新で共通の入力チェック（必須・最大文字数・日付・数値の範囲と小数・選択肢）。エラーは一覧で返す |
| `buildReportColumnValues()` | reports の 6 列の値を作る（非表示の項目は今の値を残す） |
| `saveAddedFieldValues()` | 追加項目の値を入れ直す（非表示の項目は触らない） |
| `deleteReportFieldValues()` | レポート削除時に値を削除 |
| `buildReportKeywordCondition()` | キーワード検索の条件（列は LIKE、追加項目は EXISTS）。列名は許可リストのものだけを SQL に埋め込む |
| `isReportFieldUsed()` / `isReportFieldOptionUsed()` | 削除できるかの判定（値かコメントがあれば使用中） |

### 2.4 共通処理パターン

各ページで次の処理が個別に実装されている（共通関数化されていない）。

**(1) ログインユーザー取得**

```php
requireLogin();
$loginUser = getLoginUser($pdo);
if ($loginUser === null) { header('Location: ../login.php'); exit; }
$loginUserId = (int) $loginUser['id'];
$isAdmin = (int) $loginUser['is_admin'] === 1;
```

**(2) 閲覧可能ユーザーID の取得（自分 + 全配下）** — `detail.php`、`comment_store.php`、`comment_edit.php`、`comment_update.php`、`comment_delete.php` に同一 SQL が重複

```sql
WITH RECURSIVE subordinate_users(id) AS (
    SELECT id FROM users WHERE id = :user_id
    UNION
    SELECT u.id FROM users u
    INNER JOIN subordinate_users s ON u.manager_id = s.id
)
SELECT id FROM subordinate_users
```

`index.php` のみ、起点を `WHERE manager_id = :manager_id`（配下のみ）とし `UNION ALL` を使用し、自分の ID は PHP 側で追加する別実装になっている。

**(3) ID パラメータ検証**

- レポート・コメント系: `filter_input(..., FILTER_VALIDATE_INT)` で `false` / `null` / `<= 0` を HTTP 400。
- ユーザー管理系: `is_string` かつ `ctype_digit` かつ `> 0`。

**(4) CSRF トークン（ユーザー管理のみ）**

- 画面表示時: `$_SESSION['csrf_token']` が空なら `bin2hex(random_bytes(32))` を生成し hidden に埋め込む。セッション中は同じトークンを使い回す。
- POST 時: `hash_equals` で比較。不一致は HTTP 403。

---

## 3. レポート機能

### 3.1 S-10 レポート一覧（index.php）

**入力（GET）**

| パラメータ | 説明 |
|---|---|
| name | 氏名の部分一致（trim 後） |
| keyword | 「キーワード検索の対象」の項目の部分一致（trim 後）。初期設定では件名・内容・課題・備考 |

**処理**

1. ログインユーザー取得（2.4(1)。ただし失敗時の遷移先は `login.php`）。
2. 管理者以外は閲覧可能ユーザーID を取得（2.4(2) の index 版）。
3. 次の SQL を組み立てて実行。

```sql
SELECT * FROM reports WHERE 1 = 1
  [AND name LIKE :name]                              -- '%name%'
  [AND (subject LIKE :keyword OR content LIKE :keyword
        OR issues LIKE :keyword OR remarks LIKE :keyword)]
  [AND user_id IN (:user_id_0, :user_id_1, ...)]     -- 管理者以外
ORDER BY report_date DESC, id DESC
```

LIKE の `%` / `_` はエスケープしていない（入力した `%` はワイルドカードとして働く）。ページングなし（全件表示）。

**2026-11-16 変更**: `FROM reports r` とし、keyword の条件は `buildReportKeywordCondition()` で作る（検索対象の既存列の LIKE と、追加項目の `EXISTS (SELECT 1 FROM report_field_values v WHERE v.report_id = r.id AND v.field_id IN (...) AND v.value_text LIKE :keyword)` の OR）。検索欄のプレースホルダーは検索対象の項目名。

**表示**

| 列 | 内容 |
|---|---|
| ID | reports.id |
| 「一覧に表示」の項目 | 並び順どおり。日付・氏名・件名は固定で表示。追加項目の値は `loadAddedFieldValues()` でまとめて取得し `formatReportValue()` で表示 |
| 操作 | 編集（`$canEdit` 時）、コメント（常に表示 → detail.php）、削除（`$canEdit` 時、JS confirm 後 POST） |

`$canEdit = $isAdmin || report.user_id === $loginUserId`。非表示ボタンは同幅の空白で位置を揃える。
ヘッダーにログインユーザー名、ユーザー管理・項目管理（管理者のみ）、新規登録、ログアウト。

### 3.2 S-11 / P-11 レポート登録（create.php → store.php）

**2026-11-16 変更**: 入力欄と入力チェックを項目定義から作る。

**入力欄**: 表示中の項目を並び順に表示（`reports/_fields.php`）。name 属性は内部コード（チェックボックスは `code[]`）。日付（report_date）の既定値は今日。必須の項目は「（必須）」と表示。選択肢は表示中のものだけ。

**サーバー検証**（`readReportFormInput()`。更新と共通）

| 種類 | 検証 |
|---|---|
| 共通 | 必須の項目が空ならエラー。文字列以外（配列等）はエラー |
| 1 行テキスト | 前後の空白を除いて保存。最大文字数（未設定は 200）以内 |
| 複数行テキスト | 入力のまま保存（空白だけなら未入力）。最大文字数（未設定は 10000）以内 |
| 日付 | `!Y-m-d` で解析し、`getLastErrors()` に警告・エラーがなく、再フォーマットが一致 |
| 数値 | 整数のみ（または小数可）の形式、最小値・最大値の範囲内 |
| プルダウン・ラジオ・チェックボックス | 表示中の選択肢であること（更新時は、そのレポートで選択済みの非表示の選択肢も可） |

既存 6 項目の初期設定では、氏名は 100 文字、件名は 200 文字、内容・課題・備考は 10000 文字まで（以前は登録時に長さチェックなし、更新時はバイト数で確認していた）。

**処理**: トランザクション内で `INSERT INTO reports (...)`（6 列は `buildReportColumnValues()`）→ `saveAddedFieldValues()`。
**正常終了**: `../index.php` へリダイレクト。
**エラー**: 非 POST → `create.php` へ。入力エラー → 400 で理由を一覧表示（「戻る」で入力画面へ）。DB エラー → 500 `レポートを登録できませんでした。`（詳細は `error_log`）

### 3.3 S-12 レポート詳細（reports/detail.php）

**入力**: GET `id`

**処理**

1. ID 検証 → `SELECT * FROM reports WHERE id = :id`（無ければ 404）。
2. 管理者以外は閲覧可能ユーザーID に `reports.user_id` が含まれなければ 403 `このレポートを参照する権限がありません。`
3. 項目定義（2026-11-16 変更）: `loadReportFields($pdo, true)` で表示中の項目を取得し、`loadReportValues()` で値を取得する。複数行テキストの項目は表示枠を高くする。値は `formatReportValue()` で表示用の文字にする。非表示の項目は表示しない（値とコメントは残る）。

4. `SELECT * FROM comments WHERE report_id = :report_id ORDER BY created_at ASC, id ASC` で取得し、`field_name` ごとに振り分け（定義外の field_name は無視）。

**表示（項目ごとに1行、3列グリッド）**

| 列 | 内容 |
|---|---|
| 1 | 💬 ボタン。コメントがあれば青色＋件数バッジ |
| 2 | 項目名 |
| 3 | 値（`white-space: pre-wrap`） |

💬 クリックで吹き出し（`#comments-{field_name}`）を開閉。吹き出しにはコメント一覧（本文、登録日時、更新日時※created_at と異なる場合）、各コメントの編集リンク・削除フォーム、新規コメント入力欄（maxlength=5000, required）を表示。
**コメントの編集・削除ボタンは権限に関係なく全員に表示**される（サーバー側で拒否）。投稿者名は表示しない。

**JavaScript**: 一度に開く吹き出しは1つ。外側クリック・Esc で閉じる。URL ハッシュが `#comments-xxx` なら該当吹き出しを開いた状態で表示（コメント操作後の戻り先で利用）。

### 3.4 S-13 / P-13 レポート編集（edit.php → update.php）

**権限**: 管理者、または `reports.user_id === ログインユーザー`。それ以外は 403（配下のレポートでも編集不可）。

**edit.php**: GET `id` → レポート取得（404）→ 権限確認 → 登録画面と同じ項目を現在値付きで表示。hidden `id`。

**update.php 入力検証**（2026-11-16 変更）

| 順序 | 検証 | エラー |
|---|---|---|
| 1 | POST 以外 | `../index.php` へリダイレクト |
| 2 | id（FILTER_VALIDATE_INT, > 0） | 400 `レポートIDが正しくありません。` |
| 3 | レポート存在 | 404 |
| 4 | 権限 | 403 `このレポートを更新する権限がありません。` |
| 5 | 項目定義による検証（3.2 と同じ `readReportFormInput()`） | 400 で理由を一覧表示 |

**処理**: トランザクション内で `UPDATE reports SET report_date, name, subject, content, issues, remarks, updated_at WHERE id = :id` → `saveAddedFieldValues()`。**非表示の項目は今の値を残す**（列は `buildReportColumnValues()`、追加項目は保存対象から外す）。`user_id` は変更しない。
**正常終了**: `../index.php` へ。

### 3.5 P-14 レポート削除（reports/delete.php）

1. POST 以外 → `../index.php`。
2. POST `id` 検証 → `SELECT id, user_id FROM reports`（404）。
3. 権限（管理者 or 作成者）でなければ 403。
4. トランザクション内で `deleteReportFieldValues()`（追加項目の値を削除。2026-11-16 追加）→ `DELETE FROM reports WHERE id = :id`。**コメントは削除しない**（1.4 参照）。
5. `../index.php` へ。

---

## 4. コメント機能

### 4.1 許可される field_name

2026-11-16 変更: 項目定義の内部コード（`loadReportFieldLabels()`）。

- `comment_store.php`：表示中の項目だけ（非表示の項目には新しくコメントできない）
- `comment_edit.php`、`comment_update.php`、`comment_delete.php`：非表示の項目も含む（既存コメントを扱えるようにする）

### 4.2 権限判定（共通）

| 判定 | 内容 | 適用 |
|---|---|---|
| A. レポート閲覧権限 | 管理者、またはレポート作成者が「自分＋全配下」に含まれる | 登録・編集・更新・削除 |
| B. コメント所有 | 管理者、または `comments.user_id === ログインユーザー` | 編集・更新・削除 |

### 4.3 P-20 コメント登録（comment_store.php）

**入力（POST）**: `report_id`、`field_name`、`comment`

| 順序 | 検証 | エラー |
|---|---|---|
| 1 | POST 以外 | `../index.php` へ |
| 2 | report_id（INT > 0） | 400 `レポートIDが正しくありません。` |
| 3 | field_name が許可リストにある | 400 `コメント対象の項目が正しくありません。` |
| 4 | comment（trim 後）が空でない | 400 `コメントを入力してください。` |
| 5 | comment ≤ 15,000 バイト | 400 `コメントは15000バイト以内で入力してください。` |
| 6 | レポート存在 | 404 `対象のレポートが見つかりません。` |
| 7 | 判定 A | 403 `このレポートにコメントを登録する権限がありません。` |

**処理**: `INSERT INTO comments (report_id, user_id, field_name, comment, created_at, updated_at)`、本文は trim 後の値。
**正常終了**: `../reports/detail.php?id={report_id}#comments-{field_name}`

### 4.4 S-21 / P-21 コメント編集（comment_edit.php → comment_update.php）

**取得 SQL（共通）**

```sql
SELECT c.id, c.report_id, c.user_id AS comment_user_id, c.field_name,
       c.comment, c.created_at, c.updated_at, r.user_id AS report_user_id
FROM comments c INNER JOIN reports r ON r.id = c.report_id
WHERE c.id = :id
```

（レポートが削除済みの孤立コメントは INNER JOIN で取得できず 404 になる）

**comment_edit.php**: GET `id` → 取得（404）→ 判定 A（403）→ 判定 B（403）→ field_name 検証（400）→「対象項目：{表示名}」とテキストエリア（maxlength=5000）を表示。キャンセルは詳細画面の該当吹き出しへ。

**comment_update.php**

| 順序 | 検証 | エラー |
|---|---|---|
| 1 | POST 以外 | **405** `許可されていないアクセスです。` |
| 2 | comment_id（INT > 0） | 400 |
| 3 | comment（trim 後）が空でない、≤ 15,000 バイト | 400 |
| 4 | コメント存在 | 404 |
| 5 | 判定 A / 判定 B | 403 |
| 6 | 保存済み field_name が許可リストにある | 400 |

**処理**: `UPDATE comments SET comment, updated_at WHERE id`。field_name・report_id は変更不可。
**正常終了**: 詳細画面の該当吹き出しへ。

### 4.5 P-22 コメント削除（comment_delete.php）

POST `comment_id`（詳細画面のフォームは `report_id`、`field_name` も送るが**使用しない**。DB の値を使う）。
検証順序は 4.4 の update と同様（405 → 400 → 404 → 403 → 400）。`DELETE FROM comments WHERE id` → 詳細画面の該当吹き出しへ。

---

## 5. ユーザー管理機能

全ファイル冒頭で `requireAdmin($pdo)` と `startSession()` を実行。エラー時は入力画面または一覧へ `?error={メッセージ}` 付きでリダイレクトし、画面上部にメッセージを表示する（成功時は `?message=`）。

役職リスト `['一般','主任','課長代理','課長','部長']` は `user_create.php`、`user_store.php`、`user_edit.php`、`user_update.php` にハードコード（DB の CHECK 制約とも一致させる必要あり）。

### 5.1 S-30 ユーザー一覧（users/users.php）

**取得 SQL**

```sql
SELECT u.id, u.username, u.role_title, u.manager_id, u.is_admin, u.created_at,
       m.username AS manager_name,
       (SELECT COUNT(*) FROM reports r WHERE r.user_id = u.id) AS report_count,
       (SELECT COUNT(*) FROM comments c WHERE c.user_id = u.id) AS comment_count
FROM users u LEFT JOIN users m ON u.manager_id = m.id
ORDER BY u.id ASC
```

**表示列**: ID、ユーザー名（自分なら「（ログイン中）」）、役職、上司、管理者（管理者／一般）、報告書数、コメント数、作成日時、操作。

**削除ボタン表示条件**: `!自分 && 報告書0 && コメント0 && !最後の管理者`。不可の場合は理由を表示。
※「部下がいる」条件は判定していない（ボタンは出るが削除処理で拒否される）。

### 5.2 S-31 / P-31 ユーザー登録（user_create.php → user_store.php）

**入力項目**

| 項目 | name | HTML 制約 | サーバー検証 |
|---|---|---|---|
| ユーザー名 | username | maxlength=100, required | trim 後必須、≤ 300 バイト、重複不可 |
| 初期パスワード | password | minlength=8, required | 8〜4096 バイト |
| 役職 | role_title | select | 役職リストに含まれる |
| 上司 | manager_id | select（全ユーザー、空=なし） | 空または正の整数で、存在するユーザー |
| 管理者権限 | is_admin | checkbox value=1 | 送信されていれば 1（値は見ない） |

**処理順序**: POST 確認 → CSRF → 各検証 → 重複確認 → `password_hash` → `INSERT INTO users`。
**正常終了**: `users.php?message=ユーザーを登録しました。`
**DB 例外**: `error_log` 後、`user_create.php?error=…` へ。
※エラーで戻った際、入力値は保持されない。

### 5.3 S-32 / P-32 ユーザー編集（user_edit.php → user_update.php）

**user_edit.php**

- GET `id` 検証 → ユーザー取得（無ければ一覧へ error）。
- 上司候補: 自分自身と全配下を除いたユーザー。

```sql
WITH RECURSIVE descendants(id) AS (
    SELECT id FROM users WHERE manager_id = :user_id
    UNION
    SELECT u.id FROM users u INNER JOIN descendants d ON u.manager_id = d.id
)
SELECT id, username, role_title FROM users
WHERE id <> :exclude_id AND id NOT IN (SELECT id FROM descendants)
ORDER BY id ASC
```

- パスワード欄は空欄で表示（空欄なら変更しない）。

**user_update.php 処理順序**

| 順序 | 処理 | エラー |
|---|---|---|
| 1 | POST 確認、CSRF 検証 | 一覧へ / 403 |
| 2 | id 検証 | 一覧へ error |
| 3 | 入力が全て string | 編集画面へ error |
| 4 | 対象ユーザー存在 | 一覧へ error |
| 5 | ユーザー名: 必須、≤ 300 バイト、他ユーザーと重複しない | 編集画面へ error |
| 6 | パスワード: 入力時のみ 8〜4096 バイト | 〃 |
| 7 | 役職: リスト内 | 〃 |
| 8 | 上司: 正の整数、自分以外、存在する、自分の配下でない（循環防止） | 〃 |
| 9 | トランザクション開始 | |
| 10 | 管理者 → 非管理者 への変更で、管理者数 ≤ 1 なら拒否 | 編集画面へ error |
| 11 | UPDATE（パスワード入力時は password_hash も更新） | |
| 12 | コミット → `users.php?message=ユーザー情報を更新しました。` | DB 例外は error_log 後、編集画面へ |

`is_admin` は POST 値が文字列 `'1'` のときだけ 1。
※自分自身の管理者権限を外すことは（他に管理者がいれば）可能で、その場合は更新直後から管理画面にアクセスできなくなる。

### 5.4 P-33 ユーザー削除（user_delete.php）

| 順序 | 検証 | エラーメッセージ |
|---|---|---|
| 1 | POST、CSRF | 一覧へ / 403 |
| 2 | id 検証 | `ユーザーIDが正しくありません。` |
| 3 | 自分自身でない | `ログイン中のユーザー自身は削除できません。` |
| 4 | （トランザクション内）対象が存在 | `削除対象のユーザーが見つかりません。` |
| 5 | 報告書が 0 件 | `このユーザーには報告書が登録されているため削除できません。` |
| 6 | コメントが 0 件 | `このユーザーにはコメントが登録されているため削除できません。` |
| 7 | 部下（manager_id = 対象）が 0 人 | `このユーザーを上司に設定しているユーザーがいるため削除できません。…` |
| 8 | 管理者なら管理者数 > 1 | `最後の管理者は削除できません。` |
| 9 | `DELETE FROM users WHERE id`、rowCount = 1 | `ユーザーを削除できませんでした。` |

**正常終了**: `users.php?message=ユーザー「{username}」を削除しました。`

---

## 6. 認証・その他

### 6.1 S-01 ログイン（login.php）

1. ログイン済みなら `index.php` へ。
2. POST 時: username（trim）と password が空なら `ユーザー名とパスワードを入力してください。`
3. `SELECT id, username, password_hash FROM users WHERE username = :username` → `password_verify`。
4. 成功: `session_regenerate_id(true)`（失敗時は RuntimeException）→ `$_SESSION['user_id']` 設定 → `index.php` へ。
5. 失敗: `ユーザー名またはパスワードが正しくありません。`（どちらが誤りかは示さない）。
6. 画面下部に「画面操作説明」「システム設計書」へのリンク。

ログイン試行回数の制限はない。ユーザー名入力欄の maxlength は 50（ユーザー登録画面は 100）。

### 6.2 P-02 ログアウト（logout.php）

`$_SESSION = []` → セッション Cookie を過去日時で上書き → `session_destroy()` → `login.php` へ。GET でも実行される。

### 6.3 S-50 初期管理者登録（setup_admin.php、公開ディレクトリ外）

- users が 1 件以上なら実行不可。
- 入力: username（1〜50 バイト、制御文字不可）、password（12 バイト以上）、確認用 password（一致）。
- トランザクション内で再度 0 件を確認し、`role_title='部長'`、`manager_id=NULL`、`is_admin=1` で INSERT。
- 完了画面で本ファイルの削除を促す。

### 6.4 P-51 テーブル作成（public/setup.php）

認証なしで `CREATE TABLE IF NOT EXISTS reports (...)` を実行する。定義は `user_id` を含まない**旧スキーマ**であり、現行 DB では既にテーブルが存在するため何も作成されない。users / comments の作成処理はない。

### 6.5 S-40 / S-41 ドキュメント

`docs/manual.php`、`docs/design.php` は PHP 処理を含まない静的 HTML（認証なし）。

---

## 7. メッセージ・ステータスコード一覧

| HTTP | 発生箇所 | 主なメッセージ |
|---|---|---|
| 302 | 処理完了、未ログイン、非 POST | （リダイレクト） |
| 400 | ID・入力値・日付・項目名・長さの不正 | `レポートIDが正しくありません。`、`必須項目が入力されていません。`、`日付の形式が正しくありません。`、`コメント対象の項目が正しくありません。` 等 |
| 403 | 権限なし、CSRF 不一致 | `このレポートを参照する権限がありません。`、`このコメントを編集する権限がありません。`、`この操作を実行する権限がありません。`、`不正なリクエストです。…` |
| 404 | レポート・コメントが存在しない | `指定されたレポートが見つかりません。`、`指定されたコメントが見つかりません。` |
| 405 | comment_update / comment_delete への非 POST | `許可されていないアクセスです。` |
| 500 | DB 接続失敗、パスワードハッシュ失敗 | `データベース接続エラー: …`、`パスワードの処理に失敗しました。` |

レポート・コメント系のエラーはプレーンテキスト 1 行の画面になる（戻るリンクなし）。

---

## 8. 課題一覧

| No | 重要度 | 箇所 | 内容 | 対応案 |
|---|---|---|---|---|
| 1 | 高 | `lib/auth.php` `requireLogin()` | `Location: login.php` が相対パスのため、`reports/`・`comments/`・`users/` 配下で未ログイン時に `reports/login.php` 等（存在しない）へ遷移する | ルートからのパス、または `__DIR__` 基準でログイン URL を組み立てる |
| 2 | 高 | `config/database.php` | `PRAGMA foreign_keys = ON` 未設定。レポート削除時にコメントが残る | 接続直後に有効化。既存の孤立コメントを確認・削除 |
| 3 | 高 | reports / comments の POST、logout | CSRF 対策なし | ユーザー管理と同じトークン方式を共通化して適用 |
| 4 | 中 | detail / comment_* の 5 ファイル + reports 画面 | ~~項目定義・許可リストが重複ハードコード~~ | **2026-11-16 解消**（項目管理機能。9 章） |
| 5 | 中 | 閲覧範囲 SQL | 同一の再帰 SQL が 5 ファイルに重複。index.php のみ別実装（UNION ALL で、データに循環があると無限再帰の恐れ） | 共通関数化し UNION に統一 |
| 6 | 中 | store.php / update.php | ~~長さチェック・trim・未送信時の扱いが登録と更新で不一致~~ | **2026-11-16 解消**（`readReportFormInput()` に共通化） |
| 7 | 中 | `public/setup.php` | 認証なしで公開、旧スキーマ | 削除または公開ディレクトリ外へ移動 |
| 8 | 中 | `config/database.php` | 接続エラー時に例外メッセージを表示 | 汎用メッセージ + `error_log` |
| 9 | 中 | リポジトリ | 本番 DB（パスワードハッシュ含む）と backup をコミット | `.gitignore` 対象にし、スキーマ SQL を別途管理 |
| 10 | 低 | users.php | 削除可否表示に「部下あり」条件がない | 一覧 SQL に部下数を追加 |
| 11 | 低 | detail.php | 権限のないコメントにも編集・削除ボタンを表示。投稿者名を表示しない | 権限に応じて表示制御、投稿者名を JOIN して表示 |
| 12 | 低 | 入力制限 | HTML は文字数、サーバーはバイト数で、上限が一致しない（コメント 5000 文字 / 15000 バイト等）。ログインの maxlength 50 とユーザー登録 100 も不一致 | 方針を統一 |
| 13 | 低 | index.php | ページングなし、LIKE のワイルドカード未エスケープ | 件数増加時に対応 |
| 14 | 低 | ログイン | 試行回数制限なし | 必要に応じてロックアウト等 |
| 15 | 低 | `docs/design.php` | 「レポート削除時にコメントも削除」など実動作と異なる記述 | 本書の内容で更新 |
| 16 | 中 | `public/report_fields/` | 未ログインで開くと No.1 と同じく存在しない `report_fields/login.php` へ遷移する | No.1 の対応で解消 |
| 17 | 低 | reports/store.php・update.php | 入力エラー時は理由を一覧表示するだけで、入力画面に値を戻さない（従来と同じ方式） | 入力画面へ戻して値とエラーを表示する |

---

## 9. 項目管理機能（2026-11-16 追加）

管理者（`requireAdmin()`）だけが使える。すべての POST は CSRF トークン（`lib/csrf.php`）を確認する。エラー・完了メッセージは `?error=` / `?message=` 付きのリダイレクトで表示する（ユーザー管理と同じ方式）。

### 9.1 S-60 セットアップ（report_fields/setup.php）

| 区分 | 内容 |
|---|---|
| 表示 | 3 テーブルの有無、既存 6 項目の登録状況、自動バックアップ先の設定状況 |
| バックアップ先 | `config/backup.php` の `dir`。絶対パスであること、アプリのフォルダの外であることを確認。空なら「DB ファイルを手で保存しました」のチェックを必須にする |
| 処理 | `BEGIN IMMEDIATE` → DB ファイルをバックアップ先へコピー（`reports_before_report_fields_YYYYMMDD_HHMMSS.sqlite`、権限 0600）→ `reportFieldSchemaSql()` を実行 → 項目定義が空のときだけ既存 6 項目を登録 → `COMMIT`。失敗時は `ROLLBACK` して DB は変更しない |
| 再実行 | 何度実行しても安全（`IF NOT EXISTS`）。既存 6 項目の再登録は項目定義が空のときだけ（削除した項目を復活させない） |

既存 6 項目の初期値:

| code | label | type | 固定 | 必須 | 一覧 | 検索 | 最大文字数 |
|---|---|---|---|---|---|---|---|
| report_date | 日付 | date | ○ | ○ | ○ | － | － |
| name | 氏名 | text | ○ | ○ | ○ | × | 100 |
| subject | 件名 | text | ○ | ○ | ○ | ○ | 200 |
| content / issues / remarks | 内容 / 課題 / 備考 | textarea | | | | ○ | 既定（10000） |

### 9.2 S-61 項目一覧（report_fields/index.php）

項目定義が 3 テーブルそろっていなければ S-60 へ移動。並び順に、↑↓、表示名（固定項目は「固定」）、種類（選択肢の件数。0 件なら「選択肢なし」）、必須、一覧に表示、キーワード検索、入力チェックの要約、状態、最終更新（日時・更新者）、操作（編集、選択肢、非表示にする／再表示する、削除）を表示する。

- 非表示の切り替え・削除のボタンは固定項目には出さない。
- 削除ボタンは `isReportFieldUsed()` が false の項目だけに出す。

### 9.3 項目の追加・編集（create.php → store.php、edit.php → update.php）

| 入力 | 検証（`readReportFieldForm()`） |
|---|---|
| 表示名 | 必須、100 文字以内、制御文字・改行不可 |
| 種類 | 追加時のみ。`REPORT_FIELD_TYPES` のいずれか |
| 必須 / 一覧に表示 | チェックボックス |
| キーワード検索の対象・最大文字数 | テキストの種類のみ。最大文字数は 1〜100000 の整数（空は既定値） |
| 小数の可否・最小値・最大値 | 数値のみ。±1 兆以内。整数のみの場合は最小・最大も整数。最小 ≤ 最大 |

- 追加時の code は、仮の値で INSERT した後に `f_{id}` へ更新する（同じトランザクション内）。並び順は最後。
- 選択肢の種類を追加した場合は、選択肢の管理画面（S-67）へ移動する。
- 編集では種類・code・固定区分・表示状態・並び順は変更しない。固定項目は表示名だけ受け付ける。
- 入力エラー時は、送られた値をセッションに一時保存（`rememberFormInput()`）してフォームへ戻し、フォームを開いたときに取り出して（`takeFormInput()`）入力欄に入れ直す。選択肢の追加・名前変更も同じ。
- 追加画面では、選んだ種類に合う設定欄（テキストの設定・数値の設定）だけを CSS の `:has()` で表示する（JavaScript なし）。`:has()` に対応していないブラウザでは全部表示する。

### 9.4 表示切替・並び替え・削除（toggle.php、move.php、delete.php）

| 処理 | 内容 |
|---|---|
| toggle | is_active を反転。固定項目は拒否 |
| move | POST `direction`（up / down）。`moveReportFieldSortOrder()` で隣と入れ替え、動いた 2 行の最終更新者・日時を記録 |
| delete | `BEGIN IMMEDIATE` → 固定項目・使用中（`isReportFieldUsed()`：コメントあり、既存列に値あり、値テーブルに値あり）なら拒否 → 選択肢と項目を削除 → `COMMIT` |

### 9.5 選択肢の管理（options.php ほか）

GET `field_id`（選択肢の種類以外は一覧へ戻す）。選択肢の一覧（↑↓、名前の変更欄、状態、最終更新、非表示にする／再表示する、削除）と追加欄。

| 処理 | 内容 |
|---|---|
| option_store | 名前の検証（表示名と同じ）。同じ項目内に同名があれば拒否（非表示なら「再表示してください」） |
| option_update | 名前の変更。同名（非表示を含む）があれば拒否。名前を変えると過去のレポートも新しい名前で表示される |
| option_toggle / option_move | 項目と同じ |
| option_delete | `BEGIN IMMEDIATE` → 選んだ値があれば拒否 → 削除 |
