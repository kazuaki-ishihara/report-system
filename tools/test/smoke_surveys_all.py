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
    check("回答が 3 人より少ない課（B課）は内訳を出さない", b_row is not None and "内訳は出しません" in b_row.group(1))
    status_code, _, detail_html = admin_client.get("/surveys/result.php?id=%d&detail=1" % survey_id)
    check("detail=1 でも個人の回答を出さない", "A一般1" not in detail_html)

    section("G-10", "全課共通の調査の出力")
    _, _, result_html = admin_client.get("/surveys/result.php?id=%d" % survey_id)
    admin_client.post("/surveys/result.php?id=%d" % survey_id, {"csrf_token": csrf(result_html), "request_key": rkey(result_html), "format": "csv"})
    csv_text = admin_client.last_raw.decode("utf-8-sig", "replace")
    csv_row_list = [row for row in csv.reader(io.StringIO(csv_text))]
    check("CSV に全課と課ごとの行・回答の状況が入り、名前は入らない",
          ["全課", "AI を業務で使っていますか", "毎日", "2", "50%"] in csv_row_list and ["A課", "何を使っていますか（複数回答）", "Claude", "3", "100%"] in csv_row_list
          and ["B課", "（回答者が 3 人より少ないため、内訳を出しません）", "", "", ""] in csv_row_list and ["A課", "5", "3", "60%"] in csv_row_list
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

    return summary()


if __name__ == "__main__":
    sys.exit(main())
