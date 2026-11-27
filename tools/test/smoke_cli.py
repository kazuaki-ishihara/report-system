#!/usr/bin/env python3
"""コマンド（app/bin/）の確認。使い捨ての DB で動かす（--db）。サーバーは使わない。

migrate.php・create_user.php・seed_template.php・backup.php・run_schedules.php・migrate_legacy.php・import_tomato.php・post_notice.php

    python3 tools/test/smoke_cli.py --php <php> --db app/data/verify.sqlite
"""
import argparse
import glob
import os
import shutil
import sqlite3
import subprocess
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, section, summary  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
BACKUP_DIR = os.path.join(ROOT, "app", "backups")

OLD_SCHEMA = """
CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL,
  role_title TEXT NOT NULL DEFAULT '一般', manager_id INTEGER, is_admin INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL);
CREATE TABLE reports (id INTEGER PRIMARY KEY, user_id INTEGER, report_date TEXT, name TEXT, subject TEXT, content TEXT, issues TEXT, remarks TEXT, created_at TEXT, updated_at TEXT);
CREATE TABLE comments (id INTEGER PRIMARY KEY, report_id INTEGER, user_id INTEGER, field_name TEXT, comment TEXT, created_at TEXT, updated_at TEXT);
INSERT INTO users (id, username, password_hash, role_title, created_at) VALUES (1, 'old.one@example.invalid', 'x', '一般', '2026-01-01 00:00:00'), (2, 'a@example.invalid', 'x', '課長', '2026-01-01 00:00:00');
INSERT INTO reports VALUES (1, 1, '2026-01-31', '検証一', '件名', '移行の本文', '課題', '', '2026-01-31 10:00:00', '2026-01-31 10:00:00'),
  (2, 1, '2026-02-28', '検証一', '件名2', '本文2', '', '', '2026-02-28 10:00:00', '2026-02-28 10:00:00');
INSERT INTO comments VALUES (1, 1, 2, 'content', '移行のコメント', '2026-02-01 10:00:00', '2026-02-01 10:00:00');
"""


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--php", required=True)
    ap.add_argument("--db", default="")
    ap.add_argument("--base", default="")
    a = ap.parse_args()
    work = tempfile.mkdtemp(prefix="mr_cli_")
    backups_before = set(glob.glob(os.path.join(BACKUP_DIR, "*")))
    had_backup_dir = os.path.isdir(BACKUP_DIR)

    def php(*arguments):
        done = subprocess.run([a.php] + list(arguments), cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=120)
        return done.returncode, done.stdout.decode("utf-8", "replace")

    def command(name, db_path, *arguments):
        return php(os.path.join("app", "bin", name), "--db=" + db_path, *arguments)

    def query(db_path, sql, *values):
        connection = sqlite3.connect(db_path, timeout=10)
        try:
            return connection.execute(sql, values).fetchall()
        finally:
            connection.close()

    def execute(db_path, script):
        connection = sqlite3.connect(db_path, timeout=10)
        connection.executescript(script)
        connection.commit()
        connection.close()

    try:
        section("DB", "コマンド: migrate.php")
        new_db = os.path.join(work, "new.sqlite")
        code, out = command("migrate.php", new_db)
        check("新しい DB に表を作れる", code == 0 and query(new_db, "SELECT COUNT(*) FROM sqlite_master WHERE name = 'report_records'") == [(1,)], out[-300:])
        code, out = command("migrate.php", new_db)
        check("2 回流しても同じ", code == 0, out[-300:])

        section("DB", "コマンド: backup.php")
        own = glob.glob(os.path.join(BACKUP_DIR, "reports_*.sqlite"))
        if len(own) < 13:
            code, out = command("backup.php", new_db)
            made = set(glob.glob(os.path.join(BACKUP_DIR, "reports_*.sqlite"))) - backups_before
            check("写しを 1 つ作る", code == 0 and len(made) == 1, out[-300:])
            check("写しを開けて、表がある", bool(made) and query(min(made), "PRAGMA integrity_check") == [("ok",)]
                  and query(min(made), "SELECT COUNT(*) FROM sqlite_master WHERE name = 'users'") == [(1,)])
        else:
            check("写しの確認（app/backups が多く、古い写しが消えるおそれがあるため流せない。減らしてから流す）", False, len(own))
        keep_dir = os.path.join(work, "keep")
        os.makedirs(keep_dir)
        for number in range(5):
            open(os.path.join(keep_dir, "reports_20200101_00000%d.sqlite" % number), "w").close()
        open(os.path.join(keep_dir, "before_migration.sqlite"), "w").close()
        runner = os.path.join(work, "keep.php")
        with open(runner, "w", encoding="utf-8") as handle:
            handle.write("<?php require %r; $GLOBALS['APP_CONFIG']['db_path'] = %r; $GLOBALS['APP_CONFIG']['backup_dir'] = %r; $GLOBALS['APP_CONFIG']['backup_keep'] = 3; backup_create();"
                         % tuple(p.replace(os.sep, "/") for p in (os.path.join(ROOT, "app", "bootstrap.php"), new_db, keep_dir)))
        code, out = php(runner)
        left = sorted(os.path.basename(p) for p in glob.glob(os.path.join(keep_dir, "reports_*.sqlite")))
        check("古い写しを決めた数まで減らし、ほかのファイルは消さない", code == 0 and len(left) == 3 and "reports_20200101_000004.sqlite" in left
              and os.path.exists(os.path.join(keep_dir, "before_migration.sqlite")), (out[-200:], left))

        section("G-14", "コマンド: create_user.php・seed_template.php・run_schedules.php")
        cli_db = os.path.join(work, "cli.sqlite")
        if a.db:
            source = sqlite3.connect(a.db)
            target = sqlite3.connect(cli_db)
            source.backup(target)
            source.close()
            target.close()
        else:
            command("migrate.php", cli_db)
        code, out = command("seed_template.php", cli_db)
        check("seed_template.php を 2 回目に流しても項目が増えない", code == 0 and query(cli_db, "SELECT COUNT(*) FROM report_fields WHERE code = 'duties'") == [(1,)], out[-200:])
        execute(cli_db, "INSERT INTO org_schedules (apply_on, csv_text, status, created_by, created_at) VALUES "
                        "('2020-01-01', 'これは組織の CSV ではない', 'scheduled', (SELECT id FROM users WHERE is_admin = 1 LIMIT 1), '2020-01-01 00:00:00'),"
                        "('2999-01-01', 'x', 'scheduled', (SELECT id FROM users WHERE is_admin = 1 LIMIT 1), '2020-01-01 00:00:00');")
        code, out = command("run_schedules.php", cli_db)
        check("run_schedules.php: 日が来た予約だけを処理し、誤りのある予約は失敗として残す",
              code == 0 and query(cli_db, "SELECT status FROM org_schedules WHERE apply_on = '2020-01-01' ORDER BY id DESC LIMIT 1") == [("failed",)]
              and query(cli_db, "SELECT status FROM org_schedules WHERE apply_on = '2999-01-01'") == [("scheduled",)], out[-300:])

        section("DB", "コマンド: migrate_legacy.php（既存データの移行）")
        old_db = os.path.join(work, "old.sqlite")
        execute(old_db, OLD_SCHEMA)
        code, out = command("migrate.php", old_db)
        check("旧の DB に新しい表を足せる", code == 0, out[-300:])
        code, out = command("migrate_legacy.php", old_db, "--dry-run")
        check("--dry-run は何も変えない", code == 0 and query(old_db, "SELECT COUNT(*) FROM report_records") == [(0,)], out[-300:])
        code, out = command("migrate_legacy.php", old_db)
        check("報告とコメントを移行し、件数が合う", code == 0 and "OK" in out and query(old_db, "SELECT COUNT(*) FROM report_records WHERE is_legacy = 1") == [(2,)]
              and query(old_db, "SELECT COUNT(*) FROM report_field_comments") == [(1,)], out[-400:])
        check("メールアドレスがログイン ID に替わる", query(old_db, "SELECT login_id, username FROM users ORDER BY id") == [("old.one", "old.one"), ("user2", "user2")],
              query(old_db, "SELECT login_id, username FROM users ORDER BY id"))
        plain = query(old_db, "SELECT COUNT(*) FROM report_field_values WHERE value_text LIKE '%移行の本文%'") + query(old_db, "SELECT COUNT(*) FROM report_field_comments WHERE comment_enc LIKE '%移行%'")
        check("本文とコメントは暗号化される", plain == [(0,), (0,)], plain)
        code, out = command("migrate_legacy.php", old_db)
        check("2 回流しても増えない", code == 0 and query(old_db, "SELECT COUNT(*) FROM report_records") == [(2,)], out[-300:])

        section("DB", "コマンド: import_tomato.php")
        tomato_db = os.path.join(work, "tomato.sqlite")
        execute(tomato_db, OLD_SCHEMA)
        into_db = os.path.join(work, "into.sqlite")
        command("migrate.php", into_db)
        code, out = command("import_tomato.php", into_db, "--from=" + tomato_db, "--dry-run")
        check("--dry-run は何も変えない", code == 0 and query(into_db, "SELECT COUNT(*) FROM users") == [(0,)], out[-300:])
        code, out = command("import_tomato.php", into_db, "--from=" + tomato_db)
        check("利用者・報告・コメントを取り込み、件数が合う", code == 0 and "利用者 OK" in out and query(into_db, "SELECT COUNT(*) FROM users") == [(2,)]
              and query(into_db, "SELECT COUNT(*) FROM report_records") == [(2,)] and query(into_db, "SELECT COUNT(*) FROM report_field_comments") == [(1,)], out[-400:])
        code, out = command("import_tomato.php", into_db, "--from=" + tomato_db)
        check("2 回目は何もしない", code == 0 and query(into_db, "SELECT COUNT(*) FROM users") == [(2,)], out[-300:])
        execute(into_db, "INSERT INTO departments (id, name, created_at, updated_at) VALUES (1, '取り込みの課', 'x', 'x'); UPDATE users SET department_id = 1;")
        code, out = command("import_tomato.php", into_db, "--fill-departments")
        check("--fill-departments で報告に課が入る", code == 0 and query(into_db, "SELECT COUNT(*) FROM report_records WHERE department_id = 1") == [(2,)], out[-300:])

        section("G-24", "コマンド: post_notice.php（反映のときに更新のお知らせを出す）")
        notice_db = os.path.join(work, "notice.sqlite")
        command("migrate.php", notice_db)

        def post_notice(file_text, *arguments):
            notice_path = os.path.join(work, "notice.txt")
            with open(notice_path, "w", encoding="utf-8", newline="") as notice_file:
                notice_file.write(file_text)
            return command("post_notice.php", notice_db, "--file=" + notice_path, *arguments)

        def notice_rows():
            return query(notice_db, "SELECT title, body, show_from, show_until, created_by, updated_by FROM update_notices ORDER BY id")

        signature = "以上、システム管理補佐（AI）より"
        today = query(notice_db, "SELECT date('now', 'localtime')")[0][0]
        code, out = post_notice("題名: 更新しました\n出す日数: 7\n---\n変わったこと\n", "--dry-run")
        check("--dry-run は書き込まない", code == 0 and "確かめだけ" in out and notice_rows() == [], out[-300:])
        code, out = post_notice("﻿題名: 更新しました\r\n出す日数: 7\r\n---\r\n変わったこと\r\n")
        rows = notice_rows()
        expected_until = query(notice_db, "SELECT date('now', 'localtime', '+7 days')")[0][0]
        check("お知らせを 1 件出す（BOM・CRLF のファイルも読む。本文の最後に署名・書いた人は空・出す日数のとおり）",
              code == 0 and rows == [("更新しました", "変わったこと\n\n" + signature, today, expected_until, None, None)], (out[-300:], rows))
        code, out = post_notice("題名: 更新しました\n出す日数: 7\n---\n変わったこと\n\n" + signature + "\n")
        check("もう一度流しても二重に出さない（署名を書いてあっても重ねない）", code == 0 and "同じお知らせ" in out and len(notice_rows()) == 1, out[-300:])
        check("記録に残す", query(notice_db, "SELECT COUNT(*) FROM access_logs WHERE action = 'admin' AND target LIKE 'notice:%:create_cli'") == [(1,)])
        code, out = post_notice("題名: 日数なし\n---\n")
        default_days = query(notice_db, "SELECT show_until FROM update_notices WHERE title = '日数なし'")
        check("本文が空でも署名だけ出す・出す日数を省くと既定の日数",
              code == 0 and query(notice_db, "SELECT body FROM update_notices WHERE title = '日数なし'") == [(signature,)]
              and default_days == [(query(notice_db, "SELECT date('now', 'localtime', '+14 days')")[0][0],)], (out[-300:], default_days))
        filler = 1000 - len("\n\n" + signature)
        code, out = post_notice("題名: " + "題" * 60 + "\n---\n" + "あ" * filler + "\n")
        check("題名 60 文字・本文が署名を含めて 1,000 文字ちょうどは出す", code == 0 and query(notice_db, "SELECT length(body) FROM update_notices WHERE title = ?", "題" * 60) == [(1000,)], out[-300:])
        before_count = len(notice_rows())
        for label, file_text in (("題名 61 文字", "題名: " + "題" * 61 + "\n---\n本文\n"),
                                 ("本文が署名を含めて 1,001 文字", "題名: 長い本文\n---\n" + "あ" * (filler + 1) + "\n"),
                                 ("題名がない", "---\n本文\n"),
                                 ("「---」の行がない", "題名: 区切りなし\n本文\n"),
                                 ("出す日数が 0", "題名: 日数 0\n出す日数: 0\n---\n本文\n"),
                                 ("読めない行がある", "題名: 余計な行\nなにか\n---\n本文\n"),
                                 ("制御文字がある", "題名: 制御文字\n---\n本\x07文\n")):
            code, out = post_notice(file_text)
            check("書き込まない: " + label, code == 1 and len(notice_rows()) == before_count, out[-300:])
    finally:
        for path in set(glob.glob(os.path.join(BACKUP_DIR, "*"))) - backups_before:
            os.remove(path)
        if not had_backup_dir and os.path.isdir(BACKUP_DIR) and not os.listdir(BACKUP_DIR):
            os.rmdir(BACKUP_DIR)
        shutil.rmtree(work, ignore_errors=True)
    return summary()


if __name__ == "__main__":
    sys.exit(main())
