#!/usr/bin/env python3
"""予備のコードの発行（G-14。補佐（開発担当）だけ）を、ほかの人が使えないことの確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

課長・部下・システム管理者・ふつうの補佐・前に補佐（開発担当）だった人・ログインしていない人が送っても断り、
対象の人の 2 段階認証・予備のコード・知らせ・記録が変わらないことを確かめる。
最後に、同じ人を補佐（開発担当）にすると使えることを確かめる（確かめの作りが合っていることの確かめ）。

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_codes_issue.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import json
import os
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import Client, check, csrf, login, section, summary  # noqa: E402

TARGET = "ippan_b1"


def main():
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--base", default="http://127.0.0.1:8000")
    argument_parser.add_argument("--db", required=True)
    arguments = argument_parser.parse_args()
    test_dir = os.path.dirname(os.path.abspath(__file__))
    users = json.load(open(os.path.join(test_dir, "output_dev_users.json"), encoding="utf-8"))["users"]
    database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)

    def uid(login_id):
        return users[login_id]["id"]

    def target_state():
        """対象の人の 2 段階認証・予備のコード・知らせ・記録（変わっていないかを比べる）"""
        return (database.execute("SELECT totp_enabled_at, totp_secret_enc FROM users WHERE id = ?", (uid(TARGET),)).fetchone(),
                database.execute("SELECT COUNT(*) FROM user_backup_codes WHERE user_id = ?", (uid(TARGET),)).fetchone()[0],
                database.execute("SELECT COUNT(*) FROM security_notices WHERE user_id = ?", (uid(TARGET),)).fetchone()[0],
                database.execute("SELECT COUNT(*) FROM access_logs WHERE target LIKE ?", ("user:%d:codes_issue%%" % uid(TARGET),)).fetchone()[0])

    def send(client, login_id_text):
        """対象の人のユーザーの編集へ、予備のコードの発行を送る。(状態, 行き先, 送る前の画面)"""
        edit_path = "/admin/user_edit.php?id=%d" % uid(TARGET)
        _status_code, _location, page_html = client.get(edit_path)
        token = csrf(page_html)
        if not token:
            _status_code, _location, home_html = client.get("/index.php")
            token = csrf(home_html)
        status_code, location, _body = client.post(edit_path, {"csrf_token": token, "request_key": os.urandom(16).hex(), "action": "codes_issue",
                                                               "reason": "確かめ", "operator_password": users[login_id_text]["password"]})
        return status_code, location, page_html

    # 対象の人を、2 段階認証が未設定の状態にする
    database.execute("UPDATE users SET totp_secret_enc = NULL, totp_enabled_at = NULL, totp_last_step = NULL WHERE id = ?", (uid(TARGET),))
    database.execute("DELETE FROM user_backup_codes WHERE user_id = ?", (uid(TARGET),))
    # 前に補佐（開発担当）だった人（今はふつうの利用者）
    database.execute("UPDATE users SET was_admin_deputy_lead = 1 WHERE id = ?", (uid("leader_a"),))
    database.execute("UPDATE users SET is_admin_deputy = 1, is_admin_deputy_lead = 0 WHERE id = ?", (uid("hosa01"),))
    state_before = target_state()

    section("G-14", "予備のコードの発行を、補佐（開発担当）のほかは使えない")
    for login_id, role_name in [("kacho_a", "課長"), ("ippan_a1", "部下"), ("leader_a", "前に補佐（開発担当）だった人"),
                                ("admin01", "システム管理者"), ("hosa01", "ふつうの補佐")]:
        client = login(arguments.base, login_id, users[login_id])
        status_code, location, page_html = send(client, login_id)
        check("%sは使えない（403）" % role_name, status_code == 403 and target_state() == state_before, (status_code, location))
        check("%sの画面には、予備のコードの発行の欄を出さない" % role_name, 'value="codes_issue"' not in page_html)
    anonymous_client = Client(arguments.base)
    _status_code, _location, login_html = anonymous_client.get("/login.php")
    status_code, location, _body = anonymous_client.post("/admin/user_edit.php?id=%d" % uid(TARGET), {"csrf_token": csrf(login_html), "action": "codes_issue",
                                                                                                    "reason": "確かめ", "operator_password": "x"})
    check("ログインしていない人は使えない（ログインの画面へ）", status_code in (302, 303) and "/login.php" in (location or "") and target_state() == state_before, (status_code, location))

    # 確かめの作りが合っていること: 同じ人（hosa01）を補佐（開発担当）にすると使える
    database.execute("UPDATE users SET is_admin_deputy_lead = 1 WHERE id = ?", (uid("hosa01"),))
    lead_client = login(arguments.base, "hosa01", users["hosa01"])
    status_code, location, page_html = send(lead_client, "hosa01")
    check("補佐（開発担当）にすると、欄が出て使える（確かめの作りの確かめ）",
          'value="codes_issue"' in page_html and status_code in (302, 303) and target_state()[1] == 8 and target_state()[2] == state_before[2] + 1, (status_code, target_state()))
    return summary()


if __name__ == "__main__":
    sys.exit(main())
