#!/usr/bin/env python3
"""よくある質問の CSV（G-25 §2.19。K-076）の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

- 記録の CSV: 期間の中の記録を 1 行ずつ出す。聞いた人の名前は入れない
- メンテナンス用の CSV: 載っている質問・まだ載っていない標準の質問・答えられなかった質問を 1 つのファイルに出す
- 直した CSV を取り込むと、足す・直す・消すを確かめる画面を出し（DB は変えない）、「反映する」でまとめて変える
- 誤り（無い番号・同じ質問・CSV を出したあとに画面で直された質問・UTF-8 でない）があれば、何も変えない
- 管理者・補佐でない人は、出す・取り込むのどちらもできない（403）

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_faq_csv.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import csv
import io
import json
import os
import sqlite3
import sys
from urllib.parse import quote

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, csrf, login, section, summary  # noqa: E402

LOG_PATH = "/admin/faq_logs.php"
COLUMNS = ["種類", "番号", "分野", "質問", "答え", "消す", "直した日時", "回数", "解決した", "解決しない"]
# 答えの見つからない質問（システムの言葉を含み、よくある質問のどれにも合わないもの）
UNANSWERED_QUESTIONS = ["API はある？", "API はある？", "遅延ありを選んだら何か起きる？"]


def main():
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--base", default="http://127.0.0.1:8000")
    argument_parser.add_argument("--db", required=True)
    arguments = argument_parser.parse_args()
    test_dir = os.path.dirname(os.path.abspath(__file__))
    user_info_by_login_id = json.load(open(os.path.join(test_dir, "output_dev_users.json"), encoding="utf-8"))["users"]
    database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)

    def one(sql, *params):
        found_row = database.execute(sql, params).fetchone()
        return None if found_row is None else found_row[0]

    admin_client = login(arguments.base, "admin01", user_info_by_login_id["admin01"])
    member_client = login(arguments.base, "ippan_a1", user_info_by_login_id["ippan_a1"])
    kacho_client = login(arguments.base, "kacho_a", user_info_by_login_id["kacho_a"])

    # ---- 準備: 標準のよくある質問を 1 件だけ残して取り込み、答えられない質問をチャットで聞く ----
    _status_code, _location, faq_html = admin_client.get("/faq.php")
    admin_client.post("/faq.php", {"csrf_token": csrf(faq_html), "request_key": os.urandom(16).hex(), "action": "import_standard"})
    standard_total = one("SELECT COUNT(*) FROM faq_entries")
    left_out_question = one("SELECT question FROM faq_entries ORDER BY sort_order DESC, id DESC LIMIT 1")
    database.execute("DELETE FROM faq_entries WHERE question = ?", (left_out_question,))   # 「まだ載っていない標準の質問」を作る
    for question_text in UNANSWERED_QUESTIONS:
        member_client.get("/faq.php?format=json&q=" + quote(question_text))
    no_match_count = one("SELECT COUNT(*) FROM faq_chat_logs WHERE result = 'no_match'")

    # ================================================================
    section("G-25", "§2.19 記録の CSV")
    status_code, _location, _body = admin_client.get(LOG_PATH + "?days=30&csv=log")
    log_csv_text = admin_client.last_raw.decode("utf-8-sig")
    log_row_list = list(csv.reader(io.StringIO(log_csv_text)))
    log_header_index = next((row_index for row_index, csv_row in enumerate(log_row_list) if csv_row[:1] == ["日時"]), None)
    check("記録を CSV で出せる（社外秘の見出し・列）", status_code == 200 and log_csv_text.startswith('"社外秘"') and log_header_index is not None
          and log_row_list[log_header_index] == ["日時", "役割", "開いていた画面", "質問の文", "分野", "結果", "返事をしなかった", "出した質問の番号", "反応", "反応した質問の番号", "会話"],
          status_code)
    log_data_rows = log_row_list[log_header_index + 1:] if log_header_index is not None else []
    check("記録の CSV に、聞いた質問の文と結果が出る", any(csv_row[3] == UNANSWERED_QUESTIONS[0] and csv_row[1] == "部下" for csv_row in log_data_rows)
          and len(log_data_rows) == one("SELECT COUNT(*) FROM faq_chat_logs WHERE distress_level IS NULL"), len(log_data_rows))
    check("記録の CSV に、聞いた人の名前・ID は入れない", "A一般1" not in log_csv_text and "ippan_a1" not in log_csv_text)
    check("記録の CSV を出したことが記録に残る", one("SELECT COUNT(*) FROM access_logs WHERE target = 'export:faq_chat_logs;days=30'") == 1)

    # ================================================================
    section("G-25", "§2.19 メンテナンス用の CSV")
    status_code, _location, _body = admin_client.get(LOG_PATH + "?days=30&csv=maintenance")
    maintenance_csv_text = admin_client.last_raw.decode("utf-8-sig")
    maintenance_row_list = list(csv.reader(io.StringIO(maintenance_csv_text)))
    header_index = next((row_index for row_index, csv_row in enumerate(maintenance_row_list) if csv_row == COLUMNS), None)
    check("メンテナンス用の CSV を出せる（列）", status_code == 200 and header_index is not None, status_code)
    data_rows = maintenance_row_list[header_index + 1:] if header_index is not None else []
    rows_by_kind = {}
    for csv_row in data_rows:
        rows_by_kind.setdefault(csv_row[0], []).append(csv_row)
    check("載っている質問が全部出る（番号・直した日時つき）", len(rows_by_kind.get("載っている質問", [])) == standard_total - 1
          and all(csv_row[1].isdigit() and csv_row[6] != "" for csv_row in rows_by_kind.get("載っている質問", [])))
    check("まだ載っていない標準の質問が、番号なし・答えつきで出る", [csv_row[3] for csv_row in rows_by_kind.get("標準（まだ載っていない）", [])] == [left_out_question]
          and rows_by_kind["標準（まだ載っていない）"][0][1] == "" and rows_by_kind["標準（まだ載っていない）"][0][4] != "")
    unanswered_rows = rows_by_kind.get("答えられなかった質問", [])
    check("答えられなかった質問が、同じ文をまとめて多い順に出る（回数つき・答えは空）",
          no_match_count >= 2 and unanswered_rows and unanswered_rows[0][3] == UNANSWERED_QUESTIONS[0] and unanswered_rows[0][7] == "2"
          and all(csv_row[4] == "" for csv_row in unanswered_rows), (no_match_count, unanswered_rows[:2]))
    check("管理者・補佐でない人は、CSV を出せない（403）", kacho_client.get(LOG_PATH + "?csv=maintenance")[0] == 403 and member_client.get(LOG_PATH + "?csv=log")[0] == 403)

    def build_csv(edited_rows):
        output_buffer = io.StringIO()
        csv_writer = csv.writer(output_buffer)
        for csv_row in maintenance_row_list[:header_index + 1]:
            csv_writer.writerow(csv_row)
        for csv_row in edited_rows:
            csv_writer.writerow(csv_row)
        return ("﻿" + output_buffer.getvalue()).encode("utf-8")

    def upload(csv_bytes, client=None):
        upload_client = client or admin_client
        _status_code, _location, page_html = upload_client.get(LOG_PATH if client is None else "/index.php")   # 管理者でない人は、ほかの画面の CSRF トークンで送る
        return upload_client.post_file(LOG_PATH, {"csrf_token": csrf(page_html), "action": "faq_csv_preview"}, "faq_csv", "faq.csv", csv_bytes)

    def apply_plan(preview_html, plan_token=None):
        token = plan_token if plan_token is not None else (preview_html.split('name="plan_token" value="')[1].split('"')[0] if 'name="plan_token"' in preview_html else "")
        return admin_client.post(LOG_PATH, {"csrf_token": csrf(preview_html), "request_key": os.urandom(16).hex(), "action": "faq_csv_apply", "plan_token": token})

    # ================================================================
    section("G-25", "§2.19 メンテナンス用の CSV を取り込む")
    entry_rows = [list(csv_row) for csv_row in rows_by_kind["載っている質問"]]
    edited_entry = entry_rows[0]
    deleted_entry = entry_rows[1]
    edited_faq_id, deleted_faq_id = int(edited_entry[1]), int(deleted_entry[1])
    edited_entry[4] = "CSV で直した答え。\n2 行目もある。"
    deleted_entry[5] = "1"
    answered_unanswered = list(unanswered_rows[0])
    answered_unanswered[4] = "外から使う API はありません。"
    answered_unanswered[2] = "報告の作成・提出"
    standard_row = list(rows_by_kind["標準（まだ載っていない）"][0])
    new_row = ["", "", "そのほか", "CSV で書き足した質問", "CSV で書き足した答え", "", "", "", "", ""]
    edited_rows = entry_rows + [standard_row, answered_unanswered] + [list(csv_row) for csv_row in unanswered_rows[1:]] + [new_row]
    entry_count_before = one("SELECT COUNT(*) FROM faq_entries")
    status_code, _location, preview_html = upload(build_csv(edited_rows))
    check("取り込むと、足す・直す・消すの数を出す（まだ変えない）",
          status_code == 200 and "足す 3</span>" in preview_html and "直す 1</span>" in preview_html and "消す 1</span>" in preview_html and 'value="faq_csv_apply"' in preview_html
          and one("SELECT COUNT(*) FROM faq_entries") == entry_count_before and one("SELECT answer FROM faq_entries WHERE id = ?", edited_faq_id) != edited_entry[4],
          status_code)
    check("答えが空の行（答えられなかった質問の残り）は飛ばすと出す", "答えが空で飛ばす %d" % (len(unanswered_rows) - 1) in preview_html)
    status_code, _location, _page_html = apply_plan(preview_html, plan_token="0" * 32)
    check("違う計画の番号では反映しない", one("SELECT COUNT(*) FROM faq_entries") == entry_count_before)
    status_code, location, _page_html = apply_plan(preview_html)
    check("反映すると、直した答えが変わる（改行も）", status_code == 303 and one("SELECT answer FROM faq_entries WHERE id = ?", edited_faq_id) == edited_entry[4], (status_code, location))
    check("反映すると、「消す」の質問が消える", one("SELECT COUNT(*) FROM faq_entries WHERE id = ?", deleted_faq_id) == 0)
    check("反映すると、答えを書いた行が足される（分野も）", one("SELECT category FROM faq_entries WHERE question = ?", UNANSWERED_QUESTIONS[0]) == "report"
          and one("SELECT COUNT(*) FROM faq_entries WHERE question = 'CSV で書き足した質問' AND category = ''") == 1
          and one("SELECT COUNT(*) FROM faq_entries") == entry_count_before + 3 - 1)
    check("標準の質問をそのまま足すと、作った日時と直した日時を同じにする（次の版で答えを新しくできる）",
          one("SELECT created_at = updated_at FROM faq_entries WHERE question = ?", left_out_question) == 1)
    check("書いて足した質問は、作った日時と直した日時を変える（標準の取り込みで上書きしない）",
          one("SELECT created_at <> updated_at FROM faq_entries WHERE question = 'CSV で書き足した質問'") == 1)
    check("反映が記録に残る", one("SELECT COUNT(*) FROM access_logs WHERE target = 'faq:csv_import;added=3;updated=1;deleted=1'") == 1,
          database.execute("SELECT target FROM access_logs WHERE target LIKE 'faq:csv%'").fetchall())
    # 改行の形（CRLF）が違うだけの答えは、変えたことにしない（標準の答えのファイルが CRLF で取り出されたときなど）
    crlf_faq_id = int(entry_rows[2][1])
    database.execute("UPDATE faq_entries SET answer = replace(answer, char(10), char(13) || char(10)) || ' ' WHERE id = ?", (crlf_faq_id,))
    crlf_rows = [list(csv_row) for csv_row in entry_rows[2:]]
    for csv_row in crlf_rows:
        csv_row[6] = one("SELECT updated_at FROM faq_entries WHERE id = ?", int(csv_row[1]))
    status_code, _location, preview_html = upload(build_csv(crlf_rows))
    check("改行の形（CRLF）と前後の空白が違うだけの答えは、直すにしない", "直す 0</span>" in preview_html and "変わらない %d</span>" % len(crlf_rows) in preview_html, status_code)
    status_code, _location, _page_html = apply_plan(preview_html)
    check("同じ計画で 2 回は反映しない", one("SELECT COUNT(*) FROM faq_entries") == entry_count_before + 2)

    # ================================================================
    section("G-25", "§2.19 取り込みの誤り（何も変えない）")
    _status_code, _location, _body = admin_client.get(LOG_PATH + "?days=30&csv=maintenance")
    maintenance_row_list = list(csv.reader(io.StringIO(admin_client.last_raw.decode("utf-8-sig"))))
    header_index = next(row_index for row_index, csv_row in enumerate(maintenance_row_list) if csv_row == COLUMNS)
    current_entry_rows = [list(csv_row) for csv_row in maintenance_row_list[header_index + 1:] if csv_row[0] == "載っている質問"]
    snapshot = database.execute("SELECT id, category, question, answer, updated_at FROM faq_entries ORDER BY id").fetchall()

    def unchanged():
        return database.execute("SELECT id, category, question, answer, updated_at FROM faq_entries ORDER BY id").fetchall() == snapshot

    bad_id_row = ["載っている質問", "999999", "そのほか", "無い番号", "答え", "", "", "", "", ""]
    status_code, _location, preview_html = upload(build_csv(current_entry_rows + [bad_id_row]))
    check("無い番号の行は誤りにし、反映のボタンを出さない", "番号 999999 の質問はありません" in preview_html and 'value="faq_csv_apply"' not in preview_html and unchanged())
    duplicate_row = ["", "", "そのほか", current_entry_rows[0][3], "同じ質問の答え", "", "", "", "", ""]
    status_code, _location, preview_html = upload(build_csv(current_entry_rows + [duplicate_row]))
    check("同じ質問の文を足そうとすると誤りにする", "同じ質問が" in preview_html and 'value="faq_csv_apply"' not in preview_html and unchanged())
    bad_category_row = ["", "", "無い分野", "分野の確かめ", "答え", "", "", "", "", ""]
    long_row = ["", "", "そのほか", "長すぎる答えの確かめ", "あ" * 2001, "", "", "", "", ""]
    bad_mark_row = list(current_entry_rows[1])
    bad_mark_row[5] = "x"
    status_code, _location, preview_html = upload(build_csv(current_entry_rows[:1] + [bad_mark_row] + current_entry_rows[2:] + [bad_category_row, long_row]))
    check("無い分野・長すぎる答え・「消す」の誤った印は誤りにする", "分野「無い分野」はありません" in preview_html and "答えは 2000 文字以内" in preview_html
          and "「消す」には 1・○・はい" in preview_html and 'value="faq_csv_apply"' not in preview_html and unchanged())
    stale_rows = [list(csv_row) for csv_row in current_entry_rows]
    stale_rows[0][4] = "古い CSV で直した答え"
    stale_rows[0][6] = "2000-01-01 00:00:00"
    status_code, _location, preview_html = upload(build_csv(stale_rows))
    check("CSV を出したあとに画面で直された質問（直した日時が違う）は、直さず誤りにする", "CSV を出したあとに画面で直されています" in preview_html and unchanged())
    # 確かめたあとに画面で直された: 反映の時に何も変えない
    race_rows = [list(csv_row) for csv_row in current_entry_rows]
    race_rows[0][4] = "確かめたあとに競合する答え"
    status_code, _location, preview_html = upload(build_csv(race_rows))
    database.execute("UPDATE faq_entries SET updated_at = '2099-01-01 00:00:00' WHERE id = ?", (int(race_rows[0][1]),))
    snapshot = database.execute("SELECT id, category, question, answer, updated_at FROM faq_entries ORDER BY id").fetchall()
    status_code, _location, _page_html = apply_plan(preview_html)
    _status_code, _location, log_html = admin_client.get(LOG_PATH)
    check("確かめたあとに画面で直された質問があれば、反映の時に何も変えない", 'value="faq_csv_apply"' in preview_html and unchanged()
          and "画面で直された・消された質問があります" in log_html)
    status_code, _location, preview_html = upload("報告,答え\n".encode("shift_jis") + "\x82\xa0".encode("latin-1"))
    check("UTF-8 でないファイルは読まない", "CSV は UTF-8 で保存してください" in preview_html and unchanged())
    status_code, _location, preview_html = upload("種類,質問\n".encode("utf-8"))
    check("見出しの行が無い CSV は読まない", "見出しの行" in preview_html and unchanged())
    status_code, _location, _page_html = upload(build_csv(current_entry_rows), client=kacho_client)
    check("管理者・補佐でない人は取り込めない（403）", status_code == 403 and unchanged(), status_code)

    return summary()


if __name__ == "__main__":
    sys.exit(main())
