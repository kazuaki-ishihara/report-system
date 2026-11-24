#!/usr/bin/env python3
"""すべての入力部品と、通っていなかった通常の機能の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

- 入力部品 10 種（1 行・複数行・日付・年月・数値・選択・ラジオ・チェック・複数選択・表）を項目マスタで作り、
  保存・開き直し・詳細・出力に出ること、誤りを断ること
- 提出の取り下げ・下書きの削除、お知らせ・よくある質問の削除、一覧の絞り込み、調査の個人の回答の出力

    python3 tools/test/smoke_inputs.py --base http://127.0.0.1:8000 --db app/data/verify.sqlite
"""
import argparse
import datetime
import json
import os
import re
import secrets
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, csrf, login, section, summary  # noqa: E402


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

    def one(sql, *values):
        row = db.execute(sql, values).fetchone()
        return row[0] if row else None

    clients = {name: login(a.base, name, users[name]) for name in ("admin01", "kacho_a", "ippan_a1", "ippan_a2", "leader_a")}

    def post(name, path, action, **values):
        client = clients[name]
        _, _, body = client.get("/account.php?tab=password")
        data = {"csrf_token": csrf(body), "request_key": secrets.token_hex(16), "action": action}
        data.update(values)
        return client.post(path, data)

    # ---- 入力部品 ----
    section("G-12", "すべての入力部品を項目マスタで作る")
    definitions = [
        ("t_single", "1 行の項目", "single_text", {"max_length": "10"}),
        ("t_multi", "複数行の項目", "multi_text", {"max_length": "20"}),
        ("t_date", "日付の項目", "date", {}),
        ("t_month", "年月の項目", "month", {}),
        ("t_number", "数値の項目", "number", {"min": "0", "max": "100", "decimals": "1"}),
        ("t_int", "整数の項目", "number", {}),
        ("t_select", "選択の項目", "select", {"options": True}),
        ("t_radio", "ラジオの項目", "radio", {"options": True}),
        ("t_check", "チェックの項目", "checkbox", {}),
        ("t_multi_check", "複数選択の項目", "multi_check", {"options": True}),
        ("t_table", "表の項目", "table", {"max_rows": "2", "columns_text": "name|名前|single_text\nkind|種類|select|甲,乙\nmemo|メモ|multi_text"}),
    ]
    for code, label, field_type, settings in definitions:
        values = {"label": label, "code": code, "field_type": field_type, "field_scope": "report", "confidentiality": "normal", "help_text": "", "placeholder": "",
                  "include_in_export": "1", "show_in_list": "1", "is_searchable": "1", "carry_over": "1", "show_when_field": ""}
        settings = dict(settings)
        if settings.pop("options", None):
            for index, (option_code, option_label) in enumerate((("x1", "えっくす"), ("y1", "わい"))):
                values["opt_code[%d]" % index] = option_code
                values["opt_label[%d]" % index] = option_label
                values["opt_active[%d]" % index] = "1"
        values.update(settings)
        status, _, body = post("admin01", "/admin/field_edit.php?dept=common", "save", **values)
        if one("SELECT COUNT(*) FROM report_fields WHERE code = ?", code) != 1:
            status, _, body = post("admin01", "/admin/field_edit.php", "save", **values)
        errors = re.findall(r'flash-error[^>]*>([^<]*)<', body)
        check("作れる: " + label, one("SELECT COUNT(*) FROM report_fields WHERE code = ?", code) == 1, (status, errors[:3]))
    for bad_name, bad_values in (("表の列の定義の誤り", {"field_type": "table", "columns_text": "A|x"}), ("最小が最大より大きい", {"field_type": "number", "min": "5", "max": "1"}),
                                 ("最大文字数の範囲外", {"field_type": "single_text", "max_length": "99999"}), ("ない入力部品", {"field_type": "unknown"})):
        values = {"label": "誤り", "code": "t_bad", "field_scope": "report", "confidentiality": "normal", "help_text": "", "placeholder": "", "show_when_field": ""}
        values.update(bad_values)
        post("admin01", "/admin/field_edit.php?dept=common", "save", **values)
        check("断る: " + bad_name, one("SELECT COUNT(*) FROM report_fields WHERE code = 't_bad'") == 0)

    ids = dict(db.execute("SELECT code, id FROM report_fields"))
    missing = [code for code, _, _, _ in definitions if code not in ids]
    if missing:
        check("入力部品の項目がそろう", False, missing)
        return summary()

    section("G-04", "すべての入力部品を保存し、開き直し、提出する")
    user_id = users["ippan_a1"]["id"]
    db.execute("UPDATE report_records SET deleted_at = ? WHERE user_id = ? AND period = ? AND deleted_at IS NULL", (now, user_id, period))
    project_id = one("SELECT id FROM projects WHERE code = 'P-0001'")
    good = {
        "period": period, "b[0][project]": project_id,
        "b[0][f][%d]" % ids["progress"]: "on_track", "b[0][f][%d]" % ids["continuation"]: "continuing",
        "r[%d]" % ids["t_single"]: "一行の値", "r[%d]" % ids["t_multi"]: "複数行1\n複数行2", "r[%d]" % ids["t_date"]: "2026-02-28", "r[%d]" % ids["t_month"]: "2026-03",
        "r[%d]" % ids["t_number"]: "12.5", "r[%d]" % ids["t_int"]: "-7", "r[%d]" % ids["t_select"]: "x1", "r[%d]" % ids["t_radio"]: "y1", "r[%d]" % ids["t_check"]: "1",
        "r[%d][]" % ids["t_multi_check"]: ["x1", "y1"],
        "r[%d][0][name]" % ids["t_table"]: "表の名前", "r[%d][0][kind]" % ids["t_table"]: "乙", "r[%d][0][memo]" % ids["t_table"]: "表のメモ",
    }
    status, location, body = post("ippan_a1", "/reports/save.php", "draft", **good)
    report_id = one("SELECT id FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NULL", user_id, period)
    check("下書き保存できる", status == 303 and report_id is not None, (status, re.findall(r'field-error">([^<]*)', body)[:4]))

    def stored(code):
        return db.execute("SELECT value_text, is_encrypted FROM report_field_values WHERE report_id = ? AND field_id = ? AND report_project_id = 0", (report_id, ids[code])).fetchone()

    for code, expected in (("t_date", "2026-02-28"), ("t_month", "2026-03"), ("t_number", "12.5"), ("t_int", "-7"), ("t_select", "x1"), ("t_radio", "y1"), ("t_check", "1")):
        check("DB の値: " + code, (stored(code) or (None,))[0] == expected, stored(code))
    check("DB の値: 複数選択", json.loads((stored("t_multi_check") or ("[]",))[0]) == ["x1", "y1"], stored("t_multi_check"))
    check("表の値が保存される", stored("t_table") is not None)
    _, _, body = clients["ippan_a1"].get("/reports/edit.php?period=" + period)
    for text in ("一行の値", "複数行2", "2026-02-28", "2026-03", "12.5", "-7", "表の名前", "表のメモ"):
        check("開き直した入力欄に残る: " + text, text in body)
    check("開き直した入力欄に残る: 選択・チェック", len(re.findall(r'value="(?:x1|y1|1)"[^>]*(?:checked|selected)', body)) >= 5, len(re.findall(r'value="(?:x1|y1|1)"[^>]*(?:checked|selected)', body)))

    for name, key, bad in (("1 行の長すぎ", "r[%d]" % ids["t_single"], "あ" * 11), ("1 行に改行", "r[%d]" % ids["t_single"], "あ\nい"), ("複数行の長すぎ", "r[%d]" % ids["t_multi"], "あ" * 21),
                           ("ない日付", "r[%d]" % ids["t_date"], "2026-02-30"), ("年月の形", "r[%d]" % ids["t_month"], "2026-13"), ("最大より大きい", "r[%d]" % ids["t_number"], "100.1"),
                           ("最小より小さい", "r[%d]" % ids["t_number"], "-1"), ("小数の桁", "r[%d]" % ids["t_number"], "1.23"), ("整数に小数", "r[%d]" % ids["t_int"], "1.5"), ("数でない", "r[%d]" % ids["t_int"], "abc"),
                           ("ない選択肢", "r[%d]" % ids["t_select"], "zz"), ("ないラジオ", "r[%d]" % ids["t_radio"], "zz"), ("チェックの値", "r[%d]" % ids["t_check"], "2"),
                           ("ない複数選択", "r[%d][]" % ids["t_multi_check"], ["zz"]), ("表の行が多い", None, None)):
        values = dict(good)
        if key is None:
            for row_index in range(3):
                values["r[%d][%d][name]" % (ids["t_table"], row_index)] = "行%d" % row_index
        else:
            values[key] = bad
        status, _, body = post("ippan_a1", "/reports/save.php", "draft", **values)
        check("断る: " + name, status == 200 and "field-error" in body, status)
    check("断ったあとも、保存済みの値は変わらない", (stored("t_date") or (None,))[0] == "2026-02-28" and (stored("t_number") or (None,))[0] == "12.5")

    post("ippan_a1", "/reports/save.php", "submit", **good)
    check("提出できる", one("SELECT status FROM report_records WHERE id = ?", report_id) == "submitted")
    _, _, body = clients["kacho_a"].get("/reports/view.php?id=%d" % report_id)
    for text in ("一行の値", "複数行2", "12.5", "えっくす", "わい", "表の名前", "乙", "表のメモ"):
        check("詳細の画面に出る: " + text, text in body)
    for format_name in ("csv", "print"):
        status, _, body = post("kacho_a", "/export/index.php", "", type="report", format=format_name, dept=seed["dept_a"], content="both", free="1", **{"from": period, "to": period})
        text = body if format_name == "print" else clients["kacho_a"].last_raw.decode("utf-8-sig", "replace")
        check("出力に出る（%s）: 日付・数値・複数選択・表" % format_name, status == 200 and all(x in text for x in ("2026-02-28", "12.5", "えっくす", "表の名前")),
              [x for x in ("2026-02-28", "12.5", "えっくす", "表の名前") if x not in text])
    status, _, _ = post("kacho_a", "/export/index.php", "", type="report", format="xlsx", dept=seed["dept_a"], content="both", free="1", **{"from": period, "to": period})
    check("出力に出る（xlsx）", status == 200 and clients["kacho_a"].last_raw[:2] == b"PK", status)
    status, _, _ = clients["kacho_a"].get("/status/compare.php?period=%s&dept=%d" % (period, seed["dept_a"]))
    check("横並びの画面が開く（全部の入力部品）", status == 200, status)
    for query in ("表の名前", "一行の値"):
        _, _, body = clients["kacho_a"].get("/reports/search.php?search=1&from=%s&to=%s&q=%s" % (period, period, __import__("urllib.parse").parse.quote(query)))
        check("検索で見つかる: " + query, "view.php?id=%d" % report_id in body)
    _, _, body = clients["kacho_a"].get("/reports/search.php?search=1&from=%s&to=%s&choice=%d:x1" % (period, period, ids["t_multi_check"]))
    check("選択肢で検索できる（複数選択）", "view.php?id=%d" % report_id in body)

    section("G-04", "提出の取り下げ・下書きの削除")
    status, _, _ = post("ippan_a2", "/reports/save.php", "withdraw", period=period)
    check("ほかの人の操作で取り下げにならない", one("SELECT status FROM report_records WHERE id = ?", report_id) == "submitted", status)
    post("ippan_a1", "/reports/save.php", "withdraw", period=period)
    check("取り下げると下書きに戻り、履歴が残る", one("SELECT status FROM report_records WHERE id = ?", report_id) == "draft"
          and one("SELECT COUNT(*) FROM report_status_history WHERE report_id = ? AND from_status = 'submitted' AND to_status = 'draft'", report_id) == 1)
    status, _, _ = clients["kacho_a"].get("/reports/view.php?id=%d" % report_id)
    check("取り下げた報告は、課長から見えない", status == 404, status)
    post("ippan_a1", "/reports/save.php", "delete", period=period)
    check("下書きを削除できる", one("SELECT deleted_at FROM report_records WHERE id = ?", report_id) is not None)
    post("admin01", "/admin/trash.php", "restore_report", id=report_id)
    check("管理者が戻せる", one("SELECT deleted_at FROM report_records WHERE id = ?", report_id) is None)
    post("ippan_a1", "/reports/save.php", "submit", **good)

    section("G-08", "提出状況の絞り込み")
    for filter_name in ("all", "unchecked", "not_submitted", "open_comments", "warning"):
        status, _, body = clients["kacho_a"].get("/status/index.php?period=%s&dept=%d&filter=%s" % (period, seed["dept_a"], filter_name))
        has_report = "view.php?id=%d\"" % report_id in body
        check("絞り込み %s" % filter_name, status == 200 and has_report == (filter_name in ("all", "unchecked")), (status, has_report))

    section("G-10", "臨時の調査の個人の回答の出力")
    _, location, _ = post("kacho_a", "/surveys/survey_edit.php", "save_meta", department_id=seed["dept_a"], title="個人の回答", requester="", purpose="", due_at=due,
                          result_visibility="detail", target_type="users", **{"targets[]": [str(user_id)]})
    match = re.search(r"id=(\d+)", location or "")
    survey_id = int(match.group(1)) if match else 0
    edit = "/surveys/survey_edit.php?id=%d" % survey_id
    check("人を指定した調査を作れる", one("SELECT COUNT(*) FROM adhoc_request_targets WHERE request_id = ?", survey_id) == 1, location)
    post("kacho_a", edit, "add_custom", q_label="選ぶ質問", q_type="radio", q_options="はい\nいいえ", q_conf="internal", q_help="", q_required="1")
    post("kacho_a", edit, "add_source", field_id=ids["t_date"])
    post("kacho_a", edit, "publish")
    check("月次の項目を質問に使え、公開できる", one("SELECT COUNT(*) FROM adhoc_request_fields WHERE request_id = ?", survey_id) == 2
          and one("SELECT status FROM adhoc_requests WHERE id = ?", survey_id) == "open")
    questions = [r[0] for r in db.execute("SELECT id FROM adhoc_request_fields WHERE request_id = ? ORDER BY sort_order", (survey_id,))]
    if len(questions) == 2:
        status, _, body = post("ippan_a1", "/surveys/answer.php?id=%d" % survey_id, "", id=survey_id, **{"q[%d]" % questions[1]: "2026-05-05"})
        check("必須の質問が空なら断る", one("SELECT COUNT(*) FROM adhoc_responses WHERE request_id = ? AND submitted_at IS NOT NULL", survey_id) == 0, status)
        post("ippan_a1", "/surveys/answer.php?id=%d" % survey_id, "", id=survey_id, **{"q[%d]" % questions[0]: "o1", "q[%d]" % questions[1]: "2026-05-05"})
        check("回答できる", one("SELECT COUNT(*) FROM adhoc_responses WHERE request_id = ? AND submitted_at IS NOT NULL", survey_id) == 1)
        post("ippan_a1", "/surveys/answer.php?id=%d" % survey_id, "", id=survey_id, **{"q[%d]" % questions[0]: "o2", "q[%d]" % questions[1]: "2026-06-06"})
        check("回答を直せる（増えない）", one("SELECT COUNT(*) FROM adhoc_responses WHERE request_id = ?", survey_id) == 1)
        _, _, body = clients["ippan_a1"].get("/surveys/answer.php?id=%d" % survey_id)
        check("直した回答が残る", "2026-06-06" in body)
        post("kacho_a", "/export/index.php", "", type="survey", format="csv", survey_id=survey_id, free="1")
        text = clients["kacho_a"].last_raw.decode("utf-8-sig", "replace")
        check("個人の回答が出力に出る", "2026-06-06" in text and "いいえ" in text, text[:200])
    for state in ("all", "draft", "open", "closed", "template"):
        status, _, body = clients["kacho_a"].get("/surveys/manage.php?state=" + state)
        check("調査の一覧の絞り込み: " + state, status == 200 and ("survey_edit.php?id=%d" % survey_id in body or "result.php?id=%d" % survey_id in body) == (state in ("all", "open")), status)

    section("G-24", "更新のお知らせの追加・直し・削除")
    later = (today + datetime.timedelta(days=3)).isoformat()
    post("admin01", "/admin/notices.php", "save", id="", title="検証のお知らせ", body="本文", show_from=today.isoformat(), show_until=later)
    notice_id = one("SELECT id FROM update_notices WHERE title = '検証のお知らせ'")
    _, _, body = clients["ippan_a1"].get("/index.php")
    check("足すと、ホームに出る", notice_id is not None and "検証のお知らせ" in body)
    post("admin01", "/admin/notices.php", "save", id="", title="日付の誤り", body="", show_from="x", show_until=later)
    check("日付の誤りを断る", one("SELECT COUNT(*) FROM update_notices WHERE title = '日付の誤り'") == 0)
    if notice_id is not None:
        post("admin01", "/admin/notices.php", "save", id=notice_id, title="直したお知らせ", body="本文", show_from=today.isoformat(), show_until=later)
        check("直せる", one("SELECT title FROM update_notices WHERE id = ?", notice_id) == "直したお知らせ")
        status, _, _ = post("ippan_a1", "/admin/notices.php", "delete", id=notice_id)
        check("一般の人は消せない", status == 403 and one("SELECT COUNT(*) FROM update_notices WHERE id = ?", notice_id) == 1, status)
        post("admin01", "/admin/notices.php", "delete", id=notice_id)
        check("消せる", one("SELECT COUNT(*) FROM update_notices WHERE id = ?", notice_id) == 0)

    section("G-25", "よくある質問の直し・削除")
    post("admin01", "/faq.php", "save", id="", category="login", question="消す確かめ", answer="答え")
    faq_id = one("SELECT id FROM faq_entries WHERE question = '消す確かめ'")
    check("足せる", faq_id is not None)
    if faq_id is not None:
        post("admin01", "/faq.php", "save", id=faq_id, category="login", question="消す確かめ", answer="直した答え")
        check("直せる", one("SELECT answer FROM faq_entries WHERE id = ?", faq_id) == "直した答え")
        status, _, _ = post("ippan_a1", "/faq.php", "delete", id=faq_id)
        check("一般の人は消せない", status == 403 and one("SELECT COUNT(*) FROM faq_entries WHERE id = ?", faq_id) == 1, status)
        post("admin01", "/faq.php", "delete", id=faq_id)
        check("消せる", one("SELECT COUNT(*) FROM faq_entries WHERE id = ?", faq_id) == 0)

    section("G-14", "ユーザーの一覧の絞り込み・記録の絞り込み")
    _, _, body = clients["admin01"].get("/admin/org.php?tab=users&q=ippan_a1")
    check("ユーザーを言葉で絞り込める", "ippan_a1" in body and "ippan_b1" not in body)
    _, _, body = clients["admin01"].get("/admin/org.php?tab=users&dept=%d" % seed["dept_a"])
    check("ユーザーを課で絞り込める", "ippan_a1" in body and "ippan_b1" not in body)
    _, _, body = clients["admin01"].get("/admin/org.php?tab=users&state=inactive")
    check("無効の人だけに絞り込める", "user_edit.php?id=%d\"" % user_id not in body)
    status, _, body = clients["admin01"].get("/admin/logs.php?who=ippan_a1&target=survey")
    check("記録を人と対象で絞り込める", status == 200, status)
    db.close()
    return summary()


if __name__ == "__main__":
    sys.exit(main())
