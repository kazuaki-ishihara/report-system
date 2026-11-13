# 詳細設計書 DB 編

SQLite 3。テーブルは `app/lib/schema.php` の `schema_ensure()` で作る。コマンドラインで `php app/bin/migrate.php [--db=<DB>]` を実行する。

- 1 つのトランザクションで行い、何度流しても同じ結果になる（`CREATE TABLE IF NOT EXISTS` など）。
- `users` がなければ、既存の Web 版と同じ形で作る。`users` に足りない列は `PRAGMA table_info` で調べて足す。
- `migrate.php` は、暗号化の鍵のファイルがなければ作る（共通編 §8.2）。

テーブル名と列名は、依頼元の課長が開発中の項目マスタ（`feature/dynamic-report-fields`）の `report_fields`・`report_records`・`report_field_values`・`report_field_comments` に合わせ、列を足す形にする。

---

## 1. 共通の決まり

| 決まり | 内容 |
|---|---|
| 接続ごとの設定 | `PRAGMA foreign_keys = ON;` `PRAGMA busy_timeout = 5000;` `PRAGMA journal_mode = WAL;` |
| 日時 | `TEXT`、日本時間の `YYYY-MM-DD HH:MM:SS` |
| 日付・年月 | `YYYY-MM-DD`・`YYYY-MM` |
| 真偽 | `INTEGER` の 0／1 に `CHECK` を付ける |
| 削除 | `report_records`・`report_field_comments`・`adhoc_requests` は `deleted_at` を入れる（論理削除）。`trash_days` を過ぎたら物理削除する（§5） |
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
| | `department_field_options` | 共通の選択式の項目の、課ごとの選択肢（G-12 §6.5） |
| | `department_successions` | 組織の改訂で引き継いだ課（G-14 §6.6） |
| | `department_teams` | 課の班（G-14 §6.7） |
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
| | `login_throttle` | 存在しない ID でのログインの失敗を、IP アドレスごとに数える |
| | `comment_templates` | コメントの定型文（課の。G-19） |
| | `personal_comment_templates` | コメントの定型文（自分だけの。G-19） |
| | `ui_messages` | 課長が変えた画面の文言（G-17） |
| | `app_state` | 仕組みの状態（名前と値。管理担当の課、メンテナンス中（`maintenance`）など） |
| 要望・依頼 | `support_requests` | 課長・確認者からシステム管理者への要望・依頼（G-22。件名・本文は暗号化） |
| | `support_request_messages` | 要望・依頼のやり取り（返信・状態の変更。本文は暗号化） |
| | `support_reply_templates` | 要望・依頼の返信の定型文（便利機能 B） |
| 便利機能 | `update_notices` | 更新のお知らせ（G-24） |
| | `faq_entries` | よくある質問（G-25） |
| | `org_schedules` | 組織の改訂の予約（G-14 §6.10。取り込んだ CSV を持つ） |
| 移行 | `legacy_reports`・`legacy_comments` | 移行前の `reports`・`comments` の名前を変えて残したもの（§6。`schema_ensure()` では作らない） |

---

## 3. テーブルの定義

### 3.1 利用者

```sql
-- 既存の users に列を足す（ALTER TABLE ADD COLUMN）。既存の列:
--   id, username（NOT NULL UNIQUE）, password_hash,
--   role_title（CHECK: '一般','主任','課長代理','課長','部長'）, manager_id, is_admin, created_at
-- username には login_id と同じ値を入れる（移行でメールアドレスを置き換える。新しく作る人も同じ）
ALTER TABLE users ADD COLUMN login_id         TEXT;              -- 社内の ID。一意の索引を別に作る
ALTER TABLE users ADD COLUMN display_name     TEXT NOT NULL DEFAULT '';
ALTER TABLE users ADD COLUMN department_id    INTEGER REFERENCES departments(id);
ALTER TABLE users ADD COLUMN totp_secret_enc  TEXT;              -- 2 段階認証の秘密の値（暗号化）
ALTER TABLE users ADD COLUMN totp_enabled_at  TEXT;              -- 設定が済んだ日時（NULL = 未設定）
ALTER TABLE users ADD COLUMN totp_last_step   INTEGER;           -- 最後に使った 2 段階認証の区間（同じコードの 2 回目を断る）
ALTER TABLE users ADD COLUMN session_version  INTEGER NOT NULL DEFAULT 1;
ALTER TABLE users ADD COLUMN failed_count     INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN locked_until     TEXT;
ALTER TABLE users ADD COLUMN password_changed_at TEXT;
ALTER TABLE users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0 CHECK (must_change_password IN (0, 1));
ALTER TABLE users ADD COLUMN is_active        INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1));
ALTER TABLE users ADD COLUMN updated_at       TEXT;
ALTER TABLE users ADD COLUMN ui_theme         TEXT NOT NULL DEFAULT 'auto';   -- 画面のテーマ（G-16 §4.5）
-- department_reviewers にも後から列を足す: does_check INTEGER NOT NULL DEFAULT 1（チェックするか。K-039。課長は常に 1。G-23 §5）
ALTER TABLE users ADD COLUMN ui_custom_colors TEXT NOT NULL DEFAULT '';   -- 自分の色（G-16 §4.6。JSON: base・accent・background）
ALTER TABLE users ADD COLUMN is_admin_deputy  INTEGER NOT NULL DEFAULT 0 CHECK (is_admin_deputy IN (0, 1));   -- システム管理者補佐（G-14 §6.9）
-- 補佐は有効な人で 3 名まで、管理者と兼ねない（トリガー tr_users_admin_deputy_insert・tr_users_admin_deputy_update が RAISE(ABORT)）
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

- `login_id` は英小文字・数字・`.`・`_`・`-` の 3〜32 文字で、先頭は英小文字か数字（`^[a-z0-9][a-z0-9._-]{2,31}$`）。入力は前後の空白を除き、小文字にそろえる。
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
    code            TEXT NOT NULL UNIQUE,     -- 英小文字で始まる英小文字・数字・_、3〜40 文字（^[a-z][a-z0-9_]{2,39}$）。作成後は変更不可
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
    code       TEXT NOT NULL,        -- 保存される値。^[a-z0-9_]{1,40}$、項目の中で重複なし。作成後は変更不可
    label      TEXT NOT NULL,        -- 表示名。変えても保存済みの値は変わらない
    sort_order INTEGER NOT NULL DEFAULT 0,
    is_active  INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    UNIQUE (field_id, code)
);
-- 報告で使われている選択肢は削除させない（無効にする）。暗号化した値があれば使われているとみなす。
-- 以前の「いつでも削除させない」トリガー tr_report_field_options_no_delete は DROP してから作る
CREATE TRIGGER IF NOT EXISTS tr_report_field_options_no_delete_used BEFORE DELETE ON report_field_options
WHEN EXISTS (SELECT 1 FROM report_field_values v WHERE v.field_id = OLD.field_id
               AND (v.is_encrypted = 1 OR v.value_text = OLD.code
                    OR (v.value_text LIKE '[%' AND instr(v.value_text, '"' || OLD.code || '"') > 0)))
BEGIN SELECT RAISE(ABORT, 'report_field_options in use cannot be deleted; deactivate the option instead'); END;

-- 課の班（「班ごと」で管理する課。G-14 §6.7）
CREATE TABLE IF NOT EXISTS department_teams (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    department_id INTEGER NOT NULL REFERENCES departments(id),
    name          TEXT NOT NULL,
    sort_order    INTEGER NOT NULL DEFAULT 0,
    is_active     INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL,
    UNIQUE (department_id, name)
);
-- あとから足した列
ALTER TABLE departments ADD COLUMN review_scope TEXT NOT NULL DEFAULT 'department' CHECK (review_scope IN ('department', 'team'));   -- 管理の仕方
ALTER TABLE department_reviewers ADD COLUMN team_id INTEGER REFERENCES department_teams(id);   -- 担当の班（NULL なら課全体）
ALTER TABLE users ADD COLUMN team_id INTEGER REFERENCES department_teams(id);                  -- 今の班

-- 組織の改訂で、課が引き継いだ改訂の前の課（G-14 §6.6）。後の課の確認者は、前の課の報告も見られる
CREATE TABLE IF NOT EXISTS department_successions (
    predecessor_department_id INTEGER NOT NULL REFERENCES departments(id),   -- 改訂の前の課
    successor_department_id   INTEGER NOT NULL REFERENCES departments(id),   -- 引き継いだ課
    created_by                INTEGER REFERENCES users(id),
    created_at                TEXT NOT NULL,
    PRIMARY KEY (predecessor_department_id, successor_department_id),
    CHECK (predecessor_department_id <> successor_department_id)
);

-- 共通の選択式の項目の、課ごとの選択肢（G-12 §6.5）
CREATE TABLE IF NOT EXISTS department_field_options (
    department_id INTEGER NOT NULL REFERENCES departments(id),
    field_id      INTEGER NOT NULL REFERENCES report_fields(id),   -- 共通の項目
    code          TEXT NOT NULL,                  -- 共通の選択肢のコード、または課で足した選択肢のコード（d<課ID>_o<番号>）
    label         TEXT NOT NULL DEFAULT '',       -- 共通の選択肢: この課での表示名（空なら共通のまま）。足した選択肢: 表示名
    is_added      INTEGER NOT NULL DEFAULT 0 CHECK (is_added IN (0, 1)),   -- 1 = 課で足した選択肢
    is_hidden     INTEGER NOT NULL DEFAULT 0 CHECK (is_hidden IN (0, 1)),  -- 1 = この課では出さない
    sort_order    INTEGER NOT NULL DEFAULT 0,
    updated_by    INTEGER REFERENCES users(id),
    updated_at    TEXT NOT NULL,
    PRIMARY KEY (department_id, field_id, code)
);

CREATE TABLE IF NOT EXISTS warning_words (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    word       TEXT NOT NULL UNIQUE,   -- 一般的な言葉だけ（実際の客先名は入れない）
    created_at TEXT NOT NULL
);
```

- `field_scope = 'project'` の項目は案件ブロックの中に、`'report'` の項目は報告全体に出る。
- `field_type = 'project'` の項目は、全体で 1 つだけ置ける（案件ブロックの見出しになる）。`field_scope` は `project` に固定し、無効にはできない。
- `settings_json` の中身:
  - `columns`: `[{key, label, type, options?}]`。`key` は `^[a-z][a-z0-9_]{0,29}$`、`type` は `single_text`・`multi_text`・`number`・`date`・`select`。
  - `max_rows`: 1〜50（既定 20）。
  - `show_when`: `{field: <項目のコード>, values: [...]}`。
  - 画面では、表の列を 1 行に 1 列ずつ `キー|名前|種類|選択肢1,選択肢2` の文字で入れ、表示の条件は項目のコードとカンマ区切りの値で入れる（G-12）。
- `is_searchable` は、検索（G-20）でキーワードを探す項目かどうか（共通編 §8.3）。
- `sort_order`: G-12 の ↑↓ のボタンで、同じ `field_scope`・同じ適用（共通か、その課か）の中で隣と入れ替え、そのまとまりを 10, 20, 30… で振り直す。

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
    copied_from_id   INTEGER REFERENCES report_records(id), -- 引き継いだ前月の報告（過去の報告から写したときは写した元。G-04 §4.4）
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
    target_type       TEXT NOT NULL DEFAULT 'all' CHECK (target_type IN ('all', 'users')),
                      -- all = 課の全員（公開の時点で adhoc_request_targets に展開）、users = 選んだ人（保存のときに入れる）
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
    user_id    INTEGER NOT NULL REFERENCES users(id),     -- 対象者を人で保存する（target_type を参照）
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

質問の種類（`adhoc_request_fields.field_type`）は `single_text`・`multi_text`・`number`・`date`・`month`・`select`・`radio`・`checkbox`・`multi_check`。`table` と `project` は使わない。

### 3.7.1 要望・依頼（G-22）

```sql
CREATE TABLE IF NOT EXISTS support_requests (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL REFERENCES users(id),        -- 出した人
    department_id INTEGER REFERENCES departments(id),           -- 出したときの課
    kind          TEXT NOT NULL CHECK (kind IN ('improvement', 'task', 'bug', 'question')),
    urgency       TEXT NOT NULL DEFAULT 'normal' CHECK (urgency IN ('normal', 'urgent')),
    screen_code   TEXT NOT NULL DEFAULT '',                     -- 関係する画面（G-02 など。空は指定しない）
    title_enc     TEXT NOT NULL DEFAULT '',                     -- 件名（暗号化。文脈 sreq_title:<ID>）
    body_enc      TEXT NOT NULL DEFAULT '',                     -- 内容（暗号化。文脈 sreq_body:<ID>）
    has_warning   INTEGER NOT NULL DEFAULT 0 CHECK (has_warning IN (0, 1)),
    status        TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'reviewing', 'in_progress', 'testing', 'release_waiting', 'released', 'done', 'declined', 'withdrawn')),
    source_path   TEXT NOT NULL DEFAULT '',                     -- 開いていた画面（G-22 §4。決まった問い合わせの項目だけ）
    client_text   TEXT NOT NULL DEFAULT '',                     -- 出したときの端末（PC／スマートフォン、ブラウザー・OS の名前）
    requester_seen_message_id INTEGER NOT NULL DEFAULT 0,       -- 出した人が最後に見たやり取りの番号（「回答あり」の判定）
    last_activity_at TEXT NOT NULL,
    closed_at     TEXT,                                         -- 完了・見送り・取り下げの日時
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_support_requests_user ON support_requests (user_id, last_activity_at);
CREATE INDEX IF NOT EXISTS ix_support_requests_status ON support_requests (status, last_activity_at);

CREATE TABLE IF NOT EXISTS support_request_messages (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id    INTEGER NOT NULL REFERENCES support_requests(id),
    user_id       INTEGER NOT NULL REFERENCES users(id),
    is_staff      INTEGER NOT NULL DEFAULT 0 CHECK (is_staff IN (0, 1)),   -- 1 = 管理者・補佐の返信
    body_enc      TEXT NOT NULL DEFAULT '',                     -- 本文（暗号化。文脈 sreq_msg:<要望ID>:<ID>。状態の変更だけなら空）
    status_change TEXT CHECK (status_change IS NULL OR status_change IN ('open', 'reviewing', 'in_progress', 'testing', 'release_waiting', 'released', 'done', 'declined', 'withdrawn')),
    created_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_support_request_messages_request ON support_request_messages (request_id, id);
```

保管期限による削除の対象にはしていない（期間は未決。仮設定 K-044）。

### 3.7.2 便利機能（A・B・C・D・G・J）

```sql
CREATE TABLE IF NOT EXISTS support_reply_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL DEFAULT '', body TEXT NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0,
    updated_by INTEGER REFERENCES users(id), created_at TEXT NOT NULL, updated_at TEXT NOT NULL);           -- B 返信の定型文
CREATE TABLE IF NOT EXISTS update_notices (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT NOT NULL DEFAULT '',
    show_from TEXT NOT NULL, show_until TEXT NOT NULL, source_request_id INTEGER REFERENCES support_requests(id),
    created_by INTEGER REFERENCES users(id), updated_by INTEGER REFERENCES users(id), created_at TEXT NOT NULL, updated_at TEXT NOT NULL);   -- A 更新のお知らせ
CREATE TABLE IF NOT EXISTS faq_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, question TEXT NOT NULL, answer TEXT NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0,
    source_request_id INTEGER REFERENCES support_requests(id), updated_by INTEGER REFERENCES users(id), created_at TEXT NOT NULL, updated_at TEXT NOT NULL);   -- J よくある質問
CREATE TABLE IF NOT EXISTS org_schedules (id INTEGER PRIMARY KEY AUTOINCREMENT, apply_on TEXT NOT NULL, csv_text TEXT NOT NULL, change_count INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled', 'applied', 'failed', 'cancelled')), result_text TEXT NOT NULL DEFAULT '',
    created_by INTEGER REFERENCES users(id), created_at TEXT NOT NULL, finished_at TEXT);   -- G 組織の改訂の予約（取り込んだ CSV を持つ）
-- support_requests に後から足す列: plan_text TEXT NOT NULL DEFAULT ''（D 対応の予定）、merged_into_id INTEGER（C まとめた先）
-- app_state の maintenance（F メンテナンス中。JSON: until・message・started_by・started_at）
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
    created_at TEXT NOT NULL,
    acted_as_deputy INTEGER NOT NULL DEFAULT 0 CHECK (acted_as_deputy IN (0, 1))   -- 補佐としての管理の操作・出力（あとから ALTER TABLE で足す）
);
CREATE INDEX IF NOT EXISTS ix_access_logs_created ON access_logs (created_at);
CREATE INDEX IF NOT EXISTS ix_access_logs_target ON access_logs (target);

CREATE TABLE IF NOT EXISTS login_throttle (
    throttle_key TEXT PRIMARY KEY,      -- ip:<IP アドレス>
    failures     INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT,
    updated_at   TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS comment_templates (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    department_id INTEGER NOT NULL REFERENCES departments(id),
    body          TEXT NOT NULL,                -- 定型文（300 文字まで）
    sort_order    INTEGER NOT NULL DEFAULT 0,
    updated_by    INTEGER REFERENCES users(id),
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_comment_templates_dept ON comment_templates (department_id, sort_order);

-- 自分だけのコメントの定型文（G-19）
CREATE TABLE IF NOT EXISTS personal_comment_templates (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    body       TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_personal_comment_templates_user ON personal_comment_templates (user_id, sort_order);

CREATE TABLE IF NOT EXISTS ui_messages (
    msg_key    TEXT PRIMARY KEY,               -- 既定の文言（コードの msg('…') の文）
    body       TEXT NOT NULL,                  -- 課長が変えた文言
    updated_by INTEGER REFERENCES users(id),
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS app_state (
    name  TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
```

- `comment_templates`: 課長が G-19 で用意するコメントの定型文。課ごと。コメントからは参照しない（選ぶと本文に写す）ので、物理削除する。暗号化しない（業務の中身を書かない前提の定型文）。
- `ui_messages`: 課長が G-17 で変えた文言だけを持つ（変えていない文言は行がない）。全体で 1 つ（課ごとには持たない）。暗号化しない（業務の中身ではない）。

- `login_throttle`: 存在しない ID・無効の ID でログインに失敗したとき、IP アドレスごとに数える。ロックまでの回数と時間は利用者と同じ（`login_max_failures`・`login_lock_minutes`）。
- `app_state`: 今使う名前は `housekeeping`（1 日 1 回の後片付けを最後に行った日）、`project_seq`（案件コードの最後の番号。G-13）、`management_department_id`（システムの管理担当の課。G-14 §6.7）。

---

## 4. 暗号化する列のまとめ

| テーブル | 列 | 条件 |
|---|---|---|
| `users` | `totp_secret_enc` | 常に |
| `report_field_values` | `value_text` | 自由記述の項目（`table` を含む）、または「限定」の項目（`is_encrypted = 1`） |
| `report_value_history` | `old_value`、`new_value` | 同上 |
| `report_field_comments` | `comment_enc` | 常に |
| `adhoc_response_values` | `value_text` | 自由記述の質問、または「限定」の質問 |

付加データ（共通編 §8.2）は次のとおり。

| データ | 付加データ |
|---|---|
| 値 | `rfv:<report_id>:<field_id>:<report_project_id>` |
| 変更履歴 | `rvh:<report_id>:<field_id>:<report_project_id>:<revision>:old`（変更後は `:new`） |
| コメント | `cmt:<report_id>:<コメントの id>` |
| 臨時の調査の回答 | `arv:<response_id>:<request_field_id>` |
| 2 段階認証 | `totp:<user_id>` |

- 移行した報告の `report_date`（日付）は暗号化しない。
- 選択式などの項目に「限定」を付け外したときは、既存の `report_field_values` を暗号化し直すか復号し、`report_record_fields.confidentiality` も書き換える。変更履歴は暗号化し直さない。

---

## 5. 保管期限と後片付け

1 日 1 回、ログイン画面を開いたとき（GET を含む、だれでも）に `housekeeping_daily()` で行う。最後に行った日は `app_state` の `housekeeping` に入れ、同じ日には 2 回行わない。

| 対象 | 条件 | 処理 |
|---|---|---|
| 自由記述 | 確認完了から `free_text_retention_months` を過ぎた報告 | 自由記述（`single_text`・`multi_text`・`table`）の値と、その変更履歴の変更前・変更後を空にする（`is_encrypted = 0`、`has_warning = 0`）。報告のコメントの本文もすべて空にする。`free_text_purged_at` を入れる。選択式の値は残す（「限定」の値は暗号化のまま残る） |
| 報告 | 対象月（年月）が今日から `report_retention_years` より前（移行した報告も含む） | 物理削除（コメント・チェック・履歴も）。その報告を指す `copied_from_id` は先に NULL にする |
| 削除した報告 | `deleted_at` から `trash_days` を過ぎた | 物理削除 |
| 削除したコメント | 同上 | 本文を空にする。返信のないものは物理削除し、返信のあるものは本文が空のまま残す |
| 削除した臨時の調査 | 同上 | 物理削除 |
| 送信の番号 | `request_key_hours` を過ぎた | 削除 |
| 端末の記憶 | `expires_at` を過ぎた | 削除 |
| `login_throttle` | ロック中でなく、最後の更新から `request_key_hours` を過ぎた | 削除 |
| 記録 | 会社が決める期間（未決） | 削除はまだ作っていない |

---

## 6. 既存データの移行

`php app/bin/migrate_legacy.php [--db=<DB>] [--dry-run]` で行う。基本設計書 §13。先に `app/bin/migrate.php` でテーブルを作っておく。`--dry-run` は確認と、割り当てる予定のログイン ID を出すだけで、何も変えない。

1. WAL を書き戻してから、DB のコピーを `app/backups/before_legacy_migration_<YYYYmmdd_HHMMSS>.sqlite`（権限 600）に取る（`--dry-run` では取らない）。
2. 全件を確かめる（ユーザーの `login_id` の割り当て、報告の日付の形式、報告を書いた人がいるか、コメントの `field_name` が 6 項目のどれか）。1 件でも問題があれば何もせずに、問題の一覧を出して終わる。
   - ログイン ID は、メールアドレスの `@` より前から作る。使えない文字は `_` にし、3 文字に満たなければ `user<id>` にする。重複すれば番号を足す。
3. 1 つのトランザクションで次を行う。
   - 利用者: `login_id` と、同じ値を `username` に入れる。`must_change_password = 1` にする。`display_name` が空なら、その人の最新の報告の `name`（20 文字以内のとき）か `login_id` を入れる。
   - 6 項目（`report_date`, `name`, `subject`, `content`, `issues`, `remarks`）を、共通（`department_id` が NULL）・報告全体（`field_scope = 'report'`）・社外秘・**無効**の項目として `report_fields` に登録する。移行した報告の表示だけに使う。並び順は 910〜960。種類は `report_date` が `date`、`name`・`subject` が `single_text`（最大 400 文字）、`content`・`issues`・`remarks` が `multi_text`（最大 20,000 文字）。課は作らない（`create_user.php --dept` か G-14 で作る）。
   - `reports` の各行を `report_records`（`is_legacy = 1`、`period` は日付の年月、`status = 'confirmed'`、`submitted_at` は元の `created_at`、`confirmed_at` は元の `updated_at`、`confirmed_by` は NULL、`department_id` は書いた人の今の課）と `report_record_fields`・`report_field_values` に移す。`report_date` は暗号化せず、ほかの 5 項目は暗号化する。`has_warning` も計算する。空の値は入れない。
   - `comments` を `report_field_comments`（`comment_type = 'note'`、返信なし）に移す。本文は暗号化する。報告がなくなっているコメントは飛ばす。同じ報告・同じ人・同じ作成日時・同じ項目のものは移行済みとみなす。
   - 元の `reports`・`comments` を `legacy_reports`・`legacy_comments` に名前を変えて残す。
4. コミットのあと、報告の件数（移行元の件数と `is_legacy = 1` の件数）を比べて「OK」か「不一致」を出す。不一致でも元には戻さない。コメントの件数は比べない。
5. 確認が終わったら、本番では `legacy_reports`・`legacy_comments`（平文）を消す。

何度流しても同じ結果になるよう、`legacy_report_id` で移行済みかを判定する。`legacy_reports`・`legacy_comments` があれば、それを移行元にする。

## 7. そのほかのコマンドラインの道具

ブラウザーから開くと 404 を返す。

| 道具 | 内容 |
|---|---|
| `php app/bin/seed_template.php [--dept-a=<課ID>] [--dept-b=<課ID>]` | 初期の様式を登録する。共通項目（案件、担当内容、作業フェーズ、進捗、進捗の補足（表示の条件あり）、案件の継続見込み、終了予定、人員の動き、人員の動きの詳細（限定））は常に、課別項目は課の ID を渡したときだけ（A課 3 項目、B課 1 項目）。同じコードがあれば飛ばす |
| `php app/bin/create_user.php --login=<ID> --name=<表示名> [--admin] [--dept=<課名>] [--reviewer｜--final]` | 利用者を作る（最初の管理者など）。課がなければ作る。12 文字の仮のパスワードを一度だけ出し、`must_change_password = 1` にする |
