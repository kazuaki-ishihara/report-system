#!/usr/bin/env python3
"""報告の流れの確認（ローカル検証環境専用。tools/test/dev_seed.php のデータが前提）。

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_reports.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
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


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8000")
    ap.add_argument("--db", required=True)
    a = ap.parse_args()
    here = os.path.dirname(os.path.abspath(__file__))
    seed = json.load(open(os.path.join(here, "output_dev_users.json"), encoding="utf-8"))
    U = seed["users"]
    db = sqlite3.connect("file:%s?mode=ro" % a.db, uri=True)
    F = {code: fid for code, fid in db.execute("SELECT code, id FROM report_fields")}
    P = {code: pid for code, pid in db.execute("SELECT code, id FROM projects")}

    today = datetime.date.today()
    cur = today.strftime("%Y-%m")
    prev = (today.replace(day=1) - datetime.timedelta(days=1)).strftime("%Y-%m")

    # ---- 部下: 前月の報告を作る ----
    a1 = login(a.base, "ippan_a1", U["ippan_a1"])
    s, _, body = a1.get("/reports/edit.php?period=" + prev)
    check("報告の入力画面が開ける", s == 200 and "案件" in body, s)
    check("A課の様式に A課の項目がある", "業務システムの種類" in body and "問題・悩み事" in body)
    check("B課の項目は出ない", "客先の追加要員の募集" not in body)

    def blockdata(i, project, **vals):
        d = {"b[%d][project]" % i: str(P[project])}
        for k, v in vals.items():
            key = "b[%d][f][%d]" % (i, F[k])
            d[key + ("[]" if isinstance(v, list) else "")] = v
        return d

    base = {"csrf_token": csrf(body), "period": prev}
    form = dict(base)
    form.update(blockdata(0, "P-0001", duties="在庫の引当処理の改修。株式会社テストの案件", phase="build_unit",
                          staffing="increase", staffing_note="来月 2 名増員の話", system_type="inventory"))
    form["r[%d]" % F["trouble_note"]] = "特になし"
    form["request_key"] = rkey(body)
    form["action"] = "draft"
    s, loc, _ = a1.post("/reports/save.php", form)
    check("下書き保存（必須が空でも保存できる）", s == 303 and "edit.php" in loc, (s, loc))
    rid = db.execute("SELECT id FROM report_records WHERE period = ? AND user_id = ?", (prev, U["ippan_a1"]["id"])).fetchone()
    check("報告が作られた", rid is not None)
    rid = rid[0]
    row = db.execute("SELECT value_text, is_encrypted, has_warning FROM report_field_values WHERE report_id = ? AND field_id = ?", (rid, F["duties"])).fetchone()
    check("自由記述が暗号化されている", row and row[1] == 1 and row[0].startswith("v1:") and "在庫" not in row[0], row)
    check("客先名らしい言葉に印が付く", row and row[2] == 1)
    row = db.execute("SELECT value_text, is_encrypted FROM report_field_values WHERE report_id = ? AND field_id = ?", (rid, F["phase"])).fetchone()
    check("選択式（通常）は平文", row and row[1] == 0 and row[0] == "build_unit", row)

    # 同じ送信の番号で送り直しても報告は 1 件
    s, loc, _ = a1.post("/reports/save.php", form)
    n = db.execute("SELECT COUNT(*) FROM report_records WHERE period = ? AND user_id = ?", (prev, U["ippan_a1"]["id"])).fetchone()[0]
    check("同じ送信の番号の 2 回目は処理されない", n == 1 and s == 303, (n, s))

    # 必須（進捗・継続見込み）が空のまま提出 → 戻される
    s, _, body = a1.get("/reports/edit.php?period=" + prev)
    form["csrf_token"] = csrf(body)
    form["request_key"] = rkey(body)
    form["action"] = "submit"
    s, loc, body2 = a1.post("/reports/save.php", form)
    check("必須が空なら提出できない", s == 200 and "入力してください" in body2, s)
    st = db.execute("SELECT status FROM report_records WHERE id = ?", (rid,)).fetchone()[0]
    check("提出されていない", st == "draft", st)

    form.update(blockdata(0, "P-0001", progress="on_track", continuation="continuing"))
    form["request_key"] = rkey(body2)
    s, loc, _ = a1.post("/reports/save.php", form)
    check("提出できる", s == 303 and "view.php?id=%d" % rid in loc, (s, loc))

    # ---- 閲覧の権限 ----
    leader = login(a.base, "leader_a", U["leader_a"])
    s, _, vbody = leader.get("/reports/view.php?id=%d" % rid)
    check("同じ課の確認者は見られる", s == 200 and "在庫の引当処理" in vbody, s)
    check("確認者には「限定」が見えない", "来月 2 名増員" not in vbody and "限定のため表示しません" in vbody)
    check("確認者には注意の印が見える", "客先名の可能性" in vbody)
    kacho = login(a.base, "kacho_a", U["kacho_a"])
    s, _, kbody = kacho.get("/reports/view.php?id=%d" % rid)
    check("課長には「限定」が見える", "来月 2 名増員" in kbody)
    for who in ("ippan_a2", "kacho_b"):
        c = login(a.base, who, U[who])
        s, _, _ = c.get("/reports/view.php?id=%d" % rid)
        check("%s は見られない（404）" % who, s == 404, s)

    # ---- コメント: 依頼 → 本人の返信で回答あり → 解決 ----
    s, _, vbody = leader.get("/reports/view.php?id=%d" % rid)
    s, loc, _ = leader.post("/reports/act.php", {"csrf_token": csrf(vbody), "request_key": rkey(vbody), "action": "comment", "report_id": rid,
                                                 "field_id": F["phase"], "report_project_id": db.execute("SELECT id FROM report_projects WHERE report_id = ?", (rid,)).fetchone()[0],
                                                 "comment_type": "request", "comment": "単体テストの完了見込みを教えてください"})
    check("確認者がコメントできる", s == 303, (s, loc))
    cm = db.execute("SELECT id, comment_enc, thread_status FROM report_field_comments WHERE report_id = ?", (rid,)).fetchone()
    check("コメントは暗号化されている", cm and cm[1].startswith("v1:") and "単体" not in cm[1], cm)
    check("依頼は回答待ち", cm and cm[2] == "open")
    s, _, home = a1.get("/index.php")
    check("本人のホームに回答待ちが出る", "回答待ちのコメント 1件" in home)
    s, _, vb = a1.get("/reports/view.php?id=%d" % rid)
    s, loc, _ = a1.post("/reports/act.php", {"csrf_token": csrf(vb), "request_key": rkey(vb), "action": "comment", "report_id": rid,
                                             "field_id": F["phase"], "report_project_id": 0, "comment_type": "note", "comment": "x"})
    check("本人は最初のコメントを出せない（403）", s == 403, s)
    s, loc, _ = a1.post("/reports/act.php", {"csrf_token": csrf(vb), "request_key": rkey(vb), "action": "reply", "report_id": rid,
                                             "parent_id": cm[0], "comment": "来週の予定です"})
    st = db.execute("SELECT thread_status FROM report_field_comments WHERE id = ?", (cm[0],)).fetchone()[0]
    check("本人の返信で回答ありになる", st == "answered", st)
    s, _, vb = leader.get("/reports/view.php?id=%d" % rid)
    s, loc, _ = leader.post("/reports/act.php", {"csrf_token": csrf(vb), "request_key": rkey(vb), "action": "resolve", "report_id": rid, "parent_id": cm[0]})
    st = db.execute("SELECT thread_status FROM report_field_comments WHERE id = ?", (cm[0],)).fetchone()[0]
    check("依頼した人が解決にできる", st == "resolved", st)

    # ---- チェック → 本人が修正 → 更新あり ----
    s, _, vb = leader.get("/reports/view.php?id=%d" % rid)
    leader.post("/reports/act.php", {"csrf_token": csrf(vb), "request_key": rkey(vb), "action": "check", "report_id": rid})
    s, _, vb = leader.get("/reports/view.php?id=%d" % rid)
    check("チェック済みになる", "チェック済" in vb)
    s, _, eb = a1.get("/reports/edit.php?period=" + prev)
    form["csrf_token"] = csrf(eb)
    form["request_key"] = rkey(eb)
    form["action"] = "save"
    form.update(blockdata(0, "P-0001", progress="delayed", progress_note="結合環境の準備が遅れています"))
    s, loc, _ = a1.post("/reports/save.php", form)
    rev = db.execute("SELECT revision FROM report_records WHERE id = ?", (rid,)).fetchone()[0]
    check("提出後の修正で更新の回数が上がる", rev == 1, rev)
    nh = db.execute("SELECT COUNT(*) FROM report_value_history WHERE report_id = ?", (rid,)).fetchone()[0]
    check("変更履歴が残る", nh >= 2, nh)
    s, _, vb = leader.get("/reports/view.php?id=%d" % rid)
    check("確認者に「更新あり」が出る", "更新あり" in vb)
    s, _, eb = a1.get("/reports/edit.php?period=" + prev)
    form["csrf_token"] = csrf(eb)
    form["request_key"] = rkey(eb)
    a1.post("/reports/save.php", form)
    rev2 = db.execute("SELECT revision FROM report_records WHERE id = ?", (rid,)).fetchone()[0]
    check("変更がなければ更新の回数は上がらない", rev2 == rev, rev2)

    # ---- 確認完了 ----
    s, _, vb = leader.get("/reports/view.php?id=%d" % rid)
    s, loc, _ = leader.post("/reports/act.php", {"csrf_token": csrf(vb), "request_key": rkey(vb), "action": "confirm", "report_id": rid})
    check("確認者は確認完了にできない（403）", s == 403, s)
    s, _, vb = kacho.get("/reports/view.php?id=%d" % rid)
    s, loc, _ = kacho.post("/reports/act.php", {"csrf_token": csrf(vb), "request_key": rkey(vb), "action": "confirm", "report_id": rid})
    st = db.execute("SELECT status FROM report_records WHERE id = ?", (rid,)).fetchone()[0]
    check("課長が確認完了にできる", st == "confirmed", st)
    s, loc, _ = a1.get("/reports/edit.php?period=" + prev)
    check("確認完了の報告は編集画面から詳細へ転送", s == 303 and "view.php" in loc, (s, loc))
    form["request_key"] = "0" * 31 + "1"
    s, loc, _ = a1.post("/reports/save.php", form)
    check("確認完了の報告への保存は断られる", s == 400, s)

    # ---- 前月からの引き継ぎ ----
    s, _, eb = a1.get("/reports/edit.php?period=" + cur)
    check("当月の画面に前月の内容が入る", "前月の内容を入れてあります" in eb and 'value="build_unit" selected' in eb, s)
    check("「限定」の自由記述は引き継がない設定どおり", "来月 2 名増員" not in eb)

    # ---- 管理画面の権限 ----
    s, _, _ = a1.get("/admin/fields.php")
    check("部下は項目マスタを開けない（403）", s == 403, s)
    s, _, fb = kacho.get("/admin/fields.php")
    check("課長は自分の課の項目マスタを開ける", s == 200 and "業務システムの種類" in fb, s)
    s, _, _ = kacho.get("/admin/projects.php")
    check("課長は案件の管理を開けない（403）", s == 403, s)
    adm = login(a.base, "admin01", U["admin01"])
    s, _, ob = adm.get("/admin/org.php?tab=users")
    check("管理者はユーザーの一覧を開ける", s == 200 and "ippan_a1" in ob, s)
    s, _, pb = adm.get("/admin/projects.php")
    s, loc, _ = adm.post("/admin/projects.php", {"csrf_token": csrf(pb), "request_key": rkey(pb), "action": "add", "alias": "テスト", "department_id": ""})
    newp = db.execute("SELECT code FROM projects ORDER BY id DESC LIMIT 1").fetchone()[0]
    check("案件コードが連番で振られる", newp == "P-0005", newp)

    return summary()


if __name__ == "__main__":
    sys.exit(main())
