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
import re
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import Client, check, csrf, login, section, summary  # noqa: E402


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

    # ---- アカウント設定に出す自分のシステムの役割（役割のある本人だけ） ----
    def shown_system_role(client, tab_name="totp"):
        """アカウント設定に出ている役割の名前の一覧（出ていなければ空）。200 でなければ None。"""
        status_code, _location, account_html = client.get("/account.php?tab=" + tab_name)
        if status_code != 200 or "<h1>アカウント設定</h1>" not in account_html:
            return None
        if "システムの役割" not in account_html:
            return []
        return re.findall(r'<p>システムの役割: <span class="tag">([^<]*)</span></p>', account_html)

    section("G-16", "自分のシステムの役割（役割のある本人だけ）")
    for tab_name in ["totp", "codes", "devices", "password", "theme"]:
        for client, expected_roles, person in [(admin_client, ["システム管理者"], "管理者"), (deputy_client, ["システム管理者補佐"], "補佐")]:
            shown_roles = shown_system_role(client, tab_name)
            check("%sの役割が出る（タブ %s）" % (person, tab_name), shown_roles == expected_roles, shown_roles)
    for login_id in ["ippan_a1", "kacho_a"]:
        shown_roles = shown_system_role(login(arguments.base, login_id, user_info_by_login_id[login_id]))
        check("役割のない人（%s）には出ない" % login_id, shown_roles == [], shown_roles)
    hosa_id = user_id_of("hosa01")
    database.execute("UPDATE users SET must_change_password = 1 WHERE id = ?", (hosa_id,))
    shown_roles = shown_system_role(deputy_client)
    database.execute("UPDATE users SET must_change_password = 0 WHERE id = ?", (hosa_id,))
    check("補佐でも、仮のパスワードの間は出ない", shown_roles == [], shown_roles)
    totp_row = database.execute("SELECT totp_secret_enc, totp_enabled_at FROM users WHERE id = ?", (hosa_id,)).fetchone()
    database.execute("UPDATE users SET totp_secret_enc = NULL, totp_enabled_at = NULL WHERE id = ?", (hosa_id,))
    setup_client = Client(arguments.base)
    _status_code, _location, login_html = setup_client.get("/login.php")
    setup_client.post("/login.php", {"csrf_token": csrf(login_html), "action": "password", "login_id": "hosa01",
                                     "password": user_info_by_login_id["hosa01"]["password"]})
    _status_code, _location, setup_html = setup_client.get("/account.php?tab=totp")
    database.execute("UPDATE users SET totp_secret_enc = ?, totp_enabled_at = ? WHERE id = ?", (totp_row[0], totp_row[1], hosa_id))
    check("補佐でも、2 段階認証の設定の前は出ない", "はじめてのログインです" in setup_html and "システムの役割" not in setup_html)

    # ---- ホームの「あなたの役割と権限」（役職・システムの役割・課ごとの担当。§4.4） ----
    section("G-03", "あなたの役割と権限（役職・システムの役割・課ごとの担当）")

    role_client_by_login_id = {"admin01": admin_client, "hosa01": deputy_client}

    def role_card(login_id):
        """ホームの「あなたの役割と権限」: (札の一覧, 担当の文, できることの一覧)。欄がなければ None。"""
        if login_id not in role_client_by_login_id:
            role_client_by_login_id[login_id] = login(arguments.base, login_id, user_info_by_login_id[login_id])
        _status_code, _location, page_html = role_client_by_login_id[login_id].get("/index.php")
        card = re.search(r'<section class="card role-summary">(.*?)</section>', page_html, re.S)
        if card is None:
            return None
        duty = re.search(r"<p>担当: (.*?)</p>", card.group(1), re.S)
        return (re.findall(r'<span class="tag">([^<]*)</span>', card.group(1)), duty.group(1) if duty else "", re.findall(r"<li>(.*?)</li>", card.group(1), re.S))

    has_goals = os.path.exists(os.path.join(test_dir, "..", "..", "app", "lib", "goal.php"))
    own_report = "自分の月次報告を作って提出する"
    goal_boss = ["目標管理: A課の人のシートを見て、上長評価を入れる"] if has_goals else []
    goal_deputy = ["目標管理: 同じ課の一般・主任の人のシートを見る"] if has_goals else []
    for login_id, role_title in (("ippan_a1", "一般"), ("leader_a", "主任"), ("dairi_a", "課長代理"), ("kacho_a", "課長")):
        database.execute("UPDATE users SET role_title = ? WHERE id = ?", (role_title, user_id_of(login_id)))
    database.execute("UPDATE department_reviewers SET does_check = 1 WHERE user_id = ?", (user_id_of("dairi_a"),))
    database.execute("UPDATE department_reviewers SET does_check = 0 WHERE user_id = ?", (user_id_of("leader_a"),))
    card = role_card("ippan_a1")
    check("一般: 札は「一般」だけ、担当なし、自分の報告だけ", card == (["一般"], "", [own_report]), card)
    card = role_card("leader_a")
    check("主任（チェックしない確認者）: 「主任」の札・確認者・チェックしない",
          card == (["主任"], "A課（確認者）", [own_report, "A課: 課の全員の報告を見てコメントする（チェックはしない設定）"]), card)
    card = role_card("dairi_a")
    check("課長代理（チェックする確認者）: チェックする",
          card == (["課長代理"], "A課（確認者）", [own_report, "A課: 課の全員の報告を見てコメントする・チェックする"] + goal_deputy), card)
    kacho_permissions = [own_report, "A課: 課の全員の報告を見る（「限定」の項目を含む）", "A課: 報告の確認を完了にする・出力する・課の臨時の調査を作る・課の管理をする"] + goal_boss
    card = role_card("kacho_a")
    check("課長: 課長の担当と権限", card == (["課長"], "A課（課長）", kacho_permissions), card)
    database.execute("UPDATE users SET role_title = '主任' WHERE id = ?", (user_id_of("kacho_a"),))
    card = role_card("kacho_a")
    check("役職が主任でも、課長を任されていれば課長の権限が出る（役職ではなく設定で決まる）", card == (["主任"], "A課（課長）", kacho_permissions), card)
    database.execute("UPDATE users SET role_title = '課長' WHERE id = ?", (user_id_of("ippan_a2"),))
    database.execute("UPDATE users SET role_title = '部長' WHERE id = ?", (user_id_of("kacho_a"),))
    card = role_card("kacho_a")
    check("部長: 「部長」の札（課長を兼ねれば課長の権限）", card == (["部長"], "A課（課長）", kacho_permissions), card)
    # 「ラインごと」の課で担当のラインがある確認者
    team_id = database.execute("INSERT INTO department_teams (department_id, name, sort_order, is_active, created_at, updated_at) VALUES (?, '山田ライン', 1, 1, datetime('now'), datetime('now'))", (department_a_id,)).lastrowid
    database.execute("UPDATE departments SET review_scope = 'team' WHERE id = ?", (department_a_id,))
    database.execute("UPDATE department_reviewers SET team_id = ? WHERE user_id = ?", (team_id, user_id_of("dairi_a")))
    card = role_card("dairi_a")
    check("ラインごとの課: 担当のラインの名前と範囲が出る",
          card == (["課長代理"], "A課（確認者・山田ライン）", [own_report, "A課: 担当のライン（山田ライン）の報告を見てコメントする・チェックする"] + goal_deputy), card)
    card = role_card("leader_a")
    check("ラインごとの課でも、担当のラインがない確認者は課の全員", card is not None and card[1] == "A課（確認者）" and any("課の全員" in text for text in card[2]), card)
    database.execute("UPDATE department_reviewers SET team_id = NULL WHERE user_id = ?", (user_id_of("dairi_a"),))
    database.execute("UPDATE departments SET review_scope = 'department' WHERE id = ?", (department_a_id,))
    database.execute("DELETE FROM department_teams WHERE id = ?", (team_id,))
    database.execute("INSERT INTO department_admins (department_id, user_id) VALUES (?, ?)", (department_a_id, user_id_of("ippan_a1")))
    card = role_card("ippan_a1")
    check("項目管理者: 課の項目の変更が出る", card is not None and "A課: 課の項目を変更する（項目管理者）" in card[2], card)
    database.execute("DELETE FROM department_admins WHERE user_id = ?", (user_id_of("ippan_a1"),))
    # 無効の課の担当は、見ることだけ
    database.execute("UPDATE departments SET is_active = 0 WHERE id = ?", (department_a_id,))
    card = role_card("kacho_a")
    check("無効の課の課長: 「無効の課」と出て、確認の完了・管理は出ない",
          card is not None and card[1] == "A課（課長・無効の課）" and card[2] == kacho_permissions[:2], card)
    database.execute("UPDATE departments SET is_active = 1 WHERE id = ?", (department_a_id,))
    survey_manage_text = "全課共通の臨時の調査を作り、結果（集計だけ）を見て出力する"
    deputy_text = "システム管理者の管理の仕事を代行する（確認者でない課の報告の中身などの、個々の情報は見ない）"
    admin_text = "システム全体の管理（課・ユーザー・項目・案件・記録・削除データなど）をする。報告の中身は、確認者の課の分だけ見る"
    card = role_card("admin01")
    check("システム管理者: 「システム管理者」の札と管理の権限（課がないので報告は出ない）", card is not None and "システム管理者" in card[0] and card[2] == [survey_manage_text, admin_text], card)
    card = role_card("hosa01")
    check("補佐: 「システム管理者補佐」の札と代行", card is not None and "システム管理者補佐" in card[0] and card[2] == [survey_manage_text, deputy_text], card)
    database.execute("UPDATE users SET is_admin_deputy_lead = 1 WHERE id = ?", (hosa_id,))
    card = role_card("hosa01")
    check("補佐（開発担当）: 管理者と同じ権限", card is not None and "システム管理者補佐（開発担当）" in card[0] and card[2] == [survey_manage_text, admin_text], card)
    database.execute("UPDATE users SET is_admin_deputy_lead = 0 WHERE id = ?", (hosa_id,))
    for login_id, role_title in (("ippan_a1", "一般"), ("ippan_a2", "一般"), ("kacho_a", "課長")):
        database.execute("UPDATE users SET role_title = ? WHERE id = ?", (role_title, user_id_of(login_id)))

    # ---- 代行できる管理の画面 ----
    section("G-21", "代行できる管理の画面")
    for path in ["/admin/index.php", "/admin/org.php?tab=departments", "/admin/org.php?tab=users", "/admin/projects.php",
                 "/admin/fields.php", "/admin/messages.php", "/admin/logs.php", "/admin/trash.php",
                 "/admin/user_edit.php?id=%d" % user_id_of("ippan_a1")]:
        status_code, _location, _page_html = deputy_client.get(path)
        check("補佐が開ける: " + path, status_code == 200, status_code)
    _status_code, _location, menu_html = deputy_client.get("/index.php")
    check("補佐のメニューに管理の画面（組織・ユーザー、案件、記録、メッセージ）",
          all(label in menu_html for label in ["組織・ユーザー", "案件の管理", "記録・削除データ", "メッセージ"]))

    # ---- 個々の情報は見られない ----
    section("共通", "個々の情報は見られない")
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
    section("G-14", "管理者・補佐の利用者と役は変えられない")
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

    # ---- 予備のコードの初期化（管理者・補佐。本人がコードをメール・チャットに貼った、などのとき） ----
    section("G-14", "予備のコードの初期化（管理者・補佐。本人がコードをメール・チャットに貼った、などのとき）")
    def unused_backup_code_count(login_id):
        return database.execute("SELECT COUNT(*) FROM user_backup_codes WHERE user_id = ?", (user_id_of(login_id),)).fetchone()[0]   # 予備のコードは何度でも使えるので、すべて数える

    def try_backup_code_login(login_id):
        """予備のコードでログインを試す。ログインできれば True。"""
        backup_client = Client(arguments.base)
        _status_code, _location, login_html = backup_client.get("/login.php")
        backup_client.post("/login.php", {"csrf_token": csrf(login_html), "action": "password", "login_id": login_id,
                                          "password": user_info_by_login_id[login_id]["password"]})
        _status_code, _location, code_html = backup_client.get("/login.php?step=2")
        _status_code, location, _page_html = backup_client.post("/login.php", {"csrf_token": csrf(code_html), "action": "code",
                                                                              "code": user_info_by_login_id[login_id]["backup_code"], "remember": "0"})
        return "/index.php" in (location or "")

    _status_code, _location, user_edit_html = deputy_client.get("/admin/user_edit.php?id=%d" % user_id_of("ippan_b1"))
    check("ユーザーの編集に、予備のコードの残りと「予備のコードを初期化」が出る",
          "<span>1 個</span>" in user_edit_html and 'value="reset_backup_codes"' in user_edit_html)
    totp_before_row = database.execute("SELECT totp_secret_enc, totp_enabled_at FROM users WHERE id = ?", (user_id_of("ippan_b1"),)).fetchone()
    status_code, location, _page_html = post_form(deputy_client, "/admin/user_edit.php?id=%d" % user_id_of("ippan_b1"), {"action": "reset_backup_codes"})
    totp_after_row = database.execute("SELECT totp_secret_enc, totp_enabled_at FROM users WHERE id = ?", (user_id_of("ippan_b1"),)).fetchone()
    check("補佐は一般の利用者の予備のコードを初期化できる（使えるコードが 0 個）", status_code in (302, 303) and unused_backup_code_count("ippan_b1") == 0,
          (status_code, unused_backup_code_count("ippan_b1")))
    check("予備のコードの初期化では、認証アプリの設定は変えない", tuple(totp_before_row) == tuple(totp_after_row))
    check("初期化した前の予備のコードではログインできない", not try_backup_code_login("ippan_b1"))
    check("予備のコードの初期化を記録する（補佐として）",
          database.execute("SELECT COUNT(*) FROM access_logs WHERE target = ?", ("user:%d:reset_backup_codes" % user_id_of("ippan_b1"),)).fetchone()[0] >= 1)
    ippan_b1_client = login(arguments.base, "ippan_b1", user_info_by_login_id["ippan_b1"])
    check("初期化された本人は、認証アプリのコードでログインできる", ippan_b1_client is not None)
    _status_code, _location, home_html = ippan_b1_client.get("/index.php")
    check("初期化された本人のホームに、予備のコードを作り直す知らせが出る", "予備のコードがありません" in home_html and "/account.php?tab=codes" in home_html)
    post_form(ippan_b1_client, "/account.php?tab=codes", {"action": "codes_regen", "current_password": user_info_by_login_id["ippan_b1"]["password"]})
    _status_code, _location, home_html = ippan_b1_client.get("/index.php")
    check("本人が作り直すと、ホームの知らせが消える", "予備のコードがありません" not in home_html and unused_backup_code_count("ippan_b1") == 8,
          unused_backup_code_count("ippan_b1"))
    status_code, _location, _page_html = deputy_client.post("/admin/user_edit.php?id=%d" % user_id_of("admin01"),
                                                             {"action": "reset_backup_codes", "csrf_token": csrf(user_edit_html), "request_key": os.urandom(16).hex()})
    check("補佐は管理者の予備のコードを初期化できない", status_code == 403 and unused_backup_code_count("admin01") == 1, status_code)
    status_code, _location, _page_html = post_form(admin_client, "/admin/user_edit.php?id=%d" % user_id_of("hosa01"), {"action": "reset_backup_codes"})
    check("管理者は補佐の予備のコードも初期化できる", status_code in (302, 303) and unused_backup_code_count("hosa01") == 0, status_code)
    _status_code, _location, user_edit_html = admin_client.get("/admin/user_edit.php?id=%d" % user_id_of("ippan_a2"))
    check("2 段階認証が未設定の人には、予備のコードの初期化を出さない", 'value="reset_backup_codes"' not in user_edit_html and "予備のコード</span><span>—" in user_edit_html)

    # ---- 補佐（開発担当。K-059）: 補佐のうち 1 名だけ、管理者・補佐の予備のコードも初期化できる ----
    section("G-14", "補佐（開発担当。K-059）: 補佐のうち 1 名だけ、管理者・補佐の予備のコードも初期化できる")
    status_code, _location, _page_html = save_user(deputy_client, "ippan_a1", {"is_admin_deputy_lead": "1"})
    check("補佐は、補佐（開発担当）の印を付けられない",
          database.execute("SELECT is_admin_deputy_lead FROM users WHERE id = ?", (user_id_of("ippan_a1"),)).fetchone()[0] == 0, status_code)
    # 開発担当のほかに補佐が要る（K-075）ので、この間だけ補佐をもう 1 人置く
    database.execute("UPDATE users SET is_admin_deputy = 1 WHERE id = ?", (user_id_of("kacho_b"),))
    status_code, _location, _page_html = save_user(admin_client, "hosa01", {"is_admin_deputy": "1", "is_admin_deputy_lead": "1"})
    check("管理者は、補佐に補佐（開発担当）の印を付けられる",
          database.execute("SELECT is_admin_deputy_lead FROM users WHERE id = ?", (user_id_of("hosa01"),)).fetchone()[0] == 1, status_code)
    deputy_client = login(arguments.base, "hosa01", user_info_by_login_id["hosa01"])   # 役が変わるとログインし直す
    # 補佐（開発担当）は管理者と同じ権限（K-059）。ただし心の不調・退職に関わる質問は見られない
    status_code, _location, lead_view_html = deputy_client.get("/admin/user_edit.php?id=%d" % user_id_of("admin01"))
    check("補佐（開発担当）は管理者のユーザーの編集を開け、保存・管理者と補佐の印も出る",
          status_code == 200 and 'value="save"' in lead_view_html and 'name="is_admin"' in lead_view_html and 'name="is_admin_deputy_lead"' in lead_view_html, status_code)
    status_code, _location, _page_html = post_form(deputy_client, "/admin/user_edit.php?id=%d" % user_id_of("admin01"), {"action": "unlock"})
    check("補佐（開発担当）は管理者の利用者の操作ができる（ロックの解除）", status_code in (302, 303), status_code)
    status_code, _location, _page_html = post_form(deputy_client, "/admin/user_edit.php?id=%d" % user_id_of("admin01"), {"action": "reset_backup_codes"})
    check("補佐（開発担当）は管理者の予備のコードを初期化できる", status_code in (302, 303) and unused_backup_code_count("admin01") == 0, status_code)
    check("補佐（開発担当）の操作も、補佐として記録する",
          database.execute("SELECT COUNT(*) FROM access_logs WHERE target = ? AND user_id = ? AND acted_as_deputy = 1",
                           ("user:%d:reset_backup_codes" % user_id_of("admin01"), user_id_of("hosa01"))).fetchone()[0] >= 1)
    status_code, _location, _page_html = deputy_client.get("/admin/logs.php?kind=view")
    check("補佐（開発担当）は閲覧の記録を見られる", status_code == 200, status_code)
    _status_code, _location, lead_trash_html = deputy_client.get("/admin/trash.php")
    check("補佐（開発担当）の削除データには中身の一覧が出る", "A一般1" in lead_trash_html)
    _status_code, _location, lead_status_html = deputy_client.get("/status/index.php?period=%s&dept=%d" % (previous_month, department_a_id))
    check("補佐（開発担当）の提出状況には人ごとの行が出る", "A一般1" in lead_status_html)
    _status_code, _location, lead_faq_logs_html = deputy_client.get("/admin/faq_logs.php")
    check("補佐（開発担当）でも、心の不調・退職に関わる質問は見られない",
          "知らせる質問（人への暴力・ID やパスワードを求める質問）" in lead_faq_logs_html and "心の不調・退職" not in lead_faq_logs_html)
    _status_code, _location, users_html = admin_client.get("/admin/org.php?tab=users")
    check("ユーザーの一覧に「補佐（開発担当）」と出る", "補佐（開発担当）" in users_html)
    section("G-16", "自分のシステムの役割（補佐（開発担当））")
    shown_roles = shown_system_role(deputy_client)
    check("補佐（開発担当）の役割が 1 つだけ出る", shown_roles == ["システム管理者補佐（開発担当）"], shown_roles)
    section("G-14", "管理者・補佐の利用者と役は変えられない（CSV）")
    # 以降の確かめは、ふつうの補佐として行う（印を外してログインし直す。1 名だけの確かめは最後に付け直す）
    save_user(admin_client, "hosa01", {"is_admin_deputy": "1"})
    database.execute("UPDATE users SET is_admin_deputy = 0 WHERE id = ?", (user_id_of("kacho_b"),))
    deputy_client = login(arguments.base, "hosa01", user_info_by_login_id["hosa01"])

    # CSV: 補佐は管理者の行を変えられない
    _status_code, _location, bulk_html = deputy_client.get("/admin/org.php?tab=bulk")
    csv_text = "ログイン ID,表示名,所属の課,ライン,役割,担当のライン,項目管理者,有効\r\nadmin01,管理者,B課,,,,,1\r\n"
    status_code, _location, preview_html = deputy_client.post_file(
        "/admin/org.php?tab=bulk", {"action": "bulk_preview", "csrf_token": csrf(bulk_html), "request_key": os.urandom(16).hex()},
        "org_csv", "org.csv", csv_text.encode("utf-8"))
    if status_code in (302, 303):
        _status_code, _location, preview_html = deputy_client.get("/admin/org.php?tab=bulk")
    check("補佐の CSV で管理者の行を変えようとすると、反映できない", "管理者と補佐の利用者は、管理者だけが変えられます" in preview_html)

    # ---- 補佐としての記録と、管理者への知らせ ----
    section("G-15", "補佐としての記録と、管理者への知らせ")
    deputy_log_count = database.execute("SELECT COUNT(*) FROM access_logs WHERE user_id = ? AND acted_as_deputy = 1", (user_id_of("hosa01"),)).fetchone()[0]
    check("補佐の管理の操作は「補佐として」記録する", deputy_log_count >= 3, deputy_log_count)
    _status_code, _location, admin_home_html = admin_client.get("/admin/index.php")
    check("管理者の管理のトップに、補佐の操作の数が出る", "補佐の操作（7 日）" in admin_home_html)
    _status_code, _location, deputy_home_html = deputy_client.get("/admin/index.php")
    check("補佐の管理のトップには、補佐の操作の札を出さない", "補佐の操作（7 日）" not in deputy_home_html)

    # ---- 付け外しは管理者だけ。最大 3 名、管理者と兼ねない ----
    section("G-14", "付け外しは管理者だけ。最大 3 名、管理者と兼ねない")
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
    save_user(admin_client, "hosa01", {"is_admin_deputy": "1", "is_admin_deputy_lead": "1"})
    status_code, _location, error_html = save_user(admin_client, "ippan_b1", {"is_admin_deputy": "1", "is_admin_deputy_lead": "1"})
    check("補佐（開発担当）は 1 名だけ（2 人目は断る）", status_code == 200 and "1 名だけです" in error_html
          and database.execute("SELECT is_admin_deputy_lead FROM users WHERE id = ?", (user_id_of("ippan_b1"),)).fetchone()[0] == 0, status_code)
    for sql_text, check_name in [("UPDATE users SET is_admin_deputy_lead = 1 WHERE id = %d" % user_id_of("ippan_a2"), "DB でも補佐（開発担当）の 2 人目は止める"),
                                 ("UPDATE users SET is_admin_deputy_lead = 1 WHERE id = %d" % user_id_of("leader_a"), "DB でも補佐でない人には補佐（開発担当）を付けない")]:
        try:
            database.execute(sql_text)
            database_refused = False
        except sqlite3.DatabaseError:
            database_refused = True
        check(check_name, database_refused)
    save_user(admin_client, "hosa01", {"is_admin_deputy": "1"})
    check("管理者は補佐（開発担当）の印を外せる",
          database.execute("SELECT is_admin_deputy_lead FROM users WHERE id = ?", (user_id_of("hosa01"),)).fetchone()[0] == 0)
    save_user(admin_client, "ippan_b1", {})
    check("管理者は補佐を外せる", database.execute("SELECT is_admin_deputy FROM users WHERE id = ?", (user_id_of("ippan_b1"),)).fetchone()[0] == 0)
    return summary()


if __name__ == "__main__":
    sys.exit(main())
