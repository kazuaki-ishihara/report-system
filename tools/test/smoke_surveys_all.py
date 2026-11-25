#!/usr/bin/env python3
"""全課共通の臨時の調査の確認（K-066。ローカル検証環境専用。dev_seed.php の直後に実行する）。

- システム管理者・補佐が、課をまたいで同じ質問の調査（全課共通）を作り、公開できる
- 対象は有効な課に所属する全員。答え方は選択式とチェックだけ。「限定」・月次の項目は使えない
- 課長は全課共通の調査を管理できない（一覧に出ない・開けない）
- 結果は全課の集計と課ごとのまとめだけ（個人の回答・未回答者の名前は出さない。回答が少ない課は内訳を出さない）
- 出力（印刷・CSV・xlsx）

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_surveys_all.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import csv
import datetime
import io
import json
import os
import re
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, csrf, login, rkey, section, summary  # noqa: E402


def main():
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--base", default="http://127.0.0.1:8000")
    argument_parser.add_argument("--db", required=True)
    arguments = argument_parser.parse_args()
    here = os.path.dirname(os.path.abspath(__file__))
    seed = json.load(open(os.path.join(here, "output_dev_users.json"), encoding="utf-8"))
    user_info_by_login_id = seed["users"]
    database = sqlite3.connect("file:%s?mode=ro" % arguments.db, uri=True)
    due_date_text = (datetime.date.today() + datetime.timedelta(days=5)).isoformat()

    admin_client = login(arguments.base, "admin01", user_info_by_login_id["admin01"])
    kacho_client = login(arguments.base, "kacho_a", user_info_by_login_id["kacho_a"])

    section("G-11", "全課共通の臨時の調査を作る（システム管理者）")
    status_code, _, home_html = admin_client.get("/index.php")
    check("管理者のメニューに「臨時の調査」が出る", "/surveys/manage.php" in home_html, status_code)
    status_code, _, edit_html = admin_client.get("/surveys/survey_edit.php")
    check("管理者は作成の画面を開け、「全課共通」を選べる", status_code == 200 and 'value="all"' in edit_html, status_code)
    status_code, location, _ = admin_client.post("/surveys/survey_edit.php", {
        "csrf_token": csrf(edit_html), "request_key": rkey(edit_html), "action": "save_meta", "department_id": "all",
        "title": "全社 AI 利用状況", "requester": "人財管理部", "purpose": "全社の利用状況を知るため", "due_at": due_date_text,
        "result_visibility": "detail", "target_type": "users"})
    match = re.search(r"id=(\d+)", location or "")
    check("全課共通の調査を作れる", status_code == 303 and match is not None, (status_code, location))
    survey_id = int(match.group(1)) if match else 0
    survey_row = database.execute("SELECT department_id, result_visibility, target_type FROM adhoc_requests WHERE id = ?", (survey_id,)).fetchone()
    check("課は空（全課共通）で、結果の扱いは集計のみ・対象は全員に固定される", survey_row == (None, "summary", "all"), survey_row)

    def post_edit(form_values):
        _, _, page_html = admin_client.get("/surveys/survey_edit.php?id=%d" % survey_id)
        form_values = dict(form_values, csrf_token=csrf(page_html), request_key=rkey(page_html))
        return admin_client.post("/surveys/survey_edit.php?id=%d" % survey_id, form_values)

    status_code, _, _ = post_edit({"action": "add_custom", "q_label": "自由に書いて", "q_type": "multi_text", "q_options": "", "q_conf": "internal"})
    check("全課共通の調査には文の質問を足せない（400）", status_code == 400, status_code)
    status_code, _, _ = post_edit({"action": "add_custom", "q_label": "限定の質問", "q_type": "radio", "q_options": "a\nb", "q_conf": "restricted"})
    check("全課共通の調査には「限定」の質問を足せない（400）", status_code == 400, status_code)
    phase_field_id = database.execute("SELECT id FROM report_fields WHERE code = 'phase'").fetchone()[0]
    status_code, _, _ = post_edit({"action": "add_source", "field_id": str(phase_field_id)})
    check("全課共通の調査には月次の項目を使えない（400）", status_code == 400, status_code)
    post_edit({"action": "add_custom", "q_label": "AI を業務で使っていますか", "q_type": "radio", "q_options": "毎日\nときどき\n使わない", "q_conf": "internal", "q_required": "1"})
    post_edit({"action": "add_custom", "q_label": "何を使っていますか", "q_type": "multi_check", "q_options": "GPT\nGemini\nClaude", "q_conf": "internal"})
    post_edit({"action": "add_custom", "q_label": "研修を受けたい", "q_type": "checkbox", "q_options": "", "q_conf": "internal"})
    _, _, edit_html = admin_client.get("/surveys/survey_edit.php?id=%d" % survey_id)
    check("選択式の質問は足せる（2 問）", edit_html.count("<li>") >= 2 and "全課共通" in edit_html)
    check("全課共通の調査の画面には、月次の項目を使う欄と「限定」を出さない", "月次報告の項目を使う" not in edit_html and 'value="restricted"' not in edit_html)
    status_code, _, _ = post_edit({"action": "publish"})
    target_count = database.execute("SELECT COUNT(*) FROM adhoc_request_targets WHERE request_id = ?", (survey_id,)).fetchone()[0]
    expected_target_count = database.execute(
        "SELECT COUNT(*) FROM users u JOIN departments d ON d.id = u.department_id WHERE u.is_active = 1 AND d.is_active = 1").fetchone()[0]
    check("公開すると、有効な課に所属する全員が対象になる（課の無い管理者・補佐は入らない）", target_count == expected_target_count and target_count > 0, (target_count, expected_target_count))

    section("G-11", "全課共通の調査と課長")
    status_code, _, manage_html = kacho_client.get("/surveys/manage.php")
    check("課長の一覧には全課共通の調査が出ない", status_code == 200 and "全社 AI 利用状況" not in manage_html, status_code)
    check("課長は全課共通の調査を開けない（設定・結果は 403）",
          kacho_client.get("/surveys/survey_edit.php?id=%d" % survey_id)[0] == 403 and kacho_client.get("/surveys/result.php?id=%d" % survey_id)[0] == 403)
    status_code, _, manage_html = admin_client.get("/surveys/manage.php")
    check("管理者の一覧に「全課共通」として出る", status_code == 200 and "全社 AI 利用状況" in manage_html and "全課共通" in manage_html, status_code)

    section("G-07", "全課共通の調査に答える")
    question_id_list = [row[0] for row in database.execute("SELECT id FROM adhoc_request_fields WHERE request_id = ? ORDER BY sort_order, id", (survey_id,))]
    answer_plan = [("ippan_a1", "o1", ["o1", "o3"]), ("ippan_a2", "o1", ["o3"]), ("dairi_a", "o2", ["o2", "o3"]), ("ippan_b1", "o3", [])]
    for login_id, radio_code, check_code_list in answer_plan:
        answer_client = login(arguments.base, login_id, user_info_by_login_id[login_id])
        _, _, answer_home_html = answer_client.get("/index.php")
        _, _, answer_html = answer_client.get("/surveys/answer.php?id=%d" % survey_id)
        form_values = {"csrf_token": csrf(answer_html), "request_key": rkey(answer_html), "id": str(survey_id), "q[%d]" % question_id_list[0]: radio_code}
        if check_code_list:
            form_values["q[%d][]" % question_id_list[1]] = check_code_list
        if login_id == "ippan_a1":
            form_values["q[%d]" % question_id_list[2]] = "1"   # チェックは ippan_a1 だけ
        status_code, _, _ = answer_client.post("/surveys/answer.php?id=%d" % survey_id, form_values)
        check("%s のホームに出て、答えられる" % login_id, "全社 AI 利用状況" in answer_home_html and status_code == 303, status_code)

    section("G-11", "全課共通の調査の結果（全課の集計と課ごとのまとめ）")
    for login_id, client in (("admin01", admin_client), ("hosa01", login(arguments.base, "hosa01", user_info_by_login_id["hosa01"]))):
        status_code, _, result_html = client.get("/surveys/result.php?id=%d" % survey_id)
        check("%s は結果を開ける（全課の集計と課ごとの回答の状況）" % login_id,
              status_code == 200 and "課ごとの回答の状況" in result_html and "survey-bar" in result_html and "回答 4 人" in result_html, status_code)
        check("%s の結果に個人の名前を出さない（回答者・未回答者とも）" % login_id, "A一般1" not in result_html and "B課長" not in result_html and "A課長" not in result_html)
    status_code, _, result_html = admin_client.get("/surveys/result.php?id=%d" % survey_id)
    a_row = re.search(r"A課<span class=\"note\">（回答 3 人）</span></td>(.*?)</tr>", result_html, re.S)
    check("回答が 3 人の課（A課）は選択肢ごとの内訳を出す（毎日 2）", a_row is not None and ">2<span" in a_row.group(1), a_row.group(1)[:200] if a_row else None)
    b_row = re.search(r"B課<span class=\"note\">（回答 1 人）</span></td>(.*?)</tr>", result_html, re.S)
    check("回答が少ない課（B課・1 人）も内訳を出す（使わない 1・100%）", b_row is not None and ">1<span" in b_row.group(1) and "100%" in b_row.group(1), b_row.group(1)[:200] if b_row else None)
    status_code, _, detail_html = admin_client.get("/surveys/result.php?id=%d&detail=1" % survey_id)
    check("detail=1 でも個人の回答を出さない", "A一般1" not in detail_html)

    section("G-10", "全課共通の調査の出力")
    _, _, result_html = admin_client.get("/surveys/result.php?id=%d" % survey_id)
    admin_client.post("/surveys/result.php?id=%d" % survey_id, {"csrf_token": csrf(result_html), "request_key": rkey(result_html), "format": "csv"})
    csv_text = admin_client.last_raw.decode("utf-8-sig", "replace")
    csv_row_list = [row for row in csv.reader(io.StringIO(csv_text))]
    check("CSV に全課と課ごとの行・回答の状況が入り、名前は入らない",
          ["全課", "AI を業務で使っていますか", "毎日", "2", "50%"] in csv_row_list and ["A課", "何を使っていますか（複数回答）", "Claude", "3", "100%"] in csv_row_list
          and ["B課", "AI を業務で使っていますか", "使わない", "1", "100%"] in csv_row_list and ["A課", "5", "3", "60%"] in csv_row_list
          and "回答率 57%" in csv_text and "A一般1" not in csv_text, csv_row_list[:8])
    check("チェックの質問は「はい」と「チェックなし」で数える（未回答にしない）",
          ["全課", "研修を受けたい", "はい", "1", "25%"] in csv_row_list and ["全課", "研修を受けたい", "チェックなし", "3", "75%"] in csv_row_list,
          [row for row in csv_row_list if len(row) > 1 and row[1] == "研修を受けたい"])
    _, _, result_html = admin_client.get("/surveys/result.php?id=%d" % survey_id)
    status_code, _, print_html = admin_client.post("/surveys/result.php?id=%d" % survey_id, {"csrf_token": csrf(result_html), "request_key": rkey(result_html), "format": "print"})
    check("印刷用の画面に全課の集計と課ごとのまとめが出る", status_code == 200 and "課ごとの回答の状況" in print_html and "社外秘" in print_html, status_code)
    export_log_count = database.execute("SELECT COUNT(*) FROM access_logs WHERE action = 'export' AND target LIKE ?", ("export:survey;id=%d;%%scope=all_departments" % survey_id,)).fetchone()[0]
    check("出力が記録に残る", export_log_count == 2, export_log_count)
    _, _, result_html = admin_client.get("/surveys/result.php?id=%d" % survey_id)
    status_code, _, _ = admin_client.post("/surveys/result.php?id=%d" % survey_id, {"csrf_token": csrf(result_html), "request_key": rkey(result_html), "format": "pdf"})
    check("知らない形式は 400", status_code == 400, status_code)

    section("G-11", "全課共通の調査の結果を見られる人（依頼元の担当・部長以上。K-067）")
    other_client = login(arguments.base, "ippan_a2", user_info_by_login_id["ippan_a2"])
    check("指定されていない人は、結果も一覧も開けない（403）",
          other_client.get("/surveys/result.php?id=%d" % survey_id)[0] == 403 and other_client.get("/surveys/results.php")[0] == 403)
    viewer_user_id = user_info_by_login_id["kacho_b"]["id"]
    status_code, location, _ = post_edit({"action": "save_viewers", "viewers[]": [str(viewer_user_id)]})
    viewer_row_list = database.execute("SELECT user_id FROM adhoc_request_viewers WHERE request_id = ?", (survey_id,)).fetchall()
    check("管理者が「結果を見られる人」を指定できる（公開の後でも）", status_code == 303 and viewer_row_list == [(viewer_user_id,)], (status_code, viewer_row_list))
    status_code, _, _ = post_edit({"action": "save_viewers", "viewers[]": ["999999"]})
    check("候補にない人は指定できない（400）", status_code == 400, status_code)
    viewer_client = login(arguments.base, "kacho_b", user_info_by_login_id["kacho_b"])
    _, _, viewer_home_html = viewer_client.get("/index.php")
    check("指定された人のメニューに「調査の結果」が出る", "/surveys/results.php" in viewer_home_html)
    status_code, _, results_html = viewer_client.get("/surveys/results.php")
    check("指定された人の「調査の結果」に、その調査が出る", status_code == 200 and "全社 AI 利用状況" in results_html, status_code)
    status_code, _, viewer_result_html = viewer_client.get("/surveys/result.php?id=%d" % survey_id)
    check("指定された人は結果を開け、名前は出ない・設定へのリンクは出ない",
          status_code == 200 and "課ごとの回答の状況" in viewer_result_html and "A一般1" not in viewer_result_html and "/surveys/survey_edit.php" not in viewer_result_html, status_code)
    check("指定された人でも、調査の設定は開けない（403）", viewer_client.get("/surveys/survey_edit.php?id=%d" % survey_id)[0] == 403)
    viewer_client.post("/surveys/result.php?id=%d" % survey_id, {"csrf_token": csrf(viewer_result_html), "request_key": rkey(viewer_result_html), "format": "csv"})
    check("指定された人も CSV を出力できる", "全課" in viewer_client.last_raw.decode("utf-8-sig", "replace"))

    executive_login_id = "leader_a"
    executive_user_id = user_info_by_login_id[executive_login_id]["id"]
    executive_client = login(arguments.base, executive_login_id, user_info_by_login_id[executive_login_id])
    check("印を付ける前の人は結果を開けない（403）", executive_client.get("/surveys/result.php?id=%d" % survey_id)[0] == 403)
    _, _, user_edit_html = admin_client.get("/admin/user_edit.php?id=%d" % executive_user_id)
    check("ユーザーの編集に「部長以上」の印がある", 'name="is_executive"' in user_edit_html)
    department_a_id = database.execute("SELECT department_id FROM users WHERE id = ?", (executive_user_id,)).fetchone()[0]
    status_code, _, _ = admin_client.post("/admin/user_edit.php?id=%d" % executive_user_id, {
        "csrf_token": csrf(user_edit_html), "request_key": rkey(user_edit_html), "action": "save", "display_name": "Aリーダー", "role_title": "主任",
        "department_id": str(department_a_id), "is_active": "1", "is_executive": "1"})
    executive_flag = database.execute("SELECT is_executive FROM users WHERE id = ?", (executive_user_id,)).fetchone()[0]
    check("管理者が「部長以上」の印を付けられ、記録に残る",
          status_code == 303 and executive_flag == 1
          and database.execute("SELECT COUNT(*) FROM access_logs WHERE target = ?", ("user:%d:executive_on" % executive_user_id,)).fetchone()[0] == 1, (status_code, executive_flag))
    status_code, _, executive_results_html = executive_client.get("/surveys/results.php")
    check("部長以上の人は、指定されていなくても「調査の結果」に出て、結果を開ける",
          status_code == 200 and "全社 AI 利用状況" in executive_results_html and executive_client.get("/surveys/result.php?id=%d" % survey_id)[0] == 200, status_code)
    # 課に所属しない部長以上（ふつうは課がない）: ホームに「所属の課が設定されていません」のエラーを出さず、入口を出す
    writable_database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)
    writable_database.execute("UPDATE users SET department_id = NULL, team_id = NULL WHERE id = ?", (executive_user_id,))
    writable_database.close()
    _, _, executive_home_html = executive_client.get("/index.php")
    check("課のない部長以上のホームは、エラーではなく「見られるもの」と「調査の結果」の入口",
          "所属の課が設定されていません" not in executive_home_html and "見られるもの" in executive_home_html and "/surveys/results.php" in executive_home_html)

    # ---- 人財管理者（K-071）: 全課共通の調査を作り、結果（集計）を見られる。ほかの管理・課の調査・個人の回答は見られない ----
    section("G-11", "人財管理者（全課共通の調査を作り、結果を見る。K-071）")
    hr_login_id = "ippan_b1"
    hr_user_id = user_info_by_login_id[hr_login_id]["id"]
    hr_client = login(arguments.base, hr_login_id, user_info_by_login_id[hr_login_id])
    check("印を付ける前の人は、一覧・作成・結果を開けない（403）",
          [hr_client.get(path)[0] for path in ("/surveys/manage.php", "/surveys/survey_edit.php", "/surveys/result.php?id=%d" % survey_id)] == [403, 403, 403])
    _, _, hr_home_html = hr_client.get("/index.php")
    check("印を付ける前の人のメニューに「臨時の調査」は出ない", "/surveys/manage.php" not in hr_home_html)

    def save_hr_flag(is_on):
        _, _, page_html = admin_client.get("/admin/user_edit.php?id=%d" % hr_user_id)
        form_values = {"csrf_token": csrf(page_html), "request_key": rkey(page_html), "action": "save", "display_name": "B一般1", "role_title": "一般",
                       "department_id": str(database.execute("SELECT department_id FROM users WHERE id = ?", (hr_user_id,)).fetchone()[0]), "is_active": "1"}
        if is_on:
            form_values["is_hr_manager"] = "1"
        status_code, _, _ = admin_client.post("/admin/user_edit.php?id=%d" % hr_user_id, form_values)
        return status_code, database.execute("SELECT is_hr_manager, is_admin, is_admin_deputy, is_executive FROM users WHERE id = ?", (hr_user_id,)).fetchone()

    def hr_log_count(suffix):
        return database.execute("SELECT COUNT(*) FROM access_logs WHERE target = ?", ("user:%d:%s" % (hr_user_id, suffix),)).fetchone()[0]

    status_code, flag_row = save_hr_flag(True)
    check("管理者が「人財管理者」の印を付けられ、ほかの印は付かず、記録に残る",
          status_code == 303 and flag_row == (1, 0, 0, 0) and hr_log_count("hr_manager_on") == 1, (status_code, flag_row))
    _, _, users_html = admin_client.get("/admin/org.php?tab=users")
    hr_row = re.search(r"<tr[^>]*>\s*<td>%s</td>.*?</tr>" % hr_login_id, users_html, re.S)
    check("ユーザーの一覧のその人の行に「人財管理者」と出る", hr_row is not None and "人財管理者" in hr_row.group(0) and users_html.count('class="tag">人財管理者<') == 1)
    _, _, hr_home_html = hr_client.get("/index.php")
    check("人財管理者のメニューに「臨時の調査」が出て、「調査の結果」は出ない", "/surveys/manage.php" in hr_home_html and "/surveys/results.php" not in hr_home_html)
    _, _, edit_html = hr_client.get("/surveys/survey_edit.php")
    department_select = re.search(r'<select[^>]*name="department_id".*?</select>', edit_html, re.S)
    department_value_list = re.findall(r'<option value="([^"]*)"', department_select.group(0)) if department_select else re.findall(r'name="department_id" value="([^"]*)"', edit_html)
    check("人財管理者が作れるのは全課共通だけ（課は選べない）", department_value_list == ["all"], department_value_list)
    meta_form = {"action": "save_meta", "title": "人財管理部の調査", "requester": "人財管理部", "purpose": "", "due_at": due_date_text,
                 "result_visibility": "detail", "target_type": "users"}
    department_a_id = seed["dept_a"]
    status_code, _, _ = hr_client.post("/surveys/survey_edit.php", dict(meta_form, csrf_token=csrf(edit_html), request_key=rkey(edit_html), department_id=str(department_a_id)))
    check("人財管理者でも、課の調査は作れない（403・DB に入らない）",
          status_code == 403 and database.execute("SELECT COUNT(*) FROM adhoc_requests WHERE title = '人財管理部の調査'").fetchone()[0] == 0, status_code)
    _, _, edit_html = hr_client.get("/surveys/survey_edit.php")
    status_code, location, _ = hr_client.post("/surveys/survey_edit.php", dict(meta_form, csrf_token=csrf(edit_html), request_key=rkey(edit_html), department_id="all"))
    match = re.search(r"id=(\d+)", location or "")
    hr_survey_id = int(match.group(1)) if match else 0
    hr_survey_row = database.execute("SELECT department_id, result_visibility, target_type, created_by FROM adhoc_requests WHERE id = ?", (hr_survey_id,)).fetchone()
    check("人財管理者が全課共通の調査を作れる（集計のみ・全員に固定）", status_code == 303 and hr_survey_row == (None, "summary", "all", hr_user_id), (status_code, hr_survey_row))

    def hr_edit(form_values):
        _, _, page_html = hr_client.get("/surveys/survey_edit.php?id=%d" % hr_survey_id)
        return hr_client.post("/surveys/survey_edit.php?id=%d" % hr_survey_id, dict(form_values, csrf_token=csrf(page_html), request_key=rkey(page_html)))

    check("人財管理者の調査にも、文の質問は足せない（400）",
          hr_edit({"action": "add_custom", "q_label": "自由に", "q_type": "multi_text", "q_options": "", "q_conf": "internal"})[0] == 400)
    hr_edit({"action": "add_custom", "q_label": "研修の希望", "q_type": "radio", "q_options": "ある\nない", "q_conf": "internal"})
    hr_expected_count = database.execute(
        "SELECT COUNT(*) FROM users u JOIN departments d ON d.id = u.department_id WHERE u.is_active = 1 AND d.is_active = 1").fetchone()[0]
    hr_edit({"action": "publish"})
    hr_open_row = database.execute("SELECT status, (SELECT COUNT(*) FROM adhoc_request_targets t WHERE t.request_id = q.id) FROM adhoc_requests q WHERE id = ?", (hr_survey_id,)).fetchone()
    check("人財管理者が質問を足して公開でき、課に所属する全員が対象になる", hr_open_row == ("open", hr_expected_count) and hr_expected_count > 0, (hr_open_row, hr_expected_count))
    for target_survey_id, label in ((hr_survey_id, "自分が作った"), (survey_id, "管理者が作った")):
        status_code, _, hr_result_html = hr_client.get("/surveys/result.php?id=%d&detail=1" % target_survey_id)
        check("人財管理者は%s全課共通の調査の結果を開け、名前は出ない" % label,
              status_code == 200 and "課ごとの回答の状況" in hr_result_html and "A一般1" not in hr_result_html and "B課長" not in hr_result_html, status_code)
    hr_client.post("/surveys/result.php?id=%d" % survey_id, {"csrf_token": csrf(hr_result_html), "request_key": rkey(hr_result_html), "format": "csv"})
    hr_csv_text = hr_client.last_raw.decode("utf-8-sig", "replace")
    check("人財管理者も CSV を出力でき、名前は入らない", ["全課", "AI を業務で使っていますか", "毎日", "2", "50%"] in list(csv.reader(io.StringIO(hr_csv_text))) and "A一般1" not in hr_csv_text, hr_csv_text[:200])
    # 課の調査（A課）は、一覧に出ず、設定も結果も開けない
    hr_writable_database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)
    department_survey_id = hr_writable_database.execute(
        "INSERT INTO adhoc_requests (department_id, title, requester, purpose, result_visibility, target_type, status, due_at, created_by, created_at, updated_at)"
        " VALUES (?, 'A課だけの調査', '', '', 'detail', 'all', 'open', ?, ?, datetime('now'), datetime('now'))",
        (department_a_id, due_date_text, user_info_by_login_id["kacho_a"]["id"])).lastrowid
    _, _, hr_manage_html = hr_client.get("/surveys/manage.php")
    check("人財管理者の一覧に、全課共通の調査は出て、課の調査は出ない",
          "人財管理部の調査" in hr_manage_html and "全社 AI 利用状況" in hr_manage_html and "A課だけの調査" not in hr_manage_html)
    check("人財管理者は課の調査の設定・結果を開けない（403）",
          hr_client.get("/surveys/survey_edit.php?id=%d" % department_survey_id)[0] == 403 and hr_client.get("/surveys/result.php?id=%d" % department_survey_id)[0] == 403)
    hr_report_id = hr_writable_database.execute(
        "INSERT INTO report_records (user_id, department_id, period, status, revision, submitted_at, created_at, updated_at)"
        " VALUES (?, ?, '2020-01', 'submitted', 0, datetime('now'), datetime('now'), datetime('now'))",
        (user_info_by_login_id["ippan_a1"]["id"], department_a_id)).lastrowid
    forbidden_status_list = [hr_client.get(path)[0] for path in (
        "/admin/index.php", "/admin/org.php", "/admin/logs.php", "/admin/trash.php", "/status/index.php", "/export/index.php", "/reports/view.php?id=%d" % hr_report_id)]
    check("人財管理者は、管理の画面・提出状況・出力・ほかの人の報告を開けない", all(code in (403, 404) for code in forbidden_status_list), forbidden_status_list)

    section("G-03", "あなたの役割と権限（人財管理者・部長以上）")

    def role_card(client):
        """ホームの「あなたの役割と権限」: (札の一覧, 担当の文, できることの一覧)。欄がなければ None。"""
        _, _, page_html = client.get("/index.php")
        card = re.search(r'<section class="card role-summary">(.*?)</section>', page_html, re.S)
        if card is None:
            return None
        duty = re.search(r"<p>担当: (.*?)</p>", card.group(1), re.S)
        return (re.findall(r'<span class="tag">([^<]*)</span>', card.group(1)), duty.group(1) if duty else "", re.findall(r"<li>(.*?)</li>", card.group(1), re.S))

    survey_manage_text = "全課共通の臨時の調査を作り、結果（集計だけ）を見て出力する"
    survey_view_text = "全課共通の臨時の調査の結果（集計だけ）を見て出力する"
    hr_card = role_card(hr_client)
    check("人財管理者: 役職と「人財管理者」の札、自分の報告と全課共通の調査だけ", hr_card == (["一般", "人財管理者"], "", ["自分の月次報告を作って提出する", survey_manage_text]), hr_card)
    executive_card = role_card(executive_client)
    check("課のない部長以上: 「部長以上」の札と、調査の結果を見ること（報告を作ることは出ない）",
          executive_card is not None and executive_card[0] == ["主任", "部長以上"] and survey_view_text in executive_card[2] and "自分の月次報告を作って提出する" not in executive_card[2], executive_card)
    viewer_card = role_card(viewer_client)
    check("調査ごとに指定された人: 「指定された」調査の結果", viewer_card is not None and "指定された" + survey_view_text in viewer_card[2], viewer_card)

    # 課のない人財管理者: ホームはエラーでなく入口を出す
    hr_writable_database.execute("UPDATE users SET department_id = NULL, team_id = NULL WHERE id = ?", (hr_user_id,))
    _, _, hr_home_html = hr_client.get("/index.php")
    check("課のない人財管理者のホームは、エラーではなく「臨時の調査」の入口",
          "所属の課が設定されていません" not in hr_home_html and "見られるもの" in hr_home_html and hr_home_html.count("/surveys/manage.php") >= 2)
    hr_writable_database.execute("UPDATE users SET department_id = ? WHERE id = ?", (seed["dept_b"], hr_user_id))
    hr_writable_database.close()
    status_code, flag_row = save_hr_flag(False)
    check("印を外すと、ログインし直さなくても開けなくなり、記録に残る",
          status_code == 303 and flag_row == (0, 0, 0, 0) and hr_log_count("hr_manager_off") == 1
          and hr_client.get("/surveys/manage.php")[0] == 403 and hr_client.get("/surveys/survey_edit.php?id=%d" % hr_survey_id)[0] == 403
          and hr_client.get("/surveys/result.php?id=%d" % hr_survey_id)[0] == 403, (status_code, flag_row))
    check("印を外すと、ホームの札と権限からも消える", role_card(hr_client) == (["一般"], "", ["自分の月次報告を作って提出する"]), role_card(hr_client))

    return summary()


if __name__ == "__main__":
    sys.exit(main())
