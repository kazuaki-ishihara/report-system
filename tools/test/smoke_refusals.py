#!/usr/bin/env python3
"""断る処理の確認（誤った入力・無い番号・権限のない人・状態が合わない操作）。ローカル検証環境専用。dev_seed.php の直後に実行する。

1 行ごとに、応答（コードか画面の文言）と、DB が変わらないこと（記録・送信の番号・ログインの回数を除く）を確かめる。

    python3 tools/test/smoke_refusals.py --base http://127.0.0.1:8000 --db app/data/verify.sqlite
"""
import argparse
import datetime
import hashlib
import json
import os
import re
import secrets
import sqlite3
import sys
import urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import Client, check, csrf, login, section, summary  # noqa: E402

VOLATILE_TABLES = {"access_logs", "request_keys", "login_throttle", "app_state", "sqlite_sequence", "faq_chat_logs"}
MOBILE = "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8000")
    ap.add_argument("--db", required=True)
    a = ap.parse_args()
    here = os.path.dirname(os.path.abspath(__file__))
    seed = json.load(open(os.path.join(here, "output_dev_users.json"), encoding="utf-8"))
    users = seed["users"]
    db = sqlite3.connect(a.db, timeout=10, isolation_level=None)
    now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    today = datetime.date.today()
    period = today.strftime("%Y-%m")
    due = (today + datetime.timedelta(days=5)).isoformat()
    clients = {name: login(a.base, name, users[name]) for name in ("admin01", "kacho_a", "kacho_b", "leader_a", "ippan_a1", "ippan_a2", "ippan_b1", "hosa01")}
    has_goals = os.path.exists(os.path.join(here, "..", "..", "public", "goals", "edit.php"))

    def one(sql, *values):
        row = db.execute(sql, values).fetchone()
        return row[0] if row else None

    def snapshot():
        digest = hashlib.sha256()
        for (table,) in db.execute("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name").fetchall():
            if table in VOLATILE_TABLES:
                continue
            for row in db.execute('SELECT * FROM "%s" ORDER BY 1' % table):
                values = row
                if table == "users":
                    values = row[:12]
                digest.update(repr((table, values)).encode())
        return digest.hexdigest()

    def post(name, path, action, values=None, mobile=False):
        client = clients[name]
        _, _, body = client.get("/account.php?tab=password")
        data = {"csrf_token": csrf(body), "request_key": secrets.token_hex(16), "action": action}
        data.update(values or {})
        if mobile:
            kept, client.headers = client.headers, {"User-Agent": MOBILE}
        try:
            return client.post(path, data)
        finally:
            if mobile:
                client.headers = kept

    def refused(title, name, path, action, values, expected, mobile=False):
        """expected: 応答コード、または画面（転送先を含む）に出る文言"""
        before = snapshot()
        status, location, body = post(name, path, action, values, mobile)
        after = snapshot()   # 転送先を開くと「見た」の印が付くので、その前に見る
        if isinstance(expected, int):
            ok = status == expected
        else:
            if status in (302, 303):
                _, body = clients[name].follow_get(location)
            ok = expected in body
        check(title + "（断る）", ok, (status, expected, re.findall(r"flash-error[^>]*>([^<]{0,60})", body)[:2]))
        check(title + "（DB は変わらない）", after == before)

    def opened(title, name, path, expected, mobile=False):
        client = clients[name]
        kept = client.headers
        if mobile:
            client.headers = {"User-Agent": MOBILE}
        status, location, body = client.get(path)
        client.headers = kept
        check(title, status == expected if isinstance(expected, int) else expected in body, (status, location))

    # ---- 準備 ----
    owner = users["ippan_a1"]["id"]
    for login_id in ("ippan_a1", "ippan_a2"):
        db.execute("UPDATE report_records SET deleted_at = ? WHERE user_id = ? AND deleted_at IS NULL", (now, users[login_id]["id"]))
    field_ids = dict(db.execute("SELECT code, id FROM report_fields"))
    projects = dict(db.execute("SELECT code, id FROM projects"))
    project = projects["P-0001"]
    good = {"period": period, "b[0][project]": project, "b[0][f][%d]" % field_ids["progress"]: "on_track", "b[0][f][%d]" % field_ids["continuation"]: "continuing"}
    far = "2020-01"

    # ---- 報告 ----
    section("G-04", "報告の保存を断る")
    save = "/reports/save.php"
    opened("GET では保存できない", "ippan_a1", save, 400)
    refused("知らない操作", "ippan_a1", save, "unknown", good, 400)
    refused("月の形が誤り", "ippan_a1", save, "draft", dict(good, period="x"), 400)
    refused("編集できない月", "ippan_a1", save, "draft", dict(good, period=far), 400)
    refused("下書きがないのに削除", "ippan_a1", save, "delete", {"period": period}, 400)
    refused("提出していないのに保存", "ippan_a1", save, "save", good, 400)
    refused("前月の報告がないのに変更なし", "ippan_a1", save, "unchanged", {"period": period}, "前月の報告がないため")
    refused("課のない人の保存", "admin01", save, "draft", good, 400)
    refused("案件の欄が配列でない", "ippan_a1", save, "draft", {"period": period, "b": "x"}, 400)
    refused("案件ブロックが配列でない", "ippan_a1", save, "draft", {"period": period, "b[0]": "x"}, 400)
    refused("案件の番号が数でない", "ippan_a1", save, "draft", dict(good, **{"b[0][project]": "abc"}), 400)
    refused("選べない案件", "ippan_a1", save, "draft", dict(good, **{"b[0][project]": "99999"}), "この案件は選べません")
    refused("同じ案件が 2 つ", "ippan_a1", save, "draft", dict(good, **{"b[1][project]": project}), "同じ案件が 2 つあります")
    refused("入力欄に配列", "ippan_a1", save, "draft", dict(good, **{"b[0][f][%d][]" % field_ids["duties"]: "x"}), "値が正しくありません")
    opened("月の形が誤った編集の画面", "ippan_a1", "/reports/edit.php?period=x", 400)
    opened("編集できない月の画面", "ippan_a1", "/reports/edit.php?period=" + far, 400)
    opened("課のない人の編集の画面", "admin01", "/reports/edit.php?period=" + period, 400)
    post("ippan_a1", save, "draft", good)
    report_id = one("SELECT id FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NULL", owner, period)
    check("準備: 下書き", report_id is not None)
    refused("保存済みの案件を空にする", "ippan_a1", save, "draft", dict(good, **{"b[0][project]": ""}), "案件を選んでください")
    act = "/reports/act.php"
    refused("下書きへの確認", "ippan_a1", act, "confirm", {"report_id": report_id}, 404)
    many = {"period": period}
    for number in range(11):
        code = "T-%04d" % number
        db.execute("INSERT INTO projects (code, alias, is_active, created_at, updated_at) VALUES (?, '', 1, ?, ?)", (code, now, now))
        many["b[%d][project]" % number] = one("SELECT id FROM projects WHERE code = ?", code)
    refused("案件が 11 件", "ippan_a1", save, "draft", many, "案件は 10 件まで")
    post("ippan_a1", save, "submit", good)
    check("準備: 提出", one("SELECT status FROM report_records WHERE id = ?", report_id) == "submitted")
    refused("提出済みを変更なしで提出", "ippan_a1", save, "unchanged", {"period": period}, 400)
    refused("提出済みを削除", "ippan_a1", save, "delete", {"period": period}, 400)

    section("G-05", "コメント・チェックを断る")
    opened("GET では操作できない", "kacho_a", act, 400)
    refused("知らない操作", "kacho_a", act, "unknown", {"report_id": report_id}, 400)
    refused("無い報告", "kacho_a", act, "comment", {"report_id": 99999}, 404)
    base_comment = {"report_id": report_id, "field_id": "", "report_project_id": "0", "comment": "本文", "comment_type": "note"}
    refused("コメントの種類が誤り", "kacho_a", act, "comment", dict(base_comment, comment_type="x"), 400)
    refused("コメントが空", "kacho_a", act, "comment", dict(base_comment, comment=""), "1〜1000 文字")
    refused("コメントが長すぎる", "kacho_a", act, "comment", dict(base_comment, comment="あ" * 1001), "1〜1000 文字")
    refused("無い項目へのコメント", "kacho_a", act, "comment", dict(base_comment, field_id="99999"), 400)
    refused("別の報告の案件へのコメント", "kacho_a", act, "comment", dict(base_comment, field_id=str(field_ids["duties"]), report_project_id="99999"), 400)
    refused("限定の項目へのコメント（見られない人）", "leader_a", act, "comment", dict(base_comment, field_id=str(field_ids["staffing_note"])), 400)
    refused("無いコメントへの返信", "kacho_a", act, "reply", {"report_id": report_id, "parent_id": 99999, "comment": "x"}, 404)
    refused("確認者でない人のチェック", "ippan_a1", act, "check", {"report_id": report_id}, 403)
    refused("本人の確認完了", "ippan_a1", act, "confirm", {"report_id": report_id}, 403)
    refused("確認完了でない報告の取り消し", "kacho_a", act, "unconfirm", {"report_id": report_id}, 400)
    post("kacho_a", act, "comment", base_comment)
    note_id = one("SELECT MAX(id) FROM report_field_comments WHERE report_id = ?", report_id)
    refused("回答を求めないコメントの解決", "kacho_a", act, "resolve", {"report_id": report_id, "parent_id": note_id}, 400)
    refused("無いコメントの直し", "kacho_a", act, "comment_edit", {"report_id": report_id, "comment_id": 99999, "comment": "x"}, 404)
    refused("ほかの人のコメントの直し", "leader_a", act, "comment_edit", {"report_id": report_id, "comment_id": note_id, "comment": "x"}, 403)
    refused("無いコメントの削除", "kacho_a", act, "comment_delete", {"report_id": report_id, "comment_id": 99999}, 404)
    refused("ほかの人のコメントの削除", "leader_a", act, "comment_delete", {"report_id": report_id, "comment_id": note_id}, 403)
    refused("同じ内容への直し", "kacho_a", act, "comment_edit", {"report_id": report_id, "comment_id": note_id, "comment": "本文"}, "コメントは変わっていません")
    # 限定の項目のコメント: 見られない人は返信・直しができない
    snapshot_row = one("SELECT COUNT(*) FROM report_record_fields WHERE report_id = ? AND field_id = ?", report_id, field_ids["staffing_note"])
    check("準備: 限定の項目が報告にある", snapshot_row == 1)
    project_row = one("SELECT id FROM report_projects WHERE report_id = ?", report_id)
    post("kacho_a", act, "comment", dict(base_comment, field_id=str(field_ids["staffing_note"]), report_project_id=str(project_row), comment_type="request"))
    restricted_id = one("SELECT MAX(id) FROM report_field_comments WHERE report_id = ?", report_id)
    check("準備: 限定の項目へのコメント", restricted_id != note_id)
    refused("限定の項目のコメントへの返信（見られない人）", "leader_a", act, "reply", {"report_id": report_id, "parent_id": restricted_id, "comment": "x"}, 404)
    db.execute("UPDATE report_field_comments SET user_id = ? WHERE id = ?", (users["leader_a"]["id"], restricted_id))
    refused("限定の項目のコメントの直し（見られなくなった人）", "leader_a", act, "comment_edit", {"report_id": report_id, "comment_id": restricted_id, "comment": "x"}, 404)
    opened("コメントの一覧の知らない絞り込み", "leader_a", "/reports/comments.php?tab=sent&status=x", 200)
    status, _, body = clients["leader_a"].get("/reports/comments.php?tab=sent&status=open")
    check("見られない限定の項目のコメントは一覧に出さない", "人員の動きの詳細" not in body, status)

    section("G-05", "未チェックを順に見る")
    second_owner = users["ippan_a2"]["id"]
    post("ippan_a2", save, "submit", good)
    second_id = one("SELECT id FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NULL", second_owner, period)
    status, location, _ = post("kacho_a", act, "check", {"report_id": report_id, "seq": "1"})
    check("次の未チェックの報告へ移る", "id=%d" % second_id in location, location)
    status, location, _ = post("kacho_a", act, "check", {"report_id": second_id, "seq": "1"})
    check("すべてチェックすると提出状況へ移る", "/status/index.php" in location, location)

    section("G-08", "提出状況を断る")
    status_path = "/status/index.php?period=%s&dept=%d" % (period, seed["dept_a"])
    opened("月の形が誤り", "kacho_a", "/status/index.php?period=x", 400)
    opened("見られない課", "kacho_a", "/status/index.php?period=%s&dept=%d" % (period, seed["dept_b"]), 403)
    opened("知らない絞り込みは「すべて」にする", "kacho_a", status_path + "&filter=x", 200)
    refused("知らない操作", "kacho_a", status_path, "unknown", {}, 400)
    refused("まとめてチェックの番号が誤り", "kacho_a", status_path, "bulk_check", {"report_ids[]": "x"}, 400)
    refused("古い月の催促", "kacho_a", "/status/index.php?period=2020-01&dept=%d" % seed["dept_a"], "remind", {}, 400)
    section("G-09", "横並びを断る")
    for title, query, code in (("セルの形が誤り", "cell=x", 400), ("無い報告のセル", "cell=99999:1:1", 404), ("月の形が誤り", "period=x", 400), ("見られない課", "period=%s&dept=%d" % (period, seed["dept_b"]), 403)):
        opened(title, "kacho_a", "/status/compare.php?" + query, code)

    section("G-10", "出力を断る")
    export = {"type": "report", "format": "csv", "dept": seed["dept_a"], "from": period, "to": period, "content": "both"}
    refused("知らない形式", "kacho_a", "/export/index.php", "", dict(export, format="x"), 400)
    refused("別の課", "kacho_a", "/export/index.php", "", dict(export, dept=seed["dept_b"]), 403)
    refused("月の順が逆", "kacho_a", "/export/index.php", "", dict(export, **{"from": "2026-05", "to": "2026-01"}), 400)
    refused("12 か月を超える", "kacho_a", "/export/index.php", "", dict(export, **{"from": "2020-01", "to": "2021-06"}), 400)
    refused("知らない中身", "kacho_a", "/export/index.php", "", dict(export, content="x"), 400)
    refused("報告がない月", "kacho_a", "/export/index.php", "", dict(export, **{"from": "2020-01", "to": "2020-01"}), "報告")
    refused("無い調査", "kacho_a", "/export/index.php", "", {"type": "survey", "format": "csv", "survey_id": 99999}, 404)
    refused("スマートフォンからの出力", "kacho_a", "/export/index.php", "", export, 403, mobile=True)
    opened("スマートフォンからの記録の CSV", "admin01", "/admin/logs.php?csv=1", 403, mobile=True)
    opened("スマートフォンからの案件の CSV", "admin01", "/admin/projects.php?csv=1", 403, mobile=True)
    opened("スマートフォンからの要望の CSV", "admin01", "/requests/index.php?csv=1", 403, mobile=True)

    # ---- 検索 ----
    section("G-20", "検索の誤り")
    for title, query in (("24 か月を超える", "from=2020-01&to=" + period), ("長すぎる言葉", "from=%s&to=%s&q=%s" % (period, period, "あ" * 51))):
        opened("検索: " + title, "kacho_a", "/reports/search.php?search=1&" + urllib.parse.quote(query, safe="=&-:"), "flash-error")

    opened("検索: 月の形", "kacho_a", "/reports/search.php?search=1&from=x&to=x", 400)
    opened("検索: 選択肢の形", "kacho_a", "/reports/search.php?search=1&from=%s&to=%s&choice=x" % (period, period), 400)

    # ---- 臨時の調査 ----
    section("G-11", "臨時の調査を断る")
    edit = "/surveys/survey_edit.php"
    meta = {"department_id": seed["dept_a"], "title": "断る確かめ", "requester": "", "purpose": "", "due_at": due, "result_visibility": "summary", "target_type": "all"}
    opened("一般の人は一覧を開けない", "ippan_a1", "/surveys/manage.php", 403)
    opened("無い調査", "kacho_a", edit + "?id=99999", 404)
    refused("期限が過去", "kacho_a", edit, "save_meta", dict(meta, due_at="2020-01-01"), "今日以降")
    refused("期限の形が誤り", "kacho_a", edit, "save_meta", dict(meta, due_at="x"), "今日以降")
    refused("題名が空", "kacho_a", edit, "save_meta", dict(meta, title=""), "題名")
    refused("結果の扱いが誤り", "kacho_a", edit, "save_meta", dict(meta, result_visibility="x"), 400)
    refused("課長の全課共通", "kacho_a", edit, "save_meta", dict(meta, department_id="all"), 403)
    refused("別の課の調査", "kacho_a", edit, "save_meta", dict(meta, department_id=seed["dept_b"]), 403)
    refused("対象が配列でない", "kacho_a", edit, "save_meta", dict(meta, target_type="users", targets="x"), 400)
    refused("対象が課の人でない", "kacho_a", edit, "save_meta", dict(meta, target_type="users", **{"targets[]": str(users["ippan_b1"]["id"])}), 400)
    refused("作る前の公開", "kacho_a", edit, "publish", {}, 400)
    _, location, _ = post("kacho_a", edit, "save_meta", dict(meta, due_at="", target_type="users"))
    survey_id = int(re.search(r"id=(\d+)", location).group(1))
    page = edit + "?id=%d" % survey_id
    refused("期限・質問がないまま公開", "kacho_a", page, "publish", {}, "回答期限（今日以降）")
    refused("質問が 0 問", "kacho_a", page, "publish", {}, "1〜20 問")
    question = {"q_label": "質問", "q_type": "radio", "q_options": "はい\n\nいいえ", "q_conf": "internal", "q_help": ""}
    refused("質問が空", "kacho_a", page, "add_custom", dict(question, q_label=""), "質問")
    refused("答え方が誤り", "kacho_a", page, "add_custom", dict(question, q_type="x"), 400)
    refused("選択肢がない", "kacho_a", page, "add_custom", dict(question, q_options="\n"), "選択肢を 1 行に 1 つずつ")
    refused("無い項目を質問に使う", "kacho_a", page, "add_source", {"field_id": 99999}, 400)
    refused("無い質問の削除", "kacho_a", page, "q_remove", {"q_id": 99999}, 404)
    refused("課の調査の「結果を見られる人」", "kacho_a", page, "save_viewers", {}, 400)
    refused("知らない操作", "kacho_a", page, "unknown", {}, 400)
    post("kacho_a", page, "add_custom", question)
    post("kacho_a", page, "save_meta", dict(meta, target_type="users"))
    refused("対象者がいないまま公開", "kacho_a", page, "publish", {}, "対象者がいません")
    post("kacho_a", page, "save_meta", dict(meta, title="直した題名", target_type="all"))
    check("下書きの調査を直せる", one("SELECT title FROM adhoc_requests WHERE id = ?", survey_id) == "直した題名")
    post("kacho_a", page, "publish", {})
    post("kacho_a", page, "save_meta", dict(meta, title="公開後の題名", result_visibility="detail"))
    row = db.execute("SELECT status, title, result_visibility FROM adhoc_requests WHERE id = ?", (survey_id,)).fetchone()
    check("公開後は題名を変えられるが、結果の扱いは変わらない", row == ("open", "公開後の題名", "summary"), row)
    question_id = one("SELECT id FROM adhoc_request_fields WHERE request_id = ?", survey_id)
    refused("回答が配列でない", "ippan_a1", "/surveys/answer.php?id=%d" % survey_id, "", {"id": survey_id, "q": "x"}, 400)
    refused("選択肢にない回答", "ippan_a1", "/surveys/answer.php?id=%d" % survey_id, "", {"id": survey_id, "q[%d]" % question_id: "zz"}, "field-error")
    _, location, _ = post("admin01", edit, "save_meta", dict(meta, department_id="all", title="全課"))
    all_id = int(re.search(r"id=(\d+)", location).group(1))
    refused("結果を見られる人が配列でない", "admin01", edit + "?id=%d" % all_id, "save_viewers", {"viewers": "x"}, 400)
    refused("結果を見られる人が候補にない", "admin01", edit + "?id=%d" % all_id, "save_viewers", {"viewers[]": "99999"}, 400)
    post("admin01", edit + "?id=%d" % all_id, "save_viewers", {"viewers[]": str(users["leader_a"]["id"])})
    opened("調査の結果（課長）", "kacho_a", "/surveys/result.php?id=%d" % survey_id, 200)
    post("admin01", edit + "?id=%d" % all_id, "add_custom", question)
    post("admin01", edit + "?id=%d" % all_id, "publish")
    refused("スマートフォンからの結果の出力", "admin01", "/surveys/result.php?id=%d" % all_id, "", {"format": "csv"}, 403, mobile=True)

    # ---- 管理 ----
    section("G-12", "項目マスタを断る")
    field_page = "/admin/field_edit.php?dept=common"
    field = {"label": "断る項目", "code": "t_refuse", "field_type": "single_text", "field_scope": "report", "confidentiality": "normal", "help_text": "", "placeholder": "", "show_when_field": ""}
    opened("無い項目の編集", "admin01", "/admin/field_edit.php?id=99999", 404)
    opened("一般の人の編集", "ippan_a1", "/admin/field_edit.php?id=%d" % field_ids["duties"], 403)
    opened("無い課の様式の見本", "admin01", "/admin/field_edit.php?preview=99999", 404)
    opened("権限のない課の様式の見本", "kacho_a", "/admin/field_edit.php?preview=%d" % seed["dept_b"], 403)
    refused("無い課の項目", "admin01", "/admin/field_edit.php?dept=99999", "save", field, 400)
    refused("区分が誤り", "admin01", field_page, "save", dict(field, field_scope="x"), "区分が正しくありません")
    refused("機密区分が誤り", "admin01", field_page, "save", dict(field, confidentiality="x"), "機密区分が正しくありません")
    refused("2 つ目の案件の項目", "admin01", field_page, "save", dict(field, field_type="project", field_scope="project"), "案件")
    refused("数値の最小が数でない", "admin01", field_page, "save", dict(field, field_type="number", min="x"), "数値にしてください")
    refused("選択肢が配列でない", "admin01", field_page, "save", dict(field, field_type="radio", opt_code="x"), 400)
    refused("選択肢のコードの形", "admin01", field_page, "save", dict(field, field_type="radio", **{"opt_code[0]": "あ", "opt_label[0]": "あ", "opt_active[0]": "1"}), "flash-error")
    refused("選択肢のコードの重複", "admin01", field_page, "save", dict(field, field_type="radio", **{"opt_code[0]": "x", "opt_label[0]": "1", "opt_active[0]": "1", "opt_code[1]": "x", "opt_label[1]": "2", "opt_active[1]": "1"}), "flash-error")
    refused("表示の条件の値が配列でない", "admin01", field_page, "save", dict(field, show_when_field="progress", show_when_value="x"), 400)
    opened("知らない課の項目の一覧", "kacho_a", "/admin/fields.php?dept=%d" % seed["dept_b"], 403)
    refused("無い項目の並べ替え", "admin01", "/admin/fields.php?dept=common", "move_up", {"field_id": 99999, "id": 99999}, 404)
    refused("課長による共通項目の並べ替え", "kacho_a", "/admin/fields.php?dept=%d" % seed["dept_a"], "move_up", {"field_id": field_ids["phase"], "id": field_ids["phase"]}, 403)
    options_page = "/admin/field_options.php?field=%d&dept=%d" % (field_ids["phase"], seed["dept_a"])
    opened("課ごとの選択肢の画面", "kacho_a", options_page, 200)
    opened("無い項目の選択肢", "kacho_a", "/admin/field_options.php?field=99999&dept=%d" % seed["dept_a"], 404)
    opened("無い課の選択肢", "admin01", "/admin/field_options.php?field=%d&dept=99999" % field_ids["phase"], 404)
    refused("選択肢: 知らない操作", "kacho_a", options_page, "unknown", {}, 400)
    refused("選択肢: 無いものの削除", "kacho_a", options_page, "delete_added", {"code": "zz"}, 404)
    refused("選択肢: 配列でない", "kacho_a", options_page, "save", {"shown": "x"}, 400)
    refused("選択肢: 1 つも出さない", "kacho_a", options_page, "save", {"new_label[]": ""}, "1 つ以上")
    refused("選択肢: 多すぎる", "kacho_a", options_page, "save", {"new_label[]": ["選択%d" % n for n in range(101)]}, "多すぎます")

    section("G-13", "案件を断る")
    refused("無い課の案件", "admin01", "/admin/projects.php", "add", {"code": "R-0001", "alias": "", "department_id": "99999", "id": ""}, "flash-error")
    refused("無い案件の終了", "admin01", "/admin/projects.php", "end", {"id": 99999}, 404)
    refused("知らない操作", "admin01", "/admin/projects.php", "unknown", {}, 400)

    section("G-14", "組織・ユーザーを断る")
    org = "/admin/org.php?tab=departments"
    opened("知らないタブ", "admin01", "/admin/org.php?tab=x", 200)
    opened("無い課の編集", "admin01", org + "&edit=99999", 404)
    refused("知らない操作", "admin01", org, "unknown", {}, 400)
    refused("課の名前の重複", "admin01", org, "dept_save", {"id": "", "name": one("SELECT name FROM departments WHERE id = ?", seed["dept_a"]), "sort_order": ""}, "同じ名前の課")
    refused("並び順が数でない", "admin01", org, "dept_save", {"id": "", "name": "断る課", "sort_order": "-1"}, "並び順は")
    refused("人のいる課を無効にする", "admin01", org, "dept_save", {"id": seed["dept_a"], "name": "A課", "sort_order": "1"}, "所属している人がいる")
    refused("無い課の保存", "admin01", org, "dept_save", {"id": 99999, "name": "無い課", "sort_order": "1", "is_active": "1"}, 404)
    refused("無い課の引き継ぎ", "admin01", org, "succession_save", {"dept_id": 99999}, 404)
    refused("引き継ぐ課が配列でない", "admin01", org, "succession_save", {"dept_id": seed["dept_a"], "predecessor_ids": "x"}, 400)
    refused("引き継ぐ課が無い課", "admin01", org, "succession_save", {"dept_id": seed["dept_a"], "predecessor_ids[]": "99999"}, 400)
    refused("無い課の課長", "admin01", org, "final_save", {"dept_id": 99999, "final_user_id": ""}, 404)
    refused("無い人を課長にする", "admin01", org, "final_save", {"dept_id": seed["dept_a"], "final_user_id": "99999"}, 400)
    post("admin01", org, "dept_save", {"id": "", "name": "管理担当の課", "sort_order": "5", "is_management": "1"})
    new_dept = one("SELECT id FROM departments WHERE name = '管理担当の課'")
    check("課を足し、管理担当にできる", new_dept is not None and one("SELECT value FROM app_state WHERE name = 'management_department_id'") == str(new_dept))
    post("admin01", org, "dept_save", {"id": new_dept, "name": "管理担当の課", "sort_order": "5", "is_active": "1"})
    check("管理担当を外せる", one("SELECT value FROM app_state WHERE name = 'management_department_id'") is None)
    bulk = "/admin/org.php?tab=bulk"
    form = {"display_name": "断る人", "role_title": "一般", "department_id": "", "login_id": "refuse01", "is_active": "1"}
    new_user = "/admin/user_edit.php"
    opened("無い利用者", "admin01", new_user + "?id=99999", 404)
    refused("利用者: 知らない操作", "admin01", new_user, "unknown", form, 400)
    refused("利用者: 役職が誤り", "admin01", new_user, "save", dict(form, role_title="x"), "役職が正しくありません")
    refused("利用者: 無い課", "admin01", new_user, "save", dict(form, department_id="99999"), "課が正しくありません")
    refused("利用者: ID にメールアドレス", "admin01", new_user, "save", dict(form, login_id="a@example.invalid"), "メールアドレスは使えません")
    refused("利用者: ID の形", "admin01", new_user, "save", dict(form, login_id="あ"), "英小文字")
    refused("利用者: ID の重複", "admin01", new_user, "save", dict(form, login_id="ippan_a1"), "同じログイン ID")
    admin_row = {"display_name": "管理者", "role_title": "一般", "department_id": ""}
    refused("自分を無効にする", "admin01", new_user + "?id=%d" % users["admin01"]["id"], "save", dict(admin_row, is_admin="1"), "flash-error")
    refused("自分の管理者を外す", "admin01", new_user + "?id=%d" % users["admin01"]["id"], "save", dict(admin_row, is_active="1"), "自分自身の管理者")
    post("admin01", new_user, "save", dict(form, login_id="refuse02", is_executive="1"))
    made = one("SELECT id FROM users WHERE login_id = 'refuse02'")
    check("部長以上の印を付けて足せる", made is not None and one("SELECT is_executive FROM users WHERE id = ?", made) == 1)
    refused("2 段階認証が未設定の人の予備のコードの初期化", "admin01", new_user + "?id=%d" % made, "reset_backup_codes", {}, "予備のコードはありません")
    opened("補佐は管理者の利用者を開けない", "hosa01", new_user + "?id=%d" % users["admin01"]["id"], 403)

    section("G-23", "課の管理を断る")
    department = "/admin/department.php?dept=%d" % seed["dept_a"]
    status, _, body = clients["kacho_a"].get(department)
    check("課長が課の管理を開ける", status == 200, status)
    refused("知らない操作", "kacho_a", department, "unknown", {}, 400)
    refused("締め日が範囲外", "kacho_a", department, "settings_save", {"close_day": "32", "review_scope": "department"}, "締め日は 1〜31")
    post("kacho_a", department, "settings_save", {"close_day": "10", "review_scope": "department"})
    check("締め日を保存できる", one("SELECT close_day FROM departments WHERE id = ?", seed["dept_a"]) == 10)
    refused("確認者が配列でない", "kacho_a", department, "members_save", {"reviewer": "x"}, 400)
    refused("課の人でない確認者", "kacho_a", department, "members_save", {"reviewer[99999]": "1"}, 400)
    refused("無いライン", "kacho_a", department, "members_save", {"member_team[%d]" % owner: "99999"}, 400)
    refused("無いラインの変更", "kacho_a", department, "team_update", {"team_id": 99999, "team_name": "x"}, 404)
    reviewers = {row[0] for row in db.execute("SELECT user_id FROM department_reviewers WHERE department_id = ? AND is_final = 0", (seed["dept_a"],))}
    keep = {"reviewer[%d]" % user_id: "1" for user_id in reviewers}
    keep.update({"does_check[%d]" % user_id: "1" for user_id in reviewers})
    post("kacho_a", department, "members_save", dict(keep, **{"reviewer[%d]" % owner: "1", "field_admin[%d]" % owner: "1"}))
    check("確認者・項目管理者を足せる", one("SELECT COUNT(*) FROM department_reviewers WHERE department_id = ? AND user_id = ?", seed["dept_a"], owner) == 1
          and one("SELECT COUNT(*) FROM department_admins WHERE department_id = ? AND user_id = ?", seed["dept_a"], owner) == 1)
    post("kacho_a", department, "members_save", keep)
    check("確認者・項目管理者を外せる（ほかの確認者は残る）", {row[0] for row in db.execute("SELECT user_id FROM department_reviewers WHERE department_id = ? AND is_final = 0", (seed["dept_a"],))} == reviewers
          and one("SELECT COUNT(*) FROM department_admins WHERE department_id = ? AND user_id = ?", seed["dept_a"], owner) == 0)

    section("G-15", "記録・削除データを断る")
    refused("記録: 知らない操作", "admin01", "/admin/logs.php", "unknown", {}, 400)
    opened("記録: 日付の誤り", "admin01", "/admin/logs.php?from=x&to=x", 400)
    for action in ("restore_report", "restore_comment", "restore_survey"):
        refused("無いものを戻す: " + action, "admin01", "/admin/trash.php", action, {"id": 99999}, 404)
    refused("削除データ: 知らない操作", "admin01", "/admin/trash.php", "unknown", {"id": 1}, 400)
    db.execute("UPDATE report_field_comments SET deleted_at = ?, comment_enc = '' WHERE id = ?", (now, note_id))
    refused("本文の残っていないコメントを戻す", "admin01", "/admin/trash.php", "restore_comment", {"id": note_id}, "本文が残っていない")

    section("G-17", "メッセージを断る")
    opened("知らない画面での絞り込み", "hosa01", "/admin/messages.php?screen=x", 200)
    opened("長い言葉での絞り込み", "hosa01", "/admin/messages.php?q=" + "a" * 101, 200)
    refused("知らない操作", "hosa01", "/admin/messages.php", "unknown", {"default_text": "x"}, 400)

    section("G-19", "コメントの定型文を断る")
    opened("確認者でない人の自分の定型文", "ippan_a1", "/admin/comment_templates.php?scope=mine", 403)
    templates = "/admin/comment_templates.php?dept=%d" % seed["dept_a"]
    refused("知らない操作", "kacho_a", templates, "unknown", {}, 400)
    for number in range(40):
        db.execute("INSERT INTO comment_templates (department_id, body, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?)", (seed["dept_a"], "定型%d" % number, number, now, now))
    refused("定型文の上限", "kacho_a", templates, "add", {"body": "上限を超える"}, "件までです")
    template_id = one("SELECT MIN(id) FROM comment_templates WHERE department_id = ?", seed["dept_a"])
    refused("定型文を空にする", "kacho_a", templates, "update", {"id": template_id, "template_id": template_id, "body": ""}, "flash-error")

    section("G-21", "メンテナンスの誤り")
    home = "/admin/index.php"
    refused("管理者でない人", "kacho_a", home, "maintenance_start", {"until": "2099-01-01T00:00", "message": ""}, 403)
    refused("知らない操作", "admin01", home, "unknown", {}, 400)
    refused("日時の形", "admin01", home, "maintenance_start", {"until": "x", "message": ""}, "終わる日時を入れてください")
    refused("過去の日時", "admin01", home, "maintenance_start", {"until": "2020-01-01T00:00", "message": ""}, "今より後")
    refused("7 日を超える", "admin01", home, "maintenance_start", {"until": "2099-01-01T00:00", "message": ""}, "7 日以内")

    section("G-22", "要望・依頼を断る")
    requests = "/requests/index.php"
    wish = {"kind": "improvement", "urgency": "normal", "screen_code": "G-04", "title": "断る確かめ", "body": "本文", "source_path": ""}
    refused("種類が誤り", "kacho_a", requests, "create", dict(wish, kind="x"), "flash-error")
    refused("急ぎが誤り", "kacho_a", requests, "create", dict(wish, urgency="x"), "flash-error")
    refused("画面が誤り", "kacho_a", requests, "create", dict(wish, screen_code="x"), "flash-error")
    refused("本文が空", "kacho_a", requests, "create", dict(wish, body=""), "flash-error")
    refused("一般の人", "ippan_a1", requests, "create", wish, 403)
    refused("定型文の種類が誤り", "admin01", requests, "template_add", {"template_body": "x", "template_kind": "x"}, 400)
    refused("定型文が空", "admin01", requests, "template_add", {"template_body": "", "template_kind": ""}, "flash-error")
    opened("無い要望", "admin01", "/requests/view.php?id=99999", 404)
    ids = []
    for number in range(3):
        post("kacho_a", requests, "create", dict(wish, title="要望%d" % number))
        ids.append(one("SELECT MAX(id) FROM support_requests"))
    view = "/requests/view.php?id=%d" % ids[0]
    for title, name, action, values, expected in (
            ("知らない操作", "admin01", "unknown", {}, 400), ("出した人でない返信", "admin01", "reply", {"body": "x"}, 403), ("出した人でない取り下げ", "admin01", "withdraw", {}, 403),
            ("管理者でない予定", "kacho_a", "plan_save", {"plan_text": "x"}, 403), ("管理者でないまとめ", "kacho_a", "merge", {"merge_into": str(ids[1])}, 403),
            ("状態が誤り", "admin01", "staff_reply", {"status": "x", "body": ""}, 400), ("返信も状態もない", "admin01", "staff_reply", {"status": "", "body": ""}, "返信を入れるか"),
            ("理由のない見送り", "admin01", "staff_reply", {"status": "declined", "body": ""}, "検討の結果"), ("まとめる先が無い", "admin01", "merge", {"merge_into": "99999"}, "まとめる先の番号"),
            ("自分へのまとめ", "admin01", "merge", {"merge_into": str(ids[0])}, "まとめる先の番号")):
        refused(title, name, view, action, values, expected)
    post("admin01", "/requests/view.php?id=%d" % ids[1], "merge", {"merge_into": str(ids[2])})
    refused("まとめ済みの要望へのまとめ", "admin01", view, "merge", {"merge_into": str(ids[1])}, "ほかの要望にまとめられています")
    refused("閉じた要望のまとめ", "admin01", "/requests/view.php?id=%d" % ids[1], "merge", {"merge_into": str(ids[0])}, "閉じた要望")
    refused("閉じた要望への返信", "kacho_a", "/requests/view.php?id=%d" % ids[1], "reply", {"body": "x"}, "閉じた要望")
    post("admin01", view, "staff_reply", {"status": "reviewing", "body": ""})
    refused("受付後の取り下げ", "kacho_a", view, "withdraw", {}, "未受付のときだけ")
    post("kacho_a", "/requests/view.php?id=%d" % ids[2], "withdraw", {"body": "理由"})
    check("理由を書いて取り下げられる", one("SELECT status FROM support_requests WHERE id = ?", ids[2]) == "withdrawn")
    refused("取り下げた要望への返信", "admin01", "/requests/view.php?id=%d" % ids[2], "staff_reply", {"status": "done", "body": "x"}, "取り下げられた")
    for query in ("status=all", "status=done", "status=x", "kind=bug", "kind=x"):
        opened("要望の一覧の絞り込み: " + query, "admin01", requests + "?" + query, 200)

    section("G-24", "お知らせを断る")
    notices = "/admin/notices.php"
    refused("知らない操作", "admin01", notices, "unknown", {}, 400)
    refused("無いお知らせの直し", "admin01", notices, "save", {"id": 99999, "title": "x", "body": "", "show_from": due, "show_until": due}, 404)
    refused("番号のない削除", "admin01", notices, "delete", {"id": ""}, 400)
    refused("最後の日が先", "admin01", notices, "save", {"id": "", "title": "x", "body": "", "show_from": due, "show_until": "2020-01-01"}, "出す最後の日は")
    opened("無いお知らせの編集", "admin01", notices + "?edit=99999", 404)
    opened("無い要望からのお知らせ", "admin01", notices + "?request=99999", 404)

    section("G-25", "よくある質問を断る")
    refused("知らない操作", "admin01", "/faq.php", "unknown", {}, 400)
    refused("無い質問の直し", "admin01", "/faq.php", "save", {"id": 99999, "category": "login", "question": "x", "answer": "x"}, 404)
    refused("番号のない削除", "admin01", "/faq.php", "delete", {"id": ""}, 400)
    opened("一般の人の編集", "ippan_a1", "/faq.php?edit=1", 403)
    opened("無い質問の編集", "admin01", "/faq.php?edit=99999", 404)
    opened("長すぎる質問", "ippan_a1", "/faq.php?q=" + urllib.parse.quote("あ" * 300), 200)
    opened("空の問い合わせ（JSON）", "ippan_a1", "/faq.php?format=json", 400)
    refused("記録: 知らない操作", "admin01", "/admin/faq_logs.php", "unknown", {}, 400)
    refused("記録: 受け取れない人", "kacho_a", "/admin/faq_logs.php", "distress_handled", {"log_id": 1}, 403)
    refused("記録: 無い記録", "admin01", "/admin/faq_logs.php", "distress_handled", {"log_id": 99999}, 404)
    opened("記録: 知らない期間・結果", "admin01", "/admin/faq_logs.php?days=1&result=x", 200)

    if has_goals:
        section("G-26", "通勤定期代の精算を断る")
        refused("知らない操作", "ippan_a1", "/expenses/edit.php", "unknown", {}, 400)
        sheet = {"payment_method": "transfer", "submitted_on": today.isoformat(), "employee_number": "1", "office": "", "department_name": "A課", "full_name": "検証", "advance_amount": ""}
        from_row = {"rows[0][start_date]": "2026-11-01", "rows[0][end_date]": "2027-04-30", "rows[0][purpose]": "通勤", "rows[0][transport]": "x", "rows[0][route]": "a～b", "rows[0][trip]": "x", "rows[0][amount]": "1000"}
        for title, values in (("提出日の形", {"submitted_on": "x"}), ("精算方法", {"payment_method": "x"}), ("交通手段・往復", from_row), ("始まりの日", dict(from_row, **{"rows[0][start_date]": "x"})),
                              ("業務・行先の長さ", dict(from_row, **{"rows[0][purpose]": "あ" * 61})), ("明細の数", {"rows[%d][amount]" % n: "1" for n in range(60)})):
            refused("精算: " + title, "ippan_a1", "/expenses/edit.php", "save", dict(sheet, **values), "flash-error")
        section("G-27", "目標管理を断る")
        opened("無いシート", "ippan_a1", "/goals/edit.php?id=99999", 404)
        opened("作れない年度", "ippan_a1", "/goals/edit.php?year=1999", 404)
        opened("スマートフォンからのまとめた受け取り", "kacho_a", "/goals/download_all.php?year=%d&dept=%d" % (today.year, seed["dept_a"]), 403, mobile=True)

    # ---- アカウント ----
    section("G-16", "アカウント設定を断る")
    opened("知らないタブ", "ippan_b1", "/account.php?tab=x", 200)
    refused("知らない操作", "ippan_b1", "/account.php", "unknown", {}, 400)
    refused("設定し直しでないのにコードの確認", "ippan_b1", "/account.php?tab=totp", "totp_confirm", {"code": "000000"}, 400)
    password = users["ippan_b1"]["password"]
    change = "/account.php?tab=password"
    for title, new, confirm, text in (("確認が違う", "Abcdefgh12345", "Abcdefgh1234X", "一致しません"), ("今と同じ", password, password, "今と同じパスワード"), ("短い", "short", "short", "文字以上"),
                                      ("長すぎる", "a" * 73, "a" * 73, "flash-error"), ("ログイン ID と同じ", "ippan_b1", "ippan_b1", "flash-error")):
        refused("パスワード: " + title, "ippan_b1", change, "password_change", {"current_password": password, "new_password": new, "new_password_confirm": confirm}, text)
    anonymous = Client(a.base)
    _, _, body = anonymous.get("/password_reset.php")
    for title, values, text in (("短い", {"new_password": "x", "new_password_confirm": "x"}, "文字以上"), ("確認が違う", {"new_password": "Abcdefgh12345", "new_password_confirm": "Abcdefgh1234X"}, "一致しません")):
        _, _, page = anonymous.post("/password_reset.php", dict({"csrf_token": csrf(body), "login_id": "ippan_b1", "code": "000000"}, **values))
        check("再設定: " + title + "（断る）", text in page)
    section("G-01", "ログインを断る")
    _, _, body = anonymous.get("/login.php")
    _, _, page = anonymous.post("/login.php", {"csrf_token": csrf(body), "action": "password", "login_id": "", "password": ""})
    check("空のログイン（断る）", "ログイン ID とパスワードを入れてください" in page)
    status, _, _ = anonymous.post("/login.php", {"csrf_token": csrf(body), "action": "unknown"})
    check("知らない操作（断る）", status == 400, status)
    status, location, _ = clients["ippan_a1"].get("/login.php")
    check("ログイン済みならホームへ移る", status == 303 and "/index.php" in location, (status, location))
    status, _, _ = clients["ippan_a1"].get("/logout.php")
    check("GET ではログアウトしない", status == 400, status)
    status, _, _ = clients["ippan_a1"].get("/index.php")
    check("GET のあともログインしている", status == 200, status)
    status, _, _ = post("ippan_a1", "/session_check.php", "")
    check("session_check.php は POST を断る", status == 400, status)
    section("共通", "送信の番号・番号の形を断る")
    client = clients["kacho_a"]
    _, _, body = client.get("/account.php?tab=password")
    status, _, _ = client.post("/reports/act.php", {"csrf_token": csrf(body), "request_key": "x", "action": "check", "report_id": report_id})
    check("送信の番号の形が誤り（断る）", status == 400, status)
    key = secrets.token_hex(16)
    client.post("/reports/act.php", {"csrf_token": csrf(body), "request_key": key, "action": "check", "report_id": report_id})
    status, _, _ = client.post("/reports/act.php", {"csrf_token": csrf(body), "request_key": key, "action": "confirm", "report_id": report_id})
    check("別の操作での送信の番号の使い回し（断る）", status == 400, status)
    opened("番号が数でない", "kacho_a", "/reports/view.php?id=x", 400)
    status, _, _ = client.post("/login.php?x=1", {"csrf_token": "x", "action": "password"})
    check("CSRF トークンの誤り（断る）", status in (303, 403), status)
    db.close()
    return summary()


if __name__ == "__main__":
    sys.exit(main())
