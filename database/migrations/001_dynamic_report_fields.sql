PRAGMA foreign_keys = ON;

BEGIN IMMEDIATE;

CREATE TABLE IF NOT EXISTS report_fields (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT NOT NULL UNIQUE,
    label       TEXT NOT NULL,
    field_type  TEXT NOT NULL
                CHECK (field_type IN ('single_text', 'multi_text', 'date')),
    sort_order  INTEGER NOT NULL DEFAULT 0,
    is_active   INTEGER NOT NULL DEFAULT 1
                CHECK (is_active IN (0, 1)),
    is_required INTEGER NOT NULL DEFAULT 0
                CHECK (is_required IN (0, 1)),
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_report_fields_active_order
    ON report_fields (is_active, sort_order, id);

CREATE TABLE IF NOT EXISTS report_records (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_report_records_user_created
    ON report_records (user_id, created_at DESC, id DESC);

CREATE TABLE IF NOT EXISTS report_field_values (
    report_id  INTEGER NOT NULL,
    field_id   INTEGER NOT NULL,
    value_text TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL,
    PRIMARY KEY (report_id, field_id),
    FOREIGN KEY (report_id)
        REFERENCES report_records(id)
        ON DELETE CASCADE,
    FOREIGN KEY (field_id)
        REFERENCES report_fields(id)
        ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_report_field_values_field_report
    ON report_field_values (field_id, report_id);

CREATE TABLE IF NOT EXISTS report_field_comments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    report_id  INTEGER NOT NULL,
    field_id   INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    comment    TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (report_id)
        REFERENCES report_records(id)
        ON DELETE CASCADE,
    FOREIGN KEY (field_id)
        REFERENCES report_fields(id)
        ON DELETE RESTRICT,
    FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_report_field_comments_report_field_created
    ON report_field_comments (report_id, field_id, created_at, id);

CREATE INDEX IF NOT EXISTS idx_report_field_comments_user
    ON report_field_comments (user_id);

-- Field definitions are deactivated, not physically deleted, so historical
-- values and comments retain a stable field reference.
CREATE TRIGGER IF NOT EXISTS prevent_report_field_delete
BEFORE DELETE ON report_fields
BEGIN
    SELECT RAISE(ABORT, 'report_fields cannot be deleted; deactivate the field instead');
END;

-- Seed the initial field definitions only. No legacy report or comment data
-- is copied into the new tables.
INSERT OR IGNORE INTO report_fields
    (code, label, field_type, sort_order, is_active, is_required, created_at, updated_at)
VALUES
    ('report_date', '日付', 'date', 10, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('name', '氏名', 'single_text', 20, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('subject', '件名', 'single_text', 30, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('content', '内容', 'multi_text', 40, 1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('issues', '課題', 'multi_text', 50, 1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('remarks', '備考', 'multi_text', 60, 1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

COMMIT;
