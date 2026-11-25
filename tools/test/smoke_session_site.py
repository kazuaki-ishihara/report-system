#!/usr/bin/env python3
"""同じサーバーの別のサイトのログイン（セッション）を流用できないこと（APP-123。ローカル検証環境専用）。

写しのサイトを 2 つ作り（B は A の DB・鍵までそのままの写し。違うのは置き場所だけ）、PHP の既定の置き場所
（session.save_path）を共通にして立てる。ふだんの DB や設定には触れない。

- 既定の設定: セッションはサイトごとの app/data/sessions に置き、A の値は B で通らない
- 置き場所も共通にしたとき: サイトの印だけで断る（ログイン済み・2 段階認証のコード待ち・設定待ちのどれも）。A のログインは切らない
- 置き場所を作れないとき: PHP の既定の場所で動き、WARN を残し、A の値は B で通らない

    python3 tools/test/smoke_session_site.py --db app/data/verify.sqlite --php <php>
"""
import argparse
import glob
import http.client
import json
import os
import re
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import verify_all  # noqa: E402
from lib_http import Client, check, csrf, login, section, summary, totp  # noqa: E402

ROOT = verify_all.ROOT
SKIP_NAMES = shutil.ignore_patterns("data", "logs", "backups", "keys", "output", "deploy", "__pycache__", "config.local.php")


def write_config(site_dir, session_dir=None):
    setting_list = ["'base_path' => ''", "'https' => false", "'allow_dev_seed' => true", "'dev_skip_2fa' => false",
                    "'db_path' => dirname(__DIR__) . '/data/dev.sqlite'"]
    if session_dir is not None:
        setting_list.append("'session_dir' => '%s'" % session_dir.replace(os.sep, "/"))
    with open(os.path.join(site_dir, "app", "config", "config.local.php"), "w", encoding="utf-8") as handle:
        handle.write("<?php return [%s];\n" % ", ".join(setting_list))


def cookie_of(client):
    return [cookie.value for cookie in client.jar if cookie.name == "mr_session"][-1]


def request_with_cookie(port, method, path, session_id, body=None):
    """Cookie の値を手で送る（別のサイトの値を流用する人の動き）"""
    connection = http.client.HTTPConnection("127.0.0.1", port, timeout=20)
    headers = {"User-Agent": "test", "Cookie": "mr_session=" + session_id}
    if body is not None:
        headers["Content-Type"] = "application/x-www-form-urlencoded"
    connection.request(method, path, body=body, headers=headers)
    response = connection.getresponse()
    text = response.read().decode("utf-8", "replace")
    result = (response.status, response.getheader("Location", "") or "", text, response.getheader("Set-Cookie", "") or "")
    connection.close()
    return result


def main():
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--base", default="", help="使わない（自分でサーバーを立てる）")
    argument_parser.add_argument("--db", default="", help="使わない（写しの DB を使う）")
    argument_parser.add_argument("--php", required=True)
    arguments = argument_parser.parse_args()
    environment = {name: value for name, value in os.environ.items() if name != "MR_VERIFY_DB"}
    work_dir = os.path.realpath(tempfile.mkdtemp(prefix="mr_session_site_"))
    ini_dir = os.path.join(work_dir, "ini_sessions")
    os.makedirs(ini_dir)
    site_dirs = {name: os.path.join(work_dir, "site" + name) for name in ("A", "B")}
    ports = {}

    def prepare_sites():
        for folder in ("app", "public", "tools"):
            shutil.copytree(os.path.join(ROOT, folder), os.path.join(site_dirs["A"], folder), ignore=SKIP_NAMES)
        os.makedirs(os.path.join(site_dirs["A"], "app", "data"))
        write_config(site_dirs["A"])
        subprocess.run([arguments.php, os.path.join("tools", "test", "dev_seed.php"), "--yes"], cwd=site_dirs["A"], env=environment, check=True, stdout=subprocess.DEVNULL)
        # 2 段階認証が未設定の人（設定待ちの段階を試す）
        database = sqlite3.connect(os.path.join(site_dirs["A"], "app", "data", "dev.sqlite"))
        database.execute("UPDATE users SET totp_secret_enc = NULL, totp_enabled_at = NULL WHERE login_id = 'ippan_b1'")
        database.commit()
        database.execute("PRAGMA wal_checkpoint(TRUNCATE)")
        database.close()
        shutil.copytree(site_dirs["A"], site_dirs["B"])
        with open(os.path.join(site_dirs["A"], "tools", "test", "output_dev_users.json"), encoding="utf-8") as handle:
            return json.load(handle)["users"]

    def run_case(title, action):
        processes = []
        try:
            for name in ("A", "B"):
                ports[name] = verify_all.free_port()
                processes.append(subprocess.Popen([arguments.php, "-d", "opcache.enable=0", "-d", "session.save_path=" + ini_dir,
                                                   "-S", "127.0.0.1:%d" % ports[name], "-t", "public"],
                                                  cwd=site_dirs[name], env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
                check(title + ": サイト %s が立つ" % name, verify_all.wait_server("http://127.0.0.1:%d" % ports[name]))
            action(title, "http://127.0.0.1:%d" % ports["A"])
        finally:
            for process in processes:
                process.terminate()
                process.wait(timeout=10)

    def refused(title, path, session_id):
        status, location, body, set_cookie = request_with_cookie(ports["B"], "GET", path, session_id)
        check(title, status == 303 and "/login.php" in location and "さん" not in body, (status, location))
        return set_cookie

    def logged_in(site_name, session_id):
        status, _, body, _ = request_with_cookie(ports[site_name], "GET", "/index.php", session_id)
        return status == 200 and "さん" in body

    def full_login_case(title, base, login_id):
        session_id = cookie_of(login(base, login_id, users[login_id]))
        check(title + ": A では開ける（%s）" % login_id, logged_in("A", session_id))
        for path in ("/index.php", "/account.php", "/admin/index.php"):
            refused(title + ": A のログインの値は B で通らない（%s %s）" % (login_id, path), path, session_id)
        status, _, _, _ = request_with_cookie(ports["B"], "GET", "/session_check.php?format=json", session_id)
        check(title + ": session_check.php も 401（%s）" % login_id, status == 401, status)
        check(title + ": B に送っても A のログインは切れない（%s）" % login_id, logged_in("A", session_id))
        return session_id

    def password_step(base, login_id):
        client = Client(base)
        _, _, body = client.get("/login.php")
        _, location, _ = client.post("/login.php", {"csrf_token": csrf(body), "action": "password", "login_id": login_id, "password": users[login_id]["password"]})
        return client, location

    def default_case(title, base):
        session_id = full_login_case(title, base, "admin01")
        check(title + ": A のセッションのファイルは A の app/data/sessions にある", os.path.isfile(os.path.join(site_dirs["A"], "app", "data", "sessions", "sess_" + session_id)))
        check(title + ": PHP の既定の置き場所には置かない", glob.glob(os.path.join(ini_dir, "sess_*")) == [])
        check(title + ": B の app/data/sessions に A の値のファイルはできない", not os.path.exists(os.path.join(site_dirs["B"], "app", "data", "sessions", "sess_" + session_id)))

    def shared_case(title, base):
        session_id = full_login_case(title, base, "kacho_a")
        file_path = os.path.join(shared_dir, "sess_" + session_id)
        check(title + ": 同じ置き場所を使っている（試す条件）", os.path.isfile(file_path))
        set_cookie = refused(title + ": B は断り、新しい値を渡す", "/index.php", session_id)
        new_id_match = re.search(r"mr_session=([^;]+)", set_cookie)
        check(title + ": 渡す値は A の値と違う", new_id_match is not None and new_id_match.group(1) != session_id, set_cookie)
        # サイトの印がないもの（直す前にできたセッション）も断る
        with open(file_path, "rb") as handle:
            content = handle.read()
        without_stamp, count = re.subn(rb'site\|s:64:"[0-9a-f]{64}";', b"", content)
        check(title + ": 印がファイルに 1 つある", count == 1, count)
        with open(file_path, "wb") as handle:
            handle.write(without_stamp)
        check(title + ": 印のないセッションは A でも通らない", not logged_in("A", session_id))
        with open(file_path, "wb") as handle:
            handle.write(content)
        check(title + ": 印を戻すと A で開ける", logged_in("A", session_id))
        # 2 段階認証のコード待ち: A でパスワードだけ済ませ、正しいコードを B・A に送る
        for site_name in ("B", "A"):
            client, location = password_step(base, "dairi_a")
            check(title + ": 準備 コード待ち（%s に送る）" % site_name, "step=2" in location, location)
            token = csrf(client.get("/login.php?step=2")[2])
            while time.time() % 30 > 27:
                time.sleep(0.5)
            status, location, _, _ = request_with_cookie(ports[site_name], "POST", "/login.php", cookie_of(client),
                                                         "csrf_token=%s&action=code&code=%s&remember=0" % (token, totp(users["dairi_a"]["totp"])))
            if site_name == "A":
                check(title + ": コード待ちの値に正しいコード → A は入れる", status == 303 and "/index.php" in location, (status, location))
            else:
                check(title + ": コード待ちの値に正しいコード → B は 403", status == 403, (status, location))
        # 2 段階認証の設定待ち
        client, location = password_step(base, "ippan_b1")
        check(title + ": 準備 設定待ち", "tab=totp" in location, location)
        check(title + ": 設定待ちの値は A では設定の画面が開く", request_with_cookie(ports["A"], "GET", "/account.php?tab=totp", cookie_of(client))[0] == 200)
        refused(title + ": 設定待ちの値は B で通らない", "/account.php?tab=totp", cookie_of(client))

    def fallback_case(title, base):
        session_id = full_login_case(title, base, "leader_a")
        check(title + ": PHP の既定の置き場所で動く", os.path.isfile(os.path.join(ini_dir, "sess_" + session_id)))
        for name in ("A", "B"):
            log_path = os.path.join(site_dirs[name], "app", "logs", "app.log")
            log_text = ""
            if os.path.isfile(log_path):
                with open(log_path, encoding="utf-8") as handle:
                    log_text = handle.read()
            check(title + ": WARN が app.log に残る（%s）" % name, "WARN セッションの置き場所が使えません" in log_text)

    def empty_case(title, base):
        session_id = full_login_case(title, base, "kacho_b")
        check(title + ": PHP の既定の置き場所で動く", os.path.isfile(os.path.join(ini_dir, "sess_" + session_id)))

    def cleanup_case(title, base):
        """古いセッションのファイルの掃除（100 回に 1 回ほど動くので、動くまで画面を開く）"""
        session_dir = os.path.join(site_dirs["A"], "app", "data", "sessions")
        paths = {}
        for name, hours in (("old", 12.1), ("kept", 11.9)):
            paths[name] = os.path.join(session_dir, "sess_" + name * 4)
            open(paths[name], "w").close()
            past_time = time.time() - hours * 3600
            os.utime(paths[name], (past_time, past_time))
        for _ in range(2000):
            request_with_cookie(ports["A"], "GET", "/session_check.php?format=json", "none")
            if not os.path.exists(paths["old"]):
                break
        check(title + ": 12 時間を過ぎたファイルは消える", not os.path.exists(paths["old"]))
        check(title + ": 12 時間以内のファイルは残る", os.path.exists(paths["kept"]))

    def no_key_case(title, base):
        status, _, body = Client(base).get("/login.php")
        check(title + ": ログイン画面は開く", status == 200 and csrf(body) != "", status)

    section("G-01", "別のサイトのログインの流用（APP-123）")
    shared_dir = os.path.join(work_dir, "shared_sessions")
    try:
        users = prepare_sites()
        run_case("既定の設定", default_case)
        for name in ("A", "B"):
            write_config(site_dirs[name], shared_dir)
        run_case("置き場所が共通", shared_case)
        blocked_file = os.path.join(work_dir, "blocked")
        open(blocked_file, "w").close()
        for name in ("A", "B"):
            write_config(site_dirs[name], os.path.join(blocked_file, "sessions"))
        run_case("置き場所を作れない", fallback_case)
        for name in ("A", "B"):
            write_config(site_dirs[name], "")
        run_case("置き場所の設定が空", empty_case)
        for name in ("A", "B"):
            write_config(site_dirs[name])
        run_case("掃除", cleanup_case)
        shutil.rmtree(os.path.join(site_dirs["A"], "app", "keys"))
        run_case("鍵を作る前", no_key_case)
    finally:
        shutil.rmtree(work_dir, ignore_errors=True)
    return summary()


if __name__ == "__main__":
    sys.exit(main())
