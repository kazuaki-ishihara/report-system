#!/usr/bin/env python3
"""兼務（G-23・G-14 §6.8。2026-11-26 指示）の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

- 課長が「課の管理」で、ほかの課の人を兼務の確認者に足せる（この課の人・もう確認者の人・専門職は足せない）。表で外せる
- 組織の CSV に「兼務」の列（「課の名前:役割[:担当のライン]」を「; 」でつなぐ）を出し、直して取り込むと兼務が変わる
- 兼務の課長も「課長は課に 1 人」に数える（兼務の列が無い前の形の CSV でも数え、反映の時に 500 にしない）
- 課のない部長が、課長を兼務できる（課の管理を開ける・CSV に出して取り込み直しても変わらない）

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_concurrent.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import csv
import io
import json
import os
import re
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, csrf, login, section, summary  # noqa: E402

COLUMNS = ["ログイン ID", "表示名", "所属の課", "ライン", "役割", "担当のライン", "項目管理者", "有効", "兼務"]


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

    def user_id_of(login_id):
        return user_info_by_login_id[login_id]["id"]

    client_by_login_id = {}

    def client_of(login_id):
        if login_id not in client_by_login_id:
            client_by_login_id[login_id] = login(arguments.base, login_id, user_info_by_login_id[login_id])
        return client_by_login_id[login_id]

    def post_with(client, path, form_values):
        _status_code, _location, page_html = client.get(path)
        return client.post(path, dict(form_values, csrf_token=csrf(page_html), request_key=os.urandom(16).hex()))

    def reviewer_row(login_id, department_id):
        return database.execute("SELECT is_final, team_id, does_check FROM department_reviewers WHERE user_id = ? AND department_id = ?",
                                (user_id_of(login_id), department_id)).fetchone()

    def final_user_ids(department_id):
        return [row[0] for row in database.execute("SELECT user_id FROM department_reviewers WHERE department_id = ? AND is_final = 1", (department_id,))]

    admin_client = client_of("admin01")
    kacho_a_client = client_of("kacho_a")
    manage_path_a = "/admin/department.php?dept=%d" % department_a_id

    # ================================================================
    section("G-23", "兼務の確認者を足す（課の管理）")
    _status_code, _location, manage_html = kacho_a_client.get(manage_path_a)
    candidate_html = re.search(r'<select id="concurrent_user_id".*?</select>', manage_html, re.S)
    candidate_html = candidate_html.group(0) if candidate_html else ""
    check("兼務の確認者に足す欄があり、ほかの課の人が候補に出る（札と候補の一覧）",
          'class="tag-picker"' in candidate_html and 'value="%d"' % user_id_of("ippan_b1") in candidate_html and 'value="%d"' % user_id_of("kacho_b") in candidate_html
          and '<optgroup label="B課">' in candidate_html)
    check("この課の人・もう確認者の人は、兼務の候補に出ない",
          all('value="%d"' % user_id_of(login_id) not in candidate_html for login_id in ("ippan_a1", "dairi_a", "kacho_a")))
    status_code, _location, page_html = post_with(kacho_a_client, manage_path_a, {"action": "concurrent_add", "concurrent_user_id": ""})
    check("選ばずに足すと、選ぶよう知らせる", status_code == 200 and "兼務させる人を選んでください" in page_html, status_code)
    status_code, _location, _page_html = post_with(kacho_a_client, manage_path_a, {"action": "concurrent_add", "concurrent_user_id": str(user_id_of("ippan_b1"))})
    check("ほかの課の人を兼務の確認者に足せる（チェックは役職で決まる・ラインなし）", status_code == 303 and reviewer_row("ippan_b1", department_a_id) == (0, None, 0),
          (status_code, reviewer_row("ippan_b1", department_a_id)))
    check("兼務の確認者を足したことが記録に残る", database.execute(
        "SELECT COUNT(*) FROM access_logs WHERE target = ?", ("department:%d:concurrent_add:%d" % (department_a_id, user_id_of("ippan_b1")),)).fetchone()[0] == 1)
    check("所属の課はそのまま（兼務）", database.execute("SELECT department_id FROM users WHERE id = ?", (user_id_of("ippan_b1"),)).fetchone()[0] == department_b_id)
    _status_code, _location, manage_html = kacho_a_client.get(manage_path_a)
    member_table_html = re.search(r'<table class="table" id="department-member-table">.*?</table>', manage_html, re.S).group(0)
    check("足した人は、確認者と課員の表に「ほかの課」の札つきで出て、候補からは消える",
          "B一般1" in member_table_html and '<span class="tag">ほかの課</span>' in member_table_html
          and 'value="%d"' % user_id_of("ippan_b1") not in (re.search(r'<select id="concurrent_user_id".*?</select>', manage_html, re.S) or re.search("$^", "")).group(0))
    status_code, _location, page_html = post_with(kacho_a_client, manage_path_a, {"action": "concurrent_add", "concurrent_user_id": str(user_id_of("ippan_b1"))})
    check("もう確認者の人を足そうとすると、そう知らせる", status_code == 200 and "もうこの課の確認者です" in page_html, status_code)
    status_code, _location, _page_html = post_with(kacho_a_client, manage_path_a, {"action": "concurrent_add", "concurrent_user_id": str(user_id_of("ippan_a1"))})
    check("この課の人は兼務として足せない（400）", status_code == 400 and reviewer_row("ippan_a1", department_a_id) is None, status_code)
    check("ほかの課の課長は、この課の「課の管理」を開けない（403）", client_of("kacho_b").get(manage_path_a)[0] == 403)

    # ================================================================
    section("G-14", "組織の CSV の「兼務」の列")

    def export_rows():
        admin_client.get("/admin/org.php?tab=bulk&csv=1")
        row_list = list(csv.reader(io.StringIO(admin_client.last_raw.decode("utf-8-sig"))))
        header_index = next(row_index for row_index, csv_row in enumerate(row_list) if csv_row[:1] == ["ログイン ID"])
        return row_list, header_index

    def build_csv(row_list, header_index, change_by_login_id, drop_concurrent_column=False):
        output_buffer = io.StringIO()
        csv_writer = csv.writer(output_buffer)
        for row_index, csv_row in enumerate(row_list):
            csv_row = list(csv_row)
            if row_index > header_index and csv_row and csv_row[0] in change_by_login_id:
                for column_name, new_value in change_by_login_id[csv_row[0]].items():
                    csv_row[COLUMNS.index(column_name)] = new_value
            if row_index >= header_index and drop_concurrent_column:
                csv_row = csv_row[:8]
            csv_writer.writerow(csv_row)
        return ("﻿" + output_buffer.getvalue()).encode("utf-8")

    def upload(csv_bytes):
        _status_code, _location, bulk_html = admin_client.get("/admin/org.php?tab=bulk")
        return admin_client.post_file("/admin/org.php?tab=bulk", {"csrf_token": csrf(bulk_html), "action": "bulk_preview"}, "org_csv", "org.csv", csv_bytes)

    def apply_plan(preview_html):
        plan_token = preview_html.split('name="plan_token" value="')[1].split('"')[0] if 'name="plan_token"' in preview_html else ""
        return admin_client.post("/admin/org.php?tab=bulk", {"csrf_token": csrf(preview_html), "request_key": os.urandom(16).hex(), "action": "bulk_apply", "plan_token": plan_token})

    row_list, header_index = export_rows()
    row_by_login_id = {csv_row[0]: csv_row for csv_row in row_list[header_index + 1:] if csv_row}
    check("CSV に「兼務」の列があり、兼務の役割が出る（所属の課の役割は出さない）",
          row_list[header_index] == COLUMNS and row_by_login_id["ippan_b1"][8] == "A課:確認者" and row_by_login_id["kacho_a"][8] == "", row_by_login_id.get("ippan_b1"))

    status_code, _location, preview_html = upload(build_csv(row_list, header_index, {"ippan_b1": {"兼務": "A課:確認者:兼務ライン"}}))
    check("兼務の担当のラインを変えると、変わる内容と新しく作るラインを出す",
          "兼務: A課:確認者 → A課:確認者:兼務ライン" in preview_html and "兼務ライン" in preview_html and 'value="bulk_apply"' in preview_html, status_code)
    database.execute("UPDATE department_reviewers SET does_check = 1 WHERE user_id = ? AND department_id = ?", (user_id_of("ippan_b1"), department_a_id))
    status_code, _location, _page_html = apply_plan(preview_html)
    concurrent_row = database.execute(
        "SELECT r.is_final, t.name, r.does_check FROM department_reviewers r LEFT JOIN department_teams t ON t.id = r.team_id WHERE r.user_id = ? AND r.department_id = ?",
        (user_id_of("ippan_b1"), department_a_id)).fetchone()
    check("反映すると、兼務の担当のラインが変わり、チェックするかどうかは引き継ぐ", status_code == 303 and concurrent_row == (0, "兼務ライン", 1), (status_code, concurrent_row))

    # 課長の交代を兼務で: A課の課長が B課の課長を兼ねる
    row_list, header_index = export_rows()
    status_code, _location, preview_html = upload(build_csv(row_list, header_index, {"kacho_a": {"兼務": "B課:課長"}}))
    check("兼務の課長も「課長は課に 1 人」に数える（B課の課長がいるまま兼務の課長を足すと誤り）",
          "B課 の課長が 2 人以上になります" in preview_html and 'value="bulk_apply"' not in preview_html, status_code)
    status_code, _location, preview_html = upload(build_csv(row_list, header_index, {"kacho_b": {"役割": ""}, "kacho_a": {"兼務": "B課:課長"}}))
    status_code, _location, _page_html = apply_plan(preview_html)
    check("B課の課長を外し、A課の課長に B課の課長を兼務させられる", status_code == 303 and final_user_ids(department_b_id) == [user_id_of("kacho_a")]
          and final_user_ids(department_a_id) == [user_id_of("kacho_a")], (status_code, final_user_ids(department_b_id)))
    check("兼務の課長は、兼務の課の「課の管理」を開ける", kacho_a_client.get("/admin/department.php?dept=%d" % department_b_id)[0] == 200)
    row_list, header_index = export_rows()
    status_code, _location, preview_html = upload(build_csv(row_list, header_index, {"kacho_b": {"役割": "課長"}}, drop_concurrent_column=True))
    check("「兼務」の列が無い前の形の CSV でも、今の兼務の課長を数える（反映の時に 500 にしない）",
          "B課 の課長が 2 人以上になります" in preview_html and "兼務の列が無い CSV のため、兼務の役割はそのまま" in preview_html and 'value="bulk_apply"' not in preview_html, status_code)
    for bad_text, expected_text in [("A課:課長", "所属の課（A課）の役割は、兼務ではなく「役割」の列に入れてください"),
                                    ("無い課:確認者", "兼務は「課の名前:課長」か"),
                                    ("B課:課長:ライン", "担当のラインは「確認者」のときだけ"),
                                    ("B課:課長; B課:確認者", "兼務に同じ課が 2 回あります")]:
        status_code, _location, preview_html = upload(build_csv(row_list, header_index, {"kacho_a": {"兼務": bad_text}}))
        check("兼務の誤り（%s）を知らせ、反映のボタンを出さない" % bad_text, expected_text in preview_html and 'value="bulk_apply"' not in preview_html, status_code)
    status_code, _location, preview_html = upload(build_csv(row_list, header_index, {"kacho_a": {"兼務": "B課：課長"}}))
    check("全角の「：」「；」でも読む", "B課 の課長が" not in preview_html and "兼務は「課の名前" not in preview_html and "変わる人はいません" in preview_html, status_code)
    # 元に戻す（B課の課長を kacho_b に。kacho_a の兼務は外す）
    status_code, _location, preview_html = upload(build_csv(row_list, header_index, {"kacho_b": {"役割": "課長"}, "kacho_a": {"兼務": ""}}))
    status_code, _location, _page_html = apply_plan(preview_html)
    check("兼務を外して元の課長に戻せる", status_code == 303 and final_user_ids(department_b_id) == [user_id_of("kacho_b")]
          and reviewer_row("kacho_a", department_b_id) is None, (status_code, final_user_ids(department_b_id)))

    # ================================================================
    section("G-23", "兼務の確認者を外す（課の管理の表）")
    form_values = {"action": "members_save"}
    for reviewer_user_id, team_id, does_check in database.execute(
            "SELECT user_id, team_id, does_check FROM department_reviewers WHERE department_id = ? AND is_final = 0 AND user_id <> ?", (department_a_id, user_id_of("ippan_b1"))):
        form_values["reviewer[%d]" % reviewer_user_id] = "1"
        form_values["reviewer_team[%d]" % reviewer_user_id] = "" if team_id is None else str(team_id)
        if does_check:
            form_values["does_check[%d]" % reviewer_user_id] = "1"
    for member_user_id, member_team_id in database.execute("SELECT id, team_id FROM users WHERE department_id = ? AND is_active = 1", (department_a_id,)):
        form_values["member_team[%d]" % member_user_id] = "" if member_team_id is None else str(member_team_id)
    team_by_member_before = database.execute("SELECT id, team_id FROM users WHERE department_id = ? ORDER BY id", (department_a_id,)).fetchall()
    for admin_user_id, in database.execute("SELECT user_id FROM department_admins WHERE department_id = ?", (department_a_id,)):
        form_values["field_admin[%d]" % admin_user_id] = "1"
    reviewer_count_before = database.execute("SELECT COUNT(*) FROM department_reviewers WHERE department_id = ?", (department_a_id,)).fetchone()[0]
    status_code, _location, _page_html = post_with(kacho_a_client, manage_path_a, form_values)
    check("表で「確認者」の印を外すと、兼務の確認者が外れる（ほかの確認者はそのまま）",
          status_code == 303 and reviewer_row("ippan_b1", department_a_id) is None
          and database.execute("SELECT COUNT(*) FROM department_reviewers WHERE department_id = ?", (department_a_id,)).fetchone()[0] == reviewer_count_before - 1
          and database.execute("SELECT id, team_id FROM users WHERE department_id = ? ORDER BY id", (department_a_id,)).fetchall() == team_by_member_before, status_code)

    # ================================================================
    section("G-14", "課のない部長が課長を兼務する")
    database.execute("UPDATE users SET department_id = NULL, team_id = NULL, role_title = '部長' WHERE id = ?", (user_id_of("kacho_b"),))
    _status_code, _location, manage_html = client_of("kacho_b").get("/admin/department.php?dept=%d" % department_b_id)
    check("課のない部長が課長の課の「課の管理」を開け、課員が出る", _status_code == 200 and "B一般1" in manage_html, _status_code)
    _status_code, _location, org_edit_html = admin_client.get("/admin/org.php?tab=departments&edit=%d" % department_a_id)
    final_select_html = re.search(r'<select id="final_user_id".*?</select>', org_edit_html, re.S).group(0)
    other_final_group = re.search(r'<optgroup label="ほかの課の課長・部長">(.*?)</optgroup>', final_select_html, re.S)
    check("課長の候補で、課のない部長は「ほかの課の課長・部長」に出る（課なし）",
          other_final_group is not None and 'value="%d"' % user_id_of("kacho_b") in other_final_group.group(1) and "課なし・部長" in other_final_group.group(1))
    row_list, header_index = export_rows()
    row_by_login_id = {csv_row[0]: csv_row for csv_row in row_list[header_index + 1:] if csv_row}
    check("CSV では、課のない部長の課長は「兼務」に出る", (row_by_login_id["kacho_b"][2], row_by_login_id["kacho_b"][4], row_by_login_id["kacho_b"][8]) == ("", "", "B課:課長"),
          row_by_login_id["kacho_b"])
    status_code, _location, preview_html = upload(build_csv(row_list, header_index, {}))
    check("出した CSV をそのまま取り込むと、変わる人はいない（誤りも出ない）", "変わる人はいません" in preview_html and "flash-error" not in preview_html, status_code)
    database.execute("UPDATE users SET department_id = ?, role_title = '課長' WHERE id = ?", (department_b_id, user_id_of("kacho_b")))

    return summary()


if __name__ == "__main__":
    sys.exit(main())
