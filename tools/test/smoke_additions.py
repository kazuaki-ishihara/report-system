#!/usr/bin/env python3
"""あとから足した機能の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

- コメントを直す（G-05 §6）
- 臨時の調査を、回答する人の画面で見る（見本。G-11 §6）
- 提出のお知らせ: 締め日が近い・過ぎた、課長・確認者が未提出の人に促す（G-08 §6.3・共通編。画面の中だけ。メールは使わない）
- よくある質問のチャットの通信の失敗（もう一度送る・時間切れ・エラーの種類。G-25 §2.11）

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_additions.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import datetime
import json
import os
import re
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, csrf, login, rkey, section, summary  # noqa: E402


def month_shift(ym, n):
    y, m = int(ym[:4]), int(ym[5:7]) + n
    while m < 1:
        y, m = y - 1, m + 12
    while m > 12:
        y, m = y + 1, m - 12
    return "%04d-%02d" % (y, m)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8000")
    ap.add_argument("--db", required=True)
    a = ap.parse_args()
    here = os.path.dirname(os.path.abspath(__file__))
    seed = json.load(open(os.path.join(here, "output_dev_users.json"), encoding="utf-8"))
    U = seed["users"]
    dept_a = seed["dept_a"]
    db = sqlite3.connect(a.db, timeout=10, isolation_level=None)
    now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    today = datetime.date.today()
    cur = today.strftime("%Y-%m")
    prev = month_shift(cur, -1)
    display_name = {login_id: db.execute("SELECT display_name FROM users WHERE id = ?", (info["id"],)).fetchone()[0] for login_id, info in U.items()}

    a1 = login(a.base, "ippan_a1", U["ippan_a1"])
    leader = login(a.base, "leader_a", U["leader_a"])
    kacho = login(a.base, "kacho_a", U["kacho_a"])

    # ---- 前提: ippan_a1 の今月の報告（提出済み）に、leader_a がコメントする ----
    a1_id = U["ippan_a1"]["id"]
    db.execute("UPDATE report_records SET deleted_at = ? WHERE user_id = ? AND period = ? AND deleted_at IS NULL", (now, a1_id, cur))
    db.execute("INSERT INTO report_records (user_id, department_id, period, status, is_legacy, submitted_at, created_at, updated_at) VALUES (?, ?, ?, 'submitted', 0, ?, ?, ?)",
               (a1_id, dept_a, cur, now, now, now))
    rid = db.execute("SELECT last_insert_rowid()").fetchone()[0]

    section("G-05", "コメントを直す")
    s, _, vb = leader.get("/reports/view.php?id=%d" % rid)
    s, loc, _ = leader.post("/reports/act.php", {"csrf_token": csrf(vb), "request_key": rkey(vb), "action": "comment", "report_id": rid,
                                                 "field_id": "0", "report_project_id": "0", "comment_type": "request", "comment": "完了見込みを教えてくだしあ"})
    check("前提: 確認者がコメントできる", s == 303, (s, loc))
    comment_id = db.execute("SELECT id FROM report_field_comments WHERE report_id = ? ORDER BY id DESC", (rid,)).fetchone()[0]
    enc_before = db.execute("SELECT comment_enc FROM report_field_comments WHERE id = ?", (comment_id,)).fetchone()[0]

    s, _, vb = leader.get("/reports/view.php?id=%d" % rid)
    check("書いた人には「直す」の欄が出る（今の本文が入っている）", 'value="comment_edit"' in vb and "完了見込みを教えてくだしあ</textarea>" in vb, s)
    s, _, ab = a1.get("/reports/view.php?id=%d" % rid)
    check("書いた人でなければ「直す」の欄は出ない", 'value="comment_edit"' not in ab, s)

    s, loc, _ = leader.post("/reports/act.php", {"csrf_token": csrf(vb), "request_key": rkey(vb), "action": "comment_edit", "report_id": rid,
                                                 "comment_id": comment_id, "comment": "完了見込みを教えてください"})
    check("書いた人はコメントを直せる", s == 303 and ("#comment-%d" % comment_id) in (loc or ""), (s, loc))
    row = db.execute("SELECT comment_enc, edited_at FROM report_field_comments WHERE id = ?", (comment_id,)).fetchone()
    check("直した本文も暗号化され、直した日時が残る", row[0].startswith("v1:") and row[0] != enc_before and "完了" not in row[0] and row[1] is not None, row)
    s, _, vb = leader.get("/reports/view.php?id=%d" % rid)
    check("直した本文と「直しました」が出る", "完了見込みを教えてください" in vb and "くだしあ" not in vb and "に直しました" in vb, s)

    s, _, ab = a1.get("/reports/view.php?id=%d" % rid)
    s, loc, _ = a1.post("/reports/act.php", {"csrf_token": csrf(ab), "request_key": rkey(ab), "action": "comment_edit", "report_id": rid,
                                             "comment_id": comment_id, "comment": "書き換え"})
    check("ほかの人のコメントは直せない（403）", s == 403, s)

    s, _, vb = leader.get("/reports/view.php?id=%d" % rid)
    s, loc, _ = leader.post("/reports/act.php", {"csrf_token": csrf(vb), "request_key": rkey(vb), "action": "comment_edit", "report_id": rid,
                                                 "comment_id": comment_id, "comment": ""})
    _, page = leader.follow_get(loc) if loc else (s, "")
    check("空にはできない（1〜1000 文字の案内）", s == 303 and "1〜1000 文字" in page, (s, loc))
    s, _, vb = leader.get("/reports/view.php?id=%d" % rid)
    s, loc, _ = leader.post("/reports/act.php", {"csrf_token": csrf(vb), "request_key": rkey(vb), "action": "comment_edit", "report_id": rid,
                                                 "comment_id": comment_id, "comment": "完了見込みを教えてください"})
    _, page = leader.follow_get(loc) if loc else (s, "")
    check("同じ本文なら「変わっていません」", s == 303 and "コメントは変わっていません" in page, (s, loc))

    s, _, vb = leader.get("/reports/view.php?id=%d" % rid)
    leader.post("/reports/act.php", {"csrf_token": csrf(vb), "request_key": rkey(vb), "action": "comment_delete", "report_id": rid, "comment_id": comment_id})
    s, _, vb = leader.get("/reports/view.php?id=%d" % rid)
    s, loc, _ = leader.post("/reports/act.php", {"csrf_token": csrf(vb), "request_key": rkey(vb), "action": "comment_edit", "report_id": rid,
                                                 "comment_id": comment_id, "comment": "消したあと"})
    check("削除したコメントは直せない（404）", s == 404, s)

    # ---- 臨時の調査の見本 ----
    section("G-11", "臨時の調査を回答する人の画面で見る（見本）")
    db.execute("INSERT INTO adhoc_requests (department_id, title, purpose, created_by, status, created_at, updated_at) VALUES (?, '見本の確かめ', '目的の文', ?, 'draft', ?, ?)",
               (dept_a, U["kacho_a"]["id"], now, now))
    survey_id = db.execute("SELECT last_insert_rowid()").fetchone()[0]
    db.execute("INSERT INTO adhoc_request_fields (request_id, label, field_type, is_required, options_json, sort_order) VALUES (?, 'AI を使っていますか', 'radio', 1, ?, 1)",
               (survey_id, json.dumps([{"code": "yes", "label": "使う", "active": True}, {"code": "no", "label": "使わない", "active": True}])))
    db.execute("INSERT INTO adhoc_request_targets (request_id, user_id) VALUES (?, ?)", (survey_id, U["kacho_a"]["id"]))
    s, _, eb = kacho.get("/surveys/survey_edit.php?id=%d" % survey_id)
    check("編集の画面に「回答する人の画面で見る」が出る", s == 200 and ("/surveys/answer.php?id=%d&amp;preview=1" % survey_id) in eb, s)
    s, _, pb = kacho.get("/surveys/answer.php?id=%d&preview=1" % survey_id)
    check("公開前（下書き）でも見本を開ける", s == 200 and "AI を使っていますか" in pb and "見本です" in pb, s)
    preview_part = pb[pb.find("survey-preview"):].split("</section>")[0]
    check("見本には送るフォームが無く、「回答する」は押せない",
          "survey-preview" in pb and "<form" not in preview_part and 'name="request_key"' not in preview_part and "disabled>回答する" in pb, s)
    s, _, _ = a1.get("/surveys/answer.php?id=%d&preview=1" % survey_id)
    check("管理しない人（部下）は見本を開けない（404）", s == 404, s)
    s, _, _ = leader.get("/surveys/answer.php?id=%d&preview=1" % survey_id)
    check("確認者でも課長でなければ見本を開けない（404）", s == 404, s)
    s, _, _ = kacho.get("/surveys/answer.php?id=%d" % survey_id)
    check("見本でなければ、下書きの調査は開けないまま（404）", s == 404, s)
    check("見本を開いても回答は保存されない", db.execute("SELECT COUNT(*) FROM adhoc_responses WHERE request_id = ?", (survey_id,)).fetchone()[0] == 0)

    # ---- 提出のお知らせ ----
    section("共通", "提出のお知らせ（締め日が近い・過ぎた）")
    a2_id = U["ippan_a2"]["id"]
    a2 = login(a.base, "ippan_a2", U["ippan_a2"])
    # 締め日を今日にする → 対象の月は前月、締め日は今日
    db.execute("UPDATE departments SET close_day = ? WHERE id = ?", (today.day, dept_a))
    target = prev
    db.execute("UPDATE report_records SET deleted_at = ? WHERE user_id = ? AND deleted_at IS NULL AND is_legacy = 0", (now, a2_id))
    db.execute("UPDATE users SET created_at = ? WHERE id = ?", (now, a2_id))
    s, _, hb = a2.get("/index.php")
    check("締め日が今日で未提出なら、画面の上に知らせが出る", s == 200 and "reminder-banner" in hb and "締め日は今日" in hb, s)
    check("知らせから報告の作成の画面へ行ける", ("/reports/edit.php?period=" + target) in hb, target)
    check("途中で入った人には、前の月の締め日を過ぎた知らせは出ない", "を過ぎています" not in hb)
    s, _, fb = a2.get("/faq.php")
    check("ほかの画面（よくある質問）でも知らせが出る", "reminder-banner" in fb, s)
    s, _, eb = a2.get("/reports/edit.php?period=" + target)
    check("報告の作成の画面では知らせを出さない", s == 200 and "reminder-banner" not in eb, s)
    db.execute("UPDATE users SET created_at = '2000-01-01 00:00:00' WHERE id = ?", (a2_id,))
    s, _, hb = a2.get("/index.php")
    check("前の月を出していなければ「締め日を過ぎています」が出る", "を過ぎています" in hb and ("/reports/edit.php?period=" + month_shift(target, -1)) in hb, s)
    db.execute("INSERT INTO report_records (user_id, department_id, period, status, is_legacy, submitted_at, created_at, updated_at) VALUES (?, ?, ?, 'submitted', 0, ?, ?, ?)",
               (a2_id, dept_a, target, now, now, now))
    db.execute("INSERT INTO report_records (user_id, department_id, period, status, is_legacy, submitted_at, created_at, updated_at) VALUES (?, ?, ?, 'submitted', 0, ?, ?, ?)",
               (a2_id, dept_a, month_shift(target, -1), now, now, now))
    s, _, hb = a2.get("/index.php")
    check("提出すれば締め日の知らせは消える", "reminder-banner" not in hb, s)
    if today.day > 1:
        db.execute("UPDATE departments SET close_day = ? WHERE id = ?", (today.day - 1, dept_a))
        db.execute("UPDATE report_records SET deleted_at = ? WHERE user_id = ? AND period = ?", (now, a2_id, cur))
        s, _, hb = a2.get("/index.php")
        check("締め日まで 4 日以上あれば知らせない", "締め日は" not in hb or "reminder-banner" not in hb, s)
    db.execute("UPDATE departments SET close_day = NULL WHERE id = ?", (dept_a,))
    db.execute("UPDATE report_records SET deleted_at = ? WHERE user_id = ? AND deleted_at IS NULL AND is_legacy = 0", (now, a2_id))

    section("G-08", "未提出の人に提出を促す")
    s, _, sb = kacho.get("/status/index.php?period=%s&dept=%d" % (cur, dept_a))
    check("課長の提出状況に「未提出の人に提出を促す」が出る", s == 200 and 'value="remind"' in sb, s)
    s, loc, _ = kacho.post("/status/index.php?period=%s&dept=%d" % (cur, dept_a), {"csrf_token": csrf(sb), "request_key": rkey(sb), "action": "remind"})
    _, page = kacho.follow_get(loc) if loc else (s, "")
    sent = db.execute("SELECT user_id FROM report_reminders WHERE department_id = ? AND period = ?", (dept_a, cur)).fetchall()
    sent_ids = {r[0] for r in sent}
    check("課長が促せる（未提出の人に記録が残る）", s == 303 and a2_id in sent_ids and "人に提出を促しました" in page, (s, loc, sent_ids))
    check("提出済みの人と自分には促さない", a1_id not in sent_ids and U["kacho_a"]["id"] not in sent_ids, sent_ids)
    check("ほかの課の人には促さない", U["ippan_b1"]["id"] not in sent_ids, sent_ids)
    check("提出状況に「促した」日付が出る", "促した " in page, "")
    s, _, hb = a2.get("/index.php")
    check("促された人の画面の上に、だれからかが出る", ("%s さんから" % display_name["kacho_a"]) in hb and "reminder-banner" in hb, s)
    s, _, sb = kacho.get("/status/index.php?period=%s&dept=%d" % (cur, dept_a))
    s, loc, _ = kacho.post("/status/index.php?period=%s&dept=%d" % (cur, dept_a), {"csrf_token": csrf(sb), "request_key": rkey(sb), "action": "remind"})
    _, page = kacho.follow_get(loc) if loc else (s, "")
    check("同じ日に 2 回は促さない", "促す人はいません" in page and db.execute("SELECT COUNT(*) FROM report_reminders WHERE user_id = ? AND period = ?", (a2_id, cur)).fetchone()[0] == 1, page[:0])
    s, _, sb = leader.get("/status/index.php?period=%s&dept=%d" % (cur, dept_a))
    check("確認者（主任）も促せる", 'value="remind"' in sb, s)
    s, _, sb = a1.get("/index.php")
    s, loc, _ = a1.post("/status/index.php?period=%s&dept=%d" % (cur, dept_a), {"csrf_token": csrf(sb), "request_key": rkey(sb), "action": "remind"})
    check("部下は促せない（403）", s == 403, s)
    admin = login(a.base, "admin01", U["admin01"])
    s, _, sb = admin.get("/status/index.php?period=%s&dept=%d" % (cur, dept_a))
    check("確認者でない管理者には「促す」が出ない", s == 200 and 'value="remind"' not in sb, s)
    s, loc, _ = admin.post("/status/index.php?period=%s&dept=%d" % (cur, dept_a), {"csrf_token": csrf(sb), "request_key": rkey(sb), "action": "remind"})
    check("確認者でない管理者は促せない（403）", s == 403, s)
    s, _, sb = kacho.get("/status/index.php?period=%s&dept=%d" % (month_shift(cur, -6), dept_a))
    check("作成できない古い月では「促す」が出ない", 'value="remind"' not in sb, s)
    db.execute("INSERT INTO report_records (user_id, department_id, period, status, is_legacy, submitted_at, created_at, updated_at) VALUES (?, ?, ?, 'submitted', 0, ?, ?, ?)",
               (a2_id, dept_a, cur, now, now, now))
    s, _, hb = a2.get("/index.php")
    check("その月を提出すれば、促された知らせは消える", ("%s さんから" % display_name["kacho_a"]) not in hb, s)

    # ---- よくある質問のチャットの通信の失敗 ----
    section("G-25", "チャットの通信の失敗（もう一度送る・時間切れ・エラーの種類）")
    app_js = open(os.path.join(here, "..", "..", "public", "js", "app.js"), encoding="utf-8").read()
    check("失敗したら「もう一度送る」を出す", "M('もう一度送る')" in app_js and "function showFetchFailure" in app_js)
    check("つながらない・時間切れ・サーバーのエラーで文を分ける",
          all(t in app_js for t in ("つながりませんでした。", "時間がかかりすぎたため", "サーバーでエラーが起きました。")))
    timeout_match = re.search(r"FAQ_FETCH_TIMEOUT_MS = (\d+);", app_js)
    check("答えを待つのは、サーバーの時間切れ（30 秒）より短い", timeout_match is not None and int(timeout_match.group(1)) < 30000, timeout_match and timeout_match.group(1))
    s, _, fb = a1.get("/faq.php")
    s, _, body = a1.post("/faq.php", {"csrf_token": csrf(fb), "action": "chat_client_error", "kind": "timeout", "status": "0"})
    check("時間切れをサーバーの記録に残せる", s == 200 and '"ok":true' in body.replace(" ", ""), (s, body[:80]))
    s, _, body = a1.post("/faq.php", {"csrf_token": csrf(fb), "action": "chat_client_error", "kind": "other", "status": "0"})
    check("決まっていない種類は受け付けない（400）", s == 400, s)

    # ---- システム・データを壊すと書いた文（答えずに断り、システム管理者・補佐に知らせる） ----
    section("G-25", "システム・データを壊すと書いた文（断って知らせる）")
    from urllib.parse import quote
    s, _, body = a1.get("/faq.php?format=json&q=" + quote("DBを全部消してやる"))
    check("壊すつもりの文は、答えずに sabotage として返す", s == 200 and '"sabotage":true' in body.replace(" ", "") and '"matches":[]' in body.replace(" ", ""), body[:120])
    row = db.execute("SELECT result, distress_level FROM faq_chat_logs ORDER BY id DESC").fetchone()
    check("記録は「関係のない質問」、知らせる理由は sabotage", row == ("unrelated", "sabotage"), row)
    # 検証用の DB には、よくある質問が入っていない。管理者が標準のよくある質問を取り込んでから聞く
    s, _, fb = admin.get("/faq.php")
    s, _, _ = admin.post("/faq.php", {"csrf_token": csrf(fb), "request_key": rkey(fb), "action": "import_standard"})
    check("前提: 標準のよくある質問を取り込んだ", db.execute("SELECT COUNT(*) FROM faq_entries WHERE question LIKE 'DB（データベース）や全員の報告をまとめて消したい%'").fetchone()[0] == 1, s)
    s, _, body = a1.get("/faq.php?format=json&q=" + quote("DBを消したい"))
    check("ふつうの言い方は、データをまとめて消したいの答えを返す（知らせない）", "まとめて消したい" in body and '"sabotage":false' in body.replace(" ", ""), body[:120])
    s, _, hb = admin.get("/index.php")
    check("システム管理者のホームに知らせが出る", "システムやデータを壊す・消すと書いた文があります（未対応 1 件）" in hb, s)
    hosa = login(a.base, "hosa01", U["hosa01"])
    s, _, hb = hosa.get("/index.php")
    check("補佐のホームにも知らせが出る", "システムやデータを壊す・消すと書いた文があります" in hb, s)
    s, _, lb = admin.get("/admin/faq_logs.php")
    check("記録の画面に「システム・データを壊すと書いた文」と出る", "システム・データを壊すと書いた文" in lb, s)

    # ---- 標準のよくある質問の答えの直しを取り込む（管理者が直していない答えだけ。2026-11-18） ----
    section("G-25", "標準のよくある質問の答えの直しを取り込む（管理者が直した答えは変えない）")
    untouched = db.execute("SELECT id, answer FROM faq_entries WHERE question = '出したコメントを直したい'").fetchone()
    edited = db.execute("SELECT id, answer FROM faq_entries WHERE question = 'パスワードを忘れたときは'").fetchone()
    check("前提: 標準のよくある質問が入っている", untouched is not None and edited is not None)
    # 前の版の答えのまま（管理者は直していない）と、管理者が画面で直した答え
    db.execute("UPDATE faq_entries SET answer = 'コメントを直す操作はありません。消して出し直してください。', updated_at = created_at WHERE id = ?", (untouched[0],))
    db.execute("UPDATE faq_entries SET answer = '管理者が直した答え', updated_at = '2099-01-01 00:00:00' WHERE id = ?", (edited[0],))
    s, _, fb = admin.get("/faq.php")
    check("取り込みのボタンに「答えの直し 1 件」と出る", "答えの直し 1 件" in fb, s)
    admin.post("/faq.php", {"csrf_token": csrf(fb), "request_key": rkey(fb), "action": "import_standard"})
    check("管理者が直していない答えは、新しい答えになる", db.execute("SELECT answer FROM faq_entries WHERE id = ?", (untouched[0],)).fetchone()[0] == untouched[1])
    check("管理者が直した答えは変えない", db.execute("SELECT answer FROM faq_entries WHERE id = ?", (edited[0],)).fetchone()[0] == "管理者が直した答え")
    s, _, fb = admin.get("/faq.php")
    check("取り込んだあとは、取り込みのボタンが出ない", 'value="import_standard"' not in fb, s)
    check("取り込みを記録に残す（足した数・新しくした数）", db.execute("SELECT COUNT(*) FROM access_logs WHERE target = 'faq:import_standard;added=0;updated=1'").fetchone()[0] == 1)

    return summary()


if __name__ == "__main__":
    sys.exit(main())
