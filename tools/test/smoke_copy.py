#!/usr/bin/env python3
"""過去の報告から写す機能の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。G-04 §4.4。

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_copy.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import datetime
import json
import os
import re
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, csrf, login, rkey, summary  # noqa: E402


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
    db = sqlite3.connect(a.db, timeout=10, isolation_level=None)
    F = {c: i for c, i in db.execute("SELECT code, id FROM report_fields")}
    P = {c: i for c, i in db.execute("SELECT code, id FROM projects")}
    now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    cur = datetime.date.today().strftime("%Y-%m")
    prev = month_shift(cur, -1)
    pm2 = month_shift(cur, -2)
    uid = U["ippan_a1"]["id"]

    def rid_of(period):
        r = db.execute("SELECT id FROM report_records WHERE user_id = ? AND period = ? AND is_legacy = 0 AND deleted_at IS NULL", (uid, period)).fetchone()
        return r[0] if r else 0

    a1 = login(a.base, "ippan_a1", U["ippan_a1"])

    # ---- 前提: 2 か月前（項目を多めに）と前月の報告を提出 ----
    s, _, body = a1.get("/reports/edit.php?period=" + pm2)
    form = {"csrf_token": csrf(body), "request_key": rkey(body), "period": pm2, "action": "submit",
            "b[0][project]": str(P["P-0001"]), "b[0][f][%d]" % F["phase"]: "requirements", "b[0][f][%d]" % F["progress"]: "delayed",
            "b[0][f][%d]" % F["progress_note"]: "仕様の確定待ちで遅延", "b[0][f][%d]" % F["continuation"]: "continuing",
            "b[0][f][%d]" % F["system_type"]: "inventory",
            "b[1][project]": str(P["P-0002"]), "b[1][f][%d]" % F["phase"]: "operation", "b[1][f][%d]" % F["progress"]: "on_track",
            "b[1][f][%d]" % F["continuation"]: "continuing",
            "r[%d]" % F["trouble_note"]: "夜間の障害対応が 2 回"}
    s, loc, _ = a1.post("/reports/save.php", form)
    check("前提: 2 か月前の報告を提出", s == 303 and "view.php" in loc, (s, loc))
    s, _, body = a1.get("/reports/edit.php?period=" + prev)
    form = {"csrf_token": csrf(body), "request_key": rkey(body), "period": prev, "action": "submit",
            "b[0][project]": str(P["P-0001"]), "b[0][f][%d]" % F["phase"]: "build_unit", "b[0][f][%d]" % F["progress"]: "on_track",
            "b[0][f][%d]" % F["continuation"]: "continuing", "b[0][f][%d]" % F["system_type"]: "inventory"}
    s, loc, _ = a1.post("/reports/save.php", form)
    check("前提: 前月の報告を提出", s == 303, (s, loc))
    id_pm2, id_prev = rid_of(pm2), rid_of(prev)
    db.execute("INSERT INTO report_records (user_id, department_id, period, status, is_legacy, created_at, updated_at) VALUES (?, ?, '2020-04', 'confirmed', 1, ?, ?)",
               (uid, seed["dept_a"], now, now))
    legacy_id = db.execute("SELECT last_insert_rowid()").fetchone()[0]

    # ---- 未作成の月: 写す元の一覧 ----
    s, _, body = a1.get("/reports/edit.php?period=" + cur)
    check("未作成の月に「過去の報告の内容を写す」が出る", s == 200 and 'name="copy_target"' in body, s)
    opts = [int(x) for x in re.findall(r'<option value="(\d+)"[^>]*>\d{4}年', body)]
    check("写す元は新しい月から並び、移行データを含まない", opts[:2] == [id_prev, id_pm2] and legacy_id not in opts, (opts, id_prev, id_pm2))

    # ---- 2 か月前の報告から写す（引き継がない項目も写る） ----
    db.execute("UPDATE projects SET is_active = 0 WHERE code = 'P-0002'")
    s, _, body = a1.get("/reports/edit.php?period=%s&copy_from=%d" % (cur, id_pm2))
    check("写した旨が出る", s == 200 and "の報告の内容を写しました。まだ保存していません" in body, s)
    check("選択式の値が写る", 'value="requirements" selected' in body)
    check("引き継がない項目（自由記述）も写る", "仕様の確定待ちで遅延" in body and "夜間の障害対応が 2 回" in body)
    check("終了した案件は写さず、知らせる", "写した報告の案件 P-0002 は終了したため外しました" in body)
    check("保存のフォームに写した元が入る", 'name="copy_from" value="%d"' % id_pm2 in body)
    db.execute("UPDATE projects SET is_active = 1 WHERE code = 'P-0002'")
    n_before = db.execute("SELECT COUNT(*) FROM report_records WHERE user_id = ? AND period = ?", (uid, cur)).fetchone()[0]
    check("写しただけでは保存しない", n_before == 0, n_before)

    # 写した内容で下書き保存
    vals = {"csrf_token": csrf(body), "request_key": rkey(body), "period": cur, "action": "draft", "copy_from": str(id_pm2),
            "b[0][project]": str(P["P-0001"]), "b[0][f][%d]" % F["phase"]: "requirements", "b[0][f][%d]" % F["progress"]: "delayed",
            "b[0][f][%d]" % F["progress_note"]: "仕様の確定待ちで遅延", "r[%d]" % F["trouble_note"]: "夜間の障害対応が 2 回"}
    s, loc, _ = a1.post("/reports/save.php", vals)
    row = db.execute("SELECT id, status, copied_from_id FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NULL", (uid, cur)).fetchone()
    check("写した内容で下書き保存し、写した元を記録", s == 303 and row is not None and row[2] == id_pm2, (s, loc, row))
    id_cur = row[0] if row else 0

    # ---- 下書きにも写し直せる ----
    s, _, body = a1.get("/reports/edit.php?period=%s&copy_from=%d" % (cur, id_prev))
    check("下書きでも写し直せる", s == 200 and 'value="build_unit" selected' in body and "仕様の確定待ちで遅延" not in body, s)
    vals = {"csrf_token": csrf(body), "request_key": rkey(body), "period": cur, "action": "draft", "copy_from": str(id_prev),
            "b[0][project]": str(P["P-0001"]), "b[0][f][%d]" % F["phase"]: "build_unit", "b[0][f][%d]" % F["progress"]: "on_track"}
    a1.post("/reports/save.php", vals)
    cf = db.execute("SELECT copied_from_id FROM report_records WHERE id = ?", (id_cur,)).fetchone()[0]
    check("写し直して保存すると、写した元を記録し直す", cf == id_prev, cf)

    # ---- 写せないもの ----
    other = login(a.base, "ippan_a2", U["ippan_a2"])
    s, _, ob = other.get("/reports/edit.php?period=%s&copy_from=%d" % (prev, id_pm2))
    check("ほかの人の報告は写せない（404）", s == 404, s)
    s, _, _ = other.post("/reports/save.php", {"csrf_token": csrf(other.get("/reports/edit.php?period=" + prev)[2]), "request_key": os.urandom(16).hex(),
                                               "period": prev, "action": "draft", "copy_from": str(id_pm2)})
    check("ほかの人の報告を写した元として送ると 400", s == 400, s)
    s, _, _ = a1.get("/reports/edit.php?period=%s&copy_from=%d" % (cur, id_cur))
    check("同じ月の報告は写す元にできない（404）", s == 404, s)
    s, _, _ = a1.get("/reports/edit.php?period=%s&copy_from=%d" % (cur, legacy_id))
    check("移行データは写す元にできない（404）", s == 404, s)
    s, _, _ = a1.get("/reports/edit.php?period=%s&copy_from=%d" % (prev, id_pm2))
    check("提出済みの報告には写せない（400）", s == 400, s)
    s, _, body = a1.get("/reports/edit.php?period=" + prev)
    check("提出済みの報告には「写す」を出さない", 'name="copy_target"' not in body)

    return summary()


if __name__ == "__main__":
    sys.exit(main())
