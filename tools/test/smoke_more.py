#!/usr/bin/env python3
"""段階 2・3 の機能の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

変更なしの提出、自分の報告一覧、提出状況、横並び・まとめ、臨時の調査、出力、記録、削除データ。

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_more.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import datetime
import io
import json
import os
import re
import sqlite3
import sys
import zipfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, csrf, login, rkey, summary  # noqa: E402


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8000")
    ap.add_argument("--db", required=True)
    a = ap.parse_args()
    here = os.path.dirname(os.path.abspath(__file__))
    U = json.load(open(os.path.join(here, "output_dev_users.json"), encoding="utf-8"))["users"]
    db = sqlite3.connect("file:%s?mode=ro" % a.db, uri=True)
    F = {c: i for c, i in db.execute("SELECT code, id FROM report_fields")}
    P = {c: i for c, i in db.execute("SELECT code, id FROM projects")}
    today = datetime.date.today()
    cur = today.strftime("%Y-%m")
    prev = (today.replace(day=1) - datetime.timedelta(days=1)).strftime("%Y-%m")

    def block(i, project, **vals):
        d = {"b[%d][project]" % i: str(P[project])}
        for k, v in vals.items():
            d["b[%d][f][%d]" % (i, F[k])] = v
        return d

    # ---- 前月の報告を提出 → 当月は「変更なし」で提出 ----
    a1 = login(a.base, "ippan_a1", U["ippan_a1"])
    s, _, body = a1.get("/reports/edit.php?period=" + prev)
    check("前月の報告がないときは「変更なし」が出ない", "先月から変更なしで提出" not in body)
    form = {"csrf_token": csrf(body), "request_key": rkey(body), "period": prev, "action": "submit"}
    form.update(block(0, "P-0001", duties="在庫の引当処理", phase="build_unit", progress="on_track", continuation="ending",
                      end_month=cur, staffing="increase", staffing_note="増員 2 名の話", system_type="inventory"))
    s, loc, _ = a1.post("/reports/save.php", form)
    check("前月の報告を提出", s == 303 and "view.php" in loc, (s, loc))
    s, _, body = a1.get("/reports/edit.php?period=" + cur)
    check("当月は「変更なし」で提出できる表示", "先月から変更なしで提出" in body)
    s, loc, _ = a1.post("/reports/save.php", {"csrf_token": csrf(body), "request_key": rkey(body), "period": cur, "action": "unchanged"})
    row = db.execute("SELECT id, status, unchanged FROM report_records WHERE user_id = ? AND period = ?", (U["ippan_a1"]["id"], cur)).fetchone()
    check("「変更なし」で提出された", row is not None and row[1] == "submitted" and row[2] == 1, row)
    cur_id = row[0] if row else 0
    v = db.execute("SELECT value_text FROM report_field_values WHERE report_id = ? AND field_id = ?", (cur_id, F["phase"])).fetchone()
    check("前月の値が入っている", v is not None and v[0] == "build_unit", v)

    s, _, body = a1.get("/reports/mine.php")
    check("自分の報告一覧に 2 か月分", s == 200 and body.count("/reports/view.php?id=") >= 2, s)

    # ---- 提出状況 ----
    kacho = login(a.base, "kacho_a", U["kacho_a"])
    s, _, body = kacho.get("/status/index.php?period=%s&dept=%d" % (cur, json.load(open(os.path.join(here, "output_dev_users.json"), encoding="utf-8"))["dept_a"]))
    check("提出状況が開ける", s == 200 and "A一般1" in body and "A一般2" in body, s)
    check("変更なしの印が出る", "変更なし" in body)
    s, loc, _ = kacho.get("/status/index.php?period=%s&filter=unchecked&go=1" % cur)
    check("未チェックを順に見るで最初の報告へ", s == 303 and "view.php?id=%d" % cur_id in loc, (s, loc))
    b1 = login(a.base, "ippan_b1", U["ippan_b1"])
    s, _, _ = b1.get("/status/index.php")
    check("確認者でない人は提出状況を開けない（403）", s == 403, s)
    s, _, _ = kacho.get("/status/index.php?dept=%d" % json.load(open(os.path.join(here, "output_dev_users.json"), encoding="utf-8"))["dept_b"])
    check("別の課の提出状況は開けない（403）", s == 403, s)

    # ---- 横並び・まとめ ----
    leader = login(a.base, "leader_a", U["leader_a"])
    s, _, body = leader.get("/status/compare.php?period=" + prev)
    check("横並びに案件と選択肢の表示名", s == 200 and "P-0001" in body and "製造・単体" in body, s)
    check("既定は横並びの表（まとめではない）", 'class="table compare"' in body and "終了が近い案件（" not in body)
    check("確認者の横並びに「限定」の列が出ない", "人員の動きの詳細" not in body)
    check("横並びの一覧では自由記述を出さない", "在庫の引当処理" not in body)
    rid_prev = db.execute("SELECT id FROM report_records WHERE user_id = ? AND period = ?", (U["ippan_a1"]["id"], prev)).fetchone()[0]
    rp = db.execute("SELECT id FROM report_projects WHERE report_id = ?", (rid_prev,)).fetchone()[0]
    s, _, j = leader.get("/status/compare.php?cell=%d:%d:%d" % (rid_prev, rp, F["duties"]))
    check("自由記述のセルを開ける", s == 200 and "在庫の引当処理" in j, (s, j[:80]))
    s, _, j = leader.get("/status/compare.php?cell=%d:%d:%d" % (rid_prev, rp, F["staffing_note"]))
    check("確認者は「限定」のセルを開けない", s == 404, s)
    s, _, body = kacho.get("/status/compare.php?period=%s&view=summary" % prev)
    check("まとめに終了が近い案件・人員の動き", "終了が近い案件（1件）" in body and "人員の動きがある現場（1件）" in body)

    # ---- 臨時の調査 ----
    s, loc, body = kacho.get("/surveys/survey_edit.php")
    due = (today + datetime.timedelta(days=3)).isoformat()
    s, loc, _ = kacho.post("/surveys/survey_edit.php", {"csrf_token": csrf(body), "request_key": rkey(body), "action": "save_meta",
                                                        "department_id": "1", "title": "AI の利用状況", "requester": "経営企画", "purpose": "研修の計画のため",
                                                        "due_at": due, "result_visibility": "summary", "target_type": "all"})
    m = re.search(r"id=(\d+)", loc)
    check("臨時の調査を作れる", s == 303 and m is not None, (s, loc))
    sid = int(m.group(1)) if m else 0
    s, _, body = kacho.get("/surveys/survey_edit.php?id=%d" % sid)
    kacho.post("/surveys/survey_edit.php?id=%d" % sid, {"csrf_token": csrf(body), "request_key": rkey(body), "action": "add_source", "field_id": F["phase"]})
    s, _, body = kacho.get("/surveys/survey_edit.php?id=%d" % sid)
    kacho.post("/surveys/survey_edit.php?id=%d" % sid, {"csrf_token": csrf(body), "request_key": rkey(body), "action": "add_custom", "q_label": "AI を業務で使っていますか",
                                                         "q_type": "radio", "q_options": "毎日\nときどき\n使わない", "q_conf": "internal", "q_required": "1"})
    s, _, body = kacho.get("/surveys/survey_edit.php?id=%d" % sid)
    check("質問が 2 つ", body.count("<li>") >= 2 and "AI を業務で使っていますか" in body)
    s, loc, _ = kacho.post("/surveys/survey_edit.php?id=%d" % sid, {"csrf_token": csrf(body), "request_key": rkey(body), "action": "publish"})
    st = db.execute("SELECT status FROM adhoc_requests WHERE id = ?", (sid,)).fetchone()[0]
    check("公開できる", st == "open", st)
    s, _, body = kacho.get("/surveys/survey_edit.php?id=%d" % sid)
    s, _, _ = kacho.post("/surveys/survey_edit.php?id=%d" % sid, {"csrf_token": csrf(body), "request_key": rkey(body), "action": "add_custom", "q_label": "x", "q_type": "radio", "q_options": "a", "q_conf": "normal"})
    check("公開後は質問を足せない", s == 400, s)
    s, _, home = a1.get("/index.php")
    check("対象者のホームに臨時の調査", "AI の利用状況" in home)
    s, _, ab = a1.get("/surveys/answer.php?id=%d" % sid)
    check("依頼元と結果の扱いが表示される", "経営企画" in ab and "集計のみ" in ab)
    check("月次の項目に最新の値が入る", 'value="build_unit" selected' in ab)
    qids = [int(x) for x in re.findall(r'name="q\[(\d+)\]', ab)]
    custom_q = max(qids)
    opt = re.search(r'name="q\[%d\]" value="(o\d)"' % custom_q, ab).group(1)
    s, loc, _ = a1.post("/surveys/answer.php?id=%d" % sid, {"csrf_token": csrf(ab), "request_key": rkey(ab), "id": sid,
                                                            "q[%d]" % min(qids): "build_unit", "q[%d]" % custom_q: opt})
    check("回答できる", s == 303, (s, loc))
    s, _, _ = b1.get("/surveys/answer.php?id=%d" % sid)
    check("対象外の人は回答画面を開けない（404）", s == 404, s)
    s, _, rb = kacho.get("/surveys/result.php?id=%d" % sid)
    check("結果に回答 1/", "回答 <strong>1/" in rb, s)
    check("未回答者が出る", "A一般2" in rb)

    # ---- 出力 ----
    s, _, eb = kacho.get("/export/index.php")
    base = {"csrf_token": csrf(eb), "type": "report", "dept": "1", "from": prev, "to": prev, "content": "both", "free": "1"}
    s, _, _ = kacho.post("/export/index.php", dict(base, format="xlsx"))
    raw = kacho.last_raw
    ok = False
    try:
        z = zipfile.ZipFile(io.BytesIO(raw))
        sheet = z.read("xl/worksheets/sheet1.xml").decode("utf-8")
        ok = "社外秘" in sheet and "P-0001" in sheet and "在庫の引当処理" in sheet and "増員 2 名" not in sheet
        z.testzip()
    except Exception as e:  # noqa: BLE001
        sheet = str(e)
    check("xlsx が正しい zip で、社外秘・中身があり、限定がない", s == 200 and ok, (s, sheet[:120]))
    s, _, _ = kacho.post("/export/index.php", dict(base, format="csv"))
    csvtext = kacho.last_raw.decode("utf-8-sig", "replace")
    check("CSV は BOM 付きで社外秘の見出し", kacho.last_raw.startswith(b"\xef\xbb\xbf") and "社外秘" in csvtext and "出力者" in csvtext)
    s, _, pb = kacho.post("/export/index.php", dict(base, format="print"))
    check("印刷用画面が出る", s == 200 and "印刷する" in pb and "社外秘" in pb)
    s, _, _ = leader.post("/export/index.php", dict(base, csrf_token=csrf(leader.get("/index.php")[2]), format="csv"))
    check("確認者は出力できない（403）", s == 403, s)
    kacho.headers["User-Agent"] = "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148"
    s, _, _ = kacho.post("/export/index.php", dict(base, format="csv"))
    check("スマートフォンからは出力できない（403）", s == 403, s)
    kacho.headers["User-Agent"] = "Mozilla/5.0 (Windows NT 10.0) test"
    s, _, _ = kacho.post("/export/index.php", {"csrf_token": csrf(eb), "type": "survey", "survey_id": str(sid), "format": "csv"})
    st = kacho.last_raw.decode("utf-8-sig", "replace")
    check("集計のみの調査は集計だけを出力", "AI を業務で使っていますか" in st and "A一般1" not in st, st[:200])
    n = db.execute("SELECT COUNT(*) FROM access_logs WHERE action = 'export'").fetchone()[0]
    check("出力が記録される", n >= 4, n)

    # ---- 記録・削除データ ----
    a2 = login(a.base, "ippan_a2", U["ippan_a2"])
    s, _, body = a2.get("/reports/edit.php?period=" + prev)
    f2 = {"csrf_token": csrf(body), "request_key": rkey(body), "period": prev, "action": "draft"}
    f2.update(block(0, "P-0002", phase="operation"))
    a2.post("/reports/save.php", f2)
    s, _, body = a2.get("/reports/edit.php?period=" + prev)
    a2.post("/reports/save.php", {"csrf_token": csrf(body), "request_key": rkey(body), "period": prev, "action": "delete"})
    did = db.execute("SELECT id FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NOT NULL", (U["ippan_a2"]["id"], prev)).fetchone()
    check("下書きを削除できる", did is not None)
    adm = login(a.base, "admin01", U["admin01"])
    s, _, lb = adm.get("/admin/logs.php?kind=export")
    check("記録に出力が出る", s == 200 and "export:report" in lb, s)
    s, _, tb = adm.get("/admin/trash.php")
    check("削除データに出る", "A一般2" in tb)
    s, loc, _ = adm.post("/admin/trash.php", {"csrf_token": csrf(tb), "request_key": rkey(tb), "action": "restore_report", "id": did[0] if did else 0})
    back = db.execute("SELECT deleted_at FROM report_records WHERE id = ?", (did[0] if did else 0,)).fetchone()
    check("削除した報告を戻せる", back is not None and back[0] is None, back)
    s, _, _ = kacho.get("/admin/logs.php")
    check("課長は記録を開けない（403）", s == 403, s)

    return summary()


if __name__ == "__main__":
    sys.exit(main())
