#!/usr/bin/env python3
"""組織まわりの確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

- K-025 課ごとの管理の仕方（課全体／班ごと）と班
- K-033 班を移った人の過去の報告は、今の班の課代・リーダーが見る
- K-032 課を移った人の、移る前の報告は、新しい課の確認者も見られる
- K-006 メッセージを変えられるのは、管理担当の課の課長だけ
- K-031 課長がいない課を、管理者のホームで知らせる
- K-034 組織の一括の入れ替え（CSV）
- K-042 課の管理は課長（締め日・管理の仕方・確認者・班）。管理者は課長を決める
- K-039 課代・リーダーの「チェックする／しない」

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_org.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import csv
import io
import datetime
import json
import os
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, csrf, login, rkey, summary  # noqa: E402


def shift_month(year_month_text, month_offset):
    year_number, month_number = int(year_month_text[:4]), int(year_month_text[5:7]) + month_offset
    while month_number < 1:
        year_number, month_number = year_number - 1, month_number + 12
    while month_number > 12:
        year_number, month_number = year_number + 1, month_number - 12
    return "%04d-%02d" % (year_number, month_number)


def main():
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--base", default="http://127.0.0.1:8000")
    argument_parser.add_argument("--db", required=True)
    arguments = argument_parser.parse_args()
    test_dir = os.path.dirname(os.path.abspath(__file__))
    seed_info = json.load(open(os.path.join(test_dir, "output_dev_users.json"), encoding="utf-8"))
    user_info_by_login_id = seed_info["users"]
    department_a_id, department_b_id = seed_info["dept_a"], seed_info["dept_b"]
    database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)
    field_id_by_code = {code: field_id for code, field_id in database.execute("SELECT code, id FROM report_fields")}
    project_id_by_code = {code: project_id for code, project_id in database.execute("SELECT code, id FROM projects")}
    previous_month = shift_month(datetime.date.today().strftime("%Y-%m"), -1)

    def user_id_of(login_id):
        return user_info_by_login_id[login_id]["id"]

    client_by_login_id = {}

    def client_of(login_id):
        if login_id not in client_by_login_id:
            client_by_login_id[login_id] = login(arguments.base, login_id, user_info_by_login_id[login_id])
        return client_by_login_id[login_id]

    admin_client = client_of("admin01")
    org_edit_path_a = "/admin/org.php?tab=departments&edit=%d" % department_a_id
    department_manage_path_a = "/admin/department.php?dept=%d" % department_a_id

    def post_with(client, path, form_values):
        status_code, _location, page_html = client.get(path)
        form_values = dict(form_values, csrf_token=csrf(page_html), request_key=os.urandom(16).hex())
        return client.post(path, form_values)

    def post_org(path, form_values):
        return post_with(admin_client, path, form_values)

    def post_department_a(form_values):
        """課長（kacho_a）が「課の管理」で操作する（K-042）"""
        return post_with(client_of("kacho_a"), department_manage_path_a, form_values)

    def save_department_a(review_scope):
        return post_department_a({"action": "settings_save", "close_day": "", "review_scope": review_scope})

    def team_id_of(team_name):
        team_row = database.execute("SELECT id FROM department_teams WHERE department_id = ? AND name = ?", (department_a_id, team_name)).fetchone()
        return team_row[0] if team_row else 0

    def save_members_a(reviewer_team_by_login_id, member_team_by_login_id, does_check_login_id_set):
        """課の管理の「確認者と課員」: 確認者（課代・リーダー）と担当の班・チェックする・課員の班をまとめて保存する"""
        form_values = {"action": "members_save"}
        for login_id, reviewer_team_id in reviewer_team_by_login_id.items():
            form_values["reviewer[%d]" % user_id_of(login_id)] = "1"
            form_values["reviewer_team[%d]" % user_id_of(login_id)] = str(reviewer_team_id) if reviewer_team_id else ""
            if login_id in does_check_login_id_set:
                form_values["does_check[%d]" % user_id_of(login_id)] = "1"
        for login_id, member_team_id in member_team_by_login_id.items():
            form_values["member_team[%d]" % user_id_of(login_id)] = str(member_team_id) if member_team_id else ""
        return post_department_a(form_values)

    def save_user_department(login_id, department_id):
        user_edit_path = "/admin/user_edit.php?id=%d" % user_id_of(login_id)
        user_row = database.execute("SELECT display_name, role_title FROM users WHERE id = ?", (user_id_of(login_id),)).fetchone()
        return post_org(user_edit_path, {"action": "save", "display_name": user_row[0], "role_title": user_row[1],
                                         "department_id": str(department_id), "is_active": "1"})

    def status_of(login_id, report_id):
        return client_of(login_id).get("/reports/view.php?id=%d" % report_id)[0]

    # ---- K-042 課の管理は課長。管理者は個々の課を管理しない ----
    check("課長は「課の管理」を開ける", client_of("kacho_a").get(department_manage_path_a)[0] == 200)
    check("課代は「課の管理」を開けない（403）", client_of("dairi_a").get(department_manage_path_a)[0] == 403)
    check("管理者は（課長でなければ）「課の管理」を開けない（403）", admin_client.get(department_manage_path_a)[0] == 403)
    check("ほかの課の課長は開けない（403）", client_of("kacho_b").get(department_manage_path_a)[0] == 403)
    status_code, _location, kacho_menu_html = client_of("kacho_a").get("/index.php")
    check("課長のメニューに「課の管理」", "/admin/department.php" in kacho_menu_html)
    status_code, _location, org_edit_html = admin_client.get(org_edit_path_a)
    check("管理者の課の編集は「基本・課長・引き継ぎ」（締め日・管理の仕方・確認者・班はない）",
          'data-tab-title="課長"' in org_edit_html and 'data-tab-title="班"' not in org_edit_html
          and 'name="review_scope"' not in org_edit_html and 'name="close_day"' not in org_edit_html)
    status_code, _location, _page_html = post_org(org_edit_path_a, {"action": "team_add", "dept_id": str(department_a_id), "team_name": "管理者の班"})
    check("管理者は班を足せない（400）", status_code == 400, status_code)
    status_code, _location, _page_html = post_org(org_edit_path_a, {"action": "roles_save", "dept_id": str(department_a_id)})
    check("管理者は確認者をまとめて変えられない（400）", status_code == 400, status_code)

    # ---- K-025 班ごとの課を作る（課長） ----
    status_code, location, _page_html = save_department_a("team")
    check("課長が課を「班ごと」にできる", status_code == 303 and database.execute(
        "SELECT review_scope FROM departments WHERE id = ?", (department_a_id,)).fetchone()[0] == "team", (status_code, location))
    for team_name in ["山田班", "佐藤班"]:
        post_department_a({"action": "team_add", "team_name": team_name})
    yamada_team_id, sato_team_id = team_id_of("山田班"), team_id_of("佐藤班")
    check("課長が班を足せる", yamada_team_id > 0 and sato_team_id > 0, (yamada_team_id, sato_team_id))
    post_department_a({"action": "team_add", "team_name": "山田班"})
    check("同じ名前の班は足せない", database.execute("SELECT COUNT(*) FROM department_teams WHERE name = '山田班'").fetchone()[0] == 1)
    status_code, location, _page_html = save_members_a({"dairi_a": yamada_team_id, "leader_a": sato_team_id},
                                                       {"ippan_a1": yamada_team_id, "ippan_a2": sato_team_id}, {"dairi_a", "leader_a"})
    reviewer_team_by_login_id = {login_id: database.execute("SELECT team_id FROM department_reviewers WHERE user_id = ? AND department_id = ?",
                                                            (user_id_of(login_id), department_a_id)).fetchone()[0]
                                 for login_id in ["kacho_a", "dairi_a", "leader_a"]}
    check("課長が確認者に担当の班を付けられる（課長は班を持たない）",
          reviewer_team_by_login_id == {"kacho_a": None, "dairi_a": yamada_team_id, "leader_a": sato_team_id}, reviewer_team_by_login_id)
    check("課長が課員の班を決められる", database.execute("SELECT team_id FROM users WHERE id = ?", (user_id_of("ippan_a1"),)).fetchone()[0] == yamada_team_id)
    status_code, _location, _page_html = post_department_a({"action": "members_save", "member_team[%d]" % user_id_of("ippan_b1"): str(yamada_team_id)})
    check("ほかの課の人の班は決められない（400）", status_code == 400 and database.execute(
        "SELECT team_id FROM users WHERE id = ?", (user_id_of("ippan_b1"),)).fetchone()[0] is None, status_code)

    # 前提: 2 人が前月の報告を提出
    report_id_by_login_id = {}
    for login_id in ["ippan_a1", "ippan_a2"]:
        member_client = client_of(login_id)
        status_code, _location, edit_html = member_client.get("/reports/edit.php?period=" + previous_month)
        member_client.post("/reports/save.php", {
            "csrf_token": csrf(edit_html), "request_key": rkey(edit_html), "period": previous_month, "action": "submit",
            "b[0][project]": str(project_id_by_code["P-0001"]), "b[0][f][%d]" % field_id_by_code["phase"]: "build_unit",
            "b[0][f][%d]" % field_id_by_code["progress"]: "on_track", "b[0][f][%d]" % field_id_by_code["continuation"]: "continuing",
            "b[0][f][%d]" % field_id_by_code["system_type"]: "inventory"})
        report_id_by_login_id[login_id] = database.execute(
            "SELECT id FROM report_records WHERE user_id = ? AND period = ?", (user_id_of(login_id), previous_month)).fetchone()[0]
    report_a1_id, report_a2_id = report_id_by_login_id["ippan_a1"], report_id_by_login_id["ippan_a2"]

    check("班ごと: 課代（山田班）は自分の班の人の報告を開ける", status_of("dairi_a", report_a1_id) == 200)
    check("班ごと: 課代（山田班）は隣の班の人の報告を開けない（404）", status_of("dairi_a", report_a2_id) == 404)
    check("班ごと: リーダー（佐藤班）は自分の班の人の報告を開ける", status_of("leader_a", report_a2_id) == 200)
    check("班ごと: 課長は全員の報告を開ける", status_of("kacho_a", report_a1_id) == 200 and status_of("kacho_a", report_a2_id) == 200)
    status_code, _location, mine_html = client_of("ippan_a1").get("/reports/mine.php")
    check("班ごと: チェックの人数は担当の班の確認者と課長だけ（0/2）", "チェック 0/2" in mine_html)
    status_code, _location, status_html = client_of("dairi_a").get("/status/index.php?period=%s&dept=%d" % (previous_month, department_a_id))
    check("班ごと: 提出状況のリンクは担当の班の人だけ",
          "/reports/view.php?id=%d" % report_a1_id in status_html and "/reports/view.php?id=%d" % report_a2_id not in status_html)
    status_code, _location, compare_html = client_of("dairi_a").get("/status/compare.php?period=%s&dept=%d" % (previous_month, department_a_id))
    check("班ごと: 横並びは担当の班の人の報告だけ", "A一般1" in compare_html and "A一般2" not in compare_html)

    # ---- K-033 班を移ると、過去の報告も今の班が見る ----
    save_members_a({"dairi_a": yamada_team_id, "leader_a": sato_team_id}, {"ippan_a1": sato_team_id, "ippan_a2": sato_team_id}, {"dairi_a", "leader_a"})
    client_by_login_id.clear()   # 1 回の要求の中で覚えた権限が残らないよう、ログインし直す（サーバー側は要求ごと）
    check("班を移ると、前の班の課代は過去の報告を開けない", status_of("dairi_a", report_a1_id) == 404)
    check("班を移ると、今の班のリーダーが過去の報告を開ける", status_of("leader_a", report_a1_id) == 200)

    # ---- K-032 課を移った人の、移る前の報告 ----
    save_user_department("ippan_a2", department_b_id)
    check("課を移ると班は外れる（班は課長が決める）", database.execute("SELECT team_id FROM users WHERE id = ?", (user_id_of("ippan_a2"),)).fetchone()[0] is None)
    check("課を移った人の移る前の報告を、新しい課の課長が開ける", status_of("kacho_b", report_a2_id) == 200)
    check("前の課の課長も、引き続き開ける", status_of("kacho_a", report_a2_id) == 200)
    check("前の課の、担当の班の確認者は（今の班が違うので）開けない", status_of("leader_a", report_a2_id) == 404)

    # ---- 課全体に戻す ----
    save_department_a("department")
    check("「課全体」に戻すと、課代は課の全員の報告を開ける", status_of("dairi_a", report_a1_id) == 200)

    # ---- K-039 課代・リーダーの「チェックする／しない」（課長が決める） ----
    save_members_a({"dairi_a": None, "leader_a": None}, {"ippan_a1": None}, {"dairi_a"})
    check("課長がリーダーを「チェックしない」にできる",
          database.execute("SELECT does_check FROM department_reviewers WHERE user_id = ? AND department_id = ?", (user_id_of("leader_a"), department_a_id)).fetchone()[0] == 0)
    status_code, _location, mine_html = client_of("ippan_a1").get("/reports/mine.php")
    check("チェックしない人は「チェック n/m」の m に数えない（課長と課代の 0/2）", "チェック 0/2" in mine_html, mine_html.count("チェック"))
    status_code, _location, leader_view_html = client_of("leader_a").get("/reports/view.php?id=%d" % report_a1_id)
    check("チェックしない人も報告を見られ、コメントできるが、チェックのボタンは出ない",
          status_code == 200 and 'value="check"' not in leader_view_html and 'value="comment"' in leader_view_html, status_code)
    leader_check_status, _location, _page_html = client_of("leader_a").post("/reports/act.php", {
        "csrf_token": csrf(leader_view_html), "request_key": rkey(leader_view_html) or os.urandom(16).hex(), "report_id": str(report_a1_id), "action": "check"})
    check("チェックしない人のチェックは受け付けない", database.execute(
        "SELECT COUNT(*) FROM report_reviews WHERE report_id = ? AND user_id = ?", (report_a1_id, user_id_of("leader_a"))).fetchone()[0] == 0, leader_check_status)
    status_code, _location, leader_home_html = client_of("leader_a").get("/index.php")
    check("チェックしない人のホームには「チェックをしない設定」と出し、未チェックを数えない",
          "チェックをしない設定です" in leader_home_html and "未チェック <strong>" not in leader_home_html)
    status_code, _location, leader_status_html = client_of("leader_a").get("/status/index.php?period=%s&dept=%d" % (previous_month, department_a_id))
    check("チェックしない人の提出状況には「未チェック」とまとめてチェックを出さない",
          "&amp;seq=1" not in leader_status_html and "bulk-check-form" not in leader_status_html)   # 「未チェックを順に見る」のボタン（使い方の文にも同じ言葉がある）
    status_code, _location, member_table_html = client_of("kacho_a").get(department_manage_path_a)
    check("課の管理の表に「チェックする」と課長の「常にする」", 'name="does_check[%d]"' % user_id_of("leader_a") in member_table_html and "常にする" in member_table_html)
    save_members_a({"dairi_a": None, "leader_a": None}, {"ippan_a1": None}, {"dairi_a", "leader_a"})

    # ---- 班を無効にすると、所属と担当が外れる ----
    save_members_a({"dairi_a": None, "leader_a": sato_team_id}, {"ippan_a1": sato_team_id}, {"dairi_a", "leader_a"})
    post_department_a({"action": "team_update", "team_id": str(sato_team_id), "team_name": "佐藤班"})
    check("班を無効にすると、所属の人と担当の確認者の班が外れる",
          database.execute("SELECT COUNT(*) FROM users WHERE team_id = ?", (sato_team_id,)).fetchone()[0] == 0
          and database.execute("SELECT COUNT(*) FROM department_reviewers WHERE team_id = ?", (sato_team_id,)).fetchone()[0] == 0)

    # ---- K-006 メッセージは管理担当の課の課長だけ ----
    check("管理担当の課（A課）の課長はメッセージを開ける", client_of("kacho_a").get("/admin/messages.php")[0] == 200)
    check("ほかの課の課長はメッセージを開けない（403）", client_of("kacho_b").get("/admin/messages.php")[0] == 403)
    check("管理担当の課が記録されている", database.execute(
        "SELECT value FROM app_state WHERE name = 'management_department_id'").fetchone()[0] == str(department_a_id))

    # ---- K-031 課長がいない課を、管理者のホームで知らせる ----
    status_code, _location, home_html = admin_client.get("/index.php")
    check("課長がいる間は知らせない", "課長がいない課があります" not in home_html)
    status_code, _location, _page_html = post_org("/admin/org.php?tab=departments&edit=%d" % department_b_id, {"action": "final_save", "dept_id": str(department_b_id), "final_user_id": ""})
    check("管理者が課長を外せる", database.execute("SELECT COUNT(*) FROM department_reviewers WHERE department_id = ? AND is_final = 1", (department_b_id,)).fetchone()[0] == 0, status_code)
    status_code, _location, home_html = admin_client.get("/index.php")
    check("課長がいない課を、管理者のホームで知らせる", "課長がいない課があります" in home_html and "B課" in home_html)
    status_code, _location, home_html = client_of("ippan_a1").get("/index.php")
    check("管理者でない人のホームには出さない", "課長がいない課があります" not in home_html)

    # ================================================================
    # K-034 組織の一括の入れ替え（CSV）
    # ================================================================
    status_code, _location, _csv_text = admin_client.get("/admin/org.php?tab=bulk&csv=1")
    exported_csv_text = admin_client.last_raw.decode("utf-8-sig")
    check("今の組織を CSV に出力できる（社外秘の見出し・列）", status_code == 200 and exported_csv_text.startswith('"社外秘"')
          and "ログイン ID,表示名,所属の課,班,役割,担当の班,項目管理者,有効" in exported_csv_text.replace('"', ''), status_code)
    check("管理者でない人は出力できない（403）", client_of("kacho_a").get("/admin/org.php?tab=bulk&csv=1")[0] == 403)
    exported_row_list = list(csv.reader(io.StringIO(exported_csv_text)))
    header_index = next(row_index for row_index, csv_row in enumerate(exported_row_list) if csv_row[:1] == ["ログイン ID"])

    def make_csv(change_by_login_id, extra_row_list=()):
        """出力した CSV の、指定した人の列を変えて、取り込む CSV を作る"""
        output_buffer = io.StringIO()
        csv_writer = csv.writer(output_buffer)
        for row_index, csv_row in enumerate(exported_row_list):
            if row_index > header_index and csv_row and csv_row[0] in change_by_login_id:
                csv_row = list(csv_row)
                for column_index, new_value in change_by_login_id[csv_row[0]].items():
                    csv_row[column_index] = new_value
            csv_writer.writerow(csv_row)
        for extra_row in extra_row_list:
            csv_writer.writerow(extra_row)
        return ("\ufeff" + output_buffer.getvalue()).encode("utf-8")

    def upload_csv(csv_bytes):
        status_code, _location, bulk_html = admin_client.get("/admin/org.php?tab=bulk")
        return admin_client.post_file("/admin/org.php?tab=bulk", {"csrf_token": csrf(bulk_html), "action": "bulk_preview"}, "org_csv", "org.csv", csv_bytes)

    # 列の番号: 0 ログイン ID、2 所属の課、3 班、4 役割、5 担当の班、6 項目管理者、7 有効
    status_code, _location, preview_html = upload_csv(make_csv({"kacho_b": {2: "B課", 4: "課長"}, "dairi_a": {4: "課長", 5: ""}},
                                                               [["no_such_user", "x", "A課", "", "", "", "", "1"]]))
    check("誤りがあれば知らせ、反映のボタンを出さない（登録されていない ID・課長が 2 人）",
          status_code == 200 and "登録されていないログイン ID" in preview_html and "課長が 2 人以上" in preview_html and 'value="bulk_apply"' not in preview_html, status_code)
    status_code, _location, preview_html = upload_csv(make_csv({"kacho_b": {2: "B課", 4: "課長"}, "ippan_b1": {2: "A課", 3: "鈴木班"}}))
    check("取り込むと、変わる内容と新しく作る班を出す（まだ反映しない）",
          'value="bulk_apply"' in preview_html and "鈴木班" in preview_html and "B課 → A課" in preview_html
          and database.execute("SELECT department_id FROM users WHERE id = ?", (user_id_of("ippan_b1"),)).fetchone()[0] == department_b_id)
    plan_token = preview_html.split('name="plan_token" value="')[1].split('"')[0] if 'name="plan_token"' in preview_html else ""
    status_code, _location, _page_html = admin_client.post("/admin/org.php?tab=bulk", {
        "csrf_token": csrf(preview_html), "request_key": os.urandom(16).hex(), "action": "bulk_apply", "plan_token": "0" * 32})
    check("違う計画の番号では反映しない", database.execute("SELECT department_id FROM users WHERE id = ?", (user_id_of("ippan_b1"),)).fetchone()[0] == department_b_id)
    status_code, location, _page_html = admin_client.post("/admin/org.php?tab=bulk", {
        "csrf_token": csrf(preview_html), "request_key": os.urandom(16).hex(), "action": "bulk_apply", "plan_token": plan_token})
    b1_row = database.execute("SELECT u.department_id, t.name FROM users u LEFT JOIN department_teams t ON t.id = u.team_id WHERE u.id = ?",
                              (user_id_of("ippan_b1"),)).fetchone()
    check("反映すると、所属と班が変わり、無い班は作られる", status_code == 303 and b1_row == (department_a_id, "鈴木班"), (status_code, location, b1_row))
    check("反映すると、役割も変わる（B課の課長）", database.execute(
        "SELECT is_final FROM department_reviewers WHERE user_id = ? AND department_id = ?", (user_id_of("kacho_b"), department_b_id)).fetchone() == (1,))
    check("CSV に載っていても変わらない人はそのまま（A課の課長）", database.execute(
        "SELECT is_final FROM department_reviewers WHERE user_id = ? AND department_id = ?", (user_id_of("kacho_a"), department_a_id)).fetchone() == (1,))
    check("反映が記録に残る", database.execute("SELECT 1 FROM access_logs WHERE target LIKE 'organization:bulk_apply:%'").fetchone() is not None)

    return summary()


if __name__ == "__main__":
    sys.exit(main())
