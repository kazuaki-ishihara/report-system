# 詳細設計書 DB 編

SQLite 3。テーブルを作る SQL は `app/sql/schema.sql` に置き、何度流しても同じ結果になるように書く（`CREATE TABLE IF NOT EXISTS` など）。

テーブル名と列名は、依頼元の課長が開発中の項目マスタ（`feature/dynamic-report-fields`）の `report_fields`・`report_records`・`report_field_values`・`report_field_comments` に合わせ、列を足す形にする。

---

## 1. 共通の決まり

| 決まり | 内容 |
|---|---|
| 接続ごとの設定 | `PRAGMA foreign_keys = ON;` `PRAGMA busy_timeout = 5000;` `PRAGMA journal_mode = WAL;` |
| 日時 | `TEXT`、日本時間の `YYYY-MM-DD HH:MM:SS` |
| 日付・年月 | `YYYY-MM-DD`・`YYYY-MM` |
| 真偽 | `INTEGER` の 0／1 に `CHECK` を付ける |
| 削除 | 業務のデータは `deleted_at` を入れる（論理削除）。`trash_days` を過ぎたら物理削除する |
| 暗号化した列 | 名前の末尾を `_enc` にするか、`is_encrypted` の列を持つ。保存の形は共通編 §8.2 |
| 物理削除の禁止 | 項目と選択肢はトリガーで物理削除を禁止する（無効化で扱う） |

---

## 2. テーブルの一覧

| 区分 | テーブル | 内容 |
|---|---|---|
| 利用者 | `users` | 利用者 |
| | `user_backup_codes` | 2 段階認証の予備のコード |
| | `remembered_devices` | 記憶した端末 |
| 組織 | `departments` | 課 |
| | `department_reviewers` | 課の確認者と課長 |
| | `department_admins` | 課別項目を管理できる人 |
| 項目 | `report_fields` | 項目マスタ |
| | `report_field_options` | 選択肢 |
| | `warning_words` | 客先名らしい言葉の一覧 |
| 案件 | `projects` | 案件コードと社内向けの呼び名 |
| 報告 | `report_records` | 月次報告 |
| | `report_record_fields` | 報告の作成時の項目の定義 |
| | `report_projects` | 報告の中の案件ブロック |
| | `report_field_values` | 項目ごとの値 |
| | `report_value_history` | 提出後の変更履歴 |
| | `report_status_history` | 状態の履歴 |
| 確認 | `report_reviews` | 確認者ごとのチェック |
| | `report_field_comments` | コメント |
| 臨時の調査 | `adhoc_requests` | 依頼 |
| | `adhoc_request_fields` | 質問 |
| | `adhoc_request_targets` | 対象 |
| | `adhoc_responses` | 回答（依頼×回答者） |
| | `adhoc_response_values` | 回答の値 |
| 仕組み | `request_keys` | 送信の番号 |
| | `access_logs` | 記録 |

---

## 3. テーブルの定義

### 3.1 利用者

```sql
-- 既存の users に列を足す（ALTER TABLE ADD COLUMN）。既存の列: id, username, password_hash, role_title, manager_id, is_admin, created_at
-- username（メールアドレス）は移行で login_id に置き換え、移行後は使わない
ALTER TABLE users ADD COLUMN login_id         TEXT;              -- 社内の ID（英数字 3〜32 文字）。一意の索引を別に作る
ALTER TABLE users ADD COLUMN display_name     TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN department_id    INTEGER REFERENCES departments(id);
ALTER TABLE users ADD COLUMN totp_secret_enc  TEXT;              -- 2 段階認証の秘密の値（暗号化）
ALTER TABLE users ADD COLUMN totp_enabled_at  TEXT;              -- 設定が済んだ日時（NULL = 未設定）
ALTER TABLE users ADD COLUMN session_version  INTEGER NOT NULL DEFAULT 1;
ALTER TABLE users ADD COLUMN failed_count     INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN locked_until     TEXT;
ALTER TABLE users ADD COLUMN password_changed_at TEXT;
ALTER TABLE users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0 CHECK (must_change_password IN (0, 1));
ALTER TABLE users ADD COLUMN is_active        INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1));
ALTER TABLE users ADD COLUMN updated_at       TEXT;
CREATE UNIQUE INDEX IF NOT EXISTS ux_users_login_id ON users (login_id);

CREATE TABLE IF NOT EXISTS user_backup_codes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    code_hash  TEXT NOT NULL,          -- password_hash() の値
    used_at    TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS remembered_devices (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users(id),
    token_hash   TEXT NOT NULL UNIQUE, -- Cookie の値の SHA-256
    label        TEXT NOT NULL DEFAULT '',  -- 「iPhone の Safari」など（User-Agent から作る大まかな名前）
    expires_at   TEXT NOT NULL,
    last_used_at TEXT,
    created_at   TEXT NOT NULL
);
```

- `role_title`（役職）は表示だけに使う。権限には使わない。
- `manager_id` は移行のあとは使わない（確認者は課ごとに設定する）。

### 3.2 組織

```sql
CREATE TABLE IF NOT EXISTS departments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL UNIQUE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    close_day  INTEGER CHECK (close_day BETWEEN 1 AND 31),  -- 締め日（NULL = なし。31 は月末）
    is_active  INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS department_reviewers (
    department_id INTEGER NOT NULL REFERENCES departments(id),
    user_id       INTEGER NOT NULL REFERENCES users(id),
    is_final      INTEGER NOT NULL DEFAULT 0 CHECK (is_final IN (0, 1)),
    sort_order    INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (department_id, user_id)
);
-- 課長は 1 課に 1 人
CREATE UNIQUE INDEX IF NOT EXISTS ux_department_final ON department_reviewers (department_id) WHERE is_final = 1;

CREATE TABLE IF NOT EXISTS department_admins (
    department_id INTEGER NOT NULL REFERENCES departments(id),
    user_id       INTEGER NOT NULL REFERENCES users(id),
    PRIMARY KEY (department_id, user_id)
);
```

### 3.3 項目

```sql
CREATE TABLE IF NOT EXISTS report_fields (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    code            TEXT NOT NULL UNIQUE,     -- 英小文字・数字・_、3〜40 文字。作成後は変更不可
    label           TEXT NOT NULL,
    field_type      TEXT NOT NULL CHECK (field_type IN (
                        'single_text', 'multi_text', 'date', 'month', 'number',
                        'select', 'radio', 'checkbox', 'multi_check', 'table', 'project')),
                        -- project = 案件コードを選ぶ（案件ブロックに 1 つだけ）
    department_id   INTEGER REFERENCES departments(id),   -- NULL = 共通項目
    field_scope     TEXT NOT NULL DEFAULT 'report' CHECK (field_scope IN ('report', 'project')),
    confidentiality TEXT NOT NULL DEFAULT 'normal' CHECK (confidentiality IN ('normal', 'internal', 'restricted')),
    sort_order      INTEGER NOT NULL DEFAULT 0,
    is_active       INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    is_required     INTEGER NOT NULL DEFAULT 0 CHECK (is_required IN (0, 1)),
    help_text       TEXT NOT NULL DEFAULT '',
    placeholder     TEXT NOT NULL DEFAULT '',
    settings_json   TEXT NOT NULL DEFAULT '{}',  -- max_length, min, max, decimals, max_rows, columns（table の列の定義）, show_when（ほかの項目の値で表示を切り替える）
    carry_over      INTEGER NOT NULL DEFAULT 0 CHECK (carry_over IN (0, 1)),
    show_in_list    INTEGER NOT NULL DEFAULT 0 CHECK (show_in_list IN (0, 1)),
    is_searchable   INTEGER NOT NULL DEFAULT 0 CHECK (is_searchable IN (0, 1)),
    include_in_export INTEGER NOT NULL DEFAULT 1 CHECK (include_in_export IN (0, 1)),
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_report_fields_scope ON report_fields (department_id, field_scope, is_active, sort_order);
CREATE TRIGGER IF NOT EXISTS tr_report_fields_no_delete BEFORE DELETE ON report_fields
BEGIN SELECT RAISE(ABORT, 'report_fields cannot be deleted; deactivate the field instead'); END;

CREATE TABLE IF NOT EXISTS report_field_options (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    field_id   INTEGER NOT NULL REFERENCES report_fields(id),
    code       TEXT NOT NULL,        -- 保存される値。作成後は変更不可
    label      TEXT NOT NULL,        -- 表示名。変えても保存済みの値は変わらない
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active  INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    UNIQUE (field_id, code)
);
CREATE TRIGGER IF NOT EXISTS tr_report_field_options_no_delete BEFORE DELETE ON report_field_options
BEGIN SELECT RAISE(ABORT, 'report_field_options cannot be deleted; deactivate the option instead'); END;

CREATE TABLE IF NOT EXISTS warning_words (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    word       TEXT NOT NULL UNIQUE,   -- 一般的な言葉だけ（実際の客先名は入れない）
    created_at TEXT NOT NULL
);
```

`field_scope = 'project'` の項目は案件ブロックの中に、`'report'` の項目は報告全体に出る。`field_type = 'project'` の項目は、共通項目に 1 つだけ置く（案件ブロックの見出しになる）。

### 3.4 案件

```sql
CREATE TABLE IF NOT EXISTS projects (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    code          TEXT NOT NULL UNIQUE,     -- 例 P-0012。削除後も再利用しない
    alias         TEXT NOT NULL DEFAULT '', -- 客先が特定されない社内向けの呼び名。案件終了後に消す
    department_id INTEGER REFERENCES departments(id),
    is_active     INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    ended_on      TEXT,
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);
```

客先名・案件名の列は持たない。

### 3.5 報告

```sql
CREATE TABLE IF NOT EXISTS report_records (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id          INTEGER NOT NULL REFERENCES users(id),
    department_id    INTEGER REFERENCES departments(id),   -- 作成時の所属課
    period           TEXT NOT NULL,                        -- YYYY-MM
    status           TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'submitted', 'confirmed')),
    revision         INTEGER NOT NULL DEFAULT 0,           -- 提出後に内容を変えるたびに +1
    unchanged        INTEGER NOT NULL DEFAULT 0 CHECK (unchanged IN (0, 1)),  -- 「前月から変更なし」で提出
    copied_from_id   INTEGER REFERENCES report_records(id), -- 引き継いだ前月の報告
    submitted_at     TEXT,
    confirmed_by     INTEGER REFERENCES users(id),
    confirmed_at     TEXT,
    is_legacy        INTEGER NOT NULL DEFAULT 0 CHECK (is_legacy IN (0, 1)),
    legacy_report_id INTEGER,
    free_text_purged_at TEXT,                              -- 保管期限で自由記述を消した日時
    deleted_at       TEXT,
    created_at       TEXT NOT NULL,
    updated_at       TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_report_user_period ON report_records (user_id, period) WHERE is_legacy = 0 AND deleted_at IS NULL;
CREATE INDEX IF NOT EXISTS ix_report_dept_period ON report_records (department_id, period, status);

CREATE TABLE IF NOT EXISTS report_record_fields (
    report_id       INTEGER NOT NULL REFERENCES report_records(id) ON DELETE CASCADE,
    field_id        INTEGER NOT NULL REFERENCES report_fields(id),
    label           TEXT NOT NULL,
    field_type      TEXT NOT NULL,
    field_scope     TEXT NOT NULL,
    confidentiality TEXT NOT NULL,
    sort_order      INTEGER NOT NULL,
    is_required     INTEGER NOT NULL,
    settings_json   TEXT NOT NULL,
    options_json    TEXT NOT NULL DEFAULT '[]',   -- その時点の選択肢（code, label）
    PRIMARY KEY (report_id, field_id)
);

CREATE TABLE IF NOT EXISTS report_projects (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    report_id  INTEGER NOT NULL REFERENCES report_records(id) ON DELETE CASCADE,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    sort_order INTEGER NOT NULL DEFAULT 0,
    UNIQUE (report_id, project_id)
);

CREATE TABLE IF NOT EXISTS report_field_values (
    report_id         INTEGER NOT NULL REFERENCES report_records(id) ON DELETE CASCADE,
    field_id          INTEGER NOT NULL REFERENCES report_fields(id),
    report_project_id INTEGER NOT NULL DEFAULT 0,   -- 0 = 報告全体の項目、それ以外 = report_projects.id
    value_text        TEXT NOT NULL DEFAULT '',
    is_encrypted      INTEGER NOT NULL DEFAULT 0 CHECK (is_encrypted IN (0, 1)),
    has_warning       INTEGER NOT NULL DEFAULT 0 CHECK (has_warning IN (0, 1)),  -- 客先名らしい言葉が含まれた
    updated_at        TEXT NOT NULL,
    PRIMARY KEY (report_id, field_id, report_project_id)
);
CREATE INDEX IF NOT EXISTS ix_rfv_field ON report_field_values (field_id, value_text);  -- 選択式の集計用

CREATE TABLE IF NOT EXISTS report_value_history (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    report_id         INTEGER NOT NULL REFERENCES report_records(id) ON DELETE CASCADE,
    field_id          INTEGER NOT NULL REFERENCES report_fields(id),
    report_project_id INTEGER NOT NULL DEFAULT 0,
    revision          INTEGER NOT NULL,
    old_value         TEXT NOT NULL,
    new_value         TEXT NOT NULL,
    is_encrypted      INTEGER NOT NULL DEFAULT 0 CHECK (is_encrypted IN (0, 1)),
    user_id           INTEGER NOT NULL REFERENCES users(id),
    created_at        TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS report_status_history (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    report_id   INTEGER NOT NULL REFERENCES report_records(id) ON DELETE CASCADE,
    from_status TEXT,
    to_status   TEXT NOT NULL,
    user_id     INTEGER NOT NULL REFERENCES users(id),
    created_at  TEXT NOT NULL
);
```

### 3.6 確認

```sql
CREATE TABLE IF NOT EXISTS report_reviews (
    report_id        INTEGER NOT NULL REFERENCES report_records(id) ON DELETE CASCADE,
    user_id          INTEGER NOT NULL REFERENCES users(id),
    checked_revision INTEGER NOT NULL,
    checked_at       TEXT NOT NULL,
    PRIMARY KEY (report_id, user_id)
);

CREATE TABLE IF NOT EXISTS report_field_comments (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    report_id         INTEGER NOT NULL REFERENCES report_records(id) ON DELETE CASCADE,
    field_id          INTEGER REFERENCES report_fields(id),   -- NULL = 報告全体
    report_project_id INTEGER NOT NULL DEFAULT 0,
    user_id           INTEGER NOT NULL REFERENCES users(id),
    parent_id         INTEGER REFERENCES report_field_comments(id),
    comment_type      TEXT NOT NULL DEFAULT 'note' CHECK (comment_type IN ('request', 'confirm', 'note')),
    thread_status     TEXT CHECK (thread_status IN ('open', 'answered', 'resolved')),  -- 最初のコメントだけ
    resolved_by       INTEGER REFERENCES users(id),
    resolved_at       TEXT,
    comment_enc       TEXT NOT NULL,     -- 本文（暗号化）
    deleted_at        TEXT,
    created_at        TEXT NOT NULL,
    updated_at        TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_comments_report ON report_field_comments (report_id, field_id, report_project_id, created_at);
CREATE INDEX IF NOT EXISTS ix_comments_open ON report_field_comments (thread_status) WHERE parent_id IS NULL;
```

### 3.7 臨時の調査

```sql
CREATE TABLE IF NOT EXISTS adhoc_requests (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    department_id     INTEGER NOT NULL REFERENCES departments(id),   -- 作成した課長の課
    title             TEXT NOT NULL,
    requester         TEXT NOT NULL DEFAULT '',
    purpose           TEXT NOT NULL DEFAULT '',
    result_visibility TEXT NOT NULL DEFAULT 'summary' CHECK (result_visibility IN ('summary', 'detail')),
    is_template       INTEGER NOT NULL DEFAULT 0 CHECK (is_template IN (0, 1)),
    status            TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'open', 'closed')),
    due_at            TEXT,
    created_by        INTEGER NOT NULL REFERENCES users(id),
    opened_at         TEXT,
    closed_at         TEXT,
    deleted_at        TEXT,
    created_at        TEXT NOT NULL,
    updated_at        TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS adhoc_request_fields (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id      INTEGER NOT NULL REFERENCES adhoc_requests(id) ON DELETE CASCADE,
    source_field_id INTEGER REFERENCES report_fields(id),  -- 月次の項目を使う場合
    label           TEXT NOT NULL,
    field_type      TEXT NOT NULL,
    confidentiality TEXT NOT NULL DEFAULT 'internal',
    is_required     INTEGER NOT NULL DEFAULT 0,
    help_text       TEXT NOT NULL DEFAULT '',
    settings_json   TEXT NOT NULL DEFAULT '{}',
    options_json    TEXT NOT NULL DEFAULT '[]',
    sort_order      INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS adhoc_request_targets (
    request_id INTEGER NOT NULL REFERENCES adhoc_requests(id) ON DELETE CASCADE,
    user_id    INTEGER NOT NULL REFERENCES users(id),     -- 公開の時点で対象者を人に展開して保存する
    PRIMARY KEY (request_id, user_id)
);

CREATE TABLE IF NOT EXISTS adhoc_responses (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id   INTEGER NOT NULL REFERENCES adhoc_requests(id) ON DELETE CASCADE,
    user_id      INTEGER NOT NULL REFERENCES users(id),
    submitted_at TEXT,
    created_at   TEXT NOT NULL,
    updated_at   TEXT NOT NULL,
    UNIQUE (request_id, user_id)
);

CREATE TABLE IF NOT EXISTS adhoc_response_values (
    response_id      INTEGER NOT NULL REFERENCES adhoc_responses(id) ON DELETE CASCADE,
    request_field_id INTEGER NOT NULL REFERENCES adhoc_request_fields(id) ON DELETE CASCADE,
    value_text       TEXT NOT NULL DEFAULT '',
    is_encrypted     INTEGER NOT NULL DEFAULT 0 CHECK (is_encrypted IN (0, 1)),
    has_warning      INTEGER NOT NULL DEFAULT 0 CHECK (has_warning IN (0, 1)),
    PRIMARY KEY (response_id, request_field_id)
);
```

### 3.8 仕組み

```sql
CREATE TABLE IF NOT EXISTS request_keys (
    request_key TEXT PRIMARY KEY,
    user_id     INTEGER NOT NULL,
    action      TEXT NOT NULL,          -- 例 report.save
    status      TEXT NOT NULL CHECK (status IN ('processing', 'done')),
    result_url  TEXT,                   -- 1 回目の転送先
    created_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS access_logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER,                 -- ログイン失敗のときは NULL
    action     TEXT NOT NULL CHECK (action IN ('view', 'export', 'admin', 'auth')),
    target     TEXT NOT NULL,
    ip_address TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_access_logs_created ON access_logs (created_at);
CREATE INDEX IF NOT EXISTS ix_access_logs_target ON access_logs (target);
```

---

## 4. 暗号化する列のまとめ

| テーブル | 列 | 条件 |
|---|---|---|
| `users` | `totp_secret_enc` | 常に |
| `report_field_values` | `value_text` | 自由記述の項目、または「限定」の項目（`is_encrypted = 1`） |
| `report_value_history` | `old_value`、`new_value` | 同上 |
| `report_field_comments` | `comment_enc` | 常に |
| `adhoc_response_values` | `value_text` | 自由記述の質問、または「限定」の質問 |

付加データ（共通編 §8.2）は、`rfv:<report_id>:<field_id>:<report_project_id>`、`rvh:<id>`、`cmt:<id>`、`arv:<response_id>:<request_field_id>`、`totp:<user_id>` とする。

---

## 5. 保管期限と後片付け

管理者のログイン時と、1 日 1 回の後片付け（最初の要求のときに実行し、最後の実行日時を記録する）で行う。

| 対象 | 条件 | 処理 |
|---|---|---|
| 自由記述 | 確認完了から `free_text_retention_months` を過ぎた報告 | 暗号化した値を空にし、`free_text_purged_at` を入れる。選択式の値は残す |
| 報告 | 対象月から `report_retention_years` を過ぎた | 物理削除（コメント・チェック・履歴も） |
| 削除したデータ | `deleted_at` から `trash_days` を過ぎた | 物理削除 |
| 送信の番号 | `request_key_hours` を過ぎた | 削除 |
| 端末の記憶 | `expires_at` を過ぎた | 削除 |
| 記録 | 会社が決める期間（未決） | 削除 |

---

## 6. 既存データの移行

`app/sql/migrate_from_legacy.php` で行う。基本設計書 §13。

1. DB のコピーを取る（移行前の控え）。
2. 全件を確かめる（ユーザーの `login_id` の割り当て、報告の日付の形式、コメントの `field_name` が 6 項目のどれか）。1 件でも問題があれば何もせずに、問題の一覧を出して終わる。
3. 1 つのトランザクションで次を行う。
   - 初期の課、6 項目（`report_date`, `name`, `subject`, `content`, `issues`, `remarks`）を `report_fields` に登録する（`report_date`・`name` は無効の状態で登録する）。
   - `reports` の各行を `report_records`（`is_legacy = 1`、`period` は日付の年月、`status = 'confirmed'`）と `report_field_values` に移す。自由記述は暗号化する。
   - `comments` を `report_field_comments`（`comment_type = 'note'`、返信なし）に移す。本文は暗号化する。
4. 件数を突き合わせる（移行元と移行先の報告・コメントの件数）。
5. 元の `reports`・`comments` は `legacy_reports`・`legacy_comments` に名前を変えて残す。確認が終わったら消す。

何度流しても同じ結果になるよう、`legacy_report_id` で移行済みかを判定する。
