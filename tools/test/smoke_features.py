#!/usr/bin/env python3
"""便利機能の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

- G-08 「変更なし」の報告のまとめてチェック
- G-04 過去の報告から 1 案件だけ写す
- G-04・G-05 前月との差分の表示
- G-18 コメントの一覧
- G-19 コメントの定型文
- G-20 検索
- G-12 項目の追加: コードの自動付与・表示の条件のチェックボックス
- G-12 共通の項目の課ごとの選択肢・選択肢の削除
- G-16 画面のテーマ
- K-026 管理者でも、確認者でない課の報告の中身は見られない
- G-14 組織の改訂: 引き継ぐ課・無効にした人の役割・課長がいない課

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_features.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import datetime
import json
import os
import re
import sqlite3
import sys
import urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, csrf, login, rkey, summary  # noqa: E402


def shift_month(year_month_text, month_offset):
    """'YYYY-MM' を month_offset か月ずらす"""
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
    department_a_id = seed_info["dept_a"]
    database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)
    field_id_by_code = {code: field_id for code, field_id in database.execute("SELECT code, id FROM report_fields")}
    project_id_by_code = {code: project_id for code, project_id in database.execute("SELECT code, id FROM projects")}
    current_month = datetime.date.today().strftime("%Y-%m")
    previous_month = shift_month(current_month, -1)

    def report_id_of(login_id, period):
        report_row = database.execute(
            "SELECT id FROM report_records WHERE user_id = ? AND period = ? AND is_legacy = 0 AND deleted_at IS NULL",
            (user_info_by_login_id[login_id]["id"], period)).fetchone()
        return report_row[0] if report_row else 0

    def submit_report(client, period, phase_code="build_unit"):
        """案件 P-0001 の報告を提出する。戻り値: HTTP の状態"""
        status_code, _location, edit_html = client.get("/reports/edit.php?period=" + period)
        form_values = {"csrf_token": csrf(edit_html), "request_key": rkey(edit_html), "period": period, "action": "submit",
                       "b[0][project]": str(project_id_by_code["P-0001"]),
                       "b[0][f][%d]" % field_id_by_code["phase"]: phase_code,
                       "b[0][f][%d]" % field_id_by_code["progress"]: "on_track",
                       "b[0][f][%d]" % field_id_by_code["continuation"]: "continuing",
                       "b[0][f][%d]" % field_id_by_code["system_type"]: "inventory"}
        return client.post("/reports/save.php", form_values)[0]

    def submit_unchanged(client, period):
        status_code, _location, edit_html = client.get("/reports/edit.php?period=" + period)
        return client.post("/reports/save.php", {"csrf_token": csrf(edit_html), "request_key": rkey(edit_html), "period": period, "action": "unchanged"})[0]

    # ================================================================
    # G-08 「変更なし」の報告のまとめてチェック
    # ================================================================
    member_a1_client = login(arguments.base, "ippan_a1", user_info_by_login_id["ippan_a1"])
    member_a2_client = login(arguments.base, "ippan_a2", user_info_by_login_id["ippan_a2"])
    for member_client in (member_a1_client, member_a2_client):
        submit_report(member_client, previous_month)
        submit_unchanged(member_client, current_month)
    report_a1_id, report_a2_id = report_id_of("ippan_a1", current_month), report_id_of("ippan_a2", current_month)
    check("前提: 2 人が「変更なし」で提出", report_a1_id > 0 and report_a2_id > 0, (report_a1_id, report_a2_id))

    # A一般2 は提出後に直す（revision が上がる → まとめてチェックの対象外）
    status_code, _location, edit_html = member_a2_client.get("/reports/edit.php?period=" + current_month)
    form_values = {"csrf_token": csrf(edit_html), "request_key": rkey(edit_html), "period": current_month, "action": "save",
                   "b[0][project]": str(project_id_by_code["P-0001"]),
                   "b[0][f][%d]" % field_id_by_code["phase"]: "integration",
                   "b[0][f][%d]" % field_id_by_code["progress"]: "on_track",
                   "b[0][f][%d]" % field_id_by_code["continuation"]: "continuing",
                   "b[0][f][%d]" % field_id_by_code["system_type"]: "inventory"}
    member_a2_client.post("/reports/save.php", form_values)
    revision_a2 = database.execute("SELECT revision FROM report_records WHERE id = ?", (report_a2_id,)).fetchone()[0]
    check("前提: A一般2 は提出後に直した（revision 1）", revision_a2 == 1, revision_a2)

    status_path = "/status/index.php?period=%s&dept=%d" % (current_month, department_a_id)
    leader_client = login(arguments.base, "leader_a", user_info_by_login_id["leader_a"])
    status_code, _location, status_html = leader_client.get(status_path)
    check("確認者に「まとめてチェック」が出て、対象は 1 件", 'value="bulk_check"' in status_html and "<strong>1</strong> 件あります" in status_html, status_code)
    check("「変更なし」のまま直していない報告に、選ぶ欄が出る", 'name="report_ids[]" value="%d"' % report_a1_id in status_html)
    check("提出後に直した報告には、選ぶ欄が出ない", 'name="report_ids[]" value="%d"' % report_a2_id not in status_html)

    def post_bulk_check(client, report_id_list):
        status_code, _location, page_html = client.get(status_path)
        return client.post(status_path, {"csrf_token": csrf(page_html), "request_key": os.urandom(16).hex(), "action": "bulk_check",
                                         "report_ids[]": [str(report_id) for report_id in report_id_list]})

    def checked_revision_of(login_id, report_id):
        review_row = database.execute("SELECT checked_revision FROM report_reviews WHERE report_id = ? AND user_id = ?",
                                      (report_id, user_info_by_login_id[login_id]["id"])).fetchone()
        return review_row[0] if review_row else None

    status_code, _location, _page_html = post_bulk_check(leader_client, [report_a1_id, report_a2_id])
    check("対象外の報告が混じると、何もチェックしない（400）",
          status_code == 400 and checked_revision_of("leader_a", report_a1_id) is None and checked_revision_of("leader_a", report_a2_id) is None, status_code)
    status_code, location, _page_html = post_bulk_check(leader_client, [report_a1_id])
    check("まとめてチェックできる", status_code == 303 and checked_revision_of("leader_a", report_a1_id) == 0, (status_code, location))
    status_code, _location, status_html = leader_client.get(status_path)
    check("チェックした件数を知らせ、選ぶ欄は消える", "1 件をチェック済みにしました" in status_html and 'value="bulk_check"' not in status_html)
    status_code, _location, _page_html = post_bulk_check(leader_client, [])
    check("何も選ばずに送ると、知らせて何もしない", status_code == 303)

    admin_client = login(arguments.base, "admin01", user_info_by_login_id["admin01"])
    status_code, _location, status_html = admin_client.get(status_path)
    check("確認者でない管理者には「まとめてチェック」が出ない", status_code == 200 and 'value="bulk_check"' not in status_html, status_code)
    status_code, _location, _page_html = post_bulk_check(admin_client, [report_a1_id])
    check("確認者でない管理者は送ってもチェックできない（403）", status_code == 403, status_code)
    kacho_b_client = login(arguments.base, "kacho_b", user_info_by_login_id["kacho_b"])
    status_code, _location, _page_html = kacho_b_client.get(status_path)
    check("別の課の課長は、この課の提出状況を開けない（403）", status_code == 403, status_code)

    # ================================================================
    # G-04 過去の報告から 1 案件だけ写す（課 B の人。案件 P-0003・P-0004）
    # ================================================================
    member_b1_client = login(arguments.base, "ippan_b1", user_info_by_login_id["ippan_b1"])
    status_code, _location, edit_html = member_b1_client.get("/reports/edit.php?period=" + previous_month)
    member_b1_client.post("/reports/save.php", {
        "csrf_token": csrf(edit_html), "request_key": rkey(edit_html), "period": previous_month, "action": "submit",
        "b[0][project]": str(project_id_by_code["P-0003"]), "b[0][f][%d]" % field_id_by_code["phase"]: "requirements",
        "b[0][f][%d]" % field_id_by_code["progress"]: "on_track", "b[0][f][%d]" % field_id_by_code["continuation"]: "continuing",
        "b[1][project]": str(project_id_by_code["P-0004"]), "b[1][f][%d]" % field_id_by_code["phase"]: "operation",
        "b[1][f][%d]" % field_id_by_code["progress"]: "delayed", "b[1][f][%d]" % field_id_by_code["progress_note"]: "人手が足りない",
        "b[1][f][%d]" % field_id_by_code["continuation"]: "continuing"})
    report_b1_previous_id = report_id_of("ippan_b1", previous_month)
    # 当月の下書き: P-0003 だけ（フェーズは基本設計）
    status_code, _location, edit_html = member_b1_client.get("/reports/edit.php?period=" + current_month)
    member_b1_client.post("/reports/save.php", {
        "csrf_token": csrf(edit_html), "request_key": rkey(edit_html), "period": current_month, "action": "draft",
        "b[0][project]": str(project_id_by_code["P-0003"]), "b[0][f][%d]" % field_id_by_code["phase"]: "basic_design"})
    check("前提: 前月は 2 案件、当月の下書きは 1 案件", report_b1_previous_id > 0 and report_id_of("ippan_b1", current_month) > 0)

    status_code, _location, edit_html = member_b1_client.get("/reports/edit.php?period=" + current_month)
    check("写す元の選択肢に「1 案件だけ」が出る", 'value="%d-%d"' % (report_b1_previous_id, project_id_by_code["P-0004"]) in edit_html)
    status_code, _location, edit_html = member_b1_client.get("/reports/edit.php?period=%s&copy_target=%d-%d" % (
        current_month, report_b1_previous_id, project_id_by_code["P-0004"]))
    check("1 案件だけ写すと、今の入力に足される（P-0003 は残る）",
          status_code == 200 and 'value="basic_design" selected' in edit_html and "人手が足りない" in edit_html, status_code)
    check("1 案件だけ写した旨が出る", "案件 P-0004 の内容を写しました" in edit_html)
    check("1 案件だけ写したあとは、離れるときの確認の対象", 'data-unsaved="1"' in edit_html)
    check("1 案件だけ写しても、写した元（copied_from_id）は変えない", 'name="copy_from"' not in edit_html)
    status_code, _location, edit_html = member_b1_client.get("/reports/edit.php?period=%s&copy_target=%d-%d" % (
        current_month, report_b1_previous_id, project_id_by_code["P-0003"]))
    check("同じ案件を写すと、その案件の内容を置き換える", 'value="requirements" selected' in edit_html and 'value="basic_design" selected' not in edit_html)
    status_code, _location, _page_html = member_b1_client.get("/reports/edit.php?period=%s&copy_target=%d-%d" % (
        current_month, report_b1_previous_id, project_id_by_code["P-0001"]))
    check("写す元にない案件は 404", status_code == 404, status_code)
    status_code, _location, _page_html = member_b1_client.get("/reports/edit.php?period=%s&copy_target=%s" % (current_month, "1-x"))
    check("形が違えば 400", status_code == 400, status_code)
    unsaved_project_row_count = database.execute("SELECT COUNT(*) FROM report_projects WHERE report_id = ?", (report_id_of("ippan_b1", current_month),)).fetchone()[0]
    check("写しただけでは保存しない", unsaved_project_row_count == 1, unsaved_project_row_count)

    # ================================================================
    # G-04・G-05 前月との差分の表示
    # ================================================================
    # A一般2: 前月の報告に「限定」の項目（人員の動きの詳細）を足しておく。当月は提出後にフェーズを変えてある
    status_code, _location, edit_html = member_a2_client.get("/reports/edit.php?period=" + previous_month)
    member_a2_client.post("/reports/save.php", {
        "csrf_token": csrf(edit_html), "request_key": rkey(edit_html), "period": previous_month, "action": "save",
        "b[0][project]": str(project_id_by_code["P-0001"]), "b[0][f][%d]" % field_id_by_code["phase"]: "build_unit",
        "b[0][f][%d]" % field_id_by_code["progress"]: "on_track", "b[0][f][%d]" % field_id_by_code["continuation"]: "continuing",
        "b[0][f][%d]" % field_id_by_code["system_type"]: "inventory", "b[0][f][%d]" % field_id_by_code["staffing"]: "increase",
        "b[0][f][%d]" % field_id_by_code["staffing_note"]: "来月に増員する話（限定）"})
    status_code, _location, view_html = leader_client.get("/reports/view.php?id=%d" % report_a2_id)
    check("G-05: 変わった項目に「前月から変更」と前月の値が出る", status_code == 200 and 'class="previous-value"' in view_html and "製造・単体" in view_html, status_code)
    check("G-05: 確認者には「限定」の項目の前月の値を出さない", "来月に増員する話" not in view_html)
    kacho_a_client = login(arguments.base, "kacho_a", user_info_by_login_id["kacho_a"])
    status_code, _location, view_html = kacho_a_client.get("/reports/view.php?id=%d" % report_a2_id)
    check("G-05: 課長には「限定」の項目の前月の値も出る", "来月に増員する話" in view_html)
    status_code, _location, view_html = leader_client.get("/reports/view.php?id=%d" % report_a1_id)
    check("G-05: 「変更なし」の報告には「前月から変更」が出ない", status_code == 200 and 'class="previous-value"' not in view_html, status_code)

    # B一般1: 前月は P-0003・P-0004、当月の下書きは P-0003 だけ（フェーズを変えた）
    status_code, _location, edit_html = member_b1_client.get("/reports/edit.php?period=" + current_month)
    check("G-04: 入力画面で、変わった項目に前月の値が出る", 'class="previous-value"' in edit_html)
    status_code, _location, view_html = member_b1_client.get("/reports/view.php?id=%d" % report_id_of("ippan_b1", current_month))
    check("G-05: 前月にあって今回ない案件を知らせる", "にあって今回ない案件: P-0004" in view_html, status_code)
    # 前月より前の報告がない月（前月）は比べない
    status_code, _location, view_html = member_b1_client.get("/reports/view.php?id=%d" % report_b1_previous_id)
    check("G-05: 比べる報告がなければ何も出さない", 'class="previous-value"' not in view_html and "previous-summary" not in view_html)

    # ================================================================
    # G-18 コメントの一覧
    # ================================================================
    report_a2_project_block_id = database.execute("SELECT id FROM report_projects WHERE report_id = ? ORDER BY id LIMIT 1", (report_a2_id,)).fetchone()[0]
    status_code, _location, view_html = leader_client.get("/reports/view.php?id=%d" % report_a2_id)
    status_code, _location, _page_html = leader_client.post("/reports/act.php", {
        "csrf_token": csrf(view_html), "request_key": os.urandom(16).hex(), "action": "comment", "report_id": report_a2_id,
        "field_id": field_id_by_code["phase"], "report_project_id": 0, "comment_type": "request", "comment": "位置の違うコメント"})
    check("案件ごとの項目へのコメントを、報告全体の位置には付けられない（400）", status_code == 400, status_code)
    status_code, _location, view_html = leader_client.get("/reports/view.php?id=%d" % report_a2_id)
    leader_client.post("/reports/act.php", {"csrf_token": csrf(view_html), "request_key": rkey(view_html), "action": "comment", "report_id": report_a2_id,
                                            "field_id": field_id_by_code["phase"], "report_project_id": report_a2_project_block_id, "comment_type": "request",
                                            "comment": "結合テストに入った理由を教えてください"})
    first_comment_id = database.execute("SELECT MAX(id) FROM report_field_comments WHERE report_id = ? AND parent_id IS NULL", (report_a2_id,)).fetchone()[0]
    status_code, _location, comment_list_html = member_a2_client.get("/reports/comments.php")
    check("本人の既定は「自分の報告へのコメント」で、回答待ちが出る",
          status_code == 200 and "#comment-%d" % first_comment_id in comment_list_html and "回答待ち" in comment_list_html, status_code)
    check("本文の先頭が出る", "結合テストに入った理由" in comment_list_html)
    status_code, _location, view_html = member_a2_client.get("/reports/view.php?id=%d" % report_a2_id)
    member_a2_client.post("/reports/act.php", {"csrf_token": csrf(view_html), "request_key": rkey(view_html), "action": "reply",
                                               "report_id": report_a2_id, "parent_id": first_comment_id, "comment": "単体が終わったためです"})
    status_code, _location, comment_list_html = leader_client.get("/reports/comments.php")
    check("確認者の既定は「自分が出したコメント」で、回答ありが出る",
          "#comment-%d" % first_comment_id in comment_list_html and "回答あり" in comment_list_html and "返信 1" in comment_list_html)
    status_code, _location, comment_list_html = leader_client.get("/reports/comments.php?tab=sent&status=open")
    check("状態で絞り込める（回答待ちには出ない）", "#comment-%d" % first_comment_id not in comment_list_html)
    status_code, _location, comment_list_html = member_b1_client.get("/reports/comments.php?tab=received")
    check("ほかの人の報告へのコメントは出ない", "#comment-%d" % first_comment_id not in comment_list_html)
    status_code, _location, comment_list_html = member_b1_client.get("/reports/comments.php?tab=sent")
    check("確認者でない人が「自分が出したコメント」を開いても、他人のコメントは出ない", status_code == 200 and "#comment-" not in comment_list_html, status_code)
    # ================================================================
    # G-19 コメントの定型文（リーダーを確認者から外す前に確かめる）
    # ================================================================
    templates_path = "/admin/comment_templates.php?scope=department&dept=%d" % department_a_id

    def post_template(client, action, body_text=None, template_id=None):
        status_code, _location, page_html = client.get(templates_path)
        form_values = {"csrf_token": csrf(page_html) or csrf(client.get("/index.php")[2]), "request_key": os.urandom(16).hex(), "action": action}
        if body_text is not None:
            form_values["body"] = body_text
        if template_id is not None:
            form_values["template_id"] = str(template_id)
        return client.post(templates_path, form_values)

    def template_body_list():
        return [row[0] for row in database.execute("SELECT body FROM comment_templates WHERE department_id = ? ORDER BY sort_order, id", (department_a_id,))]

    status_code, _location, page_html = kacho_a_client.get(templates_path)
    check("課長は定型文の画面を開ける", status_code == 200 and "コメントの定型文" in page_html, status_code)
    check("課長のメニューに「コメントの定型文」", "/admin/comment_templates.php" in kacho_a_client.get("/index.php")[2])
    post_template(kacho_a_client, "add", "完了の見込みを教えてください")
    post_template(kacho_a_client, "add", "客先との調整の状況を教えてください")
    check("定型文を追加できる", template_body_list() == ["完了の見込みを教えてください", "客先との調整の状況を教えてください"], template_body_list())
    status_code, _location, page_html = post_template(kacho_a_client, "add", "")
    check("空の定型文は追加できない", status_code == 200 and len(template_body_list()) == 2, status_code)
    second_template_id = database.execute("SELECT id FROM comment_templates WHERE body = ?", ("客先との調整の状況を教えてください",)).fetchone()[0]
    post_template(kacho_a_client, "move_up", template_id=second_template_id)
    check("並べ替えられる", template_body_list()[0] == "客先との調整の状況を教えてください", template_body_list())
    post_template(kacho_a_client, "update", "調整の状況を教えてください", second_template_id)
    check("編集できる", "調整の状況を教えてください" in template_body_list(), template_body_list())
    status_code, _location, view_html = leader_client.get("/reports/view.php?id=%d" % report_a1_id)
    check("確認者のコメント欄に定型文の選択が出る", 'class="comment-template-select"' in view_html and "完了の見込みを教えてください" in view_html)
    status_code, _location, _page_html = leader_client.get(templates_path)
    check("課長でない確認者は課の定型文を開けない（403）", status_code == 403, status_code)

    # ---- K-014 自分だけの定型文 ----
    personal_templates_path = "/admin/comment_templates.php?scope=mine"
    status_code, _location, personal_html = leader_client.get(personal_templates_path)
    check("確認者は自分の定型文の画面を開ける", status_code == 200 and "自分だけが使う定型文" in personal_html, status_code)
    leader_client.post(personal_templates_path, {"csrf_token": csrf(personal_html), "request_key": os.urandom(16).hex(),
                                                 "action": "add", "body": "リーダーの確認メモ"})
    personal_template_row = database.execute("SELECT id, user_id FROM personal_comment_templates WHERE body = 'リーダーの確認メモ'").fetchone()
    check("自分の定型文を足せる", personal_template_row is not None and personal_template_row[1] == user_info_by_login_id["leader_a"]["id"], personal_template_row)
    status_code, _location, view_html = leader_client.get("/reports/view.php?id=%d" % report_a1_id)
    check("コメント欄に、課の定型文と自分の定型文が並ぶ", "リーダーの確認メモ" in view_html and "完了の見込みを教えてください" in view_html and "自分の定型文" in view_html)
    status_code, _location, view_html = kacho_a_client.get("/reports/view.php?id=%d" % report_a1_id)
    check("ほかの人の定型文は出ない", "リーダーの確認メモ" not in view_html)
    status_code, _location, personal_html = kacho_a_client.get(personal_templates_path)
    status_code, _location, _page_html = kacho_a_client.post(personal_templates_path, {
        "csrf_token": csrf(personal_html), "request_key": os.urandom(16).hex(), "action": "delete", "template_id": str(personal_template_row[0] if personal_template_row else 0)})
    check("ほかの人の定型文は消せない（404）", status_code == 404 and database.execute(
        "SELECT 1 FROM personal_comment_templates WHERE body = 'リーダーの確認メモ'").fetchone() is not None, status_code)
    status_code, _location, _page_html = member_a1_client.get(personal_templates_path)
    check("確認者でない部下は、自分の定型文も開けない（403）", status_code == 403, status_code)
    status_code, _location, _page_html = member_a1_client.get(templates_path)
    check("部下は定型文の画面を開けない（403）", status_code == 403, status_code)
    status_code, _location, _page_html = kacho_b_client.get(templates_path)
    check("別の課の課長は、この課の定型文を開けない（403）", status_code == 403, status_code)
    status_code, _location, _page_html = admin_client.get(templates_path)
    check("課長でない管理者は定型文の画面を開けない（403）", status_code == 403, status_code)
    other_department_template_id = second_template_id
    status_code, _location, _page_html = kacho_b_client.post("/admin/comment_templates.php", {
        "csrf_token": csrf(kacho_b_client.get("/admin/comment_templates.php")[2]), "request_key": os.urandom(16).hex(),
        "action": "delete", "template_id": str(other_department_template_id)})
    check("別の課の定型文は消せない（404）", status_code == 404 and len(template_body_list()) == 2, status_code)
    post_template(kacho_a_client, "delete", template_id=second_template_id)
    check("削除できる", template_body_list() == ["完了の見込みを教えてください"], template_body_list())

    database.execute("DELETE FROM department_reviewers WHERE user_id = ?", (user_info_by_login_id["leader_a"]["id"],))
    status_code, _location, comment_list_html = leader_client.get("/reports/comments.php?tab=sent")
    check("確認者から外れたら、その課の報告へのコメントは出ない", "#comment-%d" % first_comment_id not in comment_list_html)

    # ================================================================
    # G-20 検索
    # ================================================================
    def search_path(**condition_values):
        return "/reports/search.php?" + urllib.parse.urlencode(dict({"search": "1", "from": shift_month(current_month, -3), "to": current_month}, **condition_values))

    status_code, _location, search_html = kacho_b_client.get(search_path(q="人手"))
    check("キーワードで自由記述を探せる（スニペットが出る）",
          status_code == 200 and "/reports/view.php?id=%d" % report_b1_previous_id in search_html and "人手が足りない" in search_html, status_code)
    status_code, _location, search_html = kacho_b_client.get(search_path(q="ｐ－０００３"))
    check("案件のコードでも探せる（全角・半角を区別しない）", "/reports/view.php?id=%d" % report_b1_previous_id in search_html)
    status_code, _location, search_html = kacho_b_client.get(search_path(choice="%d:requirements" % field_id_by_code["phase"]))
    check("選択式の値で探せる（下書きは出ない）",
          "/reports/view.php?id=%d" % report_b1_previous_id in search_html and "/reports/view.php?id=%d" % report_id_of("ippan_b1", current_month) not in search_html)
    status_code, _location, search_html = kacho_a_client.get(search_path(q="人手"))
    check("見られない課の報告は出ない", "/reports/view.php?id=%d" % report_b1_previous_id not in search_html and "該当する報告はありません" in search_html)
    database.execute("UPDATE report_fields SET is_searchable = 1 WHERE code = 'staffing_note'")
    status_code, _location, search_html = kacho_a_client.get(search_path(q="増員する話"))
    check("「限定」の項目は、限定を見られる課長には見つかる", "/reports/view.php?id=%d" % report_id_of("ippan_a2", previous_month) in search_html)
    deputy_client = login(arguments.base, "dairi_a", user_info_by_login_id["dairi_a"])
    status_code, _location, search_html = deputy_client.get(search_path(q="増員する話"))
    check("「限定」の項目は、課長でない確認者には見つからない", status_code == 200 and "来月に増員する話" not in search_html and "該当する報告はありません" in search_html, status_code)
    status_code, _location, search_html = kacho_b_client.get(search_path(q="人手", to=shift_month(current_month, -3)))
    check("対象月の範囲で絞れる", "/reports/view.php?id=%d" % report_b1_previous_id not in search_html)
    status_code, _location, search_html = kacho_b_client.get(search_path(**{"from": current_month, "to": previous_month}))
    check("対象月の始めが終わりより後なら知らせる", "終わりと同じ月かそれより前" in search_html)
    status_code, _location, search_html = member_a1_client.get(search_path(q="人手"))
    check("K-015 部下も検索を開け、ほかの人の報告は出ない", status_code == 200 and "/reports/view.php?id=%d" % report_b1_previous_id not in search_html
          and 'name="scope" value="mine"' in search_html, status_code)
    status_code, _location, search_html = member_b1_client.get(search_path(q="人手"))
    check("K-015 部下は自分の報告を探せる", "/reports/view.php?id=%d" % report_b1_previous_id in search_html)
    status_code, _location, search_html = member_b1_client.get(search_path(choice="%d:basic_design" % field_id_by_code["phase"]))
    check("K-015 自分の報告は下書きも探せる", "/reports/view.php?id=%d" % report_id_of("ippan_b1", current_month) in search_html)
    status_code, _location, search_html = kacho_b_client.get(search_path(q="人手", scope="mine"))
    check("K-015 確認者も「自分の報告」に切り替えられる（課の報告は出ない）", status_code == 200 and "/reports/view.php?id=%d" % report_b1_previous_id not in search_html)
    status_code, _location, _search_html = kacho_a_client.get(search_path(dept=str(seed_info["dept_b"])))
    check("見られない課を指定すると 403", status_code == 403, status_code)
    status_code, _location, _search_html = kacho_b_client.get(search_path(choice="%d:no_such_code" % field_id_by_code["phase"]))
    check("選択肢にない値は 400", status_code == 400, status_code)
    search_log_target = database.execute("SELECT target FROM access_logs WHERE target LIKE 'search:%' ORDER BY id DESC LIMIT 1").fetchone()
    check("検索の条件が記録に残り、キーワードの中身は残さない",
          search_log_target is not None and "keyword=" in search_log_target[0] and "人手" not in search_log_target[0], search_log_target)

    # ================================================================
    # G-12 項目の追加: コードの自動付与・表示の条件のチェックボックス
    # ================================================================
    field_edit_path = "/admin/field_edit.php?dept=%d" % department_a_id
    status_code, _location, field_edit_html = kacho_a_client.get(field_edit_path)
    check("表示の条件は、項目を選ぶ形で出る", '<select id="show_when_field"' in field_edit_html and 'name="show_when_value[]"' in field_edit_html)
    status_code, location, _page_html = kacho_a_client.post(field_edit_path, {
        "csrf_token": csrf(field_edit_html), "request_key": rkey(field_edit_html), "label": "コードを入れない項目", "code": "",
        "field_type": "radio", "field_scope": "project", "confidentiality": "internal",
        "opt_code[0]": "", "opt_label[0]": "あり", "opt_active[0]": "1",
        "opt_code[1]": "", "opt_label[1]": "なし", "opt_active[1]": "1",
        "show_when_field": "progress", "show_when_value[]": ["delayed", "other"]})
    auto_coded_field = database.execute("SELECT id, code, settings_json FROM report_fields WHERE label = 'コードを入れない項目'").fetchone()
    check("コードを空にすると、自動で付く（item_番号）", status_code == 303 and auto_coded_field is not None and re.match(r"^item_\d+$", auto_coded_field[1]) is not None,
          (status_code, location, auto_coded_field))
    auto_option_code_list = [row[0] for row in database.execute("SELECT code FROM report_field_options WHERE field_id = ? ORDER BY sort_order", (auto_coded_field[0] if auto_coded_field else 0,))]
    check("選択肢のコードも自動で付く（o1, o2）", auto_option_code_list == ["o1", "o2"], auto_option_code_list)
    check("表示の条件をチェックボックスで受け取れる",
          auto_coded_field is not None and json.loads(auto_coded_field[2]).get("show_when") == {"field": "progress", "values": ["delayed", "other"]},
          auto_coded_field[2] if auto_coded_field else None)
    status_code, _location, field_edit_html = kacho_a_client.get(field_edit_path)
    status_code, _location, response_html = kacho_a_client.post(field_edit_path, {
        "csrf_token": csrf(field_edit_html), "request_key": rkey(field_edit_html), "label": "値のない条件", "code": "",
        "field_type": "single_text", "field_scope": "project", "confidentiality": "internal", "show_when_field": "progress"})
    check("表示の条件で項目だけ選んで値を選ばないと保存しない", status_code == 200 and database.execute(
        "SELECT 1 FROM report_fields WHERE label = '値のない条件'").fetchone() is None, status_code)

    # ================================================================
    # G-12 共通の項目の課ごとの選択肢（課 B の課長が「作業フェーズ」を自分の課向けに変える）
    # ================================================================
    department_b_id = seed_info["dept_b"]
    phase_field_id = field_id_by_code["phase"]
    field_options_path = "/admin/field_options.php?field=%d&dept=%d" % (phase_field_id, department_b_id)
    status_code, _location, field_options_html = kacho_b_client.get(field_options_path)
    check("課長は、共通の項目の自分の課の選択肢を開ける", status_code == 200 and "この課での表示名" in field_options_html, status_code)
    status_code, _location, _page_html = kacho_a_client.get(field_options_path)
    check("ほかの課の課長は開けない（403）", status_code == 403, status_code)
    status_code, _location, _page_html = member_b1_client.get(field_options_path)
    check("部下は開けない（403）", status_code == 403, status_code)
    status_code, _location, fields_html = kacho_b_client.get("/admin/fields.php?dept=%d" % department_b_id)
    check("項目マスタの一覧に「この課の選択肢」が出る", field_options_path.replace("&", "&amp;") in fields_html)

    def save_department_options(shown_code_list, override_label_by_code, new_label_list):
        status_code, _location, page_html = kacho_b_client.get(field_options_path)
        form_values = {"csrf_token": csrf(page_html), "request_key": os.urandom(16).hex(), "action": "save", "new_label[]": new_label_list}
        for shown_code in shown_code_list:
            form_values["shown[%s]" % shown_code] = "1"
        for option_code, override_label in override_label_by_code.items():
            form_values["override_label[%s]" % option_code] = override_label
        return kacho_b_client.post(field_options_path, form_values)

    phase_code_list = [row[0] for row in database.execute("SELECT code FROM report_field_options WHERE field_id = ? AND is_active = 1", (phase_field_id,))]
    shown_phase_code_list = [phase_code for phase_code in phase_code_list if phase_code != "build_unit"]
    status_code, location, _page_html = save_department_options(shown_phase_code_list, {"requirements": "企画"}, ["調査"])
    department_option_row_list = database.execute(
        "SELECT code, label, is_added, is_hidden FROM department_field_options WHERE department_id = ? AND field_id = ? ORDER BY code",
        (department_b_id, phase_field_id)).fetchall()
    check("表示名を変える・出さない・足す、を保存できる", status_code == 303 and sorted(department_option_row_list) == sorted([
        ("build_unit", "", 0, 1), ("d%d_o1" % department_b_id, "調査", 1, 0), ("requirements", "企画", 0, 0)]), department_option_row_list)

    two_months_ago = shift_month(current_month, -2)
    status_code, _location, edit_html = member_b1_client.get("/reports/edit.php?period=" + two_months_ago)
    check("課 B の新しい報告に、課の選択肢が出る（変えた表示名・足した選択肢があり、出さないものはない）",
          ">企画<" in edit_html and ">調査<" in edit_html and 'value="build_unit"' not in edit_html)
    status_code, location, _page_html = member_b1_client.post("/reports/save.php", {
        "csrf_token": csrf(edit_html), "request_key": rkey(edit_html), "period": two_months_ago, "action": "draft",
        "b[0][project]": str(project_id_by_code["P-0003"]), "b[0][f][%d]" % phase_field_id: "d%d_o1" % department_b_id})
    saved_phase_value = database.execute(
        "SELECT v.value_text FROM report_field_values v JOIN report_records r ON r.id = v.report_id WHERE r.user_id = ? AND r.period = ? AND v.field_id = ?",
        (user_info_by_login_id["ippan_b1"]["id"], two_months_ago, phase_field_id)).fetchone()
    check("足した選択肢で保存できる", saved_phase_value is not None and saved_phase_value[0] == "d%d_o1" % department_b_id, saved_phase_value)
    status_code, _location, edit_html = member_a1_client.get("/reports/edit.php?period=" + two_months_ago)
    check("ほかの課（課 A）の報告は変わらない", 'value="build_unit"' in edit_html and ">調査<" not in edit_html and ">企画<" not in edit_html)

    status_code, _location, page_html = kacho_b_client.get(field_options_path)
    status_code, location, _page_html = kacho_b_client.post(field_options_path, {"csrf_token": csrf(page_html), "request_key": os.urandom(16).hex(),
                                                                                  "action": "delete_added", "code": "d%d_o1" % department_b_id})
    check("報告で使われている、足した選択肢は削除できない", database.execute(
        "SELECT 1 FROM department_field_options WHERE code = ?", ("d%d_o1" % department_b_id,)).fetchone() is not None)
    save_department_options(shown_phase_code_list + ["d%d_o1" % department_b_id], {"requirements": "企画"}, ["使わない選択肢"])
    status_code, _location, page_html = kacho_b_client.get(field_options_path)
    kacho_b_client.post(field_options_path, {"csrf_token": csrf(page_html), "request_key": os.urandom(16).hex(),
                                             "action": "delete_added", "code": "d%d_o2" % department_b_id})
    check("使われていない、足した選択肢は削除できる", database.execute(
        "SELECT 1 FROM department_field_options WHERE code = ?", ("d%d_o2" % department_b_id,)).fetchone() is None)
    status_code, _location, response_html = save_department_options([], {}, [])
    check("この課で出す選択肢が 0 になる保存はできない", status_code == 200 and "1 つ以上" in response_html, status_code)

    # ---- 課の項目の選択肢の削除（G-12 項目の編集） ----
    system_type_field_id = field_id_by_code["system_type"]
    system_type_option_code_list = [row[0] for row in database.execute(
        "SELECT code FROM report_field_options WHERE field_id = ? ORDER BY sort_order, id", (system_type_field_id,))]

    def post_system_type_options(delete_code):
        field_edit_url = "/admin/field_edit.php?id=%d" % system_type_field_id
        status_code, _location, page_html = kacho_a_client.get(field_edit_url)
        field_row = database.execute("SELECT label, field_type, field_scope, confidentiality FROM report_fields WHERE id = ?", (system_type_field_id,)).fetchone()
        form_values = {"csrf_token": csrf(page_html), "request_key": rkey(page_html), "label": field_row[0], "field_type": field_row[1],
                       "field_scope": field_row[2], "confidentiality": field_row[3], "carry_over": "1", "include_in_export": "1"}
        for option_index, option_code in enumerate(system_type_option_code_list):
            option_label = database.execute("SELECT label FROM report_field_options WHERE field_id = ? AND code = ?", (system_type_field_id, option_code)).fetchone()
            if option_label is None:
                continue
            form_values["opt_code[%d]" % option_index] = option_code
            form_values["opt_label[%d]" % option_index] = option_label[0]
            form_values["opt_active[%d]" % option_index] = "1"
            if option_code == delete_code:
                form_values["opt_delete[%d]" % option_index] = "1"
        return kacho_a_client.post(field_edit_url, form_values)

    status_code, _location, response_html = post_system_type_options("inventory")
    check("報告で使われている選択肢は削除できない（知らせる）", status_code == 200 and "使われている選択肢は削除できません" in response_html and database.execute(
        "SELECT 1 FROM report_field_options WHERE field_id = ? AND code = 'inventory'", (system_type_field_id,)).fetchone() is not None, status_code)
    status_code, _location, _page_html = post_system_type_options("hr")
    check("使われていない選択肢は削除できる", status_code == 303 and database.execute(
        "SELECT 1 FROM report_field_options WHERE field_id = ? AND code = 'hr'", (system_type_field_id,)).fetchone() is None, status_code)

    # ================================================================
    # G-16 画面のテーマ
    # ================================================================
    def save_theme(client, theme_name):
        status_code, _location, account_html = client.get("/account.php?tab=theme")
        return client.post("/account.php?tab=theme", {"csrf_token": csrf(account_html), "action": "theme_save", "ui_theme": theme_name})

    status_code, _location, account_html = member_a1_client.get("/account.php?tab=theme")
    check("アカウント設定に「表示」のタブがあり、テーマを選べる", status_code == 200 and 'name="ui_theme" value="sakura"' in account_html, status_code)
    check("最初は「自動」で、html に data-theme がない", 'value="auto" checked' in account_html and "data-theme=" not in account_html)
    status_code, _location, _page_html = save_theme(member_a1_client, "sakura")
    status_code, _location, home_html = member_a1_client.get("/index.php")
    check("テーマを選ぶと、画面の html に data-theme が付く", status_code == 200 and 'data-theme="sakura"' in home_html, status_code)
    status_code, _location, home_html = member_a2_client.get("/index.php")
    check("ほかの人の画面は変わらない", "data-theme=" not in home_html)
    status_code, _location, _page_html = save_theme(member_a1_client, "rainbow")
    check("知らないテーマは 400", status_code == 400, status_code)
    save_theme(member_a1_client, "auto")
    status_code, _location, home_html = member_a1_client.get("/index.php")
    check("「自動」に戻すと data-theme がなくなる", "data-theme=" not in home_html)

    # ================================================================
    # K-026 管理者でも、確認者でない課の報告の中身は見られない（admin01 はどの課の確認者でもない）
    # ================================================================
    status_code, _location, _page_html = admin_client.get("/reports/view.php?id=%d" % report_a1_id)
    check("K-026 管理者は、提出済みの報告を開けない（404）", status_code == 404, status_code)
    status_code, _location, _page_html = admin_client.get("/reports/view.php?id=%d" % report_id_of("ippan_b1", current_month))
    check("K-026 管理者は、下書きも開けない（404）", status_code == 404, status_code)
    status_code, _location, status_html = admin_client.get(status_path)
    check("K-026 管理者は提出状況（中身を出さない）は開けるが、報告へのリンクは出ない",
          status_code == 200 and "/reports/view.php?id=" not in status_html and "A一般1" in status_html, status_code)
    for content_path, content_label in [
        ("/status/compare.php?period=%s&dept=%d" % (current_month, department_a_id), "横並び"),
        ("/export/index.php", "出力"),
        ("/surveys/manage.php", "臨時の調査"),
    ]:
        status_code, _location, _page_html = admin_client.get(content_path)
        check("K-026 管理者は%sを開けない（403）" % content_label, status_code == 403, status_code)
    admin_menu_html = admin_client.get("/index.php")[2]
    check("K-026 管理者のメニューに、出力・臨時の調査が出ない",
          "/export/index.php" not in admin_menu_html and "/surveys/manage.php" not in admin_menu_html)
    status_code, _location, search_html = admin_client.get(search_path(q="人手", dept=str(department_a_id)))
    check("K-026 管理者の検索は自分の報告だけ（ほかの課の報告は出ない）", status_code == 200 and "/reports/view.php?id=" not in search_html, status_code)
    status_code, _location, comment_list_html = admin_client.get("/reports/comments.php?tab=sent&status=unresolved")
    check("K-026 管理者のコメントの一覧に、ほかの課のコメントは出ない", status_code == 200 and "#comment-" not in comment_list_html, status_code)
    status_code, _location, export_html = kacho_a_client.get("/export/index.php")
    check("K-026 課長は出力で「限定」を含める欄を選べる", status_code == 200 and 'name="restricted"' in export_html, status_code)

    # ================================================================
    # G-14 組織の改訂（K-029〜K-031）
    # ================================================================
    org_edit_path_a = "/admin/org.php?tab=departments&edit=%d" % department_a_id
    org_edit_path_b = "/admin/org.php?tab=departments&edit=%d" % department_b_id

    def save_succession(successor_edit_path, successor_department_id, predecessor_department_id_list):
        status_code, _location, page_html = admin_client.get(successor_edit_path)
        return admin_client.post(successor_edit_path, {"csrf_token": csrf(page_html), "request_key": os.urandom(16).hex(), "action": "succession_save",
                                                       "dept_id": str(successor_department_id),
                                                       "predecessor_ids[]": [str(department_id) for department_id in predecessor_department_id_list]})

    report_b1_previous_url = "/reports/view.php?id=%d" % report_b1_previous_id
    status_code, _location, _page_html = kacho_a_client.get(report_b1_previous_url)
    check("前提: 課 A の課長は、課 B の報告を開けない（404）", status_code == 404, status_code)
    status_code, location, _page_html = save_succession(org_edit_path_a, department_a_id, [department_b_id])
    check("課 A が課 B を引き継ぐ設定を保存できる", status_code == 303 and database.execute(
        "SELECT 1 FROM department_successions WHERE predecessor_department_id = ? AND successor_department_id = ?",
        (department_b_id, department_a_id)).fetchone() is not None, (status_code, location))
    status_code, _location, view_html = kacho_a_client.get(report_b1_previous_url)
    check("引き継いだ課（課 A）の課長は、引き継ぎ前の課（課 B）の報告を開ける", status_code == 200 and "人手が足りない" in view_html, status_code)
    status_code, _location, search_html = kacho_a_client.get(search_path(q="人手", dept=str(department_a_id)))
    check("検索で、引き継ぎ前の課の報告も見つかる", "/reports/view.php?id=%d" % report_b1_previous_id in search_html)
    status_code, _location, org_list_html = admin_client.get("/admin/org.php?tab=departments")
    check("課の一覧に引き継ぎが出る", "引き継ぎ: " in org_list_html)
    save_succession(org_edit_path_b, department_b_id, [department_a_id])
    check("輪になる引き継ぎ（課 B が課 A を引き継ぐ）は保存しない", database.execute(
        "SELECT 1 FROM department_successions WHERE predecessor_department_id = ? AND successor_department_id = ?",
        (department_a_id, department_b_id)).fetchone() is None)
    status_code, _location, _page_html = member_b1_client.get(org_edit_path_a)
    check("部下は組織の画面を開けない（403）", status_code == 403, status_code)
    save_succession(org_edit_path_a, department_a_id, [])
    status_code, _location, _page_html = kacho_a_client.get(report_b1_previous_url)
    check("引き継ぎを外すと、また開けない", status_code == 404, status_code)

    # 役職者が辞めた: 利用者を無効にすると、確認者・課長の役割が外れる
    kacho_b_user_id = user_info_by_login_id["kacho_b"]["id"]
    user_edit_path = "/admin/user_edit.php?id=%d" % kacho_b_user_id
    status_code, _location, user_edit_html = admin_client.get(user_edit_path)
    kacho_b_row = database.execute("SELECT display_name, role_title, department_id FROM users WHERE id = ?", (kacho_b_user_id,)).fetchone()
    status_code, location, _page_html = admin_client.post(user_edit_path, {
        "csrf_token": csrf(user_edit_html), "request_key": rkey(user_edit_html), "action": "save",
        "display_name": kacho_b_row[0], "role_title": kacho_b_row[1], "department_id": str(kacho_b_row[2])})
    check("確認者・課長の人も無効にできる（役割は自動で外れる）", status_code == 303 and database.execute(
        "SELECT 1 FROM department_reviewers WHERE user_id = ?", (kacho_b_user_id,)).fetchone() is None, (status_code, location))
    status_code, _location, user_edit_html = admin_client.get(user_edit_path)
    check("外した役割を知らせる", "役割を外しました" in user_edit_html and "課長" in user_edit_html)
    status_code, _location, org_list_html = admin_client.get("/admin/org.php?tab=departments")
    check("課長がいない課を知らせる", "課長がいません" in org_list_html)

    # ================================================================
    # G-21 管理のトップ
    # ================================================================
    status_code, _location, admin_home_html = admin_client.get("/admin/index.php")
    check("管理者は管理のトップを開け、組織・案件・記録のカードが出る",
          status_code == 200 and "admin-card" in admin_home_html and "組織・ユーザー" in admin_home_html and "記録・削除データ" in admin_home_html, status_code)
    status_code, _location, admin_home_html = kacho_a_client.get("/admin/index.php")
    check("課長も管理のトップを開け、自分が使える管理の画面だけが出る",
          status_code == 200 and "項目マスタ" in admin_home_html and "組織・ユーザー" not in admin_home_html.split('class="admin-card-list"')[-1], status_code)
    status_code, _location, _page_html = member_a1_client.get("/admin/index.php")
    check("管理の画面がない部下は開けない（403）", status_code == 403, status_code)
    check("メニューの「管理」の先頭に「管理のトップ」", "/admin/index.php" in admin_client.get("/index.php")[2])
    status_code, _location, org_html = admin_client.get("/admin/org.php?tab=departments")
    check("管理画面に道しるべ（管理から組織・ユーザーへ）と管理画面の切り替えが出る",
          'class="admin-nav"' in org_html and 'class="breadcrumb"' in org_html and "admin-section-tabs" in org_html)

    return summary()


if __name__ == "__main__":
    sys.exit(main())
