#!/usr/bin/env python3
"""課題の対応の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

件数の数え方、権限・状態の確認、入力の確認、文言をそろえたところを確かめる。
前提の状態を作るため、テスト用の DB に直接書き込む。

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_fixes.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import datetime
import json
import os
import re
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import Client, check, csrf, login, rkey, summary  # noqa: E402


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
    F = {c: i for c, i in db.execute("SELECT code, id FROM report_fields")}
    P = {c: i for c, i in db.execute("SELECT code, id FROM projects")}
    now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    cur = datetime.date.today().strftime("%Y-%m")
    prev = month_shift(cur, -1)
    old = month_shift(cur, -5)

    def submit(c, period, project="P-0001"):
        s, _, body = c.get("/reports/edit.php?period=" + period)
        form = {"csrf_token": csrf(body), "request_key": rkey(body), "period": period, "action": "submit",
                "b[0][project]": str(P[project]), "b[0][f][%d]" % F["phase"]: "build_unit", "b[0][f][%d]" % F["progress"]: "on_track",
                "b[0][f][%d]" % F["continuation"]: "continuing", "b[0][f][%d]" % F["system_type"]: "inventory"}
        s, loc, _ = c.post("/reports/save.php", form)
        m = re.search(r"id=(\d+)", loc)
        return int(m.group(1)) if s == 303 and m else 0

    def legacy(user, period):
        db.execute("INSERT INTO report_records (user_id, department_id, period, status, is_legacy, created_at, updated_at) VALUES (?, ?, ?, 'confirmed', 1, ?, ?)",
                   (U[user]["id"], dept_a, period, now, now))
        return db.execute("SELECT last_insert_rowid()").fetchone()[0]

    def home_counts(c):
        s, _, body = c.get("/index.php")
        m = re.search(r"未チェック <strong>(\d+)</strong>件.*?未提出 <strong>(\d+)</strong>人", body, re.S)
        return (int(m.group(1)), int(m.group(2))) if m else None

    kacho = login(a.base, "kacho_a", U["kacho_a"])
    a1 = login(a.base, "ippan_a1", U["ippan_a1"])

    # ---- APP-001 ホームの件数に移行データを数えない ----
    before = home_counts(kacho)
    legacy("ippan_a1", cur)
    legacy("ippan_a1", prev)
    legacy("ippan_a2", cur)
    legacy("ippan_a2", prev)
    after = home_counts(kacho)
    check("APP-001 ホームの未チェック・未提出に移行データを数えない", before is not None and before == after, (before, after))

    # ---- APP-012 確認者から外れた人のチェックを数えない ----
    rid = submit(a1, prev)
    check("前提: 前月の報告を提出", rid > 0, rid)
    leader = login(a.base, "leader_a", U["leader_a"])
    s, _, vb = leader.get("/reports/view.php?id=%d" % rid)
    leader.post("/reports/act.php", {"csrf_token": csrf(vb), "request_key": rkey(vb), "action": "check", "report_id": rid})
    s, _, mb = a1.get("/reports/mine.php")
    check("前提: リーダーのチェックが数えられる（1/3）", "チェック 1/3" in mb, re.findall(r"チェック \d+/\d+", mb))
    db.execute("DELETE FROM department_reviewers WHERE user_id = ?", (U["leader_a"]["id"],))
    s, _, mb = a1.get("/reports/mine.php")
    check("APP-012 自分の報告一覧: 外れた確認者のチェックを数えない（0/2）", "チェック 0/2" in mb, re.findall(r"チェック \d+/\d+", mb))
    s, _, sb = kacho.get("/status/index.php?period=%s&dept=%d" % (prev, dept_a))
    check("APP-012 提出状況: 外れた確認者のチェックを数えない（0/2）", "✓" + "0/2" in sb, ascii(re.findall("✓" + r"\d+/\d+", sb)))

    # ---- APP-003 本人は管理者でも最初のコメントを付けない ----
    kb = login(a.base, "kacho_b", U["kacho_b"])
    kid = submit(kb, prev, "P-0003")
    db.execute("UPDATE users SET is_admin = 1 WHERE id = ?", (U["kacho_b"]["id"],))
    s, _, vb = kb.get("/reports/view.php?id=%d" % kid)
    check("APP-003 管理者の自分の報告にコメントの欄が出ない", s == 200 and 'name="comment_type"' not in vb, s)
    s, _, _ = kb.post("/reports/act.php", {"csrf_token": csrf(vb), "request_key": os.urandom(16).hex(), "action": "comment", "report_id": kid,
                                          "field_id": F["phase"], "report_project_id": 0, "comment_type": "note", "comment": "x"})
    check("APP-003 管理者でも自分の報告に最初のコメントは付けられない（403）", s == 403, s)
    s, _, vb = kb.get("/reports/view.php?id=%d" % rid)
    check("K-026 管理者でも、確認者でない課の報告は開けない（404）", s == 404, s)
    s, loc, _ = kb.post("/reports/act.php", {"csrf_token": csrf(kb.get("/index.php")[2]), "request_key": os.urandom(16).hex(), "action": "comment",
                                            "report_id": rid, "field_id": 0, "report_project_id": 0, "comment_type": "note", "comment": "管理者から"})
    check("K-026 管理者でも、確認者でない課の報告にはコメントを付けられない", s in (403, 404), (s, loc))
    db.execute("UPDATE users SET is_admin = 0 WHERE id = ?", (U["kacho_b"]["id"],))

    # ---- APP-004 編集できる期間を過ぎた下書き ----
    a2 = login(a.base, "ippan_a2", U["ippan_a2"])
    db.execute("INSERT INTO report_records (user_id, department_id, period, status, created_at, updated_at) VALUES (?, ?, ?, 'draft', ?, ?)",
               (U["ippan_a2"]["id"], dept_a, old, now, now))
    old_id = db.execute("SELECT last_insert_rowid()").fetchone()[0]
    s, _, mb = a2.get("/reports/mine.php")
    check("APP-004 古い下書きは編集画面ではなく詳細へリンク", "/reports/view.php?id=%d" % old_id in mb and "edit.php?period=" + old not in mb)
    s, _, vb = a2.get("/reports/view.php?id=%d" % old_id)
    check("APP-004 古い下書きの詳細が開け、削除できる表示", s == 200 and "下書きを削除" in vb and "編集する" not in vb, s)
    s, loc, _ = a2.post("/reports/save.php", {"csrf_token": csrf(vb), "request_key": rkey(vb), "period": old, "action": "delete"})
    d = db.execute("SELECT deleted_at FROM report_records WHERE id = ?", (old_id,)).fetchone()[0]
    check("APP-004 古い下書きを削除できる", s == 303 and d is not None, (s, loc))
    s, _, body = a2.get("/reports/edit.php?period=" + cur)
    s, _, _ = a2.post("/reports/save.php", {"csrf_token": csrf(body), "request_key": rkey(body), "period": old, "action": "draft"})
    check("APP-004 古い月の保存は今までどおり断る（400）", s == 400, s)

    # ---- APP-008・009 削除データを戻す ----
    adm = login(a.base, "admin01", U["admin01"])
    expired = (datetime.datetime.now() - datetime.timedelta(days=40)).strftime("%Y-%m-%d %H:%M:%S")
    db.execute("UPDATE report_records SET deleted_at = ? WHERE id = ?", (expired, old_id))
    s, _, tb = adm.get("/admin/trash.php")
    s, loc, _ = adm.post("/admin/trash.php", {"csrf_token": csrf(tb), "request_key": os.urandom(16).hex(), "action": "restore_report", "id": old_id})
    d = db.execute("SELECT deleted_at FROM report_records WHERE id = ?", (old_id,)).fetchone()[0]
    check("APP-008 取り消しの期間を過ぎた報告は戻せない", d is not None, (s, loc, d))
    s, _, tb = adm.get("/admin/trash.php")
    check("APP-008 期間を過ぎた旨を知らせる", "日を過ぎているため、戻せません" in tb)
    lid = legacy("ippan_a1", "2020-04")
    legacy("ippan_a1", "2020-04")
    db.execute("UPDATE report_records SET deleted_at = ? WHERE id = ?", (now, lid))
    s, _, tb = adm.get("/admin/trash.php")
    adm.post("/admin/trash.php", {"csrf_token": csrf(tb), "request_key": rkey(tb), "action": "restore_report", "id": lid})
    d = db.execute("SELECT deleted_at FROM report_records WHERE id = ?", (lid,)).fetchone()[0]
    check("APP-009 移行データは同じ月に別の報告があっても戻せる（仕様）", d is None, d)

    # ---- APP-010 記録の期間 ----
    s, _, lb = adm.get("/admin/logs.php?from=%s&to=%s" % (datetime.date.today().isoformat(), (datetime.date.today() - datetime.timedelta(days=3)).isoformat()))
    check("APP-010 開始が終了より後なら専用の文言", s == 400 and "期間の開始は、終了と同じ日かそれより前" in lb and "1 年以内" not in lb, s)
    s, _, lb = adm.get("/admin/logs.php?from=2020-01-01&to=%s" % datetime.date.today().isoformat())
    check("APP-010 1 年を超える期間は今までの文言", s == 400 and "1 年以内" in lb, s)

    # ---- APP-006 結果の画面は公開した依頼だけ ----
    s, _, body = kacho.get("/surveys/survey_edit.php")
    s, loc, _ = kacho.post("/surveys/survey_edit.php", {"csrf_token": csrf(body), "request_key": rkey(body), "action": "save_meta",
                                                        "department_id": str(dept_a), "title": "下書きの調査", "requester": "総務", "purpose": "確認",
                                                        "due_at": (datetime.date.today() + datetime.timedelta(days=3)).isoformat(),
                                                        "result_visibility": "summary", "target_type": "all"})
    m = re.search(r"id=(\d+)", loc)
    sid = int(m.group(1)) if m else 0
    s, _, _ = kacho.get("/surveys/result.php?id=%d" % sid)
    check("APP-006 下書きの依頼の結果は開けない（404）", sid > 0 and s == 404, (sid, s))
    db.execute("UPDATE adhoc_requests SET is_template = 1 WHERE id = ?", (sid,))
    s, _, _ = kacho.get("/surveys/result.php?id=%d" % sid)
    check("APP-006 ひな形の結果は開けない（404）", s == 404, s)

    # ---- APP-007 表示の条件 ----
    def new_field(code, scope, sw_field, sw_values):
        s, _, body = kacho.get("/admin/field_edit.php?dept=%d" % dept_a)
        data = {"csrf_token": csrf(body), "request_key": rkey(body), "code": code, "label": "条件の確認", "field_type": "multi_text",
                "field_scope": scope, "confidentiality": "internal", "max_length": "200",
                "show_when_field": sw_field, "show_when_values": sw_values}
        s, loc, body = kacho.post("/admin/field_edit.php?dept=%d" % dept_a, data)
        made = db.execute("SELECT 1 FROM report_fields WHERE code = ?", (code,)).fetchone() is not None
        return s, made, body

    s, made, _ = new_field("sw_other_dept", "project", "client_recruiting", "yes")
    check("APP-007 別の課の項目を条件にできない", not made, s)
    db.execute("UPDATE report_fields SET is_active = 0 WHERE code = 'after_end_work'")
    s, made, _ = new_field("sw_inactive", "project", "after_end_work", "yes")
    check("APP-007 無効の項目を条件にできない", not made, s)
    db.execute("UPDATE report_fields SET is_active = 1 WHERE code = 'after_end_work'")
    s, made, body = new_field("sw_bad_value", "project", "progress", "delayed,unknown_value")
    check("APP-007 選択肢にない値は入れられない", not made and "unknown_value" in body, s)
    s, made, _ = new_field("sw_other_scope", "report", "progress", "delayed")
    check("APP-007 区分の違う項目を条件にできない", not made, s)
    s, made, _ = new_field("sw_ok", "project", "progress", "delayed,other")
    check("APP-007 同じ様式・同じ区分の有効な選択式の項目と、その選択肢なら保存できる", made, s)

    # ---- APP-005 再設定の文言をそろえる ----
    def reset(login_id, code):
        c = Client(a.base)
        s, _, body = c.get("/password_reset.php")
        s, _, body = c.post("/password_reset.php", {"csrf_token": csrf(body), "login_id": login_id, "code": code,
                                                    "new_password": "new-pass-5678-abcd", "new_password_confirm": "new-pass-5678-abcd"})
        return body

    locked_until = (datetime.datetime.now() + datetime.timedelta(minutes=15)).strftime("%Y-%m-%d %H:%M:%S")
    db.execute("UPDATE users SET locked_until = ? WHERE id = ?", (locked_until, U["ippan_b1"]["id"]))
    b_locked = reset("ippan_b1", "000000")
    b_unknown = reset("no_such_user", "000000")
    check("APP-005 ロック中の ID でもコードの誤りと同じ文言", "ログイン ID かコードが違います" in b_locked and "しばらく" not in b_locked)
    check("APP-005 存在しない ID も同じ文言", "ログイン ID かコードが違います" in b_unknown and "しばらく" not in b_unknown)
    db.execute("UPDATE users SET locked_until = NULL, failed_count = 0 WHERE id = ?", (U["ippan_b1"]["id"],))
    db.execute("DELETE FROM login_throttle")

    # ---- APP-011 設定が済む前は端末の解除・すべての端末からのログアウトをしない ----
    def account_post(c, action, extra=None):
        s, _, body = c.get("/account.php")
        data = {"csrf_token": csrf(body), "action": action}
        data.update(extra or {})
        return c.post("/account.php", data)[0]

    db.execute("UPDATE users SET totp_enabled_at = NULL WHERE id = ?", (U["ippan_b1"]["id"],))
    c = Client(a.base)
    s, _, body = c.get("/login.php")
    c.post("/login.php", {"csrf_token": csrf(body), "action": "password", "login_id": "ippan_b1", "password": U["ippan_b1"]["password"]})
    check("APP-011 2 段階認証が未設定の間はすべての端末からログアウトできない（403）", account_post(c, "logout_all") == 403)
    check("APP-011 2 段階認証が未設定の間は端末の解除ができない（403）", account_post(c, "device_revoke", {"device_id": "1"}) == 403)
    db.execute("UPDATE users SET must_change_password = 1 WHERE id = ?", (U["dairi_a"]["id"],))
    dairi = login(a.base, "dairi_a", U["dairi_a"])
    check("APP-011 仮のパスワードの間はすべての端末からログアウトできない（403）", account_post(dairi, "logout_all") == 403)
    check("APP-011 仮のパスワードの間は端末の解除ができない（403）", account_post(dairi, "device_revoke", {"device_id": "1"}) == 403)
    s, _, _ = a1.get("/account.php?tab=devices")
    check("APP-011 設定が済んだ人はすべての端末からログアウトできる", account_post(a1, "logout_all") == 303)

    return summary()


if __name__ == "__main__":
    sys.exit(main())
