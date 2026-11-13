<?php

declare(strict_types=1);

// ====================================================================
// テーブルの作成（詳細設計書 DB 編 §3）
// 何度流しても同じ結果になる。既存の users には足りない列だけを足す。
// 画面からは呼ばない（app/bin/migrate.php から呼ぶ）。
// ====================================================================

function schema_ensure(PDO $pdo): array
{
    $log = [];

    // 既存の Web 版と同じ形の users（なければ作る）
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role_title TEXT NOT NULL DEFAULT '一般'
            CHECK (role_title IN ('一般', '主任', '課長代理', '課長', '部長')),
        manager_id INTEGER,
        is_admin INTEGER NOT NULL DEFAULT 0 CHECK (is_admin IN (0, 1)),
        created_at TEXT NOT NULL,
        FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS departments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        sort_order INTEGER NOT NULL DEFAULT 0,
        close_day INTEGER CHECK (close_day BETWEEN 1 AND 31),
        is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");

    $userColumns = [
        'login_id'             => 'TEXT',
        'display_name'         => "TEXT NOT NULL DEFAULT ''",
        'department_id'        => 'INTEGER REFERENCES departments(id)',
        'totp_secret_enc'      => 'TEXT',
        'totp_enabled_at'      => 'TEXT',
        'totp_last_step'       => 'INTEGER',
        'session_version'      => 'INTEGER NOT NULL DEFAULT 1',
        'failed_count'         => 'INTEGER NOT NULL DEFAULT 0',
        'locked_until'         => 'TEXT',
        'password_changed_at'  => 'TEXT',
        'must_change_password' => 'INTEGER NOT NULL DEFAULT 0 CHECK (must_change_password IN (0, 1))',
        'is_active'            => 'INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1))',
        'updated_at'           => 'TEXT',
        'ui_theme'             => "TEXT NOT NULL DEFAULT 'auto'",   // 画面のテーマ（G-16。UI_THEMES のキー）
        'team_id'              => 'INTEGER REFERENCES department_teams(id)',   // 今の班（「班ごと」の課。G-14 §6.7）
        'is_admin_deputy'      => 'INTEGER NOT NULL DEFAULT 0 CHECK (is_admin_deputy IN (0, 1))',
        'ui_custom_colors'     => "TEXT NOT NULL DEFAULT ''",   // 自分の色（G-16 §4.6。JSON: base・accent・background）   // システム管理者補佐（G-14 §6.9。最大 3 名）
    ];
    $existing = [];
    foreach ($pdo->query('PRAGMA table_info(users)')->fetchAll() as $col) {
        $existing[$col['name']] = true;
    }
    foreach ($userColumns as $name => $def) {
        if (!isset($existing[$name])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN {$name} {$def}");
            $log[] = "users.{$name} を追加";
        }
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_users_login_id ON users (login_id)');

    $statements = [
        "CREATE TABLE IF NOT EXISTS user_backup_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id),
            code_hash TEXT NOT NULL,
            used_at TEXT,
            created_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS remembered_devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id),
            token_hash TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL DEFAULT '',
            expires_at TEXT NOT NULL,
            last_used_at TEXT,
            created_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS login_throttle (
            throttle_key TEXT PRIMARY KEY,
            failures INTEGER NOT NULL DEFAULT 0,
            locked_until TEXT,
            updated_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS department_reviewers (
            department_id INTEGER NOT NULL REFERENCES departments(id),
            user_id INTEGER NOT NULL REFERENCES users(id),
            is_final INTEGER NOT NULL DEFAULT 0 CHECK (is_final IN (0, 1)),
            sort_order INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (department_id, user_id)
        )",
        "CREATE UNIQUE INDEX IF NOT EXISTS ux_department_final ON department_reviewers (department_id) WHERE is_final = 1",
        "CREATE TABLE IF NOT EXISTS department_admins (
            department_id INTEGER NOT NULL REFERENCES departments(id),
            user_id INTEGER NOT NULL REFERENCES users(id),
            PRIMARY KEY (department_id, user_id)
        )",
        "CREATE TABLE IF NOT EXISTS report_fields (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL,
            field_type TEXT NOT NULL CHECK (field_type IN (
                'single_text', 'multi_text', 'date', 'month', 'number',
                'select', 'radio', 'checkbox', 'multi_check', 'table', 'project')),
            department_id INTEGER REFERENCES departments(id),
            field_scope TEXT NOT NULL DEFAULT 'report' CHECK (field_scope IN ('report', 'project')),
            confidentiality TEXT NOT NULL DEFAULT 'normal' CHECK (confidentiality IN ('normal', 'internal', 'restricted')),
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
            is_required INTEGER NOT NULL DEFAULT 0 CHECK (is_required IN (0, 1)),
            help_text TEXT NOT NULL DEFAULT '',
            placeholder TEXT NOT NULL DEFAULT '',
            settings_json TEXT NOT NULL DEFAULT '{}',
            carry_over INTEGER NOT NULL DEFAULT 0 CHECK (carry_over IN (0, 1)),
            show_in_list INTEGER NOT NULL DEFAULT 0 CHECK (show_in_list IN (0, 1)),
            is_searchable INTEGER NOT NULL DEFAULT 0 CHECK (is_searchable IN (0, 1)),
            include_in_export INTEGER NOT NULL DEFAULT 1 CHECK (include_in_export IN (0, 1)),
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )",
        "CREATE INDEX IF NOT EXISTS ix_report_fields_scope ON report_fields (department_id, field_scope, is_active, sort_order)",
        "CREATE TRIGGER IF NOT EXISTS tr_report_fields_no_delete BEFORE DELETE ON report_fields
         BEGIN SELECT RAISE(ABORT, 'report_fields cannot be deleted; deactivate the field instead'); END",
        "CREATE TABLE IF NOT EXISTS report_field_options (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            field_id INTEGER NOT NULL REFERENCES report_fields(id),
            code TEXT NOT NULL,
            label TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
            UNIQUE (field_id, code)
        )",
        // 選択肢は、報告で使われていれば削除させない（無効にする）。使われていなければ削除できる（G-12）。
        // 暗号化した値（「限定」の項目）は中身で照合できないので、値が 1 つでもあれば使われているとみなす。
        // 以前の「いつでも削除させない」トリガーは置き換える
        "DROP TRIGGER IF EXISTS tr_report_field_options_no_delete",
        "CREATE TRIGGER IF NOT EXISTS tr_report_field_options_no_delete_used BEFORE DELETE ON report_field_options
         WHEN EXISTS (SELECT 1 FROM report_field_values v WHERE v.field_id = OLD.field_id
                        AND (v.is_encrypted = 1 OR v.value_text = OLD.code
                             OR (v.value_text LIKE '[%' AND instr(v.value_text, '\"' || OLD.code || '\"') > 0)))
         BEGIN SELECT RAISE(ABORT, 'report_field_options in use cannot be deleted; deactivate the option instead'); END",
        "CREATE TABLE IF NOT EXISTS warning_words (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            word TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            alias TEXT NOT NULL DEFAULT '',
            department_id INTEGER REFERENCES departments(id),
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
            ended_on TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS report_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id),
            department_id INTEGER REFERENCES departments(id),
            period TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'submitted', 'confirmed')),
            revision INTEGER NOT NULL DEFAULT 0,
            unchanged INTEGER NOT NULL DEFAULT 0 CHECK (unchanged IN (0, 1)),
            copied_from_id INTEGER REFERENCES report_records(id),
            submitted_at TEXT,
            confirmed_by INTEGER REFERENCES users(id),
            confirmed_at TEXT,
            is_legacy INTEGER NOT NULL DEFAULT 0 CHECK (is_legacy IN (0, 1)),
            legacy_report_id INTEGER,
            free_text_purged_at TEXT,
            deleted_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )",
        "CREATE UNIQUE INDEX IF NOT EXISTS ux_report_user_period ON report_records (user_id, period) WHERE is_legacy = 0 AND deleted_at IS NULL",
        "CREATE INDEX IF NOT EXISTS ix_report_dept_period ON report_records (department_id, period, status)",
        "CREATE TABLE IF NOT EXISTS report_record_fields (
            report_id INTEGER NOT NULL REFERENCES report_records(id) ON DELETE CASCADE,
            field_id INTEGER NOT NULL REFERENCES report_fields(id),
            label TEXT NOT NULL,
            field_type TEXT NOT NULL,
            field_scope TEXT NOT NULL,
            confidentiality TEXT NOT NULL,
            sort_order INTEGER NOT NULL,
            is_required INTEGER NOT NULL,
            settings_json TEXT NOT NULL,
            options_json TEXT NOT NULL DEFAULT '[]',
            PRIMARY KEY (report_id, field_id)
        )",
        "CREATE TABLE IF NOT EXISTS report_projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL REFERENCES report_records(id) ON DELETE CASCADE,
            project_id INTEGER NOT NULL REFERENCES projects(id),
            sort_order INTEGER NOT NULL DEFAULT 0,
            UNIQUE (report_id, project_id)
        )",
        "CREATE TABLE IF NOT EXISTS report_field_values (
            report_id INTEGER NOT NULL REFERENCES report_records(id) ON DELETE CASCADE,
            field_id INTEGER NOT NULL REFERENCES report_fields(id),
            report_project_id INTEGER NOT NULL DEFAULT 0,
            value_text TEXT NOT NULL DEFAULT '',
            is_encrypted INTEGER NOT NULL DEFAULT 0 CHECK (is_encrypted IN (0, 1)),
            has_warning INTEGER NOT NULL DEFAULT 0 CHECK (has_warning IN (0, 1)),
            updated_at TEXT NOT NULL,
            PRIMARY KEY (report_id, field_id, report_project_id)
        )",
        "CREATE INDEX IF NOT EXISTS ix_rfv_field ON report_field_values (field_id, value_text)",
        "CREATE TABLE IF NOT EXISTS report_value_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL REFERENCES report_records(id) ON DELETE CASCADE,
            field_id INTEGER NOT NULL REFERENCES report_fields(id),
            report_project_id INTEGER NOT NULL DEFAULT 0,
            revision INTEGER NOT NULL,
            old_value TEXT NOT NULL,
            new_value TEXT NOT NULL,
            is_encrypted INTEGER NOT NULL DEFAULT 0 CHECK (is_encrypted IN (0, 1)),
            user_id INTEGER NOT NULL REFERENCES users(id),
            created_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS report_status_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL REFERENCES report_records(id) ON DELETE CASCADE,
            from_status TEXT,
            to_status TEXT NOT NULL,
            user_id INTEGER NOT NULL REFERENCES users(id),
            created_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS report_reviews (
            report_id INTEGER NOT NULL REFERENCES report_records(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id),
            checked_revision INTEGER NOT NULL,
            checked_at TEXT NOT NULL,
            PRIMARY KEY (report_id, user_id)
        )",
        "CREATE TABLE IF NOT EXISTS report_field_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_id INTEGER NOT NULL REFERENCES report_records(id) ON DELETE CASCADE,
            field_id INTEGER REFERENCES report_fields(id),
            report_project_id INTEGER NOT NULL DEFAULT 0,
            user_id INTEGER NOT NULL REFERENCES users(id),
            parent_id INTEGER REFERENCES report_field_comments(id),
            comment_type TEXT NOT NULL DEFAULT 'note' CHECK (comment_type IN ('request', 'confirm', 'note')),
            thread_status TEXT CHECK (thread_status IN ('open', 'answered', 'resolved')),
            resolved_by INTEGER REFERENCES users(id),
            resolved_at TEXT,
            comment_enc TEXT NOT NULL,
            deleted_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )",
        "CREATE INDEX IF NOT EXISTS ix_comments_report ON report_field_comments (report_id, field_id, report_project_id, created_at)",
        "CREATE INDEX IF NOT EXISTS ix_comments_open ON report_field_comments (thread_status) WHERE parent_id IS NULL",
        "CREATE TABLE IF NOT EXISTS adhoc_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            department_id INTEGER NOT NULL REFERENCES departments(id),
            title TEXT NOT NULL,
            requester TEXT NOT NULL DEFAULT '',
            purpose TEXT NOT NULL DEFAULT '',
            result_visibility TEXT NOT NULL DEFAULT 'summary' CHECK (result_visibility IN ('summary', 'detail')),
            target_type TEXT NOT NULL DEFAULT 'all' CHECK (target_type IN ('all', 'users')),
            is_template INTEGER NOT NULL DEFAULT 0 CHECK (is_template IN (0, 1)),
            status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'open', 'closed')),
            due_at TEXT,
            created_by INTEGER NOT NULL REFERENCES users(id),
            opened_at TEXT,
            closed_at TEXT,
            deleted_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS adhoc_request_fields (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id INTEGER NOT NULL REFERENCES adhoc_requests(id) ON DELETE CASCADE,
            source_field_id INTEGER REFERENCES report_fields(id),
            label TEXT NOT NULL,
            field_type TEXT NOT NULL,
            confidentiality TEXT NOT NULL DEFAULT 'internal',
            is_required INTEGER NOT NULL DEFAULT 0,
            help_text TEXT NOT NULL DEFAULT '',
            settings_json TEXT NOT NULL DEFAULT '{}',
            options_json TEXT NOT NULL DEFAULT '[]',
            sort_order INTEGER NOT NULL DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS adhoc_request_targets (
            request_id INTEGER NOT NULL REFERENCES adhoc_requests(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id),
            PRIMARY KEY (request_id, user_id)
        )",
        "CREATE TABLE IF NOT EXISTS adhoc_responses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id INTEGER NOT NULL REFERENCES adhoc_requests(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id),
            submitted_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (request_id, user_id)
        )",
        "CREATE TABLE IF NOT EXISTS adhoc_response_values (
            response_id INTEGER NOT NULL REFERENCES adhoc_responses(id) ON DELETE CASCADE,
            request_field_id INTEGER NOT NULL REFERENCES adhoc_request_fields(id) ON DELETE CASCADE,
            value_text TEXT NOT NULL DEFAULT '',
            is_encrypted INTEGER NOT NULL DEFAULT 0 CHECK (is_encrypted IN (0, 1)),
            has_warning INTEGER NOT NULL DEFAULT 0 CHECK (has_warning IN (0, 1)),
            PRIMARY KEY (response_id, request_field_id)
        )",
        "CREATE TABLE IF NOT EXISTS request_keys (
            request_key TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            status TEXT NOT NULL CHECK (status IN ('processing', 'done')),
            result_url TEXT,
            created_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS access_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT NOT NULL CHECK (action IN ('view', 'export', 'admin', 'auth')),
            target TEXT NOT NULL,
            ip_address TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL
        )",
        "CREATE INDEX IF NOT EXISTS ix_access_logs_created ON access_logs (created_at)",
        "CREATE INDEX IF NOT EXISTS ix_access_logs_target ON access_logs (target)",
        "CREATE TABLE IF NOT EXISTS department_teams (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            department_id INTEGER NOT NULL REFERENCES departments(id),
            name TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (department_id, name)
        )",
        "CREATE TABLE IF NOT EXISTS department_successions (
            predecessor_department_id INTEGER NOT NULL REFERENCES departments(id),
            successor_department_id INTEGER NOT NULL REFERENCES departments(id),
            created_by INTEGER REFERENCES users(id),
            created_at TEXT NOT NULL,
            PRIMARY KEY (predecessor_department_id, successor_department_id),
            CHECK (predecessor_department_id <> successor_department_id)
        )",
        "CREATE TABLE IF NOT EXISTS department_field_options (
            department_id INTEGER NOT NULL REFERENCES departments(id),
            field_id INTEGER NOT NULL REFERENCES report_fields(id),
            code TEXT NOT NULL,
            label TEXT NOT NULL DEFAULT '',
            is_added INTEGER NOT NULL DEFAULT 0 CHECK (is_added IN (0, 1)),
            is_hidden INTEGER NOT NULL DEFAULT 0 CHECK (is_hidden IN (0, 1)),
            sort_order INTEGER NOT NULL DEFAULT 0,
            updated_by INTEGER REFERENCES users(id),
            updated_at TEXT NOT NULL,
            PRIMARY KEY (department_id, field_id, code)
        )",
        "CREATE TABLE IF NOT EXISTS comment_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            department_id INTEGER NOT NULL REFERENCES departments(id),
            body TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            updated_by INTEGER REFERENCES users(id),
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )",
        "CREATE INDEX IF NOT EXISTS ix_comment_templates_dept ON comment_templates (department_id, sort_order)",
        "CREATE TABLE IF NOT EXISTS personal_comment_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id),
            body TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )",
        "CREATE INDEX IF NOT EXISTS ix_personal_comment_templates_user ON personal_comment_templates (user_id, sort_order)",
        "CREATE TABLE IF NOT EXISTS ui_messages (
            msg_key TEXT PRIMARY KEY,
            body TEXT NOT NULL,
            updated_by INTEGER REFERENCES users(id),
            updated_at TEXT NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS app_state (
            name TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )",
        // 要望・依頼（G-22）。件名・本文は暗号化（title_enc・body_enc）
        "CREATE TABLE IF NOT EXISTS support_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id),
            department_id INTEGER REFERENCES departments(id),
            kind TEXT NOT NULL CHECK (kind IN ('improvement', 'task', 'bug', 'question')),
            urgency TEXT NOT NULL DEFAULT 'normal' CHECK (urgency IN ('normal', 'urgent')),
            screen_code TEXT NOT NULL DEFAULT '',
            title_enc TEXT NOT NULL DEFAULT '',
            body_enc TEXT NOT NULL DEFAULT '',
            has_warning INTEGER NOT NULL DEFAULT 0 CHECK (has_warning IN (0, 1)),
            status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'reviewing', 'in_progress', 'testing', 'release_waiting', 'released', 'done', 'declined', 'withdrawn')),
            source_path TEXT NOT NULL DEFAULT '',   -- 開いていた画面（同じサイトのパスと決まった問い合わせの項目だけ）
            client_text TEXT NOT NULL DEFAULT '',   -- 出したときの端末（PC／スマートフォン、ブラウザー・OS の名前）
            requester_seen_message_id INTEGER NOT NULL DEFAULT 0,   -- 出した人が最後に見たやり取りの番号（これより後の管理者側の返信が「回答あり」）
            last_activity_at TEXT NOT NULL,
            closed_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )",
        "CREATE INDEX IF NOT EXISTS ix_support_requests_user ON support_requests (user_id, last_activity_at)",
        "CREATE INDEX IF NOT EXISTS ix_support_requests_status ON support_requests (status, last_activity_at)",
        "CREATE TABLE IF NOT EXISTS support_request_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id INTEGER NOT NULL REFERENCES support_requests(id),
            user_id INTEGER NOT NULL REFERENCES users(id),
            is_staff INTEGER NOT NULL DEFAULT 0 CHECK (is_staff IN (0, 1)),
            body_enc TEXT NOT NULL DEFAULT '',
            status_change TEXT CHECK (status_change IS NULL OR status_change IN ('open', 'reviewing', 'in_progress', 'testing', 'release_waiting', 'released', 'done', 'declined', 'withdrawn')),
            created_at TEXT NOT NULL
        )",
        "CREATE INDEX IF NOT EXISTS ix_support_request_messages_request ON support_request_messages (request_id, id)",
        // 要望・依頼の返信の定型文（便利機能 B。管理者・補佐が使う）
        "CREATE TABLE IF NOT EXISTS support_reply_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind TEXT NOT NULL DEFAULT '',   -- 要望の種類（improvement・task・bug・question。空はすべての種類）
            body TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            updated_by INTEGER REFERENCES users(id),
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )",
        // 更新のお知らせ（便利機能 A。全員のホームに表示期間の間だけ出す）
        "CREATE TABLE IF NOT EXISTS update_notices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT NOT NULL DEFAULT '',
            show_from TEXT NOT NULL,
            show_until TEXT NOT NULL,
            source_request_id INTEGER REFERENCES support_requests(id),
            created_by INTEGER REFERENCES users(id),
            updated_by INTEGER REFERENCES users(id),
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )",
        "CREATE INDEX IF NOT EXISTS ix_update_notices_show ON update_notices (show_from, show_until)",
        // よくある質問（便利機能 J。全員が見られる。管理者側が文を書く）
        "CREATE TABLE IF NOT EXISTS faq_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            question TEXT NOT NULL,
            answer TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            source_request_id INTEGER REFERENCES support_requests(id),
            updated_by INTEGER REFERENCES users(id),
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )",
        // 組織の改訂の予約（便利機能 G。予約した日に一括の CSV を反映する）
        "CREATE TABLE IF NOT EXISTS org_schedules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            apply_on TEXT NOT NULL,
            csv_text TEXT NOT NULL,
            change_count INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled', 'applied', 'failed', 'cancelled')),
            result_text TEXT NOT NULL DEFAULT '',
            created_by INTEGER REFERENCES users(id),
            created_at TEXT NOT NULL,
            finished_at TEXT
        )",
        "CREATE INDEX IF NOT EXISTS ix_org_schedules_status ON org_schedules (status, apply_on)",
    ];
    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }

    // あとから足した列（既存の DB には ALTER TABLE で足す）
    $addedColumnsByTable = [
        'departments'         => ['review_scope' => "TEXT NOT NULL DEFAULT 'department' CHECK (review_scope IN ('department', 'team'))"],   // 管理の仕方（G-14 §6.7）
        'department_reviewers' => [
            'team_id'    => 'INTEGER REFERENCES department_teams(id)',   // 担当の班（NULL なら課全体）
            'does_check' => 'INTEGER NOT NULL DEFAULT 1 CHECK (does_check IN (0, 1))',   // チェックするか（K-039。課長は常に 1。課長が「課の管理」で決める）
        ],
        'access_logs'         => ['acted_as_deputy' => 'INTEGER NOT NULL DEFAULT 0 CHECK (acted_as_deputy IN (0, 1))'],   // 補佐としての操作（G-15）
        'support_requests'    => [
            'plan_text'      => "TEXT NOT NULL DEFAULT ''",   // 対応の予定（便利機能 D。例: 2026年12月にリリース予定）
            'merged_into_id' => 'INTEGER REFERENCES support_requests(id)',   // まとめた先（便利機能 C。重複の要望）
        ],
        'support_reply_templates' => ['kind' => "TEXT NOT NULL DEFAULT ''"],   // 要望の種類（便利機能 B。K-047。空はすべての種類）
    ];
    foreach ($addedColumnsByTable as $tableName => $columnDefinitionByName) {
        $existingColumnNameSet = [];
        foreach ($pdo->query('PRAGMA table_info(' . $tableName . ')')->fetchAll() as $columnInfo) {
            $existingColumnNameSet[$columnInfo['name']] = true;
        }
        foreach ($columnDefinitionByName as $columnName => $columnDefinition) {
            if (!isset($existingColumnNameSet[$columnName])) {
                $pdo->exec("ALTER TABLE {$tableName} ADD COLUMN {$columnName} {$columnDefinition}");
                $log[] = "{$tableName}.{$columnName} を追加";
            }
        }
    }

    // システム管理者補佐: 有効な人で最大 3 名。管理者と補佐は兼ねない（G-14 §6.9。画面でも確かめるが、DB でも止める）
    foreach ([
        "CREATE TRIGGER IF NOT EXISTS tr_users_admin_deputy_insert AFTER INSERT ON users
           WHEN NEW.is_admin_deputy = 1
           BEGIN
             SELECT RAISE(ABORT, 'admin deputy: not with admin') WHERE NEW.is_admin = 1;
             SELECT RAISE(ABORT, 'admin deputy: max 3') WHERE (SELECT COUNT(*) FROM users WHERE is_admin_deputy = 1 AND is_active = 1) > 3;
           END",
        "CREATE TRIGGER IF NOT EXISTS tr_users_admin_deputy_update AFTER UPDATE OF is_admin_deputy, is_admin, is_active ON users
           WHEN NEW.is_admin_deputy = 1
           BEGIN
             SELECT RAISE(ABORT, 'admin deputy: not with admin') WHERE NEW.is_admin = 1;
             SELECT RAISE(ABORT, 'admin deputy: max 3') WHERE (SELECT COUNT(*) FROM users WHERE is_admin_deputy = 1 AND is_active = 1) > 3;
           END",
    ] as $deputyTriggerSql) {
        $pdo->exec($deputyTriggerSql);
    }

    // 作業フェーズの表示名「総合テスト」を「システムテスト」に（APP-021。課長が別の名前に変えていれば変えない。作成済みの報告の表示はそのまま）
    $pdo->exec("UPDATE report_field_options SET label = 'システムテスト'
                 WHERE code = 'system_test' AND label = '総合テスト'
                   AND field_id IN (SELECT id FROM report_fields WHERE code = 'phase')");

    // 客先名らしい言葉の初期値（一般的な言葉だけ。実際の客先名は入れない）
    $words = ['株式会社', '（株）', '(株)', '㈱', '有限会社', '（有）', '㈲', '合同会社', '様', '御中', 'Inc.', 'Co., Ltd.', 'Corporation', 'Ltd.'];
    $st = $pdo->prepare('INSERT OR IGNORE INTO warning_words (word, created_at) VALUES (?, ?)');
    foreach ($words as $w) {
        $st->execute([$w, now()]);
    }

    return $log;
}
