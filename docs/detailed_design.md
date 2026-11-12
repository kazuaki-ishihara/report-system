# レポート管理システム 詳細設計書

| 項目 | 内容 |
|---|---|
| 対象システム | レポート管理システム（report-system） |
| 対象ブランチ / コミット | `main` / `9ffe3e5`（作業ブランチ `dev_check_report` 作成時点） |
| 作成日 | 2026-11-12 |
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
| 4 | field_name | TEXT | × | | 対象項目（reports のカラム名） |
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
| keyword | 件名・内容・課題・備考の部分一致（trim 後） |

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

**表示**

| 列 | 内容 |
|---|---|
| ID / 日付 / 氏名 / 件名 | reports の各値 |
| 操作 | 編集（`$canEdit` 時）、コメント（常に表示 → detail.php）、削除（`$canEdit` 時、JS confirm 後 POST） |

`$canEdit = $isAdmin || report.user_id === $loginUserId`。非表示ボタンは同幅の空白で位置を揃える。
ヘッダーにログインユーザー名、ユーザー管理（管理者のみ）、新規登録、ログアウト。

### 3.2 S-11 / P-11 レポート登録（create.php → store.php）

**入力項目**

| 項目 | name | HTML 制約 | サーバー検証 |
|---|---|---|---|
| 日付 | report_date | date, required, 既定値=今日 | 必須（trim 後）、`!Y-m-d` で解析し再フォーマットが一致すること |
| 氏名 | name | maxlength=100, required | 必須（trim 後）。**長さチェックなし** |
| 件名 | subject | maxlength=200, required | 必須（trim 後）。**長さチェックなし** |
| 内容 | content | textarea | なし |
| 課題 | issues | textarea | なし |
| 備考 | remarks | textarea | なし |

- 文字列以外（配列等）が送信された項目は空文字として扱う。
- 保存値は **trim しない元の値**（必須判定のみ trim）。

**処理**: `INSERT INTO reports (user_id, report_date, name, subject, content, issues, remarks, created_at, updated_at)`。`user_id` はログインユーザーID、`created_at = updated_at = 現在時刻`。
**正常終了**: `../index.php` へリダイレクト。
**エラー**: 非 POST → `create.php` へ。必須不足 → 400 `必須項目が入力されていません。` / 日付不正 → 400 `日付の形式が正しくありません。`

### 3.3 S-12 レポート詳細（reports/detail.php）

**入力**: GET `id`

**処理**

1. ID 検証 → `SELECT * FROM reports WHERE id = :id`（無ければ 404）。
2. 管理者以外は閲覧可能ユーザーID に `reports.user_id` が含まれなければ 403 `このレポートを参照する権限がありません。`
3. 項目定義（ハードコード）:

```php
$fields = [
    'report_date' => '日付', 'name' => '氏名', 'subject' => '件名',
    'content' => '内容', 'issues' => '課題', 'remarks' => '備考',
];
$multilineFields = ['content', 'issues', 'remarks'];  // 表示枠を高くする
```

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

**update.php 入力検証**

| 順序 | 検証 | エラー |
|---|---|---|
| 1 | POST 以外 | `../index.php` へリダイレクト |
| 2 | id（FILTER_VALIDATE_INT, > 0） | 400 `レポートIDが正しくありません。` |
| 3 | レポート存在 | 404 |
| 4 | 権限 | 403 `このレポートを更新する権限がありません。` |
| 5 | 6項目すべて string であること（未送信も不可） | 400 `入力値が正しくありません。` |
| 6 | 必須: report_date（trim なし）、name・subject（trim 後） | 400 `必須項目が入力されていません。` |
| 7 | 日付: `!Y-m-d` + `getLastErrors()` 警告/エラーなし + 再フォーマット一致 | 400 `日付の形式が正しくありません。` |
| 8 | name ≤ 300 バイト、subject ≤ 600 バイト | 400 `氏名が長すぎます。` / `件名が長すぎます。` |

**処理**: `UPDATE reports SET report_date, name, subject, content, issues, remarks, updated_at WHERE id = :id`。name・subject は **trim 後の値**で保存（登録時と異なる）。`user_id` は変更しない。
**正常終了**: `../index.php` へ。

### 3.5 P-14 レポート削除（reports/delete.php）

1. POST 以外 → `../index.php`。
2. POST `id` 検証 → `SELECT id, user_id FROM reports`（404）。
3. 権限（管理者 or 作成者）でなければ 403。
4. `DELETE FROM reports WHERE id = :id`。**コメントは削除しない**（1.4 参照）。
5. `../index.php` へ。

---

## 4. コメント機能

### 4.1 許可される field_name

`report_date` / `name` / `subject` / `content` / `issues` / `remarks`。
`comment_store.php`、`comment_edit.php`、`comment_update.php`、`comment_delete.php` のそれぞれに配列でハードコード（`detail.php` と合わせ計5箇所）。

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
| 4 | 中 | detail / comment_* の 5 ファイル + reports 画面 | 項目定義・許可リストが重複ハードコード | 項目定義を一元化（共通定義またはマスタテーブル） |
| 5 | 中 | 閲覧範囲 SQL | 同一の再帰 SQL が 5 ファイルに重複。index.php のみ別実装（UNION ALL で、データに循環があると無限再帰の恐れ） | 共通関数化し UNION に統一 |
| 6 | 中 | store.php / update.php | 長さチェック・trim・未送信時の扱いが登録と更新で不一致 | 検証処理を共通化 |
| 7 | 中 | `public/setup.php` | 認証なしで公開、旧スキーマ | 削除または公開ディレクトリ外へ移動 |
| 8 | 中 | `config/database.php` | 接続エラー時に例外メッセージを表示 | 汎用メッセージ + `error_log` |
| 9 | 中 | リポジトリ | 本番 DB（パスワードハッシュ含む）と backup をコミット | `.gitignore` 対象にし、スキーマ SQL を別途管理 |
| 10 | 低 | users.php | 削除可否表示に「部下あり」条件がない | 一覧 SQL に部下数を追加 |
| 11 | 低 | detail.php | 権限のないコメントにも編集・削除ボタンを表示。投稿者名を表示しない | 権限に応じて表示制御、投稿者名を JOIN して表示 |
| 12 | 低 | 入力制限 | HTML は文字数、サーバーはバイト数で、上限が一致しない（コメント 5000 文字 / 15000 バイト等）。ログインの maxlength 50 とユーザー登録 100 も不一致 | 方針を統一 |
| 13 | 低 | index.php | ページングなし、LIKE のワイルドカード未エスケープ | 件数増加時に対応 |
| 14 | 低 | ログイン | 試行回数制限なし | 必要に応じてロックアウト等 |
| 15 | 低 | `docs/design.php` | 「レポート削除時にコメントも削除」など実動作と異なる記述 | 本書の内容で更新 |
