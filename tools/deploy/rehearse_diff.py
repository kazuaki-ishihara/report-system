#!/usr/bin/env python3
"""差分の一式（build_diff_package.py が作るもの）を、公開済みのサイトに上げる手順を手元でなぞって確かめる（リハーサル）。

    python3 tools/deploy/rehearse_diff.py tools/deploy/output/diff_<日時>_<今の版>_<上げる版>
    python3 tools/deploy/rehearse_diff.py <一式> --keep          # 作業フォルダーを残す（中を見るとき）
    python3 tools/deploy/rehearse_diff.py <一式> --base 56a6983  # 今の版を指定する（既定は一式のフォルダー名から読む）

すること（手順書の 3〜7・9 と同じ順。サーバーでの手順書は onionプレオープン更新手順書_*.txt など）:
  1. 今の版（--base）の作業ツリーを一時フォルダーに作り、その版のテストデータと検証の道具で、報告・コメント・調査・要望・
     お知らせ・よくある質問・目標管理・精算書などを 1 つの DB に入れる（これを「今のサイトの DB」とみなす）。
  2. サイトの配置（公開の入り口と app/ が同じフォルダー）を作り、今の版で全員のログインと画面を確かめる。
  3〜6. 控え → 写しで移行（migration_check.php の snapshot・compare、post_notice.php --dry-run）→ 本番の位置の DB を移行 →
     プログラムを上げる → 2 回目の migrate.php。
  7. 新しい版で確かめる: 全員のログイン（同じパスワードと 2 段階認証）・予備のコード・画面（報告・目標管理・精算書が今の版と同じように読める）・
     組織に全員が出る・要望・よくある質問の取り込み（二重にならない）・お知らせを出す（二重にならない・字下げ・署名）。
  9. プログラムだけ今の版に戻して、ログインと画面を確かめる。
  ユーザーに関わる表（利用者・予備のコード・端末の記憶・課・課の役割・ライン）は、移行のあと・確かめのあと・戻したあとに、
  もとの列の値を 1 行ずつ比べる（ログインで変わる列だけは、ログインしたあとの比べから除く）。

開発の DB・鍵・設定には触れない。立てたサーバーは終わりに止め、作業ツリー（git worktree）と一時フォルダーは消す（--keep で残す）。
本番のサーバーでしか分からないこと（さくらの PHP・SQLite、.htaccess、cron、実際の利用者のデータ）は確かめられない。
Python 3.8 以上の標準ライブラリと git・PHP で動く（Linux・Windows 共通）。
"""
import argparse
import glob
import html
import json
import os
import re
import shutil
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, os.path.join(ROOT, "tools", "test"))
import lib_http  # noqa: E402

# 今の版の検証の道具のうち、データを入れるのに使うもの（その版にあるものだけ流す。NG は気にしない＝データを入れるため）
POPULATE_SCRIPTS = ["smoke_reports.py", "smoke_more.py", "smoke_features.py", "smoke_requests.py", "smoke_conveniences.py",
                    "smoke_additions.py", "smoke_expenses.py", "smoke_goals.py"]
USER_TABLES = ["users", "user_backup_codes", "remembered_devices", "department_reviewers", "department_admins", "department_teams", "departments"]
# ログインすると変わる列（ログインしたあとの比べから除く）。used_at は予備のコードで試しにログインした日時
LOGIN_VOLATILE_COLUMNS = ("last_login", "totp_last_step", "failed", "locked", "lock_", "used_at")
UNREADABLE_TEXT = "この内容は表示できません"   # 暗号化した値を戻せないときに画面に出す文（form.php・goal.php）
SIGNATURE_TEXT = "以上、システム管理補佐（AI）より"
RESULTS = []


def check(name, ok, detail=None):
    RESULTS.append((name, bool(ok)))
    print(("OK  " if ok else "NG  ") + name + ("" if ok or detail is None else "  " + str(detail)[:400]), flush=True)


class Rehearsal:
    def __init__(self, php_path, work_directory):
        self.php_path = php_path
        self.work = work_directory

    def php(self, *arguments, cwd=None, environment=None):
        done = subprocess.run([self.php_path] + list(arguments), cwd=cwd or self.work, env=environment,
                              stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        return done.returncode, done.stdout.decode("utf-8", "replace")


def copy_tree(source, target, skip=("data", "keys", "logs", "backups")):
    """フォルダーを写す。app/ の data・keys・logs・backups（DB・鍵・記録）は写さない（source が app のときは直下の同じ名前）"""
    skip_set = {os.path.join("app", name) for name in skip} | (set(skip) if os.path.basename(os.path.normpath(source)) == "app" else set())
    for folder, names, files in os.walk(source):
        relative = os.path.relpath(folder, source)
        names[:] = [name for name in names if os.path.normpath(os.path.join(relative, name)) not in skip_set and name != ".git"]
        os.makedirs(os.path.join(target, relative), exist_ok=True)
        for name in files:
            shutil.copy2(os.path.join(folder, name), os.path.join(target, relative, name))


def table_rows(db_path, table, columns=None):
    connection = sqlite3.connect(db_path)
    try:
        all_columns = [row[1] for row in connection.execute("PRAGMA table_info(%s)" % table)]
        use_columns = [column for column in (columns or all_columns) if column in all_columns]
        if not use_columns:
            return [], []
        return use_columns, sorted(connection.execute("SELECT %s FROM %s" % (", ".join(use_columns), table)).fetchall(), key=repr)
    finally:
        connection.close()


def user_snapshot(db_path, columns_by_table=None, skip_volatile=False):
    snapshot = {}
    for table in USER_TABLES:
        use_columns, rows = table_rows(db_path, table, (columns_by_table or {}).get(table))
        if skip_volatile:
            keep_index = [index for index, column in enumerate(use_columns) if not any(part in column for part in LOGIN_VOLATILE_COLUMNS)]
            use_columns = [use_columns[index] for index in keep_index]
            rows = sorted([tuple(row[index] for index in keep_index) for row in rows], key=repr)
        snapshot[table] = (use_columns, rows)
    return snapshot


def compare_snapshots(label, before, after):
    for table in USER_TABLES:
        columns, before_rows = before[table]
        after_columns, after_rows = after[table]
        if after_columns != columns:
            index_list = [after_columns.index(column) for column in columns]
            after_rows = sorted([tuple(row[index] for index in index_list) for row in after_rows], key=repr)
        check("%s: %s のもとの列（%d 列）の値が %d 行とも同じ" % (label, table, len(columns), len(before_rows)), before_rows == after_rows,
              [row for row in before_rows if row not in after_rows][:2])


def free_port():
    probe = socket.socket()
    probe.bind(("127.0.0.1", 0))
    port_number = probe.getsockname()[1]
    probe.close()
    return port_number


class Server:
    """PHP の組み込みサーバー（document_root を公開の入り口にする）"""

    def __init__(self, php_path, document_root, environment):
        self.base = "http://127.0.0.1:%d" % free_port()
        self.process = subprocess.Popen([php_path, "-S", self.base[len("http://"):], "-t", document_root], cwd=document_root, env=environment,
                                        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        for _ in range(75):
            try:
                if opener.open(self.base + "/health.php", timeout=2).read().strip() == b"ok":
                    return
            except Exception:  # noqa: BLE001
                time.sleep(0.2)
        self.stop()
        raise SystemExit("サーバーが起動しません: %s" % document_root)

    def stop(self):
        if self.process.poll() is None:
            self.process.terminate()
            try:
                self.process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                self.process.kill()


def environment_without_verify():
    return {key: value for key, value in os.environ.items() if key != "MR_VERIFY_DB"}


def try_logins(base, users):
    """全員のログイン（パスワードと 2 段階認証）。ログインできた人の一覧"""
    ok_list = []
    for login_id, info in sorted(users.items()):
        try:
            lib_http.login(base, login_id, info)
            ok_list.append(login_id)
        except SystemExit:
            pass
    return ok_list


def page_readability(base, users, login_ok, db_path):
    """報告（書いた本人）・目標管理・精算書の画面の状態と「表示できません」の数、組織の画面に出る有効な人（今の版と比べる）"""
    connection = sqlite3.connect(db_path)
    report_rows = connection.execute("SELECT r.id, u.login_id FROM report_records r JOIN users u ON u.id = r.user_id WHERE r.deleted_at IS NULL ORDER BY r.id").fetchall()
    owners_by_kind = {}
    for kind, sql in (("目標管理", "SELECT DISTINCT u.login_id FROM goal_sheets g JOIN users u ON u.id = g.user_id ORDER BY 1"),
                      ("精算書", "SELECT DISTINCT u.login_id FROM expense_sheets e JOIN users u ON u.id = e.user_id ORDER BY 1")):
        try:
            owners_by_kind[kind] = [row[0] for row in connection.execute(sql)]
        except sqlite3.OperationalError:
            owners_by_kind[kind] = []   # その版に無い機能
    active_names = sorted(row[0] for row in connection.execute("SELECT display_name FROM users WHERE login_id IS NOT NULL AND is_active = 1"))
    connection.close()
    clients = {}

    def client_of(login_id):
        if login_id not in clients:
            clients[login_id] = lib_http.login(base, login_id, users[login_id])
        return clients[login_id]

    result = {}
    for report_id, owner in report_rows:
        if owner in login_ok:
            status_code, _location, page_html = client_of(owner).get("/reports/view.php?id=%d" % report_id)
            result["報告 %d" % report_id] = (status_code, page_html.count(UNREADABLE_TEXT))
    for kind, path in (("目標管理", "/goals/index.php"), ("精算書", "/expenses/index.php")):
        for owner in owners_by_kind[kind]:
            if owner in login_ok:
                status_code, _location, page_html = client_of(owner).get(path)
                result["%s %s" % (kind, owner)] = (status_code, page_html.count(UNREADABLE_TEXT))
    admin_login = next((login_id for login_id in login_ok if login_id.startswith("admin")), None)
    org_html = client_of(admin_login).get("/admin/org.php?tab=users")[2] if admin_login else ""
    result["組織に出る有効な人"] = [name for name in active_names if html.escape(name) in org_html]
    result["有効な人"] = active_names
    return result


def populate(rehearsal, base_tree, db_path):
    """今の版のテストデータを作り、その版の検証の道具で 1 つの DB にデータを入れる"""
    environment = dict(environment_without_verify(), MR_VERIFY_DB=db_path, PYTHONIOENCODING="utf-8")
    code, out = rehearsal.php(os.path.join("tools", "test", "dev_seed.php"), "--yes", cwd=base_tree, environment=environment)
    if code != 0:
        raise SystemExit("今の版のテストデータを作れません: " + out[-300:])
    server = Server(rehearsal.php_path, os.path.join(base_tree, "public"), environment)
    try:
        for script_name in POPULATE_SCRIPTS:
            if not os.path.exists(os.path.join(base_tree, "tools", "test", script_name)):
                continue
            # 前の版の道具は工程ごとにテストデータを作り直す前提で、同じ区間の 2 段階認証のコードを使い回せない。区間の記録だけ消す（テストの DB）
            connection = sqlite3.connect(db_path)
            connection.execute("UPDATE users SET totp_last_step = NULL")
            connection.commit()
            connection.close()
            done = subprocess.run([sys.executable, os.path.join("tools", "test", script_name), "--base", server.base, "--db", db_path],
                                  cwd=base_tree, env=environment, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            output_text = done.stdout.decode("utf-8", "replace")
            print("   データを入れる: %-24s OK %4d / NG %3d（NG は同じ DB に続けて流したため。気にしない）"
                  % (script_name, len(re.findall(r"^OK  ", output_text, re.M)), len(re.findall(r"^NG  ", output_text, re.M))), flush=True)
    finally:
        server.stop()
    # データを入れた道具のログインで使った 2 段階認証の区間の記録を消す（このあとのログインの確かめが、同じ区間で断られないように。テストの DB）
    connection = sqlite3.connect(db_path)
    connection.execute("UPDATE users SET totp_last_step = NULL")
    connection.commit()
    connection.close()


def notice_expectations(notice_path):
    """お知らせ.txt から、ホームで確かめる題名・字下げのある行"""
    text = open(notice_path, encoding="utf-8-sig").read().replace("\r\n", "\n")
    title = re.search(r"^\s*題名\s*[:：]\s*(.*?)\s*$", text, re.M)
    body = text.split("\n---\n", 1)[1] if "\n---\n" in text else ""
    indented_lines = [line for line in body.split("\n") if re.match(r"^[ 　]+\S", line)]
    return (title.group(1) if title else ""), indented_lines


def main():
    argument_parser = argparse.ArgumentParser(description="差分の一式を上げる手順を手元でなぞって確かめる（リハーサル）")
    argument_parser.add_argument("package", help="一式のフォルダー（tools/deploy/output/diff_<日時>_<今の版>_<上げる版>）")
    argument_parser.add_argument("--base", default="", help="今サイトにある版（既定は一式のフォルダー名から読む）")
    argument_parser.add_argument("--php", default="", help="PHP（既定は PATH の php）")
    argument_parser.add_argument("--keep", action="store_true", help="作業フォルダーと今の版の作業ツリーを残す")
    arguments = argument_parser.parse_args()
    package = os.path.abspath(arguments.package)
    name_match = re.match(r"^diff_\d{8}_\d{6}_([0-9a-f]+)_([0-9a-f]+)$", os.path.basename(os.path.normpath(package)))
    base_commit = arguments.base or (name_match.group(1) if name_match else "")
    if not base_commit:
        print("今の版が分かりません（--base で指定してください）")
        return 2
    site_list = [name for name in os.listdir(package) if os.path.isdir(os.path.join(package, name))]
    if len(site_list) != 1:
        print("一式の中にサイトのフォルダーが 1 つではありません: %s" % site_list)
        return 2
    site = site_list[0]
    php_path = arguments.php or shutil.which("php")
    if not php_path:
        print("PHP が見つかりません（--php で指定してください）")
        return 2
    has_notice = os.path.exists(os.path.join(package, "お知らせ.txt"))
    work = os.path.realpath(tempfile.mkdtemp(prefix="mr_rehearsal_"))
    base_tree = os.path.join(work, "base_tree")
    rehearsal = Rehearsal(php_path, work)
    print("リハーサル: %s（今の版 %s → 一式 %s）\n作業フォルダー: %s" % (site, base_commit, os.path.basename(package), work), flush=True)
    worktree_added = False
    servers = []
    try:
        subprocess.run(["git", "-C", ROOT, "worktree", "add", "--detach", base_tree, base_commit], check=True, stdout=subprocess.DEVNULL)
        worktree_added = True
        for name in ("data", "keys", "logs"):
            os.makedirs(os.path.join(base_tree, "app", name), exist_ok=True)
        with open(os.path.join(base_tree, "app", "config", "config.local.php"), "w", encoding="utf-8") as config_file:
            config_file.write("<?php\nreturn ['base_path' => '', 'https' => false, 'allow_dev_seed' => true, 'dev_skip_2fa' => false];\n")
        source_db = os.path.join(work, "source.sqlite")
        print("== 1. 今の版（%s）で、データを入れる" % base_commit, flush=True)
        populate(rehearsal, base_tree, source_db)
        users = json.load(open(os.path.join(base_tree, "tools", "test", "output_dev_users.json"), encoding="utf-8"))["users"]

        # ---- 2. サイトの配置（~/www/<site>/）を今の版で作る ----
        site_root = os.path.join(work, "www", site)
        update_root = os.path.join(work, "update")   # ~/onion_update/ に当たる
        copy_tree(os.path.join(base_tree, "public"), site_root)
        copy_tree(os.path.join(base_tree, "app"), os.path.join(site_root, "app"))
        for name in ("data", "logs"):
            os.makedirs(os.path.join(site_root, "app", name), exist_ok=True)
        shutil.copytree(os.path.join(base_tree, "app", "keys"), os.path.join(site_root, "app", "keys"))
        with open(os.path.join(site_root, "app", "config", "config.local.php"), "w", encoding="utf-8") as config_file:
            config_file.write("<?php\nreturn ['base_path' => '', 'https' => false, 'allow_dev_seed' => false, 'dev_skip_2fa' => false, 'db_path' => dirname(__DIR__) . '/data/reports.sqlite'];\n")
        db = os.path.join(site_root, "app", "data", "reports.sqlite")
        shutil.copy2(source_db, db)
        key_bytes_before = {name: open(os.path.join(site_root, "app", "keys", name), "rb").read() for name in sorted(os.listdir(os.path.join(site_root, "app", "keys")))}

        print("== 2. 今の版で、ログインと画面を確かめる（あとで比べる）", flush=True)
        server = Server(php_path, site_root, environment_without_verify()); servers.append(server)
        old_login_ok = try_logins(server.base, users)
        readability_before = page_readability(server.base, users, old_login_ok, db)
        server.stop()
        print("   今の版の画面: %s" % readability_before)
        check("2: 今の版で、テストの利用者がログインできる（%d / %d 人）" % (len(old_login_ok), len(users)), len(old_login_ok) >= max(2, len(users) // 2), old_login_ok)
        user_before = user_snapshot(db)
        user_columns = {table: user_before[table][0] for table in USER_TABLES}

        print("== 3. 準備と控え", flush=True)
        copy_tree(os.path.join(package, site), os.path.join(update_root, site), skip=())
        for name in ("migration_check.php", "お知らせ.txt"):
            if os.path.exists(os.path.join(package, name)):
                shutil.copy2(os.path.join(package, name), update_root)
        check("3: 一式に config*.php が無い", not glob.glob(os.path.join(update_root, site, "**", "config*.php"), recursive=True))
        check("3: 一式に migration_check.php がある", os.path.exists(os.path.join(update_root, "migration_check.php")))
        program_backup = os.path.join(update_root, "program_before")
        copy_tree(site_root, program_backup)
        shutil.copy2(db, os.path.join(update_root, "before.sqlite"))

        print("== 4. リハーサル（写しで移行する）", flush=True)
        stage = os.path.join(update_root, "stage")
        copy_tree(os.path.join(site_root, "app"), os.path.join(stage, "app"))
        copy_tree(os.path.join(update_root, site, "app"), os.path.join(stage, "app"), skip=())
        shutil.copytree(os.path.join(site_root, "app", "keys"), os.path.join(stage, "app", "keys"))
        rehearsal_db = os.path.join(update_root, "rehearsal.sqlite")
        shutil.copy2(os.path.join(update_root, "before.sqlite"), rehearsal_db)
        migration_check = os.path.join(update_root, "migration_check.php")
        code, out = rehearsal.php(migration_check, "snapshot", rehearsal_db, os.path.join(update_root, "rehearsal_before.json"))
        check("4: snapshot が「整合の確かめ: OK」", code == 0 and "整合の確かめ: OK" in out, out[-300:])
        code, out = rehearsal.php(os.path.join(stage, "app", "bin", "migrate.php"), "--db=" + rehearsal_db)
        print("   " + out.strip().replace("\n", "\n   "))
        check("4: migrate.php（写し）がエラーなく終わる", code == 0 and "テーブルを確認しました" in out, out[-300:])
        check("4: 写しのプログラムの鍵が増えていない", sorted(os.listdir(os.path.join(stage, "app", "keys"))) == sorted(key_bytes_before))
        code, out = rehearsal.php(migration_check, "compare", rehearsal_db, os.path.join(update_root, "rehearsal_before.json"))
        check("4: compare が「判定: OK」（もとの表の中身が変わらない）", code == 0 and "判定: OK" in out, out[-300:])
        compare_snapshots("4: 写しの移行のあと", user_before, user_snapshot(rehearsal_db, user_columns))
        if has_notice:
            code, out = rehearsal.php(os.path.join(stage, "app", "bin", "post_notice.php"), "--file=" + os.path.join(update_root, "お知らせ.txt"), "--db=" + rehearsal_db, "--dry-run")
            check("4: post_notice.php --dry-run が本文（署名つき）を出して書き込まない",
                  code == 0 and "確かめだけです" in out and SIGNATURE_TEXT in out
                  and table_rows(rehearsal_db, "update_notices")[1] == table_rows(os.path.join(update_root, "before.sqlite"), "update_notices")[1], out[-300:])

        print("== 5. 本番の位置の DB を移行する", flush=True)
        rehearsal.php(migration_check, "snapshot", db, os.path.join(update_root, "final_before.json"))
        code, out = rehearsal.php(os.path.join(stage, "app", "bin", "migrate.php"), "--db=" + db)
        check("5: migrate.php がエラーなく終わる", code == 0 and "テーブルを確認しました" in out, out[-300:])
        code, out = rehearsal.php(migration_check, "compare", db, os.path.join(update_root, "final_before.json"))
        check("5: compare が「判定: OK」", code == 0 and "判定: OK" in out, out[-300:])

        print("== 6. プログラムを上げる", flush=True)
        copy_tree(os.path.join(update_root, site), site_root, skip=())
        package_files = [path for path in glob.glob(os.path.join(update_root, site, "**", "*"), recursive=True) if os.path.isfile(path)]
        differing = [path for path in package_files if open(path, "rb").read() != open(os.path.join(site_root, os.path.relpath(path, os.path.join(update_root, site))), "rb").read()]
        check("6: 上げたファイル（%d）がすべて一式と同じ" % len(package_files), not differing, differing[:3])
        code, out = rehearsal.php(os.path.join(site_root, "app", "bin", "migrate.php"), cwd=site_root)
        check("6: 2 回目の migrate.php は何も足さない", code == 0 and "を追加" not in out and "作り直" not in out and "テーブルを確認しました" in out, out[-300:])
        check("6: 暗号の鍵の名前と中身が前と同じ",
              {name: open(os.path.join(site_root, "app", "keys", name), "rb").read() for name in sorted(os.listdir(os.path.join(site_root, "app", "keys")))} == key_bytes_before)
        compare_snapshots("6: 移行とプログラムを上げたあと", user_before, user_snapshot(db, user_columns))

        print("== 7. 新しい版で確かめる", flush=True)
        server = Server(php_path, site_root, environment_without_verify()); servers.append(server)
        new_login_ok = try_logins(server.base, users)
        check("7: 今の版でログインできた人が全員、同じパスワードと 2 段階認証でログインできる（%d 人）" % len(new_login_ok), new_login_ok == old_login_ok, (old_login_ok, new_login_ok))
        connection = sqlite3.connect(db)
        code_user_list = [row[0] for row in connection.execute(
            "SELECT DISTINCT u.login_id FROM users u JOIN user_backup_codes c ON c.user_id = u.id WHERE c.used_at IS NULL AND u.is_active = 1 ORDER BY u.id")]
        connection.close()
        backup_login_id = next((login_id for login_id in code_user_list if login_id in old_login_ok and users.get(login_id, {}).get("backup_code")), None)
        if backup_login_id:
            info = users[backup_login_id]
            for attempt in (1, 2):
                client = lib_http.Client(server.base)
                _status, _location, body = client.get("/login.php")
                client.post("/login.php", {"csrf_token": lib_http.csrf(body), "action": "password", "login_id": backup_login_id, "password": info["password"]})
                _status, _location, body = client.get("/login.php?step=2")
                _status, location, _body = client.post("/login.php", {"csrf_token": lib_http.csrf(body), "action": "code", "code": info["backup_code"], "remember": "0"})
                check("7: 今の版で作った予備のコードでログインできる（%s・%d 回目）" % (backup_login_id, attempt), "/index.php" in (location or ""), location)
        else:
            check("7: 予備のコードを確かめる人がいる（使っていない予備のコードを持ち、ログインできる人）", False)
        readability_after = page_readability(server.base, users, old_login_ok, db)
        print("   新しい版の画面: %s" % readability_after)
        page_keys = [key for key in readability_before if key not in ("組織に出る有効な人", "有効な人")]
        check("7: 報告・目標管理・精算書の画面（%d）が開け、「%s」の数が今の版と同じ（暗号化した値が同じように読める）" % (len(page_keys), UNREADABLE_TEXT),
              all(readability_after.get(key) == readability_before[key] for key in page_keys) and all(readability_after[key][0] == 200 for key in page_keys), readability_after)
        check("7: 組織・ユーザーに、有効な人が全員出る（%d 人。今の版と同じ）" % len(readability_after["有効な人"]),
              readability_after["組織に出る有効な人"] == readability_after["有効な人"] == readability_before["有効な人"], readability_after["組織に出る有効な人"])
        admin_login = next(login_id for login_id in old_login_ok if login_id.startswith("admin"))
        admin = lib_http.login(server.base, admin_login, users[admin_login])
        connection = sqlite3.connect(db)
        request_ids = [row[0] for row in connection.execute("SELECT id FROM support_requests")]
        faq_before = connection.execute("SELECT COUNT(*) FROM faq_entries").fetchone()[0]
        connection.close()
        bad_requests = [request_id for request_id in request_ids if admin.get("/requests/view.php?id=%d" % request_id)[0] != 200]
        check("7: 要望・依頼（%d 件）を管理者が開ける" % len(request_ids), not bad_requests, bad_requests)
        _status, _location, faq_html = admin.get("/faq.php")
        import_button = re.search(r"標準のよくある質問を取り込む（[^）]*）", faq_html)
        if import_button:
            print("   " + import_button.group(0))
            form_match = re.search(r'<form[^>]*>(?:(?!</form>).)*?標準のよくある質問を取り込む.*?</form>', faq_html, re.S)
            form_values = dict(re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', form_match.group(0))) if form_match else {}
            form_values["action"] = "import_standard"
            admin.post("/faq.php", form_values)
            connection = sqlite3.connect(db)
            faq_after = connection.execute("SELECT COUNT(*) FROM faq_entries").fetchone()[0]
            duplicated = connection.execute("SELECT COUNT(*) FROM (SELECT question FROM faq_entries GROUP BY question HAVING COUNT(*) > 1)").fetchone()[0]
            connection.close()
            _status, _location, faq_html = admin.get("/faq.php")
            check("7: 標準のよくある質問を取り込める（%d → %d 件）。同じ質問が二重にならず、取り込むものが残らない" % (faq_before, faq_after),
                  faq_after >= faq_before and duplicated == 0 and not re.search(r"標準のよくある質問を取り込む（まだ載っていない [1-9]", faq_html), duplicated)
        else:
            print("   標準のよくある質問の取り込むボタンは出ていない（取り込むものが無い）")
        if has_notice:
            notice_path = os.path.join(update_root, "お知らせ.txt")
            code, out = rehearsal.php(os.path.join(site_root, "app", "bin", "post_notice.php"), "--file=" + notice_path, cwd=site_root)
            check("7: post_notice.php でお知らせを出せる", code == 0 and "お知らせを出しました" in out, out[-300:])
            code, out = rehearsal.php(os.path.join(site_root, "app", "bin", "post_notice.php"), "--file=" + notice_path, cwd=site_root)
            check("7: もう一度流しても二重に出ない", code == 0 and "同じお知らせがあります" in out, out[-300:])
            notice_title, indented_lines = notice_expectations(notice_path)
            member_login = next((login_id for login_id in old_login_ok if not login_id.startswith(("admin", "hosa"))), admin_login)
            _status, _location, home_html = lib_http.login(server.base, member_login, users[member_login]).get("/index.php")
            notice_bodies = re.findall(r'<div class="update-notice-body">(.*?)</div>', home_html, re.S)
            notice_body = next((body for body in notice_bodies if body.rstrip().endswith(SIGNATURE_TEXT)), None)
            check("7: ホームにお知らせ（%s）が出て、最後に署名があり、字下げの行（%d 行）が書いたとおりに出る" % (notice_title, len(indented_lines)),
                  html.escape(notice_title) in home_html and notice_body is not None and all(html.escape(line) in notice_body for line in indented_lines),
                  (notice_body or "")[:200])
        server.stop()
        compare_snapshots("7: ログイン・取り込み・お知らせのあと（ログインで変わる列を除く）",
                          user_snapshot(source_db, user_columns, skip_volatile=True), user_snapshot(db, user_columns, skip_volatile=True))

        print("== 9. プログラムだけ今の版に戻す", flush=True)
        copy_tree(program_backup, site_root)
        server = Server(php_path, site_root, environment_without_verify()); servers.append(server)
        rollback_login_ok = try_logins(server.base, users)
        check("9: 今の版に戻しても、同じ人がログインできる", rollback_login_ok == old_login_ok, rollback_login_ok)
        readability_rollback = page_readability(server.base, users, old_login_ok, db)
        check("9: 今の版に戻しても、画面が開け、「%s」の数が移行の前と同じ" % UNREADABLE_TEXT,
              all(readability_rollback.get(key) == value for key, value in readability_before.items()), readability_rollback)
        server.stop()
        compare_snapshots("9: 今の版に戻したあと（ログインで変わる列を除く）",
                          user_snapshot(source_db, user_columns, skip_volatile=True), user_snapshot(db, user_columns, skip_volatile=True))
    finally:
        for server in servers:
            server.stop()
        if worktree_added and not arguments.keep:
            subprocess.run(["git", "-C", ROOT, "worktree", "remove", "--force", base_tree], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if not arguments.keep:
            shutil.rmtree(work, ignore_errors=True)
            subprocess.run(["git", "-C", ROOT, "worktree", "prune"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        else:
            print("作業フォルダーを残しました: %s（今の版の作業ツリーも。消すときは git worktree remove --force %s）" % (work, base_tree))
    ng_count = sum(1 for _name, ok in RESULTS if not ok)
    print("\n結果: OK %d / NG %d" % (len(RESULTS) - ng_count, ng_count))
    return 1 if ng_count else 0


if __name__ == "__main__":
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(errors="replace")
    sys.exit(main())
