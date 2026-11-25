#!/usr/bin/env python3
"""暗号化の鍵の入れ替え（app/bin/rotate_key.php。共通編 §8.2.1。ローカル検証環境専用）。

写しのサイト（自分用の DB・鍵）で動かす。ふだんの DB・鍵には触れない。
すべての暗号化の列に値を入れ、入れ替えの前後で平文が同じこと、断るとき・途中で失敗したときに DB が変わらないこと、
2 段階認証でのログイン・報告の表示が続くこと、入れ替えの前のログインが切れることを確かめる。

    python3 tools/test/smoke_key_rotation.py --db app/data/verify.sqlite --php <php>
"""
import argparse
import datetime
import glob
import json
import os
import re
import shutil
import sqlite3
import subprocess
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import verify_all  # noqa: E402
from lib_http import check, csrf, login, section, summary  # noqa: E402

ROOT = verify_all.ROOT
SKIP_NAMES = shutil.ignore_patterns("data", "logs", "backups", "keys", "output", "deploy", "__pycache__", "config.local.php")
# 暗号化の列と付加データ（製品の一覧とは別に、ここでも書く。$r は行）
ENCRYPTED = [
    ("users", "totp_secret_enc", "1 = 1", "'totp:' . $r['id']"),
    ("user_backup_codes", "code_enc", "1 = 1", "'bkcode:' . $r['user_id'] . ':' . $r['id']"),
    ("lead_recovery_requests", "first_half_enc", "1 = 1", "'leadrec:' . $r['id']"),
    ("report_field_values", "value_text", "is_encrypted = 1", "'rfv:' . $r['report_id'] . ':' . $r['field_id'] . ':' . $r['report_project_id']"),
    ("report_value_history", "old_value", "is_encrypted = 1", "'rvh:' . $r['report_id'] . ':' . $r['field_id'] . ':' . $r['report_project_id'] . ':' . $r['revision'] . ':old'"),
    ("report_value_history", "new_value", "is_encrypted = 1", "'rvh:' . $r['report_id'] . ':' . $r['field_id'] . ':' . $r['report_project_id'] . ':' . $r['revision'] . ':new'"),
    ("report_field_comments", "comment_enc", "1 = 1", "'cmt:' . $r['report_id'] . ':' . $r['id']"),
    ("adhoc_response_values", "value_text", "is_encrypted = 1", "'arv:' . $r['response_id'] . ':' . $r['request_field_id']"),
    ("support_requests", "title_enc", "1 = 1", "'sreq_title:' . $r['id']"),
    ("support_requests", "body_enc", "1 = 1", "'sreq_body:' . $r['id']"),
    ("support_request_messages", "body_enc", "1 = 1", "'sreq_msg:' . $r['request_id'] . ':' . $r['id']"),
    ("faq_chat_logs", "question_enc", "1 = 1", "'faq_chat:' . $r['id']"),
    ("expense_sheets", "data_enc", "1 = 1", "'expense_sheets:' . $r['user_id']"),
    ("goal_sheets", "data_enc", "1 = 1", "'goal_sheets:' . $r['id']"),
    ("goal_sheet_comments", "body_enc", "1 = 1", "'goal_sheet_comments:' . $r['sheet_id']"),
    ("goal_sheet_meetings", "note_enc", "1 = 1", "'goal_sheet_meetings:' . $r['sheet_id']"),
]


def main():
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--base", default="", help="使わない（自分でサーバーを立てる）")
    argument_parser.add_argument("--db", default="", help="使わない（写しの DB を使う）")
    argument_parser.add_argument("--php", required=True)
    arguments = argument_parser.parse_args()
    environment = {name: value for name, value in os.environ.items() if name != "MR_VERIFY_DB"}
    work = os.path.realpath(tempfile.mkdtemp(prefix="mr_key_rotation_"))
    site = os.path.join(work, "site")
    db_path = os.path.join(site, "app", "data", "dev.sqlite")
    key_dir = os.path.join(site, "app", "keys")
    runner_path = os.path.join(work, "runner.php")
    servers = []

    def php(*command):
        done = subprocess.run([arguments.php] + list(command), cwd=site, env=environment, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=120)
        return done.returncode, done.stdout.decode("utf-8", "replace")

    def rotate(*options):
        return php(os.path.join("app", "bin", "rotate_key.php"), *options)

    def run_code(code, target_db=None):
        with open(runner_path, "w", encoding="utf-8") as handle:
            handle.write("<?php require %s; %s %s" % (json.dumps(os.path.join(site, "app", "bootstrap.php").replace(os.sep, "/")),
                         "$GLOBALS['APP_CONFIG']['db_path'] = %s;" % json.dumps(target_db.replace(os.sep, "/")) if target_db else "", code))
        return php(runner_path)

    def execute(sql, *values):
        connection = sqlite3.connect(db_path, timeout=10)
        try:
            rows = connection.execute(sql, values).fetchall()
            connection.commit()
            return rows
        finally:
            connection.close()

    def columns(table):
        return [row[1] for row in execute("PRAGMA table_info(%s)" % table)]

    def present_targets():
        return [target for target in ENCRYPTED if target[1] in columns(target[0])]

    def plain_texts(target_db=None):
        """すべての暗号化の値を復号したもの（付加データはこの検証の一覧のもの）。{表.列: [平文]}"""
        code = "$out = [];"
        for table, column, condition, aad in present_targets():
            code += ("foreach (db_all(\"SELECT * FROM %s WHERE %s AND %s IS NOT NULL AND %s <> '' ORDER BY rowid\") as $r) { $out['%s.%s'][] = dec((string) $r['%s'], %s); }"
                     % (table, condition, column, column, table, column, column, aad))
        status, text = run_code(code + "echo json_encode($out);", target_db)
        return json.loads(text) if status == 0 else {"error": text}

    def blobs():
        return {"%s.%s" % (t, c): execute("SELECT rowid, %s FROM %s WHERE %s ORDER BY rowid" % (c, t, w)) for t, c, w, _ in present_targets()}

    def versions():
        return {blob.split(":")[0] for rows in blobs().values() for _, blob in rows if blob}

    def keys():
        return sorted(os.path.basename(path) for path in glob.glob(os.path.join(key_dir, "*.key")))

    def backups():
        return glob.glob(os.path.join(site, "app", "backups", "before_key_rotation_*.sqlite"))

    def insert(table, **values):
        for _, name, kind, not_null, default, primary in execute("PRAGMA table_info(%s)" % table):
            if not_null and default is None and not primary and name not in values:
                values[name] = 1 if "INT" in kind.upper() else "2026-11-01 00:00:00"
        execute("INSERT INTO %s (%s) VALUES (%s)" % (table, ", ".join(values), ", ".join("?" * len(values))), *values.values())

    def fill_every_column():
        """まだ値がない列に、行と暗号文を入れる（どの列も 1 件以上ある状態にする）"""
        report_id = execute("SELECT MIN(id) FROM report_records")[0][0]
        field_id = execute("SELECT MIN(id) FROM report_fields")[0][0]
        rows = {
            "lead_recovery_requests": {"user_id": 1, "requester_id": 1, "requester_name": "x", "requester_role": "x", "reason": "x", "status": "pending", "first_half_enc": "x"},
            "report_value_history": {"report_id": report_id, "field_id": field_id, "revision": 1, "old_value": "x", "new_value": "x", "is_encrypted": 1, "user_id": 1},
            "report_field_comments": {"report_id": report_id, "user_id": 2, "comment_enc": "x"},
            "adhoc_requests": {"department_id": 1, "title": "x", "created_by": 2, "status": "open", "result_visibility": "detail"},
            "adhoc_request_fields": {"request_id": 1, "label": "x", "field_type": "single_text"},
            "adhoc_request_targets": {"request_id": 1, "user_id": 5},
            "adhoc_responses": {"request_id": 1, "user_id": 5, "submitted_at": "2026-11-01 00:00:00"},
            "adhoc_response_values": {"response_id": 1, "request_field_id": 1, "value_text": "x", "is_encrypted": 1},
            "support_requests": {"kind": "bug", "user_id": 2, "department_id": 1, "title_enc": "x", "body_enc": "x"},
            "support_request_messages": {"request_id": 1, "user_id": 1, "body_enc": "x"},
            "faq_chat_logs": {"question_enc": "x", "user_id": 5, "conversation_id": "verify", "role": "member", "result": "matched"},
            "expense_sheets": {"user_id": 5, "data_enc": "x"},
            "goal_sheets": {"user_id": 5, "fiscal_year": 2026, "kind": "general", "data_enc": "x"},
            "goal_sheet_comments": {"sheet_id": 1, "body_enc": "x"},
            "goal_sheet_meetings": {"sheet_id": 1, "note_enc": "x", "held_on": "2026-11-01"},
        }
        connection = sqlite3.connect(db_path)
        connection.execute("PRAGMA foreign_keys = OFF")
        connection.close()
        for table, values in rows.items():
            if columns(table) and execute("SELECT COUNT(*) FROM %s" % table)[0][0] == 0:
                try:
                    insert(table, **values)
                except sqlite3.IntegrityError as error:
                    print("    準備: %s に入れられません（%s）" % (table, error))
        execute("UPDATE user_backup_codes SET code_enc = 'x' WHERE code_enc IS NULL")
        code = ""
        for number, (table, column, condition, aad) in enumerate(present_targets()):
            plain = "'平文%d-' . $r['rr']" % number
            if table == "user_backup_codes":
                plain = "sprintf('ab%08d', $r['rr'])"   # 予備のコードは半角 10 文字
            if table in ("expense_sheets", "goal_sheets"):
                plain = "json_encode(['full_name' => %s], JSON_UNESCAPED_UNICODE)" % plain
            code += ("foreach (db_all(\"SELECT rowid AS rr, * FROM %s WHERE %s AND %s = 'x'\") as $r) { db_exec('UPDATE %s SET %s = ? WHERE rowid = ?', [enc(%s, %s), $r['rr']]); }"
                     % (table, condition, column, table, column, plain, aad))
        status, text = run_code(code)
        check("準備: 暗号文を入れる", status == 0, text[-300:])

    def check_screens(title, texts):
        """暗号化した値が出る画面を、役割ごとに開き、平文が読めることを確かめる（平文は texts のもの）"""
        execute("UPDATE users SET totp_last_step = NULL")
        clients = {login_id: login(base, login_id, users[login_id]) for login_id in ("ippan_a1", "kacho_a", "admin01")}
        report_id = execute("SELECT MIN(id) FROM report_records")[0][0]
        screens = [
            ("報告の詳細の値", "ippan_a1", "/reports/view.php?id=%d" % report_id, "report_field_values.value_text"),
            ("報告の詳細のコメント", "ippan_a1", "/reports/view.php?id=%d" % report_id, "report_field_comments.comment_enc"),
            ("変更履歴の変更前", "ippan_a1", "/reports/view.php?id=%d" % report_id, "report_value_history.old_value"),
            ("変更履歴の変更後", "ippan_a1", "/reports/view.php?id=%d" % report_id, "report_value_history.new_value"),
            ("調査の回答", "ippan_a1", "/surveys/answer.php?id=1", "adhoc_response_values.value_text"),
            ("調査の結果", "kacho_a", "/surveys/result.php?id=1&detail=1", "adhoc_response_values.value_text"),
            ("要望・依頼の件名", "admin01", "/requests/view.php?id=1", "support_requests.title_enc"),
            ("要望・依頼の本文", "admin01", "/requests/view.php?id=1", "support_requests.body_enc"),
            ("要望・依頼のやり取り", "admin01", "/requests/view.php?id=1", "support_request_messages.body_enc"),
            ("要望・依頼の一覧", "admin01", "/requests/index.php", "support_requests.title_enc"),
            ("チャットの記録", "admin01", "/admin/faq_logs.php?conversation=verify", "faq_chat_logs.question_enc"),
            ("通勤定期代の精算書", "ippan_a1", "/expenses/edit.php?id=1", "expense_sheets.data_enc"),
            ("目標管理シート", "ippan_a1", "/goals/edit.php?id=1", "goal_sheets.data_enc"),
            ("目標管理のコメント", "ippan_a1", "/goals/edit.php?id=1", "goal_sheet_comments.body_enc"),
            ("目標管理の面談", "ippan_a1", "/goals/edit.php?id=1", "goal_sheet_meetings.note_enc"),
        ]
        for name, login_id, path, label in screens:
            if label not in texts:
                continue   # 表がないブランチ
            plain = texts[label][0]
            match = re.search(r"平文[0-9]+-[0-9]+", plain)
            expected = match.group(0) if match else plain
            status, _, body = clients[login_id].get(path)
            check("%s: %s が読める" % (title, name), status == 200 and expected in body and "この内容は表示できません" not in body and "（読み出せません）" not in body,
                  (status, expected, expected in body))
        edit_path = "/admin/user_edit.php?id=%d" % users["ippan_a1"]["id"]
        _, _, page = clients["admin01"].get(edit_path)
        clients["admin01"].post(edit_path, {"csrf_token": csrf(page), "request_key": os.urandom(16).hex(), "action": "view_backup_codes", "reason": "確かめ",
                                            "operator_password": users["admin01"]["password"]})
        _, _, page = clients["admin01"].get(edit_path)
        own_numbers = [index for index, row in enumerate(execute("SELECT user_id FROM user_backup_codes WHERE code_enc IS NOT NULL ORDER BY rowid")) if row[0] == users["ippan_a1"]["id"]]
        expected_codes = [code[:4] + "-" + code[4:] for code in (texts["user_backup_codes.code_enc"][index] for index in own_numbers)]
        check("%s: 予備のコードの確認で読める" % title, expected_codes != [] and all(code in page for code in expected_codes), (expected_codes, re.findall(r"<li>(.{0,30})</li>", page)[:3]))

    def set_maintenance(is_on):
        execute("DELETE FROM app_state WHERE name = 'maintenance'")
        if is_on:
            execute("INSERT INTO app_state (name, value) VALUES ('maintenance', ?)", json.dumps({"until": "2099-01-01 00:00:00", "message": "", "started_by": 1, "started_at": "2026-11-01 00:00:00"}))

    def unchanged(title, before, action):
        status, text = action()
        check(title, status != 0 and blobs() == before and not backups() and keys() == ["field_v1.key"], (status, text[-200:], keys()))
        return text

    section("共通", "暗号化の鍵の入れ替え（rotate_key.php）")
    try:
        for folder in ("app", "public", "tools"):
            shutil.copytree(os.path.join(ROOT, folder), os.path.join(site, folder), ignore=SKIP_NAMES)
        os.makedirs(os.path.join(site, "app", "data"))
        with open(os.path.join(site, "app", "config", "config.local.php"), "w", encoding="utf-8") as handle:
            handle.write("<?php return ['base_path' => '', 'https' => false, 'allow_dev_seed' => true, 'dev_skip_2fa' => false, 'db_path' => dirname(__DIR__) . '/data/dev.sqlite'];\n")
        check("準備: テストデータ", php(os.path.join("tools", "test", "dev_seed.php"), "--yes")[0] == 0)
        users = json.load(open(os.path.join(site, "tools", "test", "output_dev_users.json"), encoding="utf-8"))["users"]
        port = verify_all.free_port()
        base = "http://127.0.0.1:%d" % port
        servers.append(subprocess.Popen([arguments.php, "-d", "opcache.enable=0", "-S", "127.0.0.1:%d" % port, "-t", "public"], cwd=site, env=environment,
                                        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
        check("準備: サーバー", verify_all.wait_server(base))
        # 実際の画面から報告を出し、暗号文を作る
        client = login(base, "ippan_a1", users["ippan_a1"])
        period = (datetime.date.today().replace(day=1) - datetime.timedelta(days=1)).strftime("%Y-%m")
        _, _, page = client.get("/reports/edit.php?period=" + period)
        project = execute("SELECT id FROM projects WHERE code = 'P-0001'")[0][0]
        duties = execute("SELECT id FROM report_fields WHERE code = 'duties'")[0][0]
        client.post("/reports/save.php", {"csrf_token": csrf(page), "request_key": os.urandom(16).hex(), "action": "draft", "period": period,
                                          "b[0][project]": project, "b[0][f][%d]" % duties: "鍵の入れ替えの本文"})
        check("準備: 画面から保存した値が暗号化されている", any(blob.startswith("v1:") for _, blob in blobs()["report_field_values.value_text"]))
        fill_every_column()
        before_texts = plain_texts()
        filled = {"%s.%s" % (t, c) for t, c, _, _ in present_targets()}
        check("準備: すべての暗号化の列に値がある", set(before_texts) == filled, sorted(filled - set(before_texts)))
        check("準備: すべて復号できる", all(value is not None for values in before_texts.values() for value in values))
        check_screens("入れ替えの前", before_texts)
        before_blobs = blobs()
        total = sum(1 for rows in before_blobs.values() for _, blob in rows if blob)

        # ---- 確かめだけ・断るとき ----
        status, text = rotate("--dry-run")
        check("--dry-run は件数を出し、何も変えない", status == 0 and "今の鍵の版: v1 / 暗号化されている値: %d 件" % total in text
              and blobs() == before_blobs and keys() == ["field_v1.key"] and not backups(), text[-300:])
        check("メンテナンス中でなければ断る", "メンテナンス中ではありません" in unchanged("メンテナンス中でないとき何も変えない", before_blobs, rotate))
        set_maintenance(True)
        unchanged("DB がないとき断る", before_blobs, lambda: rotate("--db=" + os.path.join(work, "none.sqlite").replace(os.sep, "/")))
        check("DB がないとき、空の DB を作らない", not os.path.exists(os.path.join(work, "none.sqlite")))
        sqlite3.connect(os.path.join(work, "empty.sqlite")).execute("CREATE TABLE a (b)").connection.close()
        check("表がない DB は断る", "先に migrate.php" in unchanged("表がない DB で何も変えない", before_blobs, lambda: rotate("--db=" + os.path.join(work, "empty.sqlite").replace(os.sep, "/"))))
        execute("ALTER TABLE departments ADD COLUMN memo_enc TEXT")
        check("一覧にない暗号化の列があれば断る", "departments.memo_enc" in unchanged("一覧にない列のとき何も変えない", before_blobs, rotate))
        execute("ALTER TABLE departments DROP COLUMN memo_enc")
        comment_rowid, comment_blob = execute("SELECT rowid, comment_enc FROM report_field_comments ORDER BY rowid LIMIT 1")[0]
        execute("UPDATE report_field_comments SET comment_enc = 'v1:broken' WHERE rowid = ?", comment_rowid)
        broken_blobs = blobs()
        text = unchanged("読めない値があれば何も変えない", broken_blobs, rotate)
        check("読めない値の表と件数を出し、中身は出さない", "report_field_comments.comment_enc 1 件" in text and "broken" not in text, text[-300:])
        status, text = rotate("--dry-run")
        check("--dry-run でも、読めない値を知らせて失敗にする", status == 1 and "復号できない値: report_field_comments.comment_enc 1 件" in text)
        execute("UPDATE report_field_comments SET comment_enc = ? WHERE rowid = ?", comment_blob, comment_rowid)

        # ---- 途中で失敗（書き込みの最後のほうで止める）: DB は元のまま・鍵の版も元のまま ----
        execute("CREATE TRIGGER tr_verify_stop BEFORE UPDATE OF question_enc ON faq_chat_logs BEGIN SELECT RAISE(ABORT, 'verify stop'); END")
        status, text = rotate()
        check("途中で失敗したら、DB は元のまま", status == 1 and blobs() == before_blobs and execute("SELECT COUNT(*) FROM app_state WHERE name = 'crypto_version'") == [(0,)], (status, text[-200:]))
        check("途中で失敗しても、控えがあり、値を読める", len(backups()) == 1 and plain_texts() == before_texts)
        execute("DROP TRIGGER tr_verify_stop")
        for path in backups():
            os.remove(path)

        # ---- 入れ替え（前に途中で止まったときの v2 の鍵があっても使える） ----
        old_client = login(base, "kacho_a", users["kacho_a"])
        check("準備: 入れ替えの前はログインしている", old_client.get("/index.php")[0] == 200)
        status, text = rotate()
        check("入れ替えられる", status == 0 and "入れ替えました: v1 → v2、%d 件" % total in text, text[-400:])
        check("途中で止まったときの新しい鍵を使うことを知らせる", "新しい版の鍵がもうあるので" in text)
        check("すべて v2 になる", versions() == {"v2"}, versions())
        check("平文は入れ替えの前と同じ", plain_texts() == before_texts)
        check("今の版は v2", execute("SELECT value FROM app_state WHERE name = 'crypto_version'") == [("v2",)])
        check("古い鍵は消さない・新しい鍵は別の値", keys() == ["field_v1.key", "field_v2.key"]
              and open(os.path.join(key_dir, "field_v1.key")).read() != open(os.path.join(key_dir, "field_v2.key")).read())
        check("記録に残る", execute("SELECT COUNT(*) FROM access_logs WHERE action = 'admin' AND target = ?", "key:rotate;v1->v2;count=%d" % total) == [(1,)])
        check("DB の整合・外部キー", execute("PRAGMA integrity_check") == [("ok",)] and execute("PRAGMA foreign_key_check") == [], (execute("PRAGMA integrity_check"), execute("PRAGMA foreign_key_check")))
        check("出た行に、平文・鍵が出ない", "平文" not in text and "鍵の入れ替えの本文" not in text
              and all(open(os.path.join(key_dir, name)).read().strip() not in text for name in keys()))
        raw = b"".join(open(path, "rb").read() for path in glob.glob(db_path + "*"))
        old_blob_list = [blob for rows in before_blobs.values() for _, blob in rows if blob]
        check("DB のファイルに、古い暗号文が残らない", not any(blob.encode() in raw for blob in old_blob_list))
        check("控えは、古い鍵で読める", len(backups()) == 1 and plain_texts(backups()[0]) == before_texts)
        status, location, _ = old_client.get("/index.php")
        check("入れ替えの前のログインは切れる", status == 303 and "/login.php" in location, (status, location))

        # ---- 古い鍵を外しても使える ----
        os.rename(os.path.join(key_dir, "field_v1.key"), os.path.join(work, "field_v1.key"))
        check("古い鍵を外しても、すべて読める", plain_texts() == before_texts)
        check("古い鍵を外して migrate.php を流しても、古い版の鍵を作り直さない", php(os.path.join("app", "bin", "migrate.php"))[0] == 0 and keys() == ["field_v2.key"], keys())
        set_maintenance(False)
        execute("UPDATE users SET totp_last_step = NULL")
        check_screens("入れ替えのあと", before_texts)
        execute("UPDATE users SET totp_last_step = NULL")
        client = login(base, "ippan_a1", users["ippan_a1"])
        check("2 段階認証でログインできる", client.get("/index.php")[0] == 200)
        _, _, page = client.get("/reports/edit.php?period=" + period)
        check("画面で報告の値が読める", "鍵の入れ替えの本文" in page and "この内容は表示できません" not in page)
        values = {"csrf_token": csrf(page), "request_key": os.urandom(16).hex(), "action": "draft", "period": period, "b[0][project]": project, "b[0][f][%d]" % duties: "入れ替え後の本文"}
        for name, value in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', page):
            values.setdefault(name, value)
        status, location, body = client.post("/reports/save.php", values)
        _, _, page = client.get("/reports/edit.php?period=" + period)
        check("入れ替えのあとに保存した値も v2 で、画面で読める", "入れ替え後の本文" in page and versions() == {"v2"}, (status, location, versions()))

        # ---- もう一度（v2 → v3） ----
        before_texts = plain_texts()
        set_maintenance(True)
        status, text = rotate()
        check("もう一度入れ替えられる（v2 → v3）", status == 0 and "v2 → v3" in text and versions() == {"v3"} and plain_texts() == before_texts, text[-300:])
        # ---- 版の値がおかしいとき ----
        execute("UPDATE app_state SET value = '../x' WHERE name = 'crypto_version'")
        status, text = rotate("--dry-run")
        check("鍵の版の値がおかしいときは断る", status == 1 and "encryption key version is invalid" in text, text[-200:])
        execute("UPDATE app_state SET value = 'v3' WHERE name = 'crypto_version'")
        check("元に戻せば使える", rotate("--dry-run")[0] == 0)
    finally:
        for server in servers:
            server.terminate()
            server.wait(timeout=10)
        shutil.rmtree(work, ignore_errors=True)
    return summary()


if __name__ == "__main__":
    sys.exit(main())
