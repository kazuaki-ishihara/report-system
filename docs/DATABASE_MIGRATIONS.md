# Dynamic report fields database migration

This migration adds the database schema for the configurable report feature. It deliberately does not alter, copy, or delete data in the existing `reports` or `comments` tables. Existing report and comment data is not displayed by the new feature.

## What it creates

- `report_fields`: field definitions, field type, order, visibility, and required status.
- `report_records`: report records for the new feature, linked to the existing `users` table.
- `report_field_values`: values for each dynamic field on a new report.
- `report_field_comments`: comments on fields of new reports.
- Indexes for common ordering and lookup operations.
- A trigger that prevents physical deletion of field definitions. Deactivate a field by setting `is_active = 0` instead.
- Six initial field definitions: 日付, 氏名, 件名, 内容, 課題, 備考. These definitions are seeded without copying any existing report values or comments.

Supported field types are `single_text`, `multi_text`, and `date`. Date values should be stored by the application as `YYYY-MM-DD` text.

## Preconditions

- Run against a copy of the actual SQLite database first.
- The database must already contain the existing `users(id)` table. This migration references it for report owners and comment authors.
- Stop application writes while taking the backup and applying the migration.
- Do not commit or replace `data/reports.sqlite` as part of this migration change.

## Backup and apply

From the repository root, create a SQLite backup outside the repository (replace the destination with a suitable secure location):

```sh
sqlite3 data/reports.sqlite ".backup '/secure/path/reports-before-dynamic-fields.sqlite'"
```

Apply the migration to the database file:

```sh
sqlite3 data/reports.sqlite < database/migrations/001_dynamic_report_fields.sql
```

`PRAGMA foreign_keys = ON` is included in the migration connection. SQLite foreign-key enforcement is connection-local, so the application must also run `PRAGMA foreign_keys = ON;` immediately after opening each PDO/SQLite connection, before starting transactions, for example in `config/database.php`. The migration intentionally does not modify that application file.

## Verification

The migration is designed to be safely re-run: tables and indexes use `IF NOT EXISTS`, the trigger uses `IF NOT EXISTS`, and the seed uses `INSERT OR IGNORE`. Verify by applying it a second time and checking the resulting schema and initial fields:

```sh
sqlite3 data/reports.sqlite <<'SQL'
PRAGMA foreign_keys;
PRAGMA foreign_key_check;
.tables
SELECT code, label, field_type, sort_order, is_active, is_required
FROM report_fields
ORDER BY sort_order, id;
SELECT COUNT(*) AS new_report_count FROM report_records;
SELECT COUNT(*) AS new_value_count FROM report_field_values;
SELECT COUNT(*) AS new_comment_count FROM report_field_comments;
SQL
```

`PRAGMA foreign_keys` should report `1` for that CLI connection, and `PRAGMA foreign_key_check` should return no rows. The new report/value/comment counts should remain zero until the new application screens are implemented and used. To confirm the existing tables were not modified, compare `.schema reports`, `.schema comments`, and their row counts before and after the migration.

## Rollback

Do not drop these tables as a routine rollback after the feature has been used: that would delete new-feature data. Before use, rollback should be performed by restoring the pre-migration backup. If new report data has been created, take a separate backup and plan a data-preserving rollback before changing the schema.
