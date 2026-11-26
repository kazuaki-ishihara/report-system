#!/usr/bin/env python3
"""設計書の「検証の観点」のうち、ほかの検証で確かめていなかった項目の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

G-01（同じコード・手順の飛ばし・セッション ID・記憶した端末・ロック中）、G-02（文言・予備のコード・ほかの端末・失敗の数）、
G-03（部下と確認者の欄）、G-10（別の課の課長）、G-12（物理削除・表示名）、G-13（客先名の列）、
G-14（自分の管理者・最後の管理者・@・仮のパスワードの記録・無効・課長の一意）、G-16（ほかの人の端末・予備のコードのハッシュ）。

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_screens.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import json
import os
import re
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import Client, check, csrf, login, next_login_code, rkey, section, summary  # noqa: E402

LOCK_MESSAGE_PART = "しばらく"
WRONG_CODE_MESSAGE = "ログイン ID かコードが違います"


def cookie_value(client, cookie_name):
    for cookie in client.jar:
        if cookie.name == cookie_name:
            return cookie.value
    return ""


def password_step(client, login_id, password_text):
    """手順 1（ID とパスワード）。戻り値は (状態コード, 転送先, 本文)"""
    _status_code, _location, login_html = client.get("/login.php")
    return client.post("/login.php", {"csrf_token": csrf(login_html), "action": "password", "login_id": login_id, "password": password_text})


def code_step(client, code_text, remember=False):
    """手順 2（2 段階認証のコード）。戻り値は (状態コード, 転送先, 本文)"""
    _status_code, _location, step_html = client.get("/login.php?step=2")
    return client.post("/login.php", {"csrf_token": csrf(step_html), "action": "code", "code": code_text, "remember": "1" if remember else "0"})


def reset_password(base, login_id, code_text, new_password="new-pass-5678-abcd"):
    reset_client = Client(base)
    _status_code, _location, reset_html = reset_client.get("/password_reset.php")
    return reset_client.post("/password_reset.php", {"csrf_token": csrf(reset_html), "login_id": login_id, "code": code_text,
                                                     "new_password": new_password, "new_password_confirm": new_password})


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8000")
    ap.add_argument("--db", required=True)
    arguments = ap.parse_args()
    here_dir = os.path.dirname(os.path.abspath(__file__))
    seed_info = json.load(open(os.path.join(here_dir, "output_dev_users.json"), encoding="utf-8"))
    user_info_by_login_id = seed_info["users"]
    database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)

    def clear_throttle():
        database.execute("DELETE FROM login_throttle")

    # ================================================================ G-01 ログイン
    section("G-01", "ログイン（検証の観点の残り）")
    first_client = Client(arguments.base)
    first_client.get("/login.php")
    session_before_login = cookie_value(first_client, "mr_session")
    password_step(first_client, "ippan_a1", user_info_by_login_id["ippan_a1"]["password"])
    used_code = next_login_code("ippan_a1", user_info_by_login_id["ippan_a1"]["totp"])
    _status_code, login_location, _page = code_step(first_client, used_code)
    check("ログインできる（前提）", "/index.php" in login_location, login_location)
    check("ログインの前後でセッション ID が変わる", session_before_login != "" and cookie_value(first_client, "mr_session") not in ("", session_before_login))

    second_client = Client(arguments.base)
    password_step(second_client, "ippan_a1", user_info_by_login_id["ippan_a1"]["password"])
    _status_code, reuse_location, reuse_html = code_step(second_client, used_code)
    check("同じ 2 段階認証のコードは 2 回使えない", "/index.php" not in reuse_location, (reuse_location, re.findall(r'flash-error[^>]*>([^<]+)', reuse_html)[:1]))

    skip_client = Client(arguments.base)
    _status_code, _location, login_html = skip_client.get("/login.php")
    _status_code, skip_location, _page = skip_client.post("/login.php", {"csrf_token": csrf(login_html), "action": "code",
                                                                         "code": next_login_code("ippan_a2", user_info_by_login_id["ippan_a2"]["totp"]), "remember": "0"})
    home_status, home_location, _page = skip_client.get("/index.php")
    check("手順 1 を飛ばして手順 2 に送っても入れない", "/index.php" not in skip_location and home_status in (302, 303) and "login" in home_location, (skip_location, home_status))

    remember_client = Client(arguments.base)
    password_step(remember_client, "ippan_a2", user_info_by_login_id["ippan_a2"]["password"])
    code_step(remember_client, next_login_code("ippan_a2", user_info_by_login_id["ippan_a2"]["totp"]), remember=True)
    remembered_cookie_list = [cookie for cookie in remember_client.jar if cookie.name == "mr_device"]
    check("「この端末を記憶する」で記憶の Cookie が付く（前提）", len(remembered_cookie_list) == 1)
    other_user_client = Client(arguments.base)
    for remembered_cookie in remembered_cookie_list:
        other_user_client.jar.set_cookie(remembered_cookie)
    _status_code, other_location, _page = password_step(other_user_client, "ippan_b1", user_info_by_login_id["ippan_b1"]["password"])
    check("記憶した端末の Cookie をほかの利用者で使っても、2 段階認証を飛ばせない", "step=2" in other_location, other_location)

    lock_client = Client(arguments.base)
    for attempt_number in range(10):
        password_step(lock_client, "leader_a", "wrong-password-%d" % attempt_number)
    clear_throttle()   # 端末（IP アドレス）の制限ではなく、利用者のロックを確かめる
    _status_code, locked_location, locked_html = password_step(lock_client, "leader_a", user_info_by_login_id["leader_a"]["password"])
    check("ロック中は、正しいパスワードでも入れない", "step=2" not in locked_location and LOCK_MESSAGE_PART in locked_html, locked_location)
    database.execute("UPDATE users SET locked_until = NULL, failed_count = 0 WHERE login_id = 'leader_a'")
    clear_throttle()

    # ================================================================ G-02 パスワードの再設定
    section("G-02", "パスワードの再設定（検証の観点の残り）")
    _status_code, _location, wrong_code_html = reset_password(arguments.base, "ippan_a2", "000000")
    check("コードが違うときも「ログイン ID かコードが違います」", WRONG_CODE_MESSAGE in wrong_code_html)
    database.execute("UPDATE users SET totp_enabled_at = NULL WHERE login_id = 'dairi_a'")
    _status_code, _location, no_totp_html = reset_password(arguments.base, "dairi_a", "000000")
    check("2 段階認証が未設定の ID も同じ文言", WRONG_CODE_MESSAGE in no_totp_html and LOCK_MESSAGE_PART not in no_totp_html)
    database.execute("UPDATE users SET totp_enabled_at = created_at WHERE login_id = 'dairi_a'")
    clear_throttle()

    other_device_client = login(arguments.base, "ippan_b1", user_info_by_login_id["ippan_b1"])
    check("ほかの端末でログインしている（前提）", other_device_client.get("/index.php")[0] == 200)
    backup_code = user_info_by_login_id["ippan_b1"]["backup_code"]
    reset_status, reset_location, _page = reset_password(arguments.base, "ippan_b1", backup_code)
    check("予備のコードでパスワードを再設定できる", reset_status in (302, 303) and "login" in reset_location, (reset_status, reset_location))
    after_status, after_location, _page = other_device_client.get("/index.php")
    check("再設定のあと、ほかの端末のセッションが切れる", after_status in (302, 303) and "login" in after_location, (after_status, after_location))
    _status_code, _location, reused_backup_html = reset_password(arguments.base, "ippan_b1", backup_code, "another-pass-9999-xyz")
    check("再設定に使った予備のコードも、使えるまま（予備のコードは何度でも使える。2026-11-26 指示）",
          WRONG_CODE_MESSAGE not in reused_backup_html and database.execute(
              "SELECT COUNT(*) FROM user_backup_codes WHERE user_id = (SELECT id FROM users WHERE login_id = 'ippan_b1') AND used_at IS NOT NULL").fetchone()[0] >= 1)
    user_info_by_login_id["ippan_b1"]["password"] = "another-pass-9999-xyz"   # 2 回目の再設定で変わったパスワード
    clear_throttle()

    count_client = Client(arguments.base)
    for attempt_number in range(9):
        password_step(count_client, "kacho_b", "wrong-password-%d" % attempt_number)
    reset_password(arguments.base, "kacho_b", "000000")
    clear_throttle()
    _status_code, counted_location, counted_html = password_step(count_client, "kacho_b", user_info_by_login_id["kacho_b"]["password"])
    check("再設定の失敗は、ログインの失敗と合わせて数えられ、ロックされる（9 回 + 1 回）", "step=2" not in counted_location and LOCK_MESSAGE_PART in counted_html, counted_location)
    database.execute("UPDATE users SET locked_until = NULL, failed_count = 0 WHERE login_id = 'kacho_b'")
    clear_throttle()

    # ================================================================ G-03 ホーム
    section("G-03", "ホーム（部下と確認者の欄）")
    member_client = login(arguments.base, "ippan_a1", user_info_by_login_id["ippan_a1"])
    _status_code, _location, member_home_html = member_client.get("/index.php")
    check("部下のホームには確認者の欄（「（確認）」の見出し）が出ない", "（確認）</h2>" not in member_home_html)
    leader_client = login(arguments.base, "leader_a", user_info_by_login_id["leader_a"])
    _status_code, _location, leader_home_html = leader_client.get("/index.php")
    reviewer_heading_list = re.findall(r"<h2>([^<]*)（確認）</h2>", leader_home_html)
    check("確認者のホームには、自分が確認者の課の欄だけが出る", len(reviewer_heading_list) == 1 and reviewer_heading_list[0].startswith("A課"), reviewer_heading_list)

    # ================================================================ G-09 横並び表示
    section("G-09", "横並び表示（案件 0 件・前月の報告がない人）")
    current_period = database.execute("SELECT strftime('%Y-%m', 'now', 'localtime')").fetchone()[0]
    field_id_by_code = {code: field_id for code, field_id in database.execute("SELECT code, id FROM report_fields")}
    _status_code, _location, edit_html = member_client.get("/reports/edit.php?period=" + current_period)
    empty_report_form = {"csrf_token": csrf(edit_html), "request_key": rkey(edit_html), "period": current_period, "action": "submit",
                         "r[%d]" % field_id_by_code["trouble_note"]: "特になし"}
    submit_status, submit_location, submit_html = member_client.post("/reports/save.php", empty_report_form)
    check("案件 0 件では提出できない（「案件なし」を選ぶよう知らせる）", submit_status == 200 and "「案件なし」を選んでください" in submit_html, submit_status)
    # 決まりを足す前に提出された案件 0 件の報告が残っていても、画面が崩れないことを見る（下書きで保存し、DB で提出済みにする）
    _status_code, _location, edit_html = member_client.get("/reports/edit.php?period=" + current_period)
    member_client.post("/reports/save.php", dict(empty_report_form, csrf_token=csrf(edit_html), request_key=rkey(edit_html), action="draft"))
    database.execute("UPDATE report_records SET status = 'submitted', submitted_at = datetime('now', 'localtime') WHERE user_id = ? AND period = ? AND deleted_at IS NULL",
                     (user_info_by_login_id["ippan_a1"]["id"], current_period))
    submitted_row = database.execute("SELECT status, (SELECT COUNT(*) FROM report_projects p WHERE p.report_id = r.id) FROM report_records r WHERE user_id = ? AND period = ? AND deleted_at IS NULL",
                                     (user_info_by_login_id["ippan_a1"]["id"], current_period)).fetchone()
    check("案件 0 件の提出済みの報告（以前のデータ）がある（前提）", submitted_row is not None and submitted_row == ("submitted", 0), submitted_row)
    compare_status, _location, compare_html = leader_client.get("/status/compare.php?period=" + current_period)
    check("案件 0 件・前月の報告がない人がいても、横並びの画面が崩れない（200・PHP のエラーなし・その人が出る）",
          compare_status == 200 and "A一般1" in compare_html and "Warning" not in compare_html and "Fatal" not in compare_html, compare_status)
    _status_code, _location, summary_html = leader_client.get("/status/compare.php?period=%s&view=summary" % current_period)
    check("案件 0 件の人がいても、まとめの画面が崩れない", "Warning" not in summary_html and "Fatal" not in summary_html)

    # ================================================================ G-06 自分の報告一覧
    section("G-06", "自分の報告一覧（ほかの人の報告・削除した報告）")
    member_report_id = database.execute("SELECT id FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NULL",
                                        (user_info_by_login_id["ippan_a1"]["id"], current_period)).fetchone()[0]
    report_link_text = "view.php?id=%d" % member_report_id
    _status_code, _location, member_mine_html = member_client.get("/reports/mine.php")
    check("自分の報告一覧に、自分の報告が出る（前提）", report_link_text in member_mine_html or ("period=" + current_period) in member_mine_html)
    _status_code, _location, leader_mine_html = leader_client.get("/reports/mine.php")
    check("ほかの人の報告は、自分の報告一覧に出ない", report_link_text not in leader_mine_html and ("period=" + current_period + '"') not in leader_mine_html.replace("edit.php?period=" + current_period + '"', ""))
    database.execute("UPDATE report_records SET deleted_at = datetime('now') WHERE id = ?", (member_report_id,))
    _status_code, _location, deleted_mine_html = member_client.get("/reports/mine.php")
    check("削除した報告は、自分の報告一覧に出ない", report_link_text not in deleted_mine_html)
    database.execute("UPDATE report_records SET deleted_at = NULL WHERE id = ?", (member_report_id,))

    # ================================================================ G-10 出力
    section("G-10", "出力（別の課の課長）")
    other_manager_client = login(arguments.base, "kacho_b", user_info_by_login_id["kacho_b"])
    _status_code, _location, export_html = other_manager_client.get("/export/index.php")
    previous_period = database.execute("SELECT strftime('%Y-%m', 'now', 'localtime', 'start of month', '-1 month')").fetchone()[0]
    export_status, _location, _page = other_manager_client.post("/export/index.php", {
        "csrf_token": csrf(export_html), "type": "report", "dept": str(seed_info["dept_a"]), "from": previous_period, "to": previous_period,
        "content": "both", "free": "1", "format": "csv"})
    check("別の課の課長は、ほかの課の報告を出力できない（403）", export_status == 403, export_status)

    # ================================================================ G-12 項目マスタ
    section("G-12", "項目マスタ（物理削除・表示名）")
    any_field_id = database.execute("SELECT id FROM report_fields ORDER BY id LIMIT 1").fetchone()[0]
    try:
        database.execute("DELETE FROM report_fields WHERE id = ?", (any_field_id,))
        field_deleted = True
    except sqlite3.DatabaseError:
        field_deleted = False
    check("項目は DB でも物理削除できない（トリガーで止まる）", not field_deleted and database.execute("SELECT COUNT(*) FROM report_fields WHERE id = ?", (any_field_id,)).fetchone()[0] == 1)
    script_label = '<script>alert("x")</script>項目'
    original_label = database.execute("SELECT label FROM report_fields WHERE id = ?", (any_field_id,)).fetchone()[0]
    field_department_id = database.execute("SELECT department_id FROM report_fields WHERE id = ?", (any_field_id,)).fetchone()[0]
    database.execute("UPDATE report_fields SET label = ? WHERE id = ?", (script_label, any_field_id))
    admin_client = login(arguments.base, "admin01", user_info_by_login_id["admin01"])
    _status_code, _location, fields_html = admin_client.get("/admin/fields.php" + ("?dept=%d" % field_department_id if field_department_id else ""))
    check("表示名に <script> を入れても、文字として出る", '<script>alert("x")</script>' not in fields_html and "&lt;script&gt;" in fields_html)
    database.execute("UPDATE report_fields SET label = ? WHERE id = ?", (original_label, any_field_id))

    # ================================================================ G-13 案件の管理
    section("G-13", "案件の管理（客先名を持たない）")
    project_column_list = [row[1] for row in database.execute("PRAGMA table_info(projects)")]
    check("案件の表に客先名の列がない", not [column for column in project_column_list if "client" in column or "customer" in column], project_column_list)

    # ================================================================ G-14 組織・ユーザーの管理
    section("G-14", "組織・ユーザーの管理（検証の観点の残り）")
    admin_user_id = user_info_by_login_id["admin01"]["id"]

    def save_user(client, user_id, overrides):
        edit_path = "/admin/user_edit.php" + ("?id=%d" % user_id if user_id else "")
        _status_code, _location, edit_html = client.get(edit_path)
        form_values = {"csrf_token": csrf(edit_html), "request_key": rkey(edit_html), "action": "save",
                       "display_name": "検証の人", "role_title": "一般", "department_id": str(seed_info["dept_a"]), "is_active": "1"}
        form_values.update(overrides)
        return client.post(edit_path, form_values)

    _status_code, _location, self_admin_html = save_user(admin_client, admin_user_id, {"display_name": "管理者", "department_id": "", "is_admin": "0"})
    check("自分の管理者は外せない", "自分自身の管理者は外せません" in self_admin_html and database.execute("SELECT is_admin FROM users WHERE id = ?", (admin_user_id,)).fetchone()[0] == 1)
    # 最後の管理者: 管理者でない操作者は、補佐（開発担当。管理者と同じことができる。K-059）だけ。管理者が admin01 だけのとき、外す・無効にするのを断る
    database.execute("UPDATE users SET is_admin_deputy_lead = 1 WHERE login_id = 'hosa01'")
    deputy_lead_client = login(arguments.base, "hosa01", user_info_by_login_id["hosa01"])
    check("管理者は admin01 の 1 人だけ（前提）", database.execute("SELECT COUNT(*) FROM users WHERE is_admin = 1 AND is_active = 1").fetchone()[0] == 1)
    _status_code, _location, last_admin_html = save_user(deputy_lead_client, admin_user_id, {"display_name": "管理者", "department_id": "", "is_admin": "0"})
    check("最後の管理者は、管理者を外せない（「管理者が 1 人もいなくなるため」）",
          "管理者が 1 人もいなくなるため" in last_admin_html and database.execute("SELECT is_admin FROM users WHERE id = ?", (admin_user_id,)).fetchone()[0] == 1)
    _status_code, _location, last_admin_inactive_html = save_user(deputy_lead_client, admin_user_id, {"display_name": "管理者", "department_id": "", "is_admin": "1", "is_active": "0"})
    check("最後の管理者は、無効にもできない",
          "管理者が 1 人もいなくなるため" in last_admin_inactive_html and database.execute("SELECT is_active FROM users WHERE id = ?", (admin_user_id,)).fetchone()[0] == 1)
    database.execute("UPDATE users SET is_admin = 1 WHERE login_id = 'kacho_a'")   # 管理者が 2 人なら外せる（上の確かめが空振りでないこと）
    _status_code, _location, _page = save_user(deputy_lead_client, user_info_by_login_id["kacho_a"]["id"], {"display_name": "A課長", "role_title": "課長", "is_admin": "0"})
    check("管理者が 2 人いれば、1 人の管理者を外せる", database.execute("SELECT is_admin FROM users WHERE login_id = 'kacho_a'").fetchone()[0] == 0)
    database.execute("UPDATE users SET is_admin_deputy_lead = 0 WHERE login_id = 'hosa01'")

    _status_code, _location, at_mark_html = save_user(admin_client, None, {"login_id": "someone@example.com", "display_name": "メールの人"})
    check("ログイン ID に @ を含められない", "ログイン ID にメールアドレスは使えません" in at_mark_html
          and database.execute("SELECT COUNT(*) FROM users WHERE login_id LIKE '%@%'").fetchone()[0] == 0)

    create_status, create_location, _page = save_user(admin_client, None, {"login_id": "kensho_new", "display_name": "新しい人"})
    _status_code, _location, created_html = admin_client.get(create_location or "/admin/user_edit.php")
    temp_password_match = re.search(r'id="temp-password">([^<]+)<', created_html)
    temp_password_text = temp_password_match.group(1) if temp_password_match else ""
    check("利用者を足すと仮のパスワードが出る（前提）", create_status in (302, 303) and temp_password_text != "", (create_status, create_location))
    leaked_log_count = database.execute("SELECT COUNT(*) FROM access_logs WHERE target LIKE ?", ("%" + temp_password_text + "%",)).fetchone()[0] if temp_password_text else -1
    check("仮のパスワードが操作の記録（access_logs）に残らない", leaked_log_count == 0, leaked_log_count)

    target_member_client = login(arguments.base, "ippan_a2", user_info_by_login_id["ippan_a2"])
    check("無効にする人がログインしている（前提）", target_member_client.get("/index.php")[0] == 200)
    save_user(admin_client, user_info_by_login_id["ippan_a2"]["id"], {"display_name": "A一般2", "is_active": "0"})
    deactivated_status, deactivated_location, _page = target_member_client.get("/index.php")
    check("無効にすると、その人のセッションが切れる", deactivated_status in (302, 303) and "login" in deactivated_location, (deactivated_status, deactivated_location))
    _status_code, deactivated_login_location, _page = password_step(Client(arguments.base), "ippan_a2", user_info_by_login_id["ippan_a2"]["password"])
    check("無効にした人はログインできない", "step=2" not in deactivated_login_location, deactivated_login_location)
    clear_throttle()

    try:
        database.execute("INSERT INTO department_reviewers (department_id, user_id, is_final, sort_order) VALUES (?, ?, 1, 999)",
                         (seed_info["dept_a"], user_info_by_login_id["leader_a"]["id"]))
        second_final_added = True
    except sqlite3.DatabaseError:
        second_final_added = False
    check("1 つの課に課長は 2 人にならない（DB の一意の索引で止まる）", not second_final_added)

    # ================================================================ G-16 アカウント設定
    section("G-16", "アカウント設定（ほかの人の端末・予備のコード）")
    other_device_row = database.execute("SELECT id FROM remembered_devices WHERE user_id = ? LIMIT 1", (user_info_by_login_id["ippan_a2"]["id"],)).fetchone()
    owner_client = login(arguments.base, "leader_a", user_info_by_login_id["leader_a"])
    _status_code, _location, devices_html = owner_client.get("/account.php?tab=devices")
    if other_device_row is None:
        database.execute("INSERT INTO remembered_devices (user_id, token_hash, label, expires_at, created_at) VALUES (?, 'verify-other-device', '検証', datetime('now', '+30 day'), datetime('now'))",
                         (user_info_by_login_id["ippan_b1"]["id"],))
        other_device_row = database.execute("SELECT id FROM remembered_devices WHERE token_hash = 'verify-other-device'").fetchone()
    owner_client.post("/account.php?tab=devices", {"csrf_token": csrf(devices_html), "request_key": rkey(devices_html), "action": "device_revoke", "device_id": str(other_device_row[0])})
    check("ほかの人の端末の記憶は解除できない", database.execute("SELECT COUNT(*) FROM remembered_devices WHERE id = ?", (other_device_row[0],)).fetchone()[0] == 1)
    database.execute("INSERT INTO remembered_devices (user_id, token_hash, label, expires_at, created_at) VALUES (?, 'verify-own-device', '検証', datetime('now', '+30 day'), datetime('now'))",
                     (user_info_by_login_id["leader_a"]["id"],))
    own_device_id = database.execute("SELECT id FROM remembered_devices WHERE token_hash = 'verify-own-device'").fetchone()[0]
    _status_code, _location, devices_html = owner_client.get("/account.php?tab=devices")
    owner_client.post("/account.php?tab=devices", {"csrf_token": csrf(devices_html), "request_key": rkey(devices_html), "action": "device_revoke", "device_id": str(own_device_id)})
    check("自分の端末の記憶は、同じ送り方で解除できる（上の確かめが空振りでないこと）", database.execute("SELECT COUNT(*) FROM remembered_devices WHERE id = ?", (own_device_id,)).fetchone()[0] == 0)
    backup_hash_list = [row[0] for row in database.execute("SELECT code_hash FROM user_backup_codes")]
    check("予備のコードは DB に平文で入っていない", backup_hash_list != [] and all(code_hash.startswith("$2y$") or code_hash.startswith("$argon") for code_hash in backup_hash_list)
          and not any(user_info["backup_code"].replace("-", "") in code_hash for user_info in user_info_by_login_id.values() for code_hash in backup_hash_list))

    return summary()


if __name__ == "__main__":
    sys.exit(main())
