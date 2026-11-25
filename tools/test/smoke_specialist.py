#!/usr/bin/env python3
"""専門職（技師・担当課長・担当部長）の確認（K-074。ローカル検証環境専用。dev_seed.php の直後に実行する）。

- 専門職の報告を見るのは課長だけ。担当部長の報告は本人だけ（報告・コメント・確認・横並び・検索・出力・提出状況・催促・課の調査・目標管理）
- 専門職は、ほかの人のものを見られない。課長・確認者・項目管理者になれない（画面・CSV・DB）
- 印を付ける前と外した後は、今までどおり

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_specialist.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import datetime
import json
import os
import re
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, csrf, login, section, summary  # noqa: E402

OWNER = "ippan_a2"
OWNER_NAME = "A一般2"


def main():
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--base", default="http://127.0.0.1:8000")
    argument_parser.add_argument("--db", required=True)
    arguments = argument_parser.parse_args()
    test_dir = os.path.dirname(os.path.abspath(__file__))
    seed = json.load(open(os.path.join(test_dir, "output_dev_users.json"), encoding="utf-8"))
    users = seed["users"]
    department_id = seed["dept_a"]
    database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)
    has_goals = os.path.exists(os.path.join(test_dir, "..", "..", "app", "lib", "goal.php"))
    today = datetime.date.today()
    period = (today.replace(day=1) - datetime.timedelta(days=1)).strftime("%Y-%m")
    due = (today + datetime.timedelta(days=5)).isoformat()
    clients = {}

    def client_of(login_id):
        if login_id not in clients:
            clients[login_id] = login(arguments.base, login_id, users[login_id])
        return clients[login_id]

    def uid(login_id):
        return users[login_id]["id"]

    def one(sql, *values):
        row = database.execute(sql, values).fetchone()
        return row[0] if row else None

    def post(login_id, path, values):
        _, _, page_html = client_of(login_id).get(path)
        return client_of(login_id).post(path, dict(values, csrf_token=csrf(page_html), request_key=os.urandom(16).hex()))

    def save_user(login_id, specialist_title):
        name, role_title, dept = database.execute("SELECT display_name, role_title, department_id FROM users WHERE id = ?", (uid(login_id),)).fetchone()
        return post("admin01", "/admin/user_edit.php?id=%d" % uid(login_id), {
            "action": "save", "display_name": name, "role_title": role_title, "specialist_title": specialist_title,
            "department_id": str(dept or ""), "is_active": "1"})

    owner_id = uid(OWNER)
    report_id = database.execute(
        "INSERT INTO report_records (user_id, department_id, period, status, revision, unchanged, submitted_at, created_at, updated_at)"
        " VALUES (?, ?, ?, 'submitted', 0, 1, datetime('now'), datetime('now'), datetime('now'))", (owner_id, department_id, period)).lastrowid
    # 比べるための、専門職でない人の報告
    other_report_id = database.execute(
        "INSERT INTO report_records (user_id, department_id, period, status, revision, unchanged, submitted_at, created_at, updated_at)"
        " VALUES (?, ?, ?, 'submitted', 0, 1, datetime('now'), datetime('now'), datetime('now'))", (uid("ippan_a1"), department_id, period)).lastrowid
    project_id = one("SELECT id FROM projects ORDER BY id LIMIT 1")
    for each_report_id in (report_id, other_report_id):
        database.execute("INSERT INTO report_projects (report_id, project_id, sort_order) VALUES (?, ?, 1)", (each_report_id, project_id))
    survey_id = database.execute(
        "INSERT INTO adhoc_requests (department_id, title, requester, purpose, result_visibility, target_type, status, due_at, created_by, created_at, updated_at)"
        " VALUES (?, 'A課の調査', '', '', 'detail', 'all', 'open', ?, ?, datetime('now'), datetime('now'))", (department_id, due, uid("kacho_a"))).lastrowid
    question_id = database.execute(
        "INSERT INTO adhoc_request_fields (request_id, label, field_type, confidentiality, is_required, help_text, settings_json, options_json, sort_order)"
        " VALUES (?, '使っていますか', 'radio', 'internal', 0, '', '{}', ?, 10)", (survey_id, json.dumps([{"code": "o1", "label": "はい", "active": True}]))).lastrowid
    for target_id in (owner_id, uid("ippan_a1")):
        database.execute("INSERT INTO adhoc_request_targets (request_id, user_id) VALUES (?, ?)", (survey_id, target_id))
        response_id = database.execute("INSERT INTO adhoc_responses (request_id, user_id, submitted_at, created_at, updated_at) VALUES (?, ?, datetime('now'), datetime('now'), datetime('now'))", (survey_id, target_id)).lastrowid
        database.execute("INSERT INTO adhoc_response_values (response_id, request_field_id, value_text, is_encrypted) VALUES (?, ?, 'o1', 0)", (response_id, question_id))

    status_path = "/status/index.php?period=%s&dept=%d" % (period, department_id)
    view_path = "/reports/view.php?id=%d" % report_id

    def status_rows(login_id, suffix=""):
        """提出状況の人ごとの行の HTML（名前を含む行だけ）。"""
        _, _, page_html = client_of(login_id).get(status_path + suffix)
        return [row for row in re.findall(r"<li>(.*?)</li>", page_html, re.S) if OWNER_NAME in row]

    def export_text(login_id, values):
        client = client_of(login_id)
        _, _, page_html = client.get("/export/index.php")
        client.post("/export/index.php", dict(values, csrf_token=csrf(page_html), request_key=os.urandom(16).hex()))
        return client.last_raw.decode("utf-8-sig", "replace")

    report_export = {"type": "report", "dept": str(department_id), "from": period, "to": period, "content": "both", "format": "csv"}
    survey_export = {"type": "survey", "survey_id": str(survey_id), "format": "csv"}
    goal_id = None
    if has_goals:
        goal_path = "/goals/edit.php?year=%d" % (today.year if today.month >= 10 else today.year - 1)
        _, _, goal_html = client_of(OWNER).get(goal_path)
        from smoke_goals import owner_form
        goal_values = owner_form(goal_html, full_name=OWNER_NAME)
        client_of(OWNER).post(goal_path, goal_values)
        goal_id = one("SELECT id FROM goal_sheets WHERE user_id = ?", owner_id)
        check("準備: 本人の目標管理シートがある", goal_id is not None, goal_id)

    def expect_visibility(title, visible_login_ids):
        """見る人の組み合わせ。visible_login_ids だけが見られる。"""
        label = title or "専門職でない"
        section("G-05", "専門職の報告を見られる人: " + label)
        for login_id in ("ippan_a1", "leader_a", "dairi_a", "kacho_a", "kacho_b", "admin01", "hosa01"):
            code = client_of(login_id).get(view_path)[0]
            check("%s の報告: %s は%s" % (label, login_id, "見られる" if login_id in visible_login_ids else "見られない（404・403）"),
                  code == 200 if login_id in visible_login_ids else code in (403, 404), code)
        check("%s の報告: 本人は見られる" % label, client_of(OWNER).get(view_path)[0] == 200)
        reviewers = ("leader_a", "dairi_a", "kacho_a")
        for login_id in reviewers:
            visible = login_id in visible_login_ids
            before = one("SELECT COUNT(*) FROM report_field_comments WHERE report_id = ? AND user_id = ?", report_id, uid(login_id))
            _, _, home_html = client_of(login_id).get("/index.php")
            code, _, _ = client_of(login_id).post("/reports/act.php", {"csrf_token": csrf(home_html), "request_key": os.urandom(16).hex(), "action": "comment",
                                                                      "report_id": str(report_id), "comment_type": "note", "field_id": "0", "report_project_id": "0", "comment": "確かめ"})
            after = one("SELECT COUNT(*) FROM report_field_comments WHERE report_id = ? AND user_id = ?", report_id, uid(login_id))
            check("%s: %s の直接のコメントは%s" % (label, login_id, "入る" if visible else "断られ、DB に入らない"),
                  (after == before + 1 and code in (302, 303)) if visible else (after == before and code in (403, 404)), (code, before, after))
            compare_html = client_of(login_id).get("/status/compare.php?period=%s&dept=%d" % (period, department_id))[2]
            check("%s: %s の横並びに%s" % (label, login_id, "出る" if visible else "出ない"),
                  "A一般1" in compare_html and (OWNER_NAME in compare_html) == visible)
            search_html = client_of(login_id).get("/reports/search.php?search=1&dept=%d&from=%s&to=%s" % (department_id, period, period))[2]
            check("%s: %s の検索に%s" % (label, login_id, "出る" if visible else "出ない"),
                  "/reports/view.php?id=%d" % other_report_id in search_html and ("/reports/view.php?id=%d" % report_id in search_html) == visible)
            check("%s: %s の提出状況の行が%s" % (label, login_id, "出る" if visible else "出ない"), bool(status_rows(login_id)) == visible)
        section("G-08", "専門職のチェックの人数・まとめてチェック: " + label)
        expected = len([login_id for login_id in reviewers if login_id in visible_login_ids])
        rows = status_rows("admin01")
        if title == "担当部長":
            check("担当部長は、管理者の提出状況にも出ない（人数に入れない）", rows == [], rows)
        else:
            check("%s: チェックの人数（m）は見られる人だけ（%d）" % (label, expected), len(rows) == 1 and re.search(r"✓\d+/%d<" % expected, rows[0]) is not None, rows)
        for login_id in ("dairi_a", "kacho_a"):
            visible = login_id in visible_login_ids
            database.execute("DELETE FROM report_reviews WHERE report_id = ?", (report_id,))
            code, _, _ = post(login_id, status_path, {"action": "bulk_check", "report_ids[]": [str(report_id)]})
            checked = one("SELECT COUNT(*) FROM report_reviews WHERE report_id = ? AND user_id = ?", report_id, uid(login_id))
            check("%s: %s のまとめてチェックは%s" % (label, login_id, "できる" if visible else "断られ、DB に入らない"),
                  (checked == 1 and code in (302, 303)) if visible else (checked == 0 and code == 400), (code, checked))
        database.execute("DELETE FROM report_reviews WHERE report_id = ?", (report_id,))
        section("G-10", "専門職の出力・課の調査: " + label)
        final_visible = "kacho_a" in visible_login_ids
        csv_text = export_text("kacho_a", report_export)
        check("%s: 課長の月次報告の出力に%s" % (label, "出る" if final_visible else "出ない"), "A一般1" in csv_text and (OWNER_NAME in csv_text) == final_visible, csv_text[:200])
        survey_csv = export_text("kacho_a", survey_export)
        check("%s: 課長の調査の出力に%s（人数・集計も）" % (label, "出る" if final_visible else "出ない"),
              "A一般1" in survey_csv and (OWNER_NAME in survey_csv) == final_visible and ("回答 2 人" if final_visible else "回答 1 人") in survey_csv, survey_csv[:300])
        result_html = client_of("kacho_a").get("/surveys/result.php?id=%d&detail=1" % survey_id)[2]
        check("%s: 課長の調査の結果に%s" % (label, "出る" if final_visible else "出ない"), "A一般1" in result_html and (OWNER_NAME in result_html) == final_visible)
        if has_goals:
            section("G-27", "専門職の目標管理: " + label)
            for login_id in ("leader_a", "dairi_a", "kacho_a", "admin01"):
                goal_visible = login_id == "kacho_a" and final_visible or (title == "" and login_id == "dairi_a")
                codes = (client_of(login_id).get("/goals/edit.php?id=%d" % goal_id)[0], client_of(login_id).get("/goals/download.php?id=%d" % goal_id)[0])
                check("%s: %s は目標管理シートを%s" % (label, login_id, "見られる" if goal_visible else "見られない"),
                      codes == ((200, 200) if goal_visible else (404, 404)), codes)

    everyone = {"leader_a", "dairi_a", "kacho_a"}
    expect_visibility("", everyone)
    for title in ("技師", "担当課長", "担当部長"):
        section("G-14", "専門職を付ける: " + title)
        code, _, _ = save_user(OWNER, title)
        check("管理者が「%s」を付けられる" % title, code in (302, 303) and one("SELECT specialist_title FROM users WHERE id = ?", owner_id) == title, code)
        users_html = client_of("admin01").get("/admin/org.php?tab=users")[2]
        check("ユーザーの一覧に「%s」と出る" % title, '<span class="tag">%s</span>' % title in users_html)
        manage_html = client_of("kacho_a").get("/admin/department.php?dept=%d" % department_id)[2]
        check("課の管理に「%s」と出て、確認者・チェック・項目管理者の欄がない" % title,
              "<td>%s</td>" % title in manage_html and all('name="%s[%d]"' % (name, owner_id) not in manage_html for name in ("reviewer", "does_check", "field_admin"))
              and 'name="reviewer[%d]"' % uid("ippan_a1") in manage_html)
        home_html = client_of(OWNER).get("/index.php")[2]
        card = re.search(r'<section class="card role-summary">(.*?)</section>', home_html, re.S).group(1)
        check("本人のホームに「%s」「専門職」の札" % title, re.findall(r'<span class="tag">([^<]*)</span>', card) == [title, "専門職"], card)
        check("担当部長だけ、ほかの人に見えないことが書いてある", ("ほかの人には見えず" in card) == (title == "担当部長"))
        expect_visibility(title, set() if title == "担当部長" else {"kacho_a"})

        section("G-05", "専門職の報告の確認の完了: " + title)
        _, _, home_html = client_of("kacho_a").get("/index.php")
        code, _, _ = client_of("kacho_a").post("/reports/act.php", {"csrf_token": csrf(home_html), "request_key": os.urandom(16).hex(), "action": "confirm", "report_id": str(report_id)})
        status_text = one("SELECT status FROM report_records WHERE id = ?", report_id)
        check("%s: 課長の確認の完了は%s" % (title, "断られる" if title == "担当部長" else "できる"),
              (status_text == "submitted" and code == 404) if title == "担当部長" else status_text == "confirmed", (code, status_text))
        database.execute("UPDATE report_records SET status = 'submitted' WHERE id = ?", (report_id,))

        section("G-08", "専門職と未提出・催促: " + title)
        database.execute("UPDATE report_records SET status = 'draft' WHERE id = ?", (report_id,))
        for login_id, reminded in (("leader_a", False), ("kacho_a", title != "担当部長")):
            database.execute("DELETE FROM report_reminders")
            post(login_id, status_path, {"action": "remind"})
            count = one("SELECT COUNT(*) FROM report_reminders WHERE user_id = ?", owner_id)
            sent_to_others = one("SELECT COUNT(*) FROM report_reminders WHERE user_id <> ?", owner_id)
            check("%s: %s の催促は%s（ほかの未提出の人には届く）" % (title, login_id, "届く" if reminded else "届かない"), (count == 1) == reminded and sent_to_others >= 1, (count, sent_to_others))
        _, _, kacho_home = client_of("kacho_a").get("/index.php")
        shown = re.search(r"未提出 <strong>(\d+)</strong>", kacho_home)
        in_members = "" if title != "担当部長" else " AND u.id <> %d" % owner_id
        # ホームの対象月は締め日で変わるので、その月で数え直す
        home_period = re.search(r"/status/index\.php\?period=(\d{4}-\d{2})", kacho_home).group(1)
        expected_not_submitted = one("SELECT COUNT(*) FROM users u WHERE u.department_id = ? AND u.is_active = 1" + in_members
                                     + " AND NOT EXISTS (SELECT 1 FROM report_records r WHERE r.user_id = u.id AND r.period = ? AND r.status IN ('submitted','confirmed') AND r.deleted_at IS NULL)", department_id, home_period)
        check("%s: 課長のホームの未提出の人数" % title, shown is not None and int(shown.group(1)) == expected_not_submitted, (shown and shown.group(1), expected_not_submitted))
        database.execute("INSERT INTO report_reminders (period, department_id, user_id, sent_by, created_at) VALUES (?, ?, ?, ?, datetime('now'))", (period, department_id, owner_id, uid("kacho_a")))
        owner_home = client_of(OWNER).get("/index.php")[2]
        check("%s: 本人の画面の提出のお知らせは%s" % (title, "出ない" if title == "担当部長" else "出る"), ("reminder-banner" in owner_home) == (title != "担当部長"))
        database.execute("DELETE FROM report_reminders")
        database.execute("UPDATE report_records SET status = 'submitted' WHERE id = ?", (report_id,))

        section("G-11", "専門職と課の調査の対象: " + title)
        edit_html = client_of("kacho_a").get("/surveys/survey_edit.php")[2]
        check("%s: 課の調査の対象の候補に%s" % (title, "出ない" if title == "担当部長" else "出る"),
              "A一般1" in edit_html and ('name="targets[]" value="%d"' % owner_id in edit_html) == (title != "担当部長"))

        section("G-14", "専門職は課長・確認者・項目管理者になれない: " + title)
        before_roles = database.execute("SELECT * FROM department_reviewers ORDER BY department_id, user_id").fetchall()
        for field_name in ("reviewer", "does_check", "field_admin"):
            code, _, _ = post("kacho_a", "/admin/department.php?dept=%d" % department_id, {"action": "members_save", "%s[%d]" % (field_name, owner_id): "1"})
            check("%s: 課の管理で %s を送っても断られる" % (title, field_name), code == 400, code)
        org_path = "/admin/org.php?tab=departments&edit=%d" % department_id
        code, _, _ = post("admin01", org_path, {"action": "final_save", "dept_id": str(department_id), "final_user_id": str(owner_id), "role_mismatch_ok": "1"})
        check("%s: 課長にしようとしても断られる" % title, code == 400, code)
        org_html = client_of("admin01").get(org_path)[2]
        final_select = re.search(r'<select id="final_user_id".*?</select>', org_html, re.S).group(0)
        check("%s: 課長の候補に出ない" % title, 'value="%d"' % owner_id not in final_select and 'value="%d"' % uid("ippan_a1") in final_select)
        for role_text, field_admin in (("確認者", ""), ("課長", ""), ("", "1")):
            _, _, bulk_html = client_of("admin01").get("/admin/org.php?tab=bulk")
            csv_text = "ログイン ID,表示名,所属の課,ライン,役割,担当のライン,項目管理者,有効\r\n%s,%s,A課,,%s,,%s,1\r\n" % (OWNER, OWNER_NAME, role_text, field_admin)
            code, _, preview_html = client_of("admin01").post_file("/admin/org.php?tab=bulk", {"action": "bulk_preview", "csrf_token": csrf(bulk_html), "request_key": os.urandom(16).hex()},
                                                                  "org_csv", "org.csv", csv_text.encode("utf-8"))
            if code in (302, 303):
                _, _, preview_html = client_of("admin01").get("/admin/org.php?tab=bulk")
            check("%s: CSV で「%s%s」にできない" % (title, role_text, "項目管理者" if field_admin else ""), "専門職の人は、課長・確認者・項目管理者にできません" in preview_html)
        for table in ("department_reviewers", "department_admins"):
            try:
                database.execute("INSERT INTO %s (department_id, user_id) VALUES (?, ?)" % table, (department_id, owner_id))
                refused = False
            except sqlite3.DatabaseError as error:
                refused = "specialist" in str(error)
            check("%s: DB でも %s に入らない" % (title, table), refused)
        check("%s: 断られたあと、課の役割は変わっていない" % title, database.execute("SELECT * FROM department_reviewers ORDER BY department_id, user_id").fetchall() == before_roles)

        section("共通", "専門職はほかの人のものを見られない: " + title)
        check("%s: ほかの人の報告を開けない" % title, client_of(OWNER).get("/reports/view.php?id=%d" % other_report_id)[0] in (403, 404))
        codes = [client_of(OWNER).get(path)[0] for path in ("/status/index.php", "/status/compare.php", "/export/index.php", "/surveys/result.php?id=%d" % survey_id, "/admin/department.php")]
        check("%s: 提出状況・横並び・出力・調査の結果・課の管理を開けない" % title, all(code in (403, 404) for code in codes), codes)
        # 専門職どうし
        save_user("ippan_a1", "技師")
        check("%s と技師: 専門職どうしは互いに見られない" % title,
              client_of("ippan_a1").get(view_path)[0] in (403, 404) and client_of(OWNER).get("/reports/view.php?id=%d" % other_report_id)[0] in (403, 404))
        save_user("ippan_a1", "")
        if has_goals:
            section("G-27", "専門職の目標管理の様式: " + title)
            database.execute("DELETE FROM goal_sheets WHERE user_id = ? AND id <> ?", (owner_id, goal_id))
            next_html = client_of(OWNER).get("/goals/edit.php?year=%d" % ((today.year if today.month >= 10 else today.year - 1) + 1))[2]
            kinds = re.search(r'name="kind" value="([a-z]+)"', next_html)
            if title == "技師":
                check("技師の様式はリーダー・課長代理", kinds is not None and kinds.group(1) == "leader" and 'id="goal-kind"' not in next_html, kinds and kinds.group(1))
            else:
                check("%s の様式は、課長以上・営業・間接部門から選ぶ" % title, 'id="goal-kind"' in next_html and all('value="%s"' % kind in next_html for kind in ("manager", "sales", "indirect")))

    section("G-14", "専門職を外す・知らない名前")
    code, _, _ = save_user(OWNER, "専門部長")
    check("知らない専門職は 400 で、DB は変わらない", code == 400 and one("SELECT specialist_title FROM users WHERE id = ?", owner_id) == "担当部長", code)
    code, _, _ = save_user(OWNER, "")
    check("専門職を外せる（記録 specialist_on・off が残る）", code in (302, 303) and one("SELECT specialist_title FROM users WHERE id = ?", owner_id) == ""
          and one("SELECT COUNT(*) FROM access_logs WHERE target = ?", "user:%d:specialist_on" % owner_id) >= 1
          and one("SELECT COUNT(*) FROM access_logs WHERE target = ?", "user:%d:specialist_off" % owner_id) == 1)
    expect_visibility("", everyone)

    section("G-14", "確認者・課長・項目管理者だった人を専門職にする")
    database.execute("INSERT INTO department_admins (department_id, user_id) VALUES (?, ?)", (department_id, uid("dairi_a")))
    for login_id, title in (("dairi_a", "技師"), ("kacho_a", "担当課長")):
        check("%s: 付ける前はほかの人の報告を見られる" % login_id, client_of(login_id).get("/reports/view.php?id=%d" % other_report_id)[0] == 200)
        code, _, _ = save_user(login_id, title)
        flash_html = client_of("admin01").get("/admin/user_edit.php?id=%d" % uid(login_id))[2]
        left = one("SELECT (SELECT COUNT(*) FROM department_reviewers WHERE user_id = ?1) + (SELECT COUNT(*) FROM department_admins WHERE user_id = ?1)", uid(login_id))
        check("%s を%sにすると、役割が外れ、外した役割を知らせる" % (login_id, title), code in (302, 303) and left == 0 and "役割を外しました" in flash_html and "A課" in flash_html, (code, left))
        check("%s は、すぐにほかの人の報告を見られなくなる" % login_id, client_of(login_id).get("/reports/view.php?id=%d" % other_report_id)[0] in (403, 404))
        if has_goals and login_id == "dairi_a":
            check("元の役職が課長代理でも、専門職は目標管理でほかの人のシートを見られない", client_of(login_id).get("/goals/edit.php?id=%d" % goal_id)[0] == 404)

    section("G-14", "新規作成で専門職・人財管理者を付ける")
    def create_user(login_id_new, extra):
        form = {"action": "save", "login_id": login_id_new, "display_name": login_id_new, "role_title": "一般", "department_id": str(department_id)}
        form.update(extra)
        return post("admin01", "/admin/user_edit.php", form)
    create_user("spec_new1", {"specialist_title": "担当課長"})
    new_specialist_id = one("SELECT id FROM users WHERE login_id = ?", "spec_new1")
    check("新規作成で専門職を付けられ、記録に残る",
          one("SELECT specialist_title FROM users WHERE id = ?", new_specialist_id) == "担当課長"
          and one("SELECT COUNT(*) FROM access_logs WHERE target = ?", "user:%d:specialist_on" % new_specialist_id) == 1, new_specialist_id)
    create_user("hr_new1", {"is_hr_manager": "1"})
    new_hr_id = one("SELECT id FROM users WHERE login_id = ?", "hr_new1")
    check("新規作成で人財管理者を付けられ、記録に残る",
          one("SELECT is_hr_manager FROM users WHERE id = ?", new_hr_id) == 1
          and one("SELECT COUNT(*) FROM access_logs WHERE target = ?", "user:%d:hr_manager_on" % new_hr_id) == 1, new_hr_id)

    return summary()


if __name__ == "__main__":
    sys.exit(main())
