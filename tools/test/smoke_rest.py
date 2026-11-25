#!/usr/bin/env python3
"""通っていなかった画面・状態の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

一覧に中身があるときの表示、件数が多いとき、組織の CSV の取り込みの誤り、ログインのロック、仮のパスワードの段階、
JavaScript なしのチャット、前月との比べ・引き継ぎ、古い形の DB の作り直し、コマンドの誤り。

    python3 tools/test/smoke_rest.py --base http://127.0.0.1:8000 --db app/data/verify.sqlite --php <php>
"""
import argparse
import datetime
import glob
import json
import os
import re
import secrets
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import lib_http  # noqa: E402
from lib_http import Client, check, csrf, login, section, summary  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
MOBILE = "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8000")
    ap.add_argument("--db", required=True)
    ap.add_argument("--php", required=True)
    a = ap.parse_args()
    here = os.path.dirname(os.path.abspath(__file__))
    seed = json.load(open(os.path.join(here, "output_dev_users.json"), encoding="utf-8"))
    users = seed["users"]
    db = sqlite3.connect(a.db, timeout=10, isolation_level=None)
    now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    today = datetime.date.today()
    period = today.strftime("%Y-%m")
    previous = (today.replace(day=1) - datetime.timedelta(days=1)).strftime("%Y-%m")
    due = (today + datetime.timedelta(days=5)).isoformat()
    has_goals = os.path.exists(os.path.join(ROOT, "public", "goals", "edit.php"))
    clients = {name: login(a.base, name, users[name]) for name in ("admin01", "kacho_a", "kacho_b", "leader_a", "ippan_a1", "ippan_a2", "hosa01")}

    def one(sql, *values):
        row = db.execute(sql, values).fetchone()
        return row[0] if row else None

    def post(name, path, action, values=None):
        client = clients[name]
        _, _, body = client.get("/account.php?tab=password")
        data = {"csrf_token": csrf(body), "request_key": secrets.token_hex(16), "action": action}
        data.update(values or {})
        return client.post(path, data)

    def followed(name, path, action, values=None):
        status, location, body = post(name, path, action, values)
        return clients[name].follow_get(location)[1] if status in (302, 303) else body

    def page(name, path, mobile=False):
        client = clients[name]
        kept = client.headers
        if mobile:
            client.headers = {"User-Agent": MOBILE}
        status, _, body = client.get(path)
        client.headers = kept
        return body if status == 200 else "HTTP %s" % status

    for login_id in ("ippan_a1", "ippan_a2"):
        db.execute("UPDATE report_records SET deleted_at = ? WHERE user_id = ? AND deleted_at IS NULL", (now, users[login_id]["id"]))
    owner = users["ippan_a1"]["id"]
    ids = dict(db.execute("SELECT code, id FROM report_fields"))
    project = one("SELECT id FROM projects WHERE code = 'P-0001'")
    second_project = one("SELECT id FROM projects WHERE code = 'P-0002'")
    good = {"b[0][project]": project, "b[0][f][%d]" % ids["progress"]: "on_track", "b[0][f][%d]" % ids["continuation"]: "continuing",
            "b[0][f][%d]" % ids["duties"]: "前の担当", "r[%d]" % ids["trouble_note"]: "株式会社サンプルの悩み", "b[0][f][%d]" % ids["staffing"]: "increase",
            "b[0][f][%d]" % ids["staffing_note"]: "限定の中身"}

    # ---- 引き継ぎ・比べ ----
    section("G-04", "表・複数選択の引き継ぎ、変更なしの提出、前月との比べ")
    for code, label, kind, settings, options in (
            ("rest_multi", "残り複数", "multi_check", "{}", [("m1", "一つ目"), ("m2", "二つ目")]),
            ("rest_table", "残り表", "table", json.dumps({"max_rows": 3, "columns": [{"key": "n", "label": "名", "type": "single_text"}]}), [])):
        db.execute("INSERT INTO report_fields (code, label, field_type, department_id, field_scope, confidentiality, sort_order, is_active, is_required, settings_json, carry_over, include_in_export, created_at, updated_at)"
                   " VALUES (?, ?, ?, NULL, 'report', 'normal', 900, 1, 0, ?, 1, 1, ?, ?)", (code, label, kind, settings, now, now))
        ids[code] = one("SELECT id FROM report_fields WHERE code = ?", code)
        for option_code, option_label in options:
            db.execute("INSERT INTO report_field_options (field_id, code, label, sort_order, is_active) VALUES (?, ?, ?, 10, 1)", (ids[code], option_code, option_label))
    with_rest = dict(good, period=previous)
    with_rest["r[%d][]" % ids["rest_multi"]] = ["m1", "m2"]
    with_rest["r[%d][0][n]" % ids["rest_table"]] = "表の一行"
    with_rest["b[1][project]"] = second_project
    with_rest["b[1][f][%d]" % ids["progress"]] = "on_track"
    with_rest["b[1][f][%d]" % ids["continuation"]] = "continuing"
    post("ippan_a1", "/reports/save.php", "draft", with_rest)
    home_html = page("ippan_a1", "/index.php")
    check("下書きがあり、ホームに「続きを書く」か前月の案内が出る", one("SELECT COUNT(*) FROM report_records WHERE user_id = ? AND status = 'draft' AND deleted_at IS NULL", owner) >= 1 and ("続きを書く" in home_html or "報告がまだです" in home_html))
    post("ippan_a1", "/reports/save.php", "submit", with_rest)
    previous_id = one("SELECT id FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NULL", owner, previous)
    check("準備: 前月の提出", one("SELECT status FROM report_records WHERE id = ?", previous_id) == "submitted")
    check("提出状況に「注意」の札が出る", "注意" in page("kacho_a", "/status/index.php?period=%s&dept=%d&filter=warning" % (previous, seed["dept_a"])))
    db.execute("UPDATE projects SET is_active = 0 WHERE id = ?", (second_project,))
    db.execute("UPDATE report_field_options SET is_active = 0 WHERE field_id = ? AND code = 'm2'", (ids["rest_multi"],))
    body = page("ippan_a1", "/reports/edit.php?period=" + period)
    check("終了した案件は引き継がず、知らせる", "終了したため外しました" in body)
    check("表・複数選択（有効なものだけ）を引き継ぐ", "表の一行" in body and re.search(r'value="m1"[^>]*checked', body) is not None and re.search(r'value="m2"[^>]*checked', body) is None)
    body = page("ippan_a1", "/reports/edit.php?period=%s&copy_target=%d" % (period, previous_id))
    check("写した自由記述にも注意を出す", re.search(r'field-warning(?![^>]*hidden)', body) is not None)
    post("ippan_a1", "/reports/save.php", "unchanged", {"period": period})
    current_id = one("SELECT id FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NULL", owner, period)
    stored = one("SELECT value_text FROM report_field_values WHERE report_id = ? AND field_id = ?", current_id, ids["rest_table"])
    check("変更なしで提出しても、表の値が入る", one("SELECT status FROM report_records WHERE id = ?", current_id) == "submitted" and stored is not None)
    body = page("kacho_a", "/reports/view.php?id=%d" % current_id)
    check("詳細に前月との比べが出る", "前月" in body)
    db.execute("UPDATE report_records SET free_text_purged_at = ? WHERE id = ?", (now, previous_id)) if "free_text_purged_at" in [r[1] for r in db.execute("PRAGMA table_info(report_records)")] else None
    status = clients["kacho_a"].get("/reports/view.php?id=%d" % current_id)[0]
    check("前月の自由記述が保管期限で消えていても開く", status == 200, status)
    check("確認者（限定を見られない人）も前月との比べを開ける", clients["leader_a"].get("/reports/view.php?id=%d" % current_id)[0] == 200)
    post("kacho_a", "/reports/act.php", "confirm", {"report_id": current_id})
    check("確認完了の報告に取り消しのボタンが出る", 'value="unconfirm"' in page("kacho_a", "/reports/view.php?id=%d" % current_id))
    status, _, _ = post("kacho_a", "/reports/act.php", "confirm", {"report_id": current_id})
    check("確認完了をもう一度はできない", status == 400, status)
    db.execute("UPDATE report_records SET is_legacy = 1 WHERE id = ?", (previous_id,))
    check("移行データの印が出る", "移行データ" in page("kacho_a", "/reports/view.php?id=%d" % previous_id))
    db.execute("UPDATE report_records SET is_legacy = 0 WHERE id = ?", (previous_id,))
    # 10 件の案件に、もう 1 件だけ写すのを断る
    post("ippan_a2", "/reports/save.php", "submit", dict(good, period=previous))
    copy_id = one("SELECT id FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NULL", users["ippan_a2"]["id"], previous)
    many = {"period": period}
    for number in range(10):
        db.execute("INSERT INTO projects (code, alias, is_active, created_at, updated_at) VALUES (?, '', 1, ?, ?)", ("R-%04d" % number, now, now))
        many["b[%d][project]" % number] = one("SELECT id FROM projects WHERE code = ?", "R-%04d" % number)
    post("ippan_a2", "/reports/save.php", "draft", many)
    status, _, body = clients["ippan_a2"].get("/reports/edit.php?period=%s&copy_target=%d-%d" % (period, copy_id, project))
    check("案件 10 件のとき、もう 1 件だけ写すのを断る", status == 400 and "10 件" in body, status)
    home_html_a2 = page("ippan_a2", "/index.php")
    check("ippan_a2 に下書きがあり、ホームに続きの案内が出る", one("SELECT COUNT(*) FROM report_records r JOIN users u ON u.id = r.user_id WHERE u.login_id = 'ippan_a2' AND r.status = 'draft' AND r.deleted_at IS NULL") >= 1 and ("続きを書く" in home_html_a2 or "報告がまだです" in home_html_a2))
    for number in range(30):
        month = (today.replace(day=1) - datetime.timedelta(days=31 * (number + 8))).strftime("%Y-%m")
        db.execute("INSERT INTO report_records (user_id, department_id, period, status, is_legacy, created_at, updated_at) SELECT ?, ?, ?, 'confirmed', 0, ?, ? WHERE NOT EXISTS (SELECT 1 FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NULL)",
                   (owner, seed["dept_a"], month, now, now, owner, month))
    check("自分の報告が多いとき、続きのリンクが出る", "more" in page("ippan_a1", "/reports/mine.php") or "page=" in page("ippan_a1", "/reports/mine.php") or "すべて" in page("ippan_a1", "/reports/mine.php"))

    section("G-09", "横並び・検索の表示")
    check("報告がない月の横並びに、空の知らせが出る", "提出済みの報告がありません" in page("kacho_a", "/status/compare.php?period=2020-01&dept=%d" % seed["dept_a"]))
    db.execute("UPDATE report_field_options SET is_active = 0 WHERE field_id = ? AND code = 'other'", (ids["phase"],))
    search_form_html = page("kacho_a", "/reports/search.php")
    check("無効の選択肢は検索の条件に出さず、有効な選択肢は出す", "%d:other" % ids["phase"] not in search_form_html and "%d:on_track" % ids["progress"] in search_form_html, "%d:other" % ids["phase"])
    leader_search_html = page("leader_a", "/reports/search.php?search=1&from=%s&to=%s&q=%s" % (previous, period, urllib.parse.quote("前の担当")))
    check("確認者の検索の画面が出て、絞り込みの条件が出る", "検索" in leader_search_html and "選択式の値" in leader_search_html)
    check("検索: 自分の報告だけ", "view.php?id=%d" % current_id in page("ippan_a1", "/reports/search.php?search=1&from=%s&to=%s&q=%s" % (previous, period, urllib.parse.quote("前の担当"))))

    # ---- 臨時の調査 ----
    section("G-11", "調査の結果・個人の回答・月次の項目への移し替え")
    _, location, _ = post("kacho_a", "/surveys/survey_edit.php", "save_meta", {"department_id": seed["dept_a"], "title": "結果の確かめ", "requester": "", "purpose": "", "due_at": due, "result_visibility": "detail", "target_type": "all"})
    survey_id = int(re.search(r"id=(\d+)", location).group(1))
    edit = "/surveys/survey_edit.php?id=%d" % survey_id
    post("kacho_a", edit, "add_custom", {"q_label": "複数の質問", "q_type": "multi_check", "q_options": "赤\n青", "q_conf": "internal", "q_help": ""})
    post("kacho_a", edit, "add_custom", {"q_label": "文の質問", "q_type": "multi_text", "q_options": "", "q_conf": "internal", "q_help": ""})
    post("kacho_a", edit, "add_custom", {"q_label": "限定の質問", "q_type": "radio", "q_options": "はい", "q_conf": "restricted", "q_help": ""})
    check("課長は限定の質問を作れる", one("SELECT COUNT(*) FROM adhoc_request_fields WHERE request_id = ? AND confidentiality = 'restricted'", survey_id) == 1)
    post("kacho_a", edit, "publish")
    questions = [r[0] for r in db.execute("SELECT id FROM adhoc_request_fields WHERE request_id = ? ORDER BY sort_order", (survey_id,))]
    post("ippan_a1", "/surveys/answer.php?id=%d" % survey_id, "", {"id": survey_id, "q[%d][]" % questions[0]: ["o1", "o2"], "q[%d]" % questions[1]: "個人の文", "q[%d]" % questions[2]: "o1"})
    result = "/surveys/result.php?id=%d" % survey_id
    body = page("kacho_a", result)
    check("集計に（複数回答）と件数が出る", "（複数回答）" in body and "赤" in body)
    check("個人の回答は、押すまで出さない", "個人の文" not in body)
    body = page("kacho_a", result + "&detail=1")
    check("個人の回答の表に、回答が出る", "個人の文" in body)
    check("個人の回答を開いた記録が残る", one("SELECT COUNT(*) FROM access_logs WHERE target = ?", "survey:%d:responses" % survey_id) >= 1)
    status, _, body = post("kacho_a", "/export/index.php", "", {"type": "survey", "format": "print", "survey_id": survey_id, "free": "1", "restricted": "1"})
    check("印刷用の画面に（複数回答）と個人の回答が出る", status == 200 and "（複数回答）" in body and "個人の文" in body, status)
    body = page("kacho_a", "/admin/field_edit.php?dept=%d&from_q=%d" % (seed["dept_a"], questions[0]))
    check("調査の質問を、月次の項目の初期値にできる", "複数の質問" in body and "赤" in body)
    post("kacho_a", edit, "close")
    check("締め切った調査の回答の画面", "締め切られました" in page("ippan_a1", "/surveys/answer.php?id=%d" % survey_id))
    db.execute("UPDATE users SET is_executive = 1 WHERE id = ?", (users["leader_a"]["id"],))
    _, location, _ = post("admin01", "/surveys/survey_edit.php", "save_meta", {"department_id": "all", "title": "全課の確かめ", "requester": "", "purpose": "", "due_at": due, "result_visibility": "summary", "target_type": "all"})
    all_id = int(re.search(r"id=(\d+)", location).group(1))
    check("結果を見られる人の候補に「部長以上」の札", "部長以上" in page("admin01", "/surveys/survey_edit.php?id=%d" % all_id))
    post("admin01", "/surveys/survey_edit.php?id=%d" % all_id, "add_custom", {"q_label": "全課の質問", "q_type": "radio", "q_options": "はい\nいいえ", "q_conf": "internal", "q_help": ""})
    post("admin01", "/surveys/survey_edit.php?id=%d" % all_id, "publish")
    all_question = one("SELECT id FROM adhoc_request_fields WHERE request_id = ?", all_id)
    post("ippan_a1", "/surveys/answer.php?id=%d" % all_id, "", {"id": all_id, "q[%d]" % all_question: "o1"})
    for kind in ("csv", "xlsx", "print"):
        status, _, _ = post("admin01", "/surveys/result.php?id=%d" % all_id, "", {"format": kind})
        check("全課共通の出力: " + kind, status == 200, status)
    check("全課共通の結果（スマートフォン）に回答の状況が出る", "回答" in page("admin01", "/surveys/result.php?id=%d" % all_id, mobile=True))
    check("部長以上の人の調査の一覧", "全課の確かめ" in page("leader_a", "/surveys/results.php"))
    db.execute("UPDATE adhoc_request_targets SET user_id = user_id WHERE request_id = ?", (all_id,))

    # ---- 組織の CSV ----
    section("G-14", "組織の CSV の取り込みの誤り・予約の誤り・役の印")
    bulk = "/admin/org.php?tab=bulk"

    def upload(content):
        _, _, body = clients["admin01"].get(bulk)
        return clients["admin01"].post_file(bulk, {"csrf_token": csrf(body), "action": "bulk_preview"}, "org_csv", "org.csv", content)

    _, _, body = post("admin01", bulk, "bulk_preview")
    check("ファイルを選ばない", "CSV のファイルを選んでください" in body)
    check("1 MB を超える", "大きすぎます" in upload(b"a" * (1024 * 1024 + 10))[2])
    check("UTF-8 でない", "UTF-8" in upload("課".encode("cp932"))[2])
    clients["admin01"].get("/admin/org.php?tab=bulk&csv=1")
    current_csv = clients["admin01"].last_raw
    body = upload(current_csv)[2]
    check("変わる内容がない CSV", "HTTP" not in body and 'value="bulk_apply"' not in body, body[:80])
    body = followed("admin01", bulk, "bulk_schedule", {"plan_token": "0" * 32, "apply_on": due})
    check("取り込んでいない予約を断る", "もう一度取り込んでください" in body)
    changed = current_csv.decode("utf-8-sig").replace("\r\n", "\n").split("\n")
    changed = "\n".join(line.replace("A課", "B課", 1) if "ippan_a2" in line else line for line in changed).encode()
    body = upload(changed)[2]
    token = re.search(r'name="plan_token" value="([0-9a-f]+)"', body)
    check("変わる内容のある CSV を取り込める", token is not None)
    if token:
        body = followed("admin01", bulk, "bulk_schedule", {"plan_token": token.group(1), "apply_on": today.isoformat()})
        check("今日の予約を断る", "明日から 1 年以内" in body)
        body = upload(changed)[2]
        token = re.search(r'name="plan_token" value="([0-9a-f]+)"', body)
        post("admin01", bulk, "bulk_schedule", {"plan_token": token.group(1), "apply_on": due})
        check("予約できる", one("SELECT COUNT(*) FROM org_schedules WHERE status = 'scheduled' AND apply_on = ?", due) == 1)
        check("管理のトップに予約が出る", "予約" in page("admin01", "/admin/index.php"))
    db.execute("UPDATE users SET locked_until = ? WHERE id = ?", ((datetime.datetime.now() + datetime.timedelta(minutes=10)).strftime("%Y-%m-%d %H:%M:%S"), users["ippan_b1"]["id"]))
    check("管理のトップにロック中の人が出る", "ロック中の人" in page("admin01", "/admin/index.php"))
    db.execute("UPDATE users SET locked_until = NULL WHERE id = ?", (users["ippan_b1"]["id"],))
    post("admin01", "/admin/user_edit.php", "save", {"display_name": "補佐の人", "role_title": "一般", "department_id": "", "login_id": "rest_deputy", "is_active": "1", "is_admin_deputy": "1", "is_admin_deputy_lead": "1"})
    deputy = one("SELECT id FROM users WHERE login_id = 'rest_deputy'")
    check("補佐（開発担当）の印を付けて足せる", deputy is not None and one("SELECT is_admin_deputy_lead FROM users WHERE id = ?", deputy) == 1)
    db.execute("INSERT INTO department_admins (department_id, user_id) VALUES (?, ?)", (seed["dept_a"], deputy))
    body = followed("admin01", "/admin/user_edit.php?id=%d" % deputy, "save", {"display_name": "補佐の人", "role_title": "一般", "department_id": ""})
    check("無効にすると、項目管理者から外して知らせる", one("SELECT COUNT(*) FROM department_admins WHERE user_id = ?", deputy) == 0 and "項目管理者" in body)
    db.execute("UPDATE department_reviewers SET is_final = 0 WHERE department_id = ? AND user_id = ?", (seed["dept_b"], users["kacho_b"]["id"]))
    db.execute("INSERT INTO department_reviewers (department_id, user_id, is_final, sort_order) VALUES (?, ?, 1, 10)", (seed["dept_b"], users["kacho_a"]["id"]))
    check("2 つの課の課長: 課の管理・定型文で課を選べる", "name=\"dept\"" in page("kacho_a", "/admin/department.php") and "name=\"dept\"" in page("kacho_a", "/admin/comment_templates.php?scope=department"))
    db.execute("DELETE FROM department_reviewers WHERE department_id = ? AND user_id = ?", (seed["dept_b"], users["kacho_a"]["id"]))
    db.execute("UPDATE department_reviewers SET is_final = 1 WHERE department_id = ? AND user_id = ?", (seed["dept_b"], users["kacho_b"]["id"]))

    section("G-15", "一覧に中身があるとき・件数が多いとき")
    db.execute("UPDATE report_field_comments SET deleted_at = NULL")
    post("kacho_a", "/reports/act.php", "comment", {"report_id": current_id, "field_id": "", "report_project_id": "0", "comment": "消すコメント", "comment_type": "note"})
    deleted_comment = one("SELECT MAX(id) FROM report_field_comments")
    post("kacho_a", "/reports/act.php", "comment_delete", {"report_id": current_id, "comment_id": deleted_comment})
    post("kacho_a", edit, "delete")
    body = page("admin01", "/admin/trash.php")
    check("削除データに、コメントと調査が出る", "restore_comment" in body and "restore_survey" in body)
    db.executemany("INSERT INTO access_logs (user_id, action, target, created_at) VALUES (1, 'view', 'rest', ?)", [(now,)] * 10100)
    check("記録が多いとき、次のページのリンクが出る", "page=2" in page("admin01", "/admin/logs.php"))
    status, _, _ = clients["admin01"].get("/admin/logs.php?csv=1")
    check("記録が 10,000 件を超える CSV を断る", status == 400, status)
    db.execute("DELETE FROM access_logs WHERE target = 'rest'")
    for number in range(205):
        db.execute("INSERT INTO report_field_comments (report_id, report_project_id, user_id, comment_type, thread_status, comment_enc, created_at, updated_at) VALUES (?, 0, ?, 'request', 'open', '', ?, ?)", (current_id, users["kacho_a"]["id"], now, now))
    check("コメントが 200 件を超えるときの知らせ", "200 件" in page("kacho_a", "/reports/comments.php?tab=sent&status=open"))
    db.execute("DELETE FROM report_field_comments WHERE comment_enc = ''")

    section("G-13", "案件の編集・終了した案件")
    check("案件の編集の欄に、更新の操作が出る", 'value="update"' in page("admin01", "/admin/projects.php?edit=%d" % project))
    body = followed("admin01", "/admin/projects.php", "add", {"code": "R-9001", "alias": "株式会社サンプル", "department_id": ""})
    check("客先名らしい呼び名に注意を出す", "客先名" in body, re.findall(r'flash[^>]*>([^<]{0,60})', body)[:3])
    post("admin01", "/admin/projects.php", "update", {"id": project, "alias": "株式会社サンプル", "department_id": ""})
    status, _, _ = post("admin01", "/admin/projects.php", "update", {"id": 99999, "alias": "x", "department_id": ""})
    check("無い案件の直し", status == 404, status)
    section("G-17", "メッセージ・お知らせの編集の画面")
    check("合う文言がないとき、無しの知らせが出る", "該当する文言はありません" in page("hosa01", "/admin/messages.php?q=" + urllib.parse.quote("どこにもない文言xyz")))
    screen_match = re.search(r'<option value="(G-[^"]+)"', page("hosa01", "/admin/messages.php"))
    if screen_match:
        check("画面で絞り込むと、その画面が選ばれている", ('value="%s" selected' % screen_match.group(1)) in page("hosa01", "/admin/messages.php?screen=" + urllib.parse.quote(screen_match.group(1))))
    else:
        check("画面の選択肢がある", False)
    post("hosa01", "/admin/messages.php", "save", {"msg_key": "保存しました", "body": "保存できました"})
    check("文言を変えられる", one("SELECT body FROM ui_messages WHERE msg_key = '保存しました'") == "保存できました")
    status, _, _ = post("hosa01", "/admin/messages.php", "delete_unused", {"msg_key": "保存しました"})
    check("使っている文言は消せない", status == 400, status)
    _, _, body = post("hosa01", "/admin/messages.php", "save", {"msg_key": "保存しました", "body": "ab"})
    check("使えない文字の文言を断る", "使えない文字" in body and one("SELECT body FROM ui_messages WHERE msg_key = '保存しました'") == "保存できました")
    post("hosa01", "/admin/messages.php", "reset", {"msg_key": "保存しました"})
    check("既定に戻せる", one("SELECT COUNT(*) FROM ui_messages WHERE msg_key = '保存しました'") == 0)
    post("admin01", "/admin/notices.php", "save", {"id": "", "title": "編集する", "body": "", "show_from": today.isoformat(), "show_until": due})
    notice_id = one("SELECT MAX(id) FROM update_notices")
    check("お知らせの編集の画面", "新しく書く方に戻る" in page("admin01", "/admin/notices.php?edit=%d" % notice_id))

    section("G-22", "要望・依頼の表示")
    wish = {"kind": "question", "urgency": "urgent", "screen_code": "G-04", "title": "急ぎの質問", "body": "株式会社サンプルの本文", "source_path": ""}
    post("kacho_a", "/requests/index.php", "create", wish)
    request_id = one("SELECT MAX(id) FROM support_requests")
    view = "/requests/view.php?id=%d" % request_id
    check("急ぎの札・注意の知らせが出る", "急ぎ" in page("admin01", view) and "急ぎ" in page("admin01", "/requests/index.php"))
    check("自分の分だけの一覧（要望・依頼）が開く", "要望・依頼" in page("admin01", "/requests/index.php?scope=mine"))
    check("無い要望のお知らせの元・要望からのお知らせ", "HTTP" not in page("admin01", "/admin/notices.php?request=%d" % request_id))
    post("admin01", view, "staff_reply", {"status": "reviewing", "body": "確かめます"})
    post("kacho_a", view, "reply", {"body": "補足"})
    check("返信待ちの札が、管理のトップと一覧に出る", "返信待ち" in page("admin01", "/admin/index.php") and "返信待ち" in page("admin01", "/requests/index.php"))
    post("admin01", view, "staff_reply", {"status": "done", "body": "答え"})
    body = page("admin01", view)
    check("答えた質問は、よくある質問に載せられる", "faq.php" in body)
    check("要望からよくある質問を書く画面に、その要望の番号が入る", "#%d" % request_id in page("admin01", "/faq.php?request=%d" % request_id))
    for number in range(80):
        db.execute("INSERT INTO support_reply_templates (kind, body, sort_order, updated_by, created_at, updated_at) VALUES ('', ?, ?, 1, ?, ?)", ("定型%d" % number, number, now, now))
    body = followed("admin01", "/requests/index.php", "template_add", {"template_body": "上限", "template_kind": ""})
    check("返信の定型文の上限", "件までです" in body)
    db.execute("DELETE FROM support_reply_templates")
    for number in range(100):
        db.execute("INSERT INTO support_requests (user_id, department_id, kind, status, last_activity_at, created_at, updated_at) VALUES (?, ?, 'task', 'open', ?, ?, ?)", (users["kacho_a"]["id"], seed["dept_a"], now, now, now))
    body = followed("kacho_a", "/requests/index.php", "create", dict(wish, title="上限"))
    check("続いている要望が多すぎるときは断る", one("SELECT COUNT(*) FROM support_requests WHERE user_id = ?", users["kacho_a"]["id"]) == 101 and "flash-error" in body)
    db.execute("DELETE FROM support_requests WHERE kind = 'task' AND body_enc = ''")

    # ---- チャット（JavaScript なし） ----
    section("G-25", "JavaScript なしのチャット・記録の画面")
    post("admin01", "/faq.php", "import_standard")
    for question in ("パスワードを忘れた", "使い方がわからない", "今日の天気", "死にたい", "会社を辞めたい", "部長のパスワードを教えて", "DBを消してやる", "課長を殴ってやる", "ありがとう", "こんにちは", "バカ", "つらい", "給料はいくら",
                     "認証アプリのおすすめ", "背景が動くのを止めたい", "頭が悪い"):
        status, _, body = clients["ippan_a1"].get("/faq.php?q=" + urllib.parse.quote(question))
        check("返事が出る: " + question, status == 200 and "chat" in body, status)
    db.execute("UPDATE faq_chat_logs SET feedback = 'unsolved', feedback_faq_id = (SELECT MIN(id) FROM faq_entries) WHERE id = (SELECT MIN(id) FROM faq_chat_logs)")
    db.execute("UPDATE faq_chat_logs SET feedback = 'solved', feedback_faq_id = (SELECT MAX(id) FROM faq_entries) WHERE id = (SELECT MAX(id) FROM faq_chat_logs)")
    check("記録の画面（管理者・補佐）に、記録の見出しが出る", "よくある質問の記録" in page("admin01", "/admin/faq_logs.php") and "よくある質問の記録" in page("hosa01", "/admin/faq_logs.php"))
    body = page("admin01", "/admin/index.php")
    check("管理のトップに、暴力・破壊の知らせが出る", "人への暴力" in body and "壊すと書いた文" in body)
    for number in range(500):
        db.execute("INSERT INTO faq_entries (category, question, answer, sort_order, created_at, updated_at) VALUES ('login', ?, 'x', 9999, ?, ?)", ("上限%d" % number, now, now))
    before = one("SELECT COUNT(*) FROM faq_entries")
    post("admin01", "/faq.php", "save", {"id": "", "category": "login", "question": "上限を超える", "answer": "x"})
    check("よくある質問の上限", one("SELECT COUNT(*) FROM faq_entries") == before)
    db.execute("DELETE FROM faq_entries WHERE question LIKE '上限%'")

    if has_goals:
        section("G-26", "精算書の一覧・読めない精算書")
        offices = re.findall(r'<option value="([^"]+)"', re.search(r'<select id="office".*?</select>', page("ippan_a1", "/expenses/edit.php"), re.S).group(0))
        edit_body = page("ippan_a1", "/expenses/edit.php")
        transport = re.findall(r'<option value="([^"]+)"', re.search(r'name="rows\[0\]\[transport\].*?</select>', edit_body, re.S).group(0))
        trip = re.findall(r'<option value="([^"]+)"', re.search(r'name="rows\[0\]\[trip\].*?</select>', edit_body, re.S).group(0))
        sheet = {"payment_method": "transfer", "submitted_on": today.isoformat(), "employee_number": "12", "office": offices[0], "department_name": "A課", "full_name": "検証", "advance_amount": ""}
        for number in range(20):
            sheet.update({"rows[%d][start_date]" % number: "2026-11-01", "rows[%d][end_date]" % number: "2027-04-30", "rows[%d][purpose]" % number: "通勤", "rows[%d][transport]" % number: transport[0],
                          "rows[%d][route]" % number: "甲～乙", "rows[%d][trip]" % number: trip[0], "rows[%d][amount]" % number: "1000"})
        status, location, body = post("ippan_a1", "/expenses/edit.php", "save", sheet)
        sheet_id = one("SELECT MAX(id) FROM expense_sheets")
        check("20 行の精算書を保存できる", sheet_id is not None, re.findall(r"flash-error[^>]*>([^<]*)", body)[:3])
        status, _, _ = clients["ippan_a1"].get("/expenses/download.php?id=%s" % sheet_id)
        check("2 ページになる精算書の Excel を受け取れる", status == 200 and clients["ippan_a1"].last_raw[:2] == b"PK", status)
        body = page("ippan_a1", "/expenses/index.php")
        check("一覧に精算書と Excel のリンクが出る", "download.php?id=%s" % sheet_id in body)
        check("スマートフォンの一覧には、PC で受け取る案内", "PC で受け取ってください" in page("ippan_a1", "/expenses/index.php", mobile=True) and "download.php?id=%s" % sheet_id not in page("ippan_a1", "/expenses/index.php", mobile=True))
        kept = one("SELECT data_enc FROM expense_sheets WHERE id = ?", sheet_id)
        db.execute("UPDATE expense_sheets SET data_enc = 'v1:xx' WHERE id = ?", (sheet_id,))
        check("読めない精算書: 一覧・編集が開き、知らせる", "HTTP" not in page("ippan_a1", "/expenses/index.php") and "読めません" in page("ippan_a1", "/expenses/edit.php?id=%s" % sheet_id))
        status, _, _ = clients["ippan_a1"].get("/expenses/download.php?id=%s" % sheet_id)
        check("読めない精算書は受け取れない", status != 200, status)
        db.execute("UPDATE expense_sheets SET data_enc = ? WHERE id = ?", (kept, sheet_id))
        section("G-27", "目標管理の表示・読めないシート・面談")
        status, location, _ = clients["kacho_a"].get("/goals/download_all.php?year=%d&dept=%d" % (today.year, seed["dept_a"]))
        check("シートがない年度のまとめた受け取り", status == 303, status)
        kind = re.search(r'name="kind" value="([^"]+)"', page("ippan_a1", "/goals/edit.php"))
        kind_code = kind.group(1) if kind else "general"
        post("ippan_a1", "/goals/edit.php", "save_owner", {"fiscal_year": re.search(r'name="fiscal_year" value="(\d+)"', page("ippan_a1", "/goals/edit.php")).group(1), "kind": kind_code, "full_name": "検証", "department_name": "A課", "position": "一般"})
        goal_id = one("SELECT MAX(id) FROM goal_sheets")
        check("シートを保存できる", goal_id is not None)
        if goal_id is not None:
            goal = "/goals/edit.php?id=%d" % goal_id
            check("課長の一覧・シートの表示", "HTTP" not in page("kacho_a", "/goals/index.php") and "HTTP" not in page("kacho_a", goal))
            status, _, _ = post("kacho_a", goal, "reply", {"sheet_id": goal_id, "parent_id": 99999, "body": "x"})
            check("無いコメントへの返信を断る", status == 404, status)
            status, _, _ = post("kacho_b", goal, "meeting", {"sheet_id": goal_id, "held_on": today.isoformat(), "note": "x"})
            check("ほかの課の課長の面談を断る", status in (403, 404), status)
            kept = one("SELECT data_enc FROM goal_sheets WHERE id = ?", goal_id)
            db.execute("UPDATE goal_sheets SET data_enc = 'v1:xx' WHERE id = ?", (goal_id,))
            check("読めないシートを知らせる", "読めません" in page("ippan_a1", goal))
            status, _, _ = post("ippan_a1", goal, "save_owner", {"sheet_id": goal_id})
            check("読めないシートの保存を断る", status == 409, status)
            status, _, _ = clients["ippan_a1"].get("/goals/download.php?id=%d" % goal_id)
            check("読めないシートは受け取れない", status != 200, status)
            db.execute("UPDATE goal_sheets SET data_enc = ? WHERE id = ?", (kept, goal_id))
        check("課の人がいない一覧", "HTTP" not in page("kacho_b", "/goals/index.php"))

    # ---- ログイン ----
    section("G-01", "ログインのロック・仮のパスワードの段階・時間切れ")
    far = (datetime.datetime.now() + datetime.timedelta(minutes=10)).strftime("%Y-%m-%d %H:%M:%S")

    def password_step(login_id, password):
        client = Client(a.base)
        _, _, body = client.get("/login.php")
        status, location, text = client.post("/login.php", {"csrf_token": csrf(body), "action": "password", "login_id": login_id, "password": password})
        return client, location, text

    client, location, _ = password_step("ippan_b1", users["ippan_b1"]["password"])
    _, _, body = client.get("/login.php?step=2")
    db.execute("UPDATE users SET locked_until = ? WHERE login_id = 'ippan_b1'", (far,))
    _, _, _ = client.post("/login.php", {"csrf_token": csrf(body), "action": "code", "code": "000000"})
    check("コードの段階でロックされていれば断る", "しばらくログインできません" in client.get("/login.php")[2])
    db.execute("UPDATE users SET locked_until = NULL, failed_count = 0 WHERE login_id = 'ippan_b1'")
    client, _, _ = password_step("ippan_b1", users["ippan_b1"]["password"])
    for attempt in range(12):
        _, _, body = client.get("/login.php?step=2")
        status, location, _ = client.post("/login.php", {"csrf_token": csrf(body), "action": "code", "code": "000000"})
        if one("SELECT locked_until FROM users WHERE login_id = 'ippan_b1'") is not None:
            break
    check("コードを間違え続けるとロックされる", one("SELECT locked_until FROM users WHERE login_id = 'ippan_b1'") is not None, attempt)
    db.execute("UPDATE users SET locked_until = NULL, failed_count = 0 WHERE login_id = 'ippan_b1'")
    for attempt in range(11):
        password_step("nobody%d" % attempt, "x")
    _, _, text = password_step("nobody", "x")
    check("無い ID を続けると、この端末からのログインをロックする", "しばらくログインできません" in text)
    client = Client(a.base)
    _, _, body = client.get("/password_reset.php")
    _, _, text = client.post("/password_reset.php", {"csrf_token": csrf(body), "login_id": "nobody", "code": "000000", "new_password": "Abcdefgh12345", "new_password_confirm": "Abcdefgh12345"})
    check("再設定もロックする", "しばらく操作できません" in text)
    db.execute("DELETE FROM login_throttle")
    old_hash = subprocess.run([a.php, "-r", "echo password_hash($argv[1], PASSWORD_BCRYPT, ['cost' => 4]);", users["ippan_b1"]["password"]], stdout=subprocess.PIPE).stdout.decode()
    db.execute("UPDATE users SET password_hash = ? WHERE login_id = 'ippan_b1'", (old_hash,))
    password_step("ippan_b1", users["ippan_b1"]["password"])
    check("古い強さのハッシュは、ログインのときに作り直す", one("SELECT password_hash FROM users WHERE login_id = 'ippan_b1'") != old_hash)
    db.execute("UPDATE users SET must_change_password = 1 WHERE login_id = 'ippan_b1'")
    member = login(a.base, "ippan_b1", users["ippan_b1"])
    _, _, body = member.get("/account.php?tab=password")
    for action in ("logout_all", "theme_save"):
        status, _, _ = member.post("/account.php", {"csrf_token": csrf(body), "action": action, "ui_theme": "dark"})
        check("仮のパスワードの段階では使えない: " + action, status == 403, status)
    db.execute("UPDATE users SET must_change_password = 0, totp_enabled_at = NULL WHERE login_id = 'ippan_b1'")
    client, location, _ = password_step("ippan_b1", users["ippan_b1"]["password"])
    _, _, body = client.get("/account.php?tab=totp")
    for action in ("codes_regen", "password_change", "theme_save"):
        status, _, _ = client.post("/account.php", {"csrf_token": csrf(body), "action": action})
        check("2 段階認証の設定前は使えない: " + action, status == 403, status)
    db.execute("UPDATE users SET totp_enabled_at = ? WHERE login_id = 'ippan_b1'", (now,))
    member = clients["ippan_a2"]
    _, _, body = member.get("/account.php?tab=password")
    for attempt in range(11):
        _, _, text = member.post("/account.php?tab=password", {"csrf_token": csrf(body), "action": "password_change", "current_password": "違う", "new_password": "Abcdefgh12345", "new_password_confirm": "Abcdefgh12345"})
    check("今のパスワードを間違え続けるとロックされる", "今のパスワードが違います" in text and one("SELECT locked_until FROM users WHERE login_id = 'ippan_a2'") is not None)
    db.execute("UPDATE users SET locked_until = NULL, failed_count = 0")
    section("共通", "課・所属がない人の画面")
    db.execute("UPDATE users SET department_id = NULL WHERE login_id = 'ippan_a2'")
    check("課のない人のホームに知らせが出る", "所属の課が設定されていません" in page("ippan_a2", "/index.php"))
    db.execute("UPDATE users SET department_id = ? WHERE login_id = 'ippan_a2'", (seed["dept_a"],))
    db.execute("UPDATE departments SET is_active = 0 WHERE id = ?", (seed["dept_b"],))
    check("無効の課の課長のホームが開き、役割の欄が出る", "あなたの役割と権限" in page("kacho_b", "/index.php"))
    db.execute("UPDATE departments SET is_active = 1 WHERE id = ?", (seed["dept_b"],))

    # ---- コマンドと古い形の DB ----
    section("DB", "古い形の DB の作り直し・コマンドの誤り")
    work = tempfile.mkdtemp(prefix="mr_rest_")
    backup_dir = os.path.join(ROOT, "app", "backups")
    kept_backups = set(glob.glob(os.path.join(backup_dir, "*")))
    had_backup_dir = os.path.isdir(backup_dir)
    try:
        old = os.path.join(work, "old.sqlite")
        target = sqlite3.connect(old)
        db.execute("PRAGMA wal_checkpoint(FULL)")
        db.backup(target)
        target.execute("PRAGMA foreign_keys = OFF")
        target.execute("PRAGMA legacy_alter_table = ON")
        ddl = target.execute("SELECT sql FROM sqlite_master WHERE name = 'adhoc_requests'").fetchone()[0]
        columns = ", ".join(r[1] for r in target.execute("PRAGMA table_info(adhoc_requests)"))
        target.execute("DELETE FROM adhoc_requests WHERE department_id IS NULL")
        for child in ("adhoc_request_fields", "adhoc_request_targets", "adhoc_responses", "adhoc_request_viewers"):
            target.execute("DELETE FROM %s WHERE request_id NOT IN (SELECT id FROM adhoc_requests)" % child)
        target.execute("DELETE FROM adhoc_response_values WHERE response_id NOT IN (SELECT id FROM adhoc_responses)")
        target.execute("ALTER TABLE adhoc_requests RENAME TO adhoc_requests_tmp")
        target.execute(re.sub(r"department_id INTEGER REFERENCES", "department_id INTEGER NOT NULL REFERENCES", ddl))
        target.execute("INSERT INTO adhoc_requests (%s) SELECT %s FROM adhoc_requests_tmp" % (columns, columns))
        target.execute("DROP TABLE adhoc_requests_tmp")
        chat_ddl = target.execute("SELECT sql FROM sqlite_master WHERE name = 'faq_chat_logs'").fetchone()[0]
        target.execute("DELETE FROM faq_chat_logs")
        target.execute("DROP TABLE faq_chat_logs")
        target.execute(re.sub(r",\s*conversation_id[^,\n]*", "", chat_ddl).replace("'violence', 'sabotage'", "'x'"))
        target.commit()
        counts = [target.execute("SELECT COUNT(*) FROM " + t).fetchone()[0] for t in ("adhoc_requests", "adhoc_request_fields", "adhoc_responses")]
        not_null = [r[3] for r in target.execute("PRAGMA table_info(adhoc_requests)") if r[1] == "department_id"]
        target.close()
        check("準備: 課が必須だった形の表", not_null == [1], not_null)

        def command(name, *arguments):
            done = subprocess.run([a.php, os.path.join("app", "bin", name)] + list(arguments), cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=120)
            return done.returncode, done.stdout.decode("utf-8", "replace")

        code, out = command("migrate.php", "--db=" + old)
        after = sqlite3.connect(old)
        check("migrate.php が表を作り直し、中身（調査・質問・回答）が残る", code == 0 and [r[3] for r in after.execute("PRAGMA table_info(adhoc_requests)") if r[1] == "department_id"] == [0]
              and [after.execute("SELECT COUNT(*) FROM " + t).fetchone()[0] for t in ("adhoc_requests", "adhoc_request_fields", "adhoc_responses")] == counts, out[-300:])
        check("作り直しても外部キーの食い違いがない", after.execute("PRAGMA foreign_key_check").fetchall() == [])
        after.close()
        runner = os.path.join(work, "chat.php")
        with open(runner, "w", encoding="utf-8") as handle:
            handle.write("<?php require %r; $GLOBALS['APP_CONFIG']['db_path'] = %r; faq_chat_log_ensure_table(); echo 'done';" % (os.path.join(ROOT, "app", "bootstrap.php").replace(os.sep, "/"), old.replace(os.sep, "/")))
        done = subprocess.run([a.php, runner], stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        after = sqlite3.connect(old)
        check("古い形のチャットの記録の表を、今の形に直す", "'violence', 'sabotage'" in after.execute("SELECT sql FROM sqlite_master WHERE name = 'faq_chat_logs'").fetchone()[0]
              and "conversation_id" in [r[1] for r in after.execute("PRAGMA table_info(faq_chat_logs)")], done.stdout.decode("utf-8", "replace")[-200:])
        after.close()
        code, out = command("create_user.php", "--login=あ", "--name=x")
        check("create_user.php: 形の誤り", code == 1 and "使い方" in out, out[-100:])
        code, out = command("create_user.php", "--login=ippan_a1", "--name=x")
        check("create_user.php: 同じ ID", code == 1 and "同じログイン ID" in out, out[-100:])
        code, out = command("create_user.php", "--login=rest_cli", "--name=検証", "--dept=新しい課", "--final")
        check("create_user.php: 無い課は作り、課長にできる", code == 0 and one("SELECT COUNT(*) FROM department_reviewers r JOIN users u ON u.id = r.user_id WHERE u.login_id = 'rest_cli' AND r.is_final = 1") == 1, out[-200:])
        code, out = command("seed_template.php", "--dept-a=99999")
        check("seed_template.php: 無い課", code != 0, out[-100:])
        empty = os.path.join(work, "empty.sqlite")
        sqlite3.connect(empty).close()
        for name, extra in (("migrate_legacy.php", []), ("import_tomato.php", ["--from=x"])):
            code, out = command(name, "--db=" + empty, *extra)
            check(name + ": 表がない DB を断る", code == 1 and "migrate.php" in out, out[-100:])
        fresh = os.path.join(work, "fresh.sqlite")
        command("migrate.php", "--db=" + fresh)
        code, out = command("migrate_legacy.php", "--db=" + fresh)
        check("migrate_legacy.php: 移行元がない", code == 0 and "移行元の報告がありません" in out, out[-100:])
        code, out = command("import_tomato.php", "--db=" + fresh)
        check("import_tomato.php: --from がない", code == 1, out[-100:])
        code, out = command("import_tomato.php", "--db=" + fresh, "--from=" + empty)
        check("import_tomato.php: tomato の DB でない", code == 1 and "tomato の DB ではない" in out, out[-100:])
        code, out = command("backup.php", "--db=" + os.path.join(work, "no", "such", "dir", "x.sqlite"))
        check("backup.php: DB が無いときは失敗を返し、空の写しを作らない", code == 1 and not (set(glob.glob(os.path.join(backup_dir, "reports_*"))) - kept_backups), (code, out[-100:]))

        def tomato(path, extra):
            connection = sqlite3.connect(path)
            connection.executescript("""
CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password_hash TEXT, role_title TEXT, is_admin INTEGER, created_at TEXT);
CREATE TABLE reports (id INTEGER PRIMARY KEY, user_id INTEGER, report_date TEXT, name TEXT, subject TEXT, content TEXT, issues TEXT, remarks TEXT, created_at TEXT, updated_at TEXT);
CREATE TABLE comments (id INTEGER PRIMARY KEY, report_id INTEGER, user_id INTEGER, field_name TEXT, comment TEXT, created_at TEXT, updated_at TEXT);
CREATE TABLE report_fields (id INTEGER PRIMARY KEY, code TEXT, label TEXT, field_type TEXT, is_system INTEGER, sort_order INTEGER);
CREATE TABLE report_field_options (id INTEGER PRIMARY KEY, field_id INTEGER, label TEXT, sort_order INTEGER, is_active INTEGER);
CREATE TABLE report_field_values (id INTEGER PRIMARY KEY, report_id INTEGER, field_id INTEGER, option_id INTEGER, value_number REAL, value_text TEXT);
INSERT INTO users VALUES (1, 'tomato.one@example.invalid', 'x', '一般', 0, 't'), (2, 'b@example.invalid', 'x', '謎', 1, 't');
INSERT INTO reports VALUES (1, 1, '2026-01-31', 'とても長い名前とても長い名前とても長い名前', '件名', '本文', '', '', 't', 't');
""" + extra)
            connection.commit()
            connection.close()

        good_db = os.path.join(work, "tomato_good.sqlite")
        tomato(good_db, """
INSERT INTO report_fields VALUES (1, 'content', '内容', 'textarea', 0, 1), (2, 'f_text', '文', 'text', 0, 2), (3, 'f_area', '長文', 'textarea', 0, 3), (4, 'f_num', '数', 'number', 0, 4),
 (5, 'f_sel', '選', 'select', 0, 5), (6, 'f_chk', '複', 'checkbox', 0, 6);
INSERT INTO report_field_options VALUES (1, 5, '甲', 1, 1), (2, 6, '赤', 1, 1), (3, 6, '青', 2, 0);
INSERT INTO report_field_values VALUES (1, 1, 2, NULL, NULL, '文の値'), (2, 1, 3, NULL, NULL, '長文の値'), (3, 1, 4, NULL, 12.50, NULL), (4, 1, 5, 1, NULL, NULL), (5, 1, 6, 2, NULL, NULL), (6, 1, 6, 3, NULL, NULL),
 (7, 1, 99, NULL, NULL, '無い項目'), (8, 1, 5, 99, NULL, NULL);
INSERT INTO comments VALUES (1, 1, 2, 'f_text', 'コメント', 't', 't'), (2, 99, 2, 'content', '消えた報告', 't', 't');""")
        code, out = command("import_tomato.php", "--db=" + fresh, "--from=" + good_db)
        imported = sqlite3.connect(fresh)
        values = dict(imported.execute("SELECT f.code, v.value_text FROM report_field_values v JOIN report_fields f ON f.id = v.field_id WHERE v.is_encrypted = 0"))
        check("tomato: 追加した項目（数・選択・複数選択）を取り込む", code == 0 and values.get("tomato_f_num") == "12.5" and values.get("tomato_f_sel") == "o1" and json.loads(values.get("tomato_f_chk", "[]")) == ["o2", "o3"], (out[-300:], values))
        check("tomato: 消えた報告のコメントは取り込まず、件数を知らせる", imported.execute("SELECT COUNT(*) FROM report_field_comments").fetchone() == (1,) and "取り込まず" in out, out[-300:])
        check("tomato: 知らない役職は一般、長い名前はログイン ID", imported.execute("SELECT role_title FROM users WHERE login_id = 'user2'").fetchone() == ("一般",)
              and imported.execute("SELECT display_name FROM users WHERE login_id = 'tomato.one'").fetchone() == ("tomato.one",))
        imported.close()
        bad_db = os.path.join(work, "tomato_bad.sqlite")
        tomato(bad_db, """
INSERT INTO users VALUES (3, 'tomato.one@other.invalid', 'x', '一般', 0, 't');
INSERT INTO reports VALUES (2, 99, 'x', 'a', '', '', '', '', 't', 't');
INSERT INTO report_fields VALUES (2, 'f_bad', '謎', 'unknown', 0, 2);
INSERT INTO comments VALUES (1, 1, 2, 'nothing', 'x', 't', 't');""")
        second = os.path.join(work, "second.sqlite")
        command("migrate.php", "--db=" + second)
        code, out = command("import_tomato.php", "--db=" + second, "--from=" + bad_db)
        check("tomato: 誤りのあるデータは、何も取り込まずに知らせる", code == 1 and all(x in out for x in ("もういます", "日付の形", "作成者がいません", "型が想定外", "項目が見つかりません"))
              and sqlite3.connect(second).execute("SELECT COUNT(*) FROM users").fetchone() == (0,), out[-400:])
        legacy = os.path.join(work, "legacy.sqlite")
        connection = sqlite3.connect(legacy)
        connection.executescript("""
CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, role_title TEXT NOT NULL DEFAULT '一般', manager_id INTEGER, is_admin INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL);
CREATE TABLE reports (id INTEGER PRIMARY KEY, user_id INTEGER, report_date TEXT, name TEXT, subject TEXT, content TEXT, issues TEXT, remarks TEXT, created_at TEXT, updated_at TEXT);
CREATE TABLE comments (id INTEGER PRIMARY KEY, report_id INTEGER, user_id INTEGER, field_name TEXT, comment TEXT, created_at TEXT, updated_at TEXT);
INSERT INTO users (username, password_hash, created_at) VALUES ('same@a.invalid', 'x', 't'), ('same@b.invalid', 'x', 't');
INSERT INTO reports VALUES (1, 1, 'x', 'a', '', '', '', '', 't', 't'), (2, 99, '2026-01-01', 'a', '', '', '', '', 't', 't');
INSERT INTO comments VALUES (1, 1, 1, 'nothing', 'x', 't', 't');""")
        connection.commit()
        connection.close()
        command("migrate.php", "--db=" + legacy)
        code, out = command("migrate_legacy.php", "--db=" + legacy)
        check("migrate_legacy.php: 誤りのあるデータは、何も移さずに知らせる", code == 1 and all(x in out for x in ("日付の形", "作成者がいません", "項目名が想定外")), out[-300:])
        connection = sqlite3.connect(legacy)
        connection.executescript("UPDATE reports SET report_date = '2026-01-02', user_id = 1; UPDATE comments SET field_name = 'content', report_id = 99;")
        connection.commit()
        connection.close()
        code, out = command("migrate_legacy.php", "--db=" + legacy)
        logins = [r[0] for r in sqlite3.connect(legacy).execute("SELECT login_id FROM users ORDER BY id")]
        check("migrate_legacy.php: 同じ名前には番号を付け、消えた報告のコメントは飛ばす", code == 0 and logins == ["same", "same2"], (logins, out[-200:]))
    finally:
        for path in set(glob.glob(os.path.join(backup_dir, "*"))) - kept_backups:
            os.remove(path)
        if not had_backup_dir and os.path.isdir(backup_dir) and not os.listdir(backup_dir):
            os.rmdir(backup_dir)
        shutil.rmtree(work, ignore_errors=True)
    db.close()
    return summary()


if __name__ == "__main__":
    sys.exit(main())
