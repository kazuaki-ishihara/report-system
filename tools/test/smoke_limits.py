#!/usr/bin/env python3
"""時間切れ・同時の保存・上限・壊れたデータ・Web から呼ばれたコマンドの確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

    python3 tools/test/smoke_limits.py --base http://127.0.0.1:8000 --db app/data/verify.sqlite --php <php>
"""
import argparse
import datetime
import json
import os
import re
import secrets
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import threading
import time
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import verify_all  # noqa: E402
from lib_http import Client, check, csrf, login, section, summary  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


def wait_ready(base):
    for _ in range(75):
        try:
            if urllib.request.urlopen(base + "/health.php", timeout=2).status == 200:
                return True
        except Exception:  # noqa: BLE001
            time.sleep(0.2)
    return False


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8000")
    ap.add_argument("--db", required=True)
    ap.add_argument("--php", required=True)
    a = ap.parse_args()
    here = os.path.dirname(os.path.abspath(__file__))
    seed = json.load(open(os.path.join(here, "output_dev_users.json"), encoding="utf-8"))
    users = seed["users"]
    db = sqlite3.connect(a.db, timeout=10, isolation_level=None)
    now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    today = datetime.date.today()
    period = today.strftime("%Y-%m")
    due = (today + datetime.timedelta(days=5)).isoformat()
    ids = dict(db.execute("SELECT code, id FROM report_fields"))
    project = db.execute("SELECT id FROM projects WHERE code = 'P-0001'").fetchone()[0]

    def one(sql, *values):
        row = db.execute(sql, values).fetchone()
        return row[0] if row else None

    clients = {name: login(a.base, name, users[name]) for name in ("admin01", "kacho_a", "kacho_b", "leader_a", "ippan_a1", "ippan_a2", "hosa01")}

    def post(name, path, action, values=None, headers=None):
        client = clients[name]
        _, _, body = client.get("/account.php?tab=password")
        data = {"csrf_token": csrf(body), "request_key": secrets.token_hex(16), "action": action}
        data.update(values or {})
        kept = dict(client.headers)
        client.headers.update(headers or {})
        try:
            return client.post(path, data)
        finally:
            client.headers = kept

    # ---- 時間切れ（サーバーの判定。セッションのファイルの時刻を動かす） ----
    section("共通", "自動ログアウト（無操作 30 分・最長 12 時間）")

    def shift(client, key, seconds):
        session_id = [c.value for c in client.jar if c.name == "mr_session"][0]
        path = os.path.join(verify_all.PHP_SESSION_DIR, "sess_" + session_id)
        with open(path, "rb") as handle:
            text = handle.read()
        changed, count = re.subn(key.encode() + rb"\|i:\d+", key.encode() + b"|i:%d" % (int(time.time()) - seconds), text)
        with open(path, "wb") as handle:
            handle.write(changed)
        return count

    def is_logged_in(client):
        status, _, body = client.get("/index.php")
        return status == 200 and "さん" in body

    for title, key, seconds, expected in (("無操作 29 分は続く", "last_seen", 29 * 60, True), ("無操作 31 分で切れる", "last_seen", 31 * 60, False),
                                          ("ログインから 11 時間 59 分は続く", "login_at", 12 * 3600 - 60, True), ("ログインから 12 時間 1 分で切れる", "login_at", 12 * 3600 + 60, False)):
        client = login(a.base, "ippan_b1", users["ippan_b1"])
        check("準備: " + title, shift(client, key, seconds) == 1)
        check(title, is_logged_in(client) is expected)
        if not expected:
            check(title + "（知らせが出る）", "一定時間操作がなかったため" in client.get("/login.php")[2])
            check(title + "（あとも入れない）", not is_logged_in(client))
    client = login(a.base, "ippan_b1", users["ippan_b1"])
    shift(client, "last_seen", 31 * 60)
    status, _, body = client.get("/session_check.php?format=json")
    check("時間切れのとき session_check.php は 401", status == 401 and json.loads(body).get("reason") == "login", status)
    client = login(a.base, "ippan_b1", users["ippan_b1"])
    _, _, body = client.get("/reports/edit.php?period=" + period)
    shift(client, "last_seen", 31 * 60)
    status, location, _ = client.post("/reports/save.php", {"csrf_token": csrf(body), "request_key": secrets.token_hex(16), "action": "draft", "period": period, "b[0][project]": project})
    check("時間切れの送信は保存せず、ログイン画面で知らせる", "/login.php" in location and "送信した内容は保存されていません" in client.get("/login.php")[2]
          and one("SELECT COUNT(*) FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NULL", users["ippan_b1"]["id"], period) == 0)
    client = login(a.base, "ippan_b1", users["ippan_b1"])
    session_id = [c.value for c in client.jar if c.name == "mr_session"][0]
    path = os.path.join(verify_all.PHP_SESSION_DIR, "sess_" + session_id)
    text = open(path, "rb").read()
    open(path, "wb").write(text.replace(b'stage|s:4:"full"', b'stage|s:4:"none"'))
    check("知らない段階のセッションはログアウトさせる", not is_logged_in(client))

    # ---- 同時の保存 ----
    section("G-04", "同時の新規作成（自動保存・確認完了との競合）")
    verify_db = os.environ.get("MR_VERIFY_DB")
    port = verify_all.free_port()
    second_base = "http://127.0.0.1:%d" % port
    server = subprocess.Popen(verify_all.php_server_command(a.php, port), cwd=ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        check("準備: 2 本目のサーバー", wait_ready(second_base) and verify_db is not None)
        for title, action, finish in (("自動保存", "autosave", None), ("下書き", "draft", "confirmed")):
            db.execute("UPDATE report_records SET deleted_at = ? WHERE user_id = ? AND period = ? AND deleted_at IS NULL", (now, users["ippan_a1"]["id"], period))
            first = login(a.base, "ippan_a1", users["ippan_a1"])
            _, _, body = first.get("/reports/edit.php?period=" + period)
            first.headers["X-Verify-Pause"] = "report_save"
            result = []
            values = {"csrf_token": csrf(body), "request_key": secrets.token_hex(16), "action": action, "period": period, "b[0][project]": project, "b[0][f][%d]" % ids["duties"]: "あとの値"}
            sender = threading.Thread(target=lambda: result.append(first.post("/reports/save.php", values)))
            sender.start()
            time.sleep(0.6)
            db.execute("INSERT INTO report_records (user_id, department_id, period, status, is_legacy, created_at, updated_at) VALUES (?, ?, ?, ?, 0, ?, ?)",
                       (users["ippan_a1"]["id"], seed["dept_a"], period, finish or "draft", now, now))
            sender.join(30)
            status, _, body = result[0]
            if action == "autosave":
                check("自動保存は conflict を返し、報告は 1 件", json.loads(body).get("reason") == "conflict" and one("SELECT COUNT(*) FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NULL", users["ippan_a1"]["id"], period) == 1, body[:80])
            else:
                check("確認完了の報告が先にあれば断る", status == 400 and "確認が完了しているため" in body, status)
    finally:
        server.terminate()
        try:
            server.wait(timeout=10)
        except Exception:  # noqa: BLE001
            server.kill()
    db.execute("UPDATE report_records SET deleted_at = ? WHERE deleted_at IS NULL AND period = ?", (now, period))

    # ---- 上限・検索 ----
    section("G-20", "検索の上限・見られない報告")
    good = {"period": period, "b[0][project]": project, "b[0][f][%d]" % ids["progress"]: "on_track", "b[0][f][%d]" % ids["continuation"]: "continuing"}
    for code, label in [("lim_%d" % n, "検索%d" % n) for n in range(7)]:
        db.execute("INSERT INTO report_fields (code, label, field_type, department_id, field_scope, confidentiality, sort_order, is_active, is_required, settings_json, carry_over, include_in_export, is_searchable, created_at, updated_at)"
                   " VALUES (?, ?, 'single_text', NULL, 'report', 'normal', 900, 1, 0, '{}', 0, 1, 1, ?, ?)", (code, label, now, now))
        good["r[%d]" % one("SELECT id FROM report_fields WHERE code = ?", code)] = "見つける言葉"
    post("ippan_a1", "/reports/save.php", "submit", good)
    report_id = one("SELECT id FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NULL", users["ippan_a1"]["id"], period)
    _, _, body = clients["kacho_a"].get("/reports/search.php?search=1&from=%s&to=%s&q=%s" % (period, period, urllib.parse.quote("見つける言葉")))
    check("同じ報告の合うところは 5 つまで出す", "view.php?id=%d" % report_id in body and len(re.findall(r"検索[0-6]", body)) == 5, len(re.findall(r"検索[0-6]", body)))
    db.execute("UPDATE departments SET review_scope = 'team' WHERE id = ?", (seed["dept_a"],))
    columns = [r[1] for r in db.execute("PRAGMA table_info(department_teams)")]
    for name in ("甲ライン", "乙ライン"):
        values = {"department_id": seed["dept_a"], "name": name, "sort_order": 10, "is_active": 1, "created_at": now, "updated_at": now}
        values = {k: v for k, v in values.items() if k in columns}
        db.execute("INSERT INTO department_teams (%s) VALUES (%s)" % (",".join(values), ",".join("?" * len(values))), list(values.values()))
    first_team, second_team = [r[0] for r in db.execute("SELECT id FROM department_teams ORDER BY id DESC LIMIT 2")]
    db.execute("UPDATE users SET team_id = ? WHERE login_id = 'ippan_a1'", (first_team,))
    db.execute("UPDATE department_reviewers SET team_id = ? WHERE user_id = ?", (second_team, users["leader_a"]["id"]))
    _, _, body = clients["leader_a"].get("/reports/search.php?search=1&from=%s&to=%s" % (period, period))
    check("別のラインの確認者の検索には出ない", "view.php?id=%d" % report_id not in body)
    _, _, body = clients["kacho_a"].get("/admin/department.php?dept=%d" % seed["dept_a"])
    check("ラインごとの課: 課員のラインを選ぶ欄が出る", "member_team[" in body)
    db.execute("UPDATE departments SET review_scope = 'department' WHERE id = ?", (seed["dept_a"],))
    db.execute("UPDATE department_reviewers SET team_id = NULL")
    for number in range(210):
        month = "%04d-%02d" % (today.year - 1, number % 12 + 1)
        db.execute("INSERT INTO users (username, password_hash, role_title, created_at, login_id, display_name, department_id) VALUES (?, 'x', '一般', ?, ?, ?, ?)", ("many%d" % number, now, "many%d" % number, "多数%d" % number, seed["dept_a"]))
        db.execute("INSERT INTO report_records (user_id, department_id, period, status, is_legacy, created_at, updated_at) VALUES (last_insert_rowid(), ?, ?, 'submitted', 0, ?, ?)", (seed["dept_a"], month, now, now))
    _, _, body = clients["kacho_a"].get("/reports/search.php?search=1&from=%d-01&to=%d-12" % (today.year - 1, today.year - 1))
    check("検索の結果は 200 件まで", len(set(re.findall(r"view\.php\?id=(\d+)", body))) == 200, len(set(re.findall(r"view\.php\?id=(\d+)", body))))
    db.execute("UPDATE report_records SET deleted_at = ? WHERE user_id IN (SELECT id FROM users WHERE login_id LIKE 'many%')", (now,))
    db.execute("UPDATE users SET is_active = 0 WHERE login_id LIKE 'many%'")

    section("G-09", "まとめ: 終了予定の月が近い案件・出力に入れない項目")
    soon = (today.replace(day=28) + datetime.timedelta(days=10)).strftime("%Y-%m")
    post("ippan_a2", "/reports/save.php", "submit", dict(good, **{"b[0][f][%d]" % ids["end_month"]: soon, "b[1][project]": one("SELECT id FROM projects WHERE code = 'P-0002'"),
                                                                     "b[1][f][%d]" % ids["progress"]: "on_track", "b[1][f][%d]" % ids["continuation"]: "continuing"}))
    _, _, body = clients["kacho_a"].get("/status/compare.php?period=%s&dept=%d&tab=summary" % (period, seed["dept_a"]))
    check("終了予定の月が 3 か月以内の案件が、まとめに出る", soon in body or "終了が近い" in body, body[:60])
    db.execute("UPDATE report_fields SET include_in_export = 0 WHERE code = 'lim_0'")
    post("kacho_a", "/export/index.php", "", {"type": "report", "format": "csv", "dept": seed["dept_a"], "from": period, "to": period, "content": "both", "free": "1"})
    text = clients["kacho_a"].last_raw.decode("utf-8-sig", "replace")
    check("出力に入れない項目は出ない", "検索0" not in text and "検索1" in text)
    lines = [line for line in text.splitlines() if "見つける言葉" in line]
    check("報告全体の値は、案件が 2 つでも 1 行だけに出る", len(lines) == 2, len(lines))
    rp = one("SELECT id FROM report_projects WHERE report_id = ?", report_id)
    body = clients["kacho_a"].get("/reports/view.php?id=%d" % one("SELECT id FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NULL", users["ippan_a2"]["id"], period))[2]
    check("詳細が開く（案件が 2 つ）", "案件 2" in body, rp)

    section("G-12", "チェックの表示の条件・無効の選択肢・使われている限定の項目・壊れた暗号文")
    db.execute("INSERT INTO report_fields (code, label, field_type, department_id, field_scope, confidentiality, sort_order, is_active, is_required, settings_json, created_at, updated_at) VALUES ('lim_check', '条件のチェック', 'checkbox', NULL, 'report', 'normal', 950, 1, 0, '{}', ?, ?)", (now, now))
    field = {"label": "条件付き", "code": "lim_when", "field_type": "single_text", "field_scope": "report", "confidentiality": "normal", "help_text": "", "placeholder": "", "show_when_field": "lim_check", "show_when_value[]": ["1"]}
    post("admin01", "/admin/field_edit.php?dept=common", "save", field)
    stored = one("SELECT settings_json FROM report_fields WHERE code = 'lim_when'")
    check("チェックの項目を表示の条件にできる", stored is not None and "lim_check" in stored, stored)
    post("admin01", "/admin/field_edit.php?dept=common", "save", dict(field, code="lim_table", label="表", field_type="table", show_when_field="", columns_text="a|A|single_text\n\nb|B|select|甲,乙", max_rows="3", **{"show_when_value[]": []}))
    table_id = one("SELECT id FROM report_fields WHERE code = 'lim_table'")
    body = clients["admin01"].get("/admin/field_edit.php?id=%s" % table_id)[2]
    check("表の項目の編集に、列の定義が出る", table_id is not None and "b|B|select|甲,乙" in body)
    db.execute("UPDATE report_field_options SET is_active = 0 WHERE field_id = ? AND code = 'other'", (ids["progress"],))
    body = clients["admin01"].get("/admin/field_edit.php?dept=common")[2]
    check("無効の選択肢は、表示の条件の候補に出さない", 'data-field-code="progress"' in body and not re.search(r'data-field-code="progress".*?value="other".*?</div>', body.split('data-field-code="continuation"')[0], re.S))
    check("無効の選択肢は、検索の条件に出さない", not re.search(r'value="%d:other"' % ids["progress"], clients["kacho_a"].get("/reports/search.php")[2]))
    db.execute("INSERT INTO report_field_values (report_id, field_id, report_project_id, value_text, is_encrypted, has_warning, updated_at) VALUES (?, ?, 0, 'v1:xx', 1, 0, ?)", (report_id, ids["client_recruiting"], now))
    status, _, body = post("admin01", "/admin/field_edit.php?id=%d" % ids["client_recruiting"], "save", {"label": "客先の追加要員の募集", "field_type": "radio", "field_scope": "project", "confidentiality": "normal", "help_text": "", "placeholder": "", "show_when_field": "",
                                                                                                             "opt_code[0]": "heard", "opt_label[0]": "聞いている", "opt_active[0]": "1", "opt_code[1]": "not_heard", "opt_label[1]": "聞いていない", "opt_active[1]": "1"})
    check("読めない暗号文があるときは、限定を外す途中で止め、何も変えない", status == 500 and one("SELECT confidentiality FROM report_fields WHERE id = ?", ids["client_recruiting"]) == "restricted", status)
    body = clients["kacho_a"].get("/reports/view.php?id=%d" % report_id)
    check("読めない暗号文があっても、報告は開く", body[0] == 200, body[0])
    db.execute("DELETE FROM report_field_values WHERE value_text = 'v1:xx'")

    section("G-14", "確認と項目管理者（残りの権限の切れ目）")
    db.execute("INSERT INTO department_admins (department_id, user_id) VALUES (?, ?)", (seed["dept_a"], users["ippan_a2"]["id"]))
    status, _, _ = clients["ippan_a2"].get("/admin/comment_templates.php?scope=mine")
    check("確認者でない人の自分の定型文は 403", status == 403, status)
    db.execute("DELETE FROM department_admins WHERE user_id = ?", (users["ippan_a2"]["id"],))
    status, _, _ = post("kacho_a", "/requests/index.php", "unknown")
    check("要望: 出せる人の知らない操作は 400", status == 400, status)
    db.execute("UPDATE users SET is_executive = 1 WHERE login_id = 'leader_a'")
    body = clients["leader_a"].get("/surveys/results.php")[2]
    check("見られる調査がない部長以上の一覧", "見られる調査はまだありません" in body)
    db.execute("INSERT INTO adhoc_requests (department_id, title, created_by, status, due_at, created_at, updated_at) VALUES (NULL, '質問なし', 1, 'open', ?, ?, ?)", (due, now, now))
    body = clients["admin01"].get("/surveys/result.php?id=%d" % one("SELECT MAX(id) FROM adhoc_requests"))[2]
    check("質問のない調査の結果", "集計する質問がありません" in body)

    section("G-21", "バックアップの状態・終了した案件")
    backup_dir = os.path.join(ROOT, "app", "backups")
    had_dir = os.path.isdir(backup_dir)
    os.makedirs(backup_dir, exist_ok=True)
    fake = os.path.join(backup_dir, "verify_old_marker.sqlite")
    other = [p for p in os.listdir(backup_dir) if p.endswith(".sqlite")]
    open(fake, "w").close()
    old_time = time.time() - 86400 * 40
    os.utime(fake, (old_time, old_time))
    try:
        body = clients["admin01"].get("/admin/index.php")[2]
        check("最後のバックアップの日時と個数が出る", "（%d 個）" % (len(other) + 1) in body)
        if not other:
            check("古いバックアップを目立たせる", "日" in re.search(r"（1 個）.*?</li>", body, re.S).group(0) and "tag-open" in re.search(r"（1 個）.*?</li>", body, re.S).group(0))
    finally:
        os.remove(fake)
        if not had_dir:
            os.rmdir(backup_dir)
    db.execute("UPDATE projects SET is_active = 0 WHERE code = 'P-0002'")
    body = clients["admin01"].get("/admin/projects.php?state=all")[2] + clients["admin01"].get("/admin/projects.php?state=ended")[2]
    check("終了した案件に再開のボタンが出る", 'value="reopen"' in body)

    section("G-25", "JavaScript なしのチャット（残りの返事）")
    post("admin01", "/faq.php", "import_standard")
    for question, part in (("用件なし", "わかりました"), ("大変です", "どういったご用件"), ("つらいのでパスワードを忘れた", "Q."), ("妊娠したので締め日を知りたい", "Q.")):
        _, _, body = clients["ippan_a2"].get("/faq.php?q=" + urllib.parse.quote(question))
        check("返事: " + question, part in body, re.findall(r'chat-bot[^>]*>([^<]{0,40})', body)[-2:])
    clients["ippan_a2"].get("/faq.php?q=" + urllib.parse.quote("コメントに返信したい"))
    _, _, body = clients["ippan_a2"].get("/faq.php?q=" + urllib.parse.quote("どうすればいい"))
    check("前の話題を補って答えるか、聞き返す", "についての答えです" in body, re.findall(r'chat-bot[^>]*>([^<]{0,40})', body)[-2:])
    db.execute("DELETE FROM faq_entries WHERE category = 'login'")
    _, _, body = clients["ippan_a2"].get("/faq.php?category=login")
    check("質問のない分野", "まだ載っていません" in body)

    section("G-27", "課の人がいないとき")
    if os.path.exists(os.path.join(ROOT, "public", "goals", "index.php")):
        db.execute("UPDATE users SET is_active = 0 WHERE login_id = 'ippan_b1'")
        _, _, body = clients["kacho_b"].get("/goals/index.php")
        check("見られる人がいないときの案内", "見られる人がいません" in body)
        db.execute("UPDATE users SET is_active = 1 WHERE login_id = 'ippan_b1'")

    # ---- Web から呼ばれたコマンド・公開の場所が変わるとき・DB の障害 ----
    section("共通", "Web から呼ばれたコマンドは 404・公開の場所・DB の障害")
    work = tempfile.mkdtemp(prefix="mr_limits_")
    web_port = verify_all.free_port()
    server = subprocess.Popen([a.php, "-d", "session.save_path=" + verify_all.PHP_SESSION_DIR, "-S", "127.0.0.1:%d" % web_port, "-t", os.path.join(ROOT, "app", "bin")],
                              cwd=ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        web = "http://127.0.0.1:%d/" % web_port
        for name in sorted(os.listdir(os.path.join(ROOT, "app", "bin"))):
            status = None
            for _ in range(50):
                try:
                    status = urllib.request.urlopen(web + name, timeout=5).status
                    break
                except urllib.error.HTTPError as error:
                    status = error.code
                    break
                except Exception:  # noqa: BLE001
                    time.sleep(0.2)
            check("Web から呼ばれたら 404: " + name, status == 404, status)
    finally:
        server.terminate()
        server.wait(timeout=10)
    # 写しの公開フォルダーで、公開の場所（base_path）と、壊れた DB・無い設定を試す
    copy = os.path.join(work, "site")
    shutil.copytree(os.path.join(ROOT, "public"), os.path.join(copy, "public"))
    shutil.copytree(os.path.join(ROOT, "app"), os.path.join(copy, "app"), ignore=shutil.ignore_patterns("data", "logs", "backups", "*.sqlite*"))
    os.makedirs(os.path.join(copy, "app", "data"))
    bad_db = os.path.join(copy, "app", "data", "bad.sqlite").replace(os.sep, "/")
    config = "<?php return ['base_path' => '/report-system', 'https' => false, 'allow_dev_seed' => false, 'db_path' => '%s', 'dev_skip_2fa' => %s];\n"
    web_port = verify_all.free_port()
    environment = {k: v for k, v in os.environ.items() if k != "MR_VERIFY_DB"}
    good_db = os.path.join(copy, "app", "data", "good.sqlite").replace(os.sep, "/")
    target = sqlite3.connect(good_db)
    db.backup(target)
    target.close()
    router = os.path.join(work, "router.php")
    with open(router, "w", encoding="utf-8") as handle:
        handle.write("<?php $p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); if (strpos($p, '/report-system/') === 0) { $f = %r . substr($p, 14); if (is_file($f) && substr($f, -4) === '.php') { $_SERVER['SCRIPT_NAME'] = $p; chdir(dirname($f)); require $f; return true; } } http_response_code(404); return true;"
                     % os.path.join(copy, "public").replace(os.sep, "/"))

    def with_server(db_path, skip_2fa, action):
        with open(os.path.join(copy, "app", "config", "config.local.php"), "w", encoding="utf-8") as handle:
            handle.write(config % (db_path, "true" if skip_2fa else "false"))
        process = subprocess.Popen([a.php, "-d", "opcache.enable=0", "-d", "session.save_path=" + verify_all.PHP_SESSION_DIR, "-S", "127.0.0.1:%d" % web_port, router], env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            for _ in range(50):
                try:
                    urllib.request.urlopen("http://127.0.0.1:%d/none" % web_port, timeout=2)
                except urllib.error.HTTPError:
                    break
                except Exception:  # noqa: BLE001
                    time.sleep(0.2)
            action("http://127.0.0.1:%d" % web_port)
        finally:
            process.terminate()
            process.wait(timeout=10)

    def base_path_case(base):
        client = Client(base)
        status, location, _ = client.get("/report-system/reports/mine.php")
        check("公開の場所が違っても、ログインのあとの戻り先は場所を除いて渡す", "next=%2Freports%2Fmine.php" in location, (status, location))
        _, _, body = client.get("/report-system/login.php")
        status, location, _ = client.post("/report-system/login.php", {"csrf_token": csrf(body), "action": "password", "login_id": "ippan_a1", "password": users["ippan_a1"]["password"], "next": "/index.php"})
        check("開発用の設定（https でない）では 2 段階認証を飛ばす", location == "/report-system/index.php", (status, location))

    def broken_case(base):
        for path in ("/report-system/login.php", "/report-system/index.php"):
            status, _, body = Client(base).get(path)
            check("DB が壊れていても、エラーの画面（503・500）になり、中身を出さない: " + path, status in (500, 503) and "PDOException" not in body and bad_db not in body, status)
        status, _, _ = Client(base).get("/report-system/health.php")
        check("health.php は 503", status == 503, status)
        status, _, body = Client(base).get("/report-system/faq.php?format=json")
        check("JSON の要求にもエラーを返す", status in (500, 503), status)

    try:
        with_server(good_db, True, base_path_case)
        with open(bad_db, "wb") as handle:
            handle.write(b"this is not a database" * 100)
        with_server(bad_db, False, broken_case)
        with_server(os.path.join(copy, "app").replace(os.sep, "/"), False, lambda base: check("DB を開けないとき（フォルダー）は 503・500", Client(base).get("/report-system/login.php")[0] in (500, 503)))
        done = subprocess.run([a.php, os.path.join(copy, "app", "bin", "backup.php"), "--db=" + os.path.join(copy, "app").replace(os.sep, "/")], env=environment, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        check("コマンドは、DB を開けないとき失敗を返す", done.returncode != 0, done.stdout.decode("utf-8", "replace")[-200:])
        done = subprocess.run([a.php, os.path.join(copy, "app", "bin", "run_schedules.php"), "--db=" + os.path.join(copy, "app").replace(os.sep, "/")], env=environment, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        check("コマンドは、DB を開けない理由を出して止める", done.returncode == 1 and "DB を開けません" in done.stdout.decode("utf-8", "replace"), done.stdout.decode("utf-8", "replace")[-200:])
        done = subprocess.run([a.php, os.path.join(copy, "app", "bin", "run_schedules.php"), "--db=" + bad_db], env=environment, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        check("コマンドは、壊れた DB で失敗を返す", done.returncode != 0, done.stdout.decode("utf-8", "replace")[-200:])
    finally:
        shutil.rmtree(work, ignore_errors=True)
    db.close()
    return summary()


if __name__ == "__main__":
    sys.exit(main())
