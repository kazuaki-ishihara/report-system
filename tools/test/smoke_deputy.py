#!/usr/bin/env python3
"""システム管理者補佐の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。K-043

- 補佐は管理の仕事（組織・ユーザー・案件・項目・メッセージ）を代行できる
- 補佐は個々の情報（人ごとの提出状況・閲覧と出力の記録・削除データの中身・報告の中身）を見られない
- 補佐は管理者・補佐の利用者と役を変えられない（画面・CSV）
- 補佐の操作は「補佐として」記録し、管理者の管理のトップに出す
- 補佐は最大 3 名、管理者と兼ねない（画面と DB）

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_deputy.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import datetime
import json
import os
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, csrf, login, summary  # noqa: E402


def main():
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--base", default="http://127.0.0.1:8000")
    argument_parser.add_argument("--db", required=True)
    arguments = argument_parser.parse_args()
    test_dir = os.path.dirname(os.path.abspath(__file__))
    seed_info = json.load(open(os.path.join(test_dir, "output_dev_users.json"), encoding="utf-8"))
    user_info_by_login_id = seed_info["users"]
    department_a_id = seed_info["dept_a"]
    database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)
    previous_month = (datetime.date.today().replace(day=1) - datetime.timedelta(days=1)).strftime("%Y-%m")

    def user_id_of(login_id):
        return user_info_by_login_id[login_id]["id"]

    def post_form(client, path, form_values):
        _status_code, _location, page_html = client.get(path)
        form_values = dict(form_values, csrf_token=csrf(page_html), request_key=os.urandom(16).hex())
        return client.post(path, form_values)

    def save_user(client, login_id, extra_form_values):
        user_row = database.execute("SELECT display_name, role_title, department_id FROM users WHERE id = ?", (user_id_of(login_id),)).fetchone()
        form_values = {"action": "save", "display_name": user_row[0], "role_title": user_row[1],
                       "department_id": str(user_row[2] or ""), "team_id": "", "is_active": "1"}
        form_values.update(extra_form_values)
        return post_form(client, "/admin/user_edit.php?id=%d" % user_id_of(login_id), form_values)

    deputy_client = login(arguments.base, "hosa01", user_info_by_login_id["hosa01"])
    admin_client = login(arguments.base, "admin01", user_info_by_login_id["admin01"])

    # ---- 代行できる管理の画面 ----
    for path in ["/admin/index.php", "/admin/org.php?tab=departments", "/admin/org.php?tab=users", "/admin/projects.php",
                 "/admin/fields.php", "/admin/messages.php", "/admin/logs.php", "/admin/trash.php",
                 "/admin/user_edit.php?id=%d" % user_id_of("ippan_a1")]:
        status_code, _location, _page_html = deputy_client.get(path)
        check("補佐が開ける: " + path, status_code == 200, status_code)
    _status_code, _location, menu_html = deputy_client.get("/index.php")
    check("補佐のメニューに管理の画面（組織・ユーザー、案件、記録、メッセージ）",
          all(label in menu_html for label in ["組織・ユーザー", "案件の管理", "記録・削除データ", "メッセージ"]))

    # ---- 個々の情報は見られない ----
    report_id = database.execute(
        "INSERT INTO report_records (user_id, department_id, period, status, revision, submitted_at, created_at, updated_at)"
        " VALUES (?, ?, ?, 'submitted', 0, datetime('now'), datetime('now'), datetime('now'))",
        (user_id_of("ippan_a1"), department_a_id, previous_month)).lastrowid
    status_code, _location, _page_html = deputy_client.get("/reports/view.php?id=%d" % report_id)
    check("補佐はほかの課の報告の中身を見られない", status_code in (403, 404), status_code)
    status_code, _location, status_html = deputy_client.get("/status/index.php?period=%s&dept=%d" % (previous_month, department_a_id))
    check("補佐は提出状況を開ける（課ごとの件数）", status_code == 200 and "提出 " in status_html, status_code)
    check("補佐の提出状況には人ごとの行（名前）を出さない", "A一般1" not in status_html and "課ごとの件数だけ" in status_html)
    _status_code, _location, admin_status_html = admin_client.get("/status/index.php?period=%s&dept=%d" % (previous_month, department_a_id))
    check("管理者の提出状況には人ごとの行が出る（今のまま）", "A一般1" in admin_status_html)
    status_code, _location, _page_html = deputy_client.get("/status/compare.php?period=%s&dept=%d" % (previous_month, department_a_id))
    check("補佐は横並びを開けない", status_code == 403, status_code)

    database.execute("INSERT INTO access_logs (user_id, action, target, ip_address, created_at) VALUES (?, 'view', ?, '127.0.0.1', datetime('now','localtime'))",
                     (user_id_of("kacho_a"), "report:%d" % report_id))
    status_code, _location, logs_html = deputy_client.get("/admin/logs.php")
    check("補佐の記録には閲覧の記録を出さない", status_code == 200 and "<td>report:%d</td>" % report_id not in logs_html)
    status_code, _location, _page_html = deputy_client.get("/admin/logs.php?kind=view")
    check("補佐は閲覧の記録を選べない", status_code == 403, status_code)
    status_code, _location, logs_csv_text = deputy_client.get("/admin/logs.php?csv=1")
    check("補佐の記録の CSV にも閲覧の記録を出さない", status_code == 200 and "report:%d" % report_id not in logs_csv_text, status_code)
    _status_code, _location, admin_logs_html = admin_client.get("/admin/logs.php")
    check("管理者の記録には閲覧の記録が出る", "<td>report:%d</td>" % report_id in admin_logs_html)

    database.execute("UPDATE report_records SET deleted_at = datetime('now','localtime') WHERE id = ?", (report_id,))
    _status_code, _location, trash_html = deputy_client.get("/admin/trash.php")
    check("補佐の削除データは件数だけ（だれの報告かを出さない）", "A一般1" not in trash_html and "種類ごとの件数" in trash_html)
    status_code, _location, _page_html = post_form(deputy_client, "/admin/trash.php", {"action": "restore_report", "id": str(report_id)})
    check("補佐は削除データを戻せない", status_code == 403, status_code)
    _status_code, _location, admin_trash_html = admin_client.get("/admin/trash.php")
    check("管理者の削除データには中身の一覧が出る（今のまま）", "A一般1" in admin_trash_html)

    # ---- 管理者・補佐の利用者と役は変えられない ----
    status_code, _location, _page_html = deputy_client.get("/admin/user_edit.php?id=%d" % user_id_of("admin01"))
    check("補佐は管理者の利用者を開けない", status_code == 403, status_code)
    status_code, _location, _page_html = deputy_client.get("/admin/user_edit.php?id=%d" % user_id_of("hosa01"))
    check("補佐は補佐の利用者（自分）を開けない", status_code == 403, status_code)
    _status_code, _location, user_edit_html = deputy_client.get("/admin/user_edit.php?id=%d" % user_id_of("ippan_b1"))
    check("補佐のユーザーの編集には、管理者・補佐の印がない", 'name="is_admin"' not in user_edit_html and 'name="is_admin_deputy"' not in user_edit_html)
    status_code, location, _page_html = save_user(deputy_client, "ippan_b1", {"is_admin": "1", "is_admin_deputy": "1"})
    flag_row = database.execute("SELECT is_admin, is_admin_deputy FROM users WHERE id = ?", (user_id_of("ippan_b1"),)).fetchone()
    check("補佐がユーザーを保存しても、管理者・補佐にはならない", status_code in (302, 303) and tuple(flag_row) == (0, 0), (status_code, flag_row))
    status_code, _location, _page_html = save_user(deputy_client, "admin01", {"is_active": ""})
    check("補佐は管理者を無効にできない", status_code == 403 and database.execute("SELECT is_active FROM users WHERE id = ?", (user_id_of("admin01"),)).fetchone()[0] == 1, status_code)
    for action_name in ["temp_password", "reset_2fa"]:
        status_code, _location, _page_html = post_form(deputy_client, "/admin/user_edit.php?id=%d" % user_id_of("ippan_a2"), {"action": action_name})
        check("補佐は一般の利用者の %s を代行できる" % action_name, status_code in (302, 303), status_code)
    status_code, _location, _page_html = deputy_client.post("/admin/user_edit.php?id=%d" % user_id_of("admin01"),
                                                             {"action": "temp_password", "csrf_token": csrf(user_edit_html), "request_key": os.urandom(16).hex()})
    check("補佐は管理者の仮のパスワードを発行できない", status_code == 403, status_code)

    # CSV: 補佐は管理者の行を変えられない
    _status_code, _location, bulk_html = deputy_client.get("/admin/org.php?tab=bulk")
    csv_text = "ログイン ID,表示名,所属の課,班,役割,担当の班,項目管理者,有効\r\nadmin01,管理者,B課,,,,,1\r\n"
    status_code, _location, preview_html = deputy_client.post_file(
        "/admin/org.php?tab=bulk", {"action": "bulk_preview", "csrf_token": csrf(bulk_html), "request_key": os.urandom(16).hex()},
        "org_csv", "org.csv", csv_text.encode("utf-8"))
    if status_code in (302, 303):
        _status_code, _location, preview_html = deputy_client.get("/admin/org.php?tab=bulk")
    check("補佐の CSV で管理者の行を変えようとすると、反映できない", "管理者と補佐の利用者は、管理者だけが変えられます" in preview_html)

    # ---- 補佐としての記録と、管理者への知らせ ----
    deputy_log_count = database.execute("SELECT COUNT(*) FROM access_logs WHERE user_id = ? AND acted_as_deputy = 1", (user_id_of("hosa01"),)).fetchone()[0]
    check("補佐の管理の操作は「補佐として」記録する", deputy_log_count >= 3, deputy_log_count)
    _status_code, _location, admin_home_html = admin_client.get("/admin/index.php")
    check("管理者の管理のトップに、補佐の操作の数が出る", "補佐の操作（7 日）" in admin_home_html)
    _status_code, _location, deputy_home_html = deputy_client.get("/admin/index.php")
    check("補佐の管理のトップには、補佐の操作の札を出さない", "補佐の操作（7 日）" not in deputy_home_html)

    # ---- 付け外しは管理者だけ。最大 3 名、管理者と兼ねない ----
    status_code, _location, _page_html = save_user(admin_client, "ippan_b1", {"is_admin_deputy": "1"})
    check("管理者は補佐を付けられる（2 人目）", database.execute("SELECT is_admin_deputy FROM users WHERE id = ?", (user_id_of("ippan_b1"),)).fetchone()[0] == 1, status_code)
    save_user(admin_client, "ippan_a2", {"is_admin_deputy": "1"})
    status_code, _location, error_html = save_user(admin_client, "leader_a", {"is_admin_deputy": "1"})
    check("4 人目の補佐は断る", status_code == 200 and "補佐は 3 名までです" in error_html
          and database.execute("SELECT is_admin_deputy FROM users WHERE id = ?", (user_id_of("leader_a"),)).fetchone()[0] == 0, status_code)
    status_code, _location, error_html = save_user(admin_client, "ippan_a2", {"is_admin_deputy": "1", "is_admin": "1"})
    check("管理者と補佐は兼ねられない", status_code == 200 and "兼ねられません" in error_html, status_code)
    try:
        database.execute("UPDATE users SET is_admin_deputy = 1 WHERE id = ?", (user_id_of("leader_a"),))
        database_refused = False
    except sqlite3.DatabaseError:
        database_refused = True
    check("DB でも 4 人目の補佐は止める", database_refused)
    save_user(admin_client, "ippan_b1", {})
    check("管理者は補佐を外せる", database.execute("SELECT is_admin_deputy FROM users WHERE id = ?", (user_id_of("ippan_b1"),)).fetchone()[0] == 0)
    return summary()


if __name__ == "__main__":
    sys.exit(main())
