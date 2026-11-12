#!/usr/bin/env python3
"""項目マスタ（G-12）の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

追加・編集・権限・変更の決まり（入力部品・区分・選択肢）・「限定」の付け外しと暗号化のし直し・
並べ替え・有効/無効・作成済みの報告への影響・表示名のエスケープを確かめる。

    python3 tools/test/smoke_fields.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import datetime
import json
import os
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, csrf, login, rkey, summary  # noqa: E402


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8000")
    ap.add_argument("--db", required=True)
    a = ap.parse_args()
    here = os.path.dirname(os.path.abspath(__file__))
    seed = json.load(open(os.path.join(here, "output_dev_users.json"), encoding="utf-8"))
    U, dept_a, dept_b = seed["users"], seed["dept_a"], seed["dept_b"]
    db = sqlite3.connect("file:%s?mode=ro" % a.db, uri=True)

    def F():
        return {c: i for c, i in db.execute("SELECT code, id FROM report_fields")}

    P = {c: i for c, i in db.execute("SELECT code, id FROM projects")}
    today = datetime.date.today()
    cur = today.strftime("%Y-%m")
    prev = (today.replace(day=1) - datetime.timedelta(days=1)).strftime("%Y-%m")

    def field_form(fid):
        """今の定義を、編集画面から送るときの形にする（選択肢はすべて送る）"""
        r = db.execute("SELECT label, field_type, field_scope, confidentiality, help_text, placeholder, is_required, carry_over, show_in_list, is_searchable, include_in_export, settings_json FROM report_fields WHERE id = ?", (fid,)).fetchone()
        d = {"label": r[0], "field_type": r[1], "field_scope": r[2], "confidentiality": r[3], "help_text": r[4], "placeholder": r[5]}
        for k, v in zip(["is_required", "carry_over", "show_in_list", "is_searchable", "include_in_export"], r[6:11]):
            if v:
                d[k] = "1"
        s = json.loads(r[11] or "{}")
        if "show_when" in s:
            d["show_when_field"] = s["show_when"]["field"]
            d["show_when_values"] = ",".join(s["show_when"]["values"])
        for k in ("max_length", "min", "max", "decimals", "max_rows"):
            if k in s:
                d[k] = str(s[k])
        for i, (code, label, active) in enumerate(db.execute("SELECT code, label, is_active FROM report_field_options WHERE field_id = ? ORDER BY sort_order, id", (fid,))):
            d["opt_code[%d]" % i] = code
            d["opt_label[%d]" % i] = label
            if active:
                d["opt_active[%d]" % i] = "1"
        return d

    def post_field(c, fid, changes, dept_param=None):
        path = "/admin/field_edit.php?id=%d" % fid if fid else "/admin/field_edit.php?dept=%s" % dept_param
        s, _, body = c.get(path)
        data = field_form(fid) if fid else {}
        data.update(changes)
        data["csrf_token"] = csrf(body)
        data["request_key"] = rkey(body)
        return c.post(path, data)

    # ---- 前提: 部下が前月の報告を提出（項目にデータがある状態を作る） ----
    a1 = login(a.base, "ippan_a1", U["ippan_a1"])
    f = F()
    s, _, body = a1.get("/reports/edit.php?period=" + prev)
    form = {"csrf_token": csrf(body), "request_key": rkey(body), "period": prev, "action": "submit",
            "b[0][project]": str(P["P-0001"]), "b[0][f][%d]" % f["phase"]: "build_unit", "b[0][f][%d]" % f["progress"]: "on_track",
            "b[0][f][%d]" % f["continuation"]: "continuing", "b[0][f][%d]" % f["system_type"]: "inventory"}
    s, loc, _ = a1.post("/reports/save.php", form)
    rid = db.execute("SELECT id FROM report_records WHERE user_id = ? AND period = ?", (U["ippan_a1"]["id"], prev)).fetchone()[0]
    check("前提: 前月の報告を提出", s == 303)

    kacho = login(a.base, "kacho_a", U["kacho_a"])
    adm = login(a.base, "admin01", U["admin01"])

    # ---- 追加 ----
    new = {"label": "AI の利用", "code": "ai_usage", "field_type": "radio", "field_scope": "project", "confidentiality": "normal",
           "carry_over": "1", "include_in_export": "1", "show_in_list": "1",
           "opt_code[0]": "daily", "opt_label[0]": "毎日", "opt_active[0]": "1",
           "opt_code[1]": "sometimes", "opt_label[1]": "ときどき", "opt_active[1]": "1",
           "opt_code[2]": "never", "opt_label[2]": "使わない", "opt_active[2]": "1"}
    s, loc, _ = post_field(kacho, None, new, dept_a)
    row = db.execute("SELECT id, department_id, field_type, field_scope FROM report_fields WHERE code = 'ai_usage'").fetchone()
    check("課長が自分の課の項目を追加できる", s == 303 and row is not None and row[1] == dept_a and row[2] == "radio", (s, row))
    ai = row[0] if row else 0
    n = db.execute("SELECT COUNT(*) FROM report_field_options WHERE field_id = ?", (ai,)).fetchone()[0]
    check("選択肢が 3 つ登録される", n == 3, n)
    s, _, body = post_field(kacho, None, dict(new, label="重複"), dept_a)
    check("同じコードは断られる", s == 200 and "同じコードの項目があります" in body, s)
    s, _, body = post_field(kacho, None, dict(new, code="Bad-Code"), dept_a)
    check("コードの形が違うと断られる", s == 200 and "コードは英小文字で始まる" in body, s)
    s, _, body = post_field(kacho, None, dict(new, code="ai_secret", confidentiality="restricted"), dept_a)
    check("課長は「限定」の項目を作れない", s == 200 and "管理者だけ" in body, s)
    s, _, body = post_field(kacho, None, dict(new, code="ai_noopt", **{"opt_active[0]": "", "opt_active[1]": "", "opt_active[2]": ""}), dept_a)
    check("有効な選択肢がないと断られる", s == 200 and "有効な選択肢を 1 つ以上" in body, s)
    s, _, body = post_field(kacho, None, dict(new, code="x_label", label="<script>alert(1)</script>"), dept_a)
    s, _, lb = kacho.get("/admin/fields.php?dept=%d" % dept_a)
    check("表示名の HTML はエスケープされる", "&lt;script&gt;alert(1)&lt;/script&gt;" in lb and "<script>alert(1)" not in lb)

    # ---- 権限 ----
    s, _, _ = post_field(kacho, f["phase"], {"label": "フェーズ"})
    check("課長は共通項目を変更できない（403）", s == 403, s)
    s, _, _ = post_field(kacho, f["client_recruiting"], {"label": "x"})
    check("課長は別の課の項目を変更できない（403）", s == 403, s)
    s, _, _ = post_field(kacho, None, dict(new, code="b_field"), dept_b)
    check("課長は別の課に項目を足せない（403）", s == 403, s)
    s, _, _ = kacho.get("/admin/field_edit.php?dept=common")
    check("課長は共通項目の作成画面を開けない（403）", s == 403, s)

    # ---- 作成済みの報告への影響 ----
    s, _, eb = a1.get("/reports/edit.php?period=" + cur)
    check("新しい報告の入力画面に追加した項目が出る", "AI の利用" in eb)
    s, _, vb = a1.get("/reports/view.php?id=%d" % rid)
    check("作成済みの報告には出ない（作成時の定義のまま）", "AI の利用" not in vb)
    s, _, _ = post_field(kacho, ai, {"label": "AI の利用状況"})
    s, _, eb = a1.get("/reports/edit.php?period=" + cur)
    check("表示名の変更が新しい報告に反映される", "AI の利用状況" in eb)

    # ---- 変更の決まり（データがある項目） ----
    s, _, body = post_field(adm, f["phase"], {"field_type": "number"})
    check("データがある項目は互換のない種類に変えられない", s == 200 and "入力部品をこの種類には変えられません" in body, s)
    s, _, body = post_field(adm, f["phase"], {"field_scope": "report"})
    check("データがある項目は区分を変えられない", s == 200 and "区分は変えられません" in body, s)
    d = field_form(f["phase"])
    drop = {k: "" for k in d if k.startswith(("opt_code", "opt_label", "opt_active")) and k.endswith("[0]")}
    s, _, body = post_field(adm, f["phase"], drop)
    check("選択肢は削除できない", s == 200 and "選択肢は削除できません" in body, s)
    s, _, _ = post_field(adm, f["phase"], {"field_type": "radio"})
    t = db.execute("SELECT field_type FROM report_fields WHERE id = ?", (f["phase"],)).fetchone()[0]
    check("プルダウン→ラジオは変えられる", t == "radio", t)
    post_field(adm, f["phase"], {"field_type": "select"})
    idx = [k for k, v in field_form(f["phase"]).items() if k.startswith("opt_code") and v == "build_unit"][0][len("opt_code"):]
    s, _, _ = post_field(adm, f["phase"], {"opt_active" + idx: ""})
    act = db.execute("SELECT is_active FROM report_field_options WHERE field_id = ? AND code = 'build_unit'", (f["phase"],)).fetchone()[0]
    check("選択肢を無効にできる", act == 0, act)
    s, _, eb = a1.get("/reports/edit.php?period=" + cur)
    check("無効にした選択肢は新しい報告で選べない", 'value="build_unit"' not in eb)
    s, _, vb = a1.get("/reports/view.php?id=%d" % rid)
    check("過去の報告には無効にした選択肢の値が残る", "製造・単体" in vb)
    post_field(adm, f["phase"], {"opt_active" + idx: "1"})
    s, _, _ = post_field(adm, f["project"], {"field_type": "select"})
    t = db.execute("SELECT field_type FROM report_fields WHERE id = ?", (f["project"],)).fetchone()[0]
    check("案件の項目の入力部品は変えられない", t == "project", t)

    # ---- 「限定」の付け外しと暗号化のし直し ----
    s, _, body = post_field(kacho, f["system_type"], {"confidentiality": "restricted"})
    check("課長は「限定」を付けられない", s == 200 and "管理者だけ" in body, s)
    s, _, _ = post_field(adm, f["system_type"], {"confidentiality": "restricted"})
    v = db.execute("SELECT value_text, is_encrypted FROM report_field_values WHERE report_id = ? AND field_id = ?", (rid, f["system_type"])).fetchone()
    check("管理者が「限定」にすると入力済みの値が暗号化される", v and v[1] == 1 and v[0].startswith("v1:"), v)
    conf = db.execute("SELECT confidentiality FROM report_record_fields WHERE report_id = ? AND field_id = ?", (rid, f["system_type"])).fetchone()[0]
    check("作成済みの報告の定義も「限定」になる", conf == "restricted", conf)
    leader = login(a.base, "leader_a", U["leader_a"])
    # 案件の呼び名（物流系・在庫管理更改）にも同じ文字があるので、出てくる回数で比べる
    s, _, lvb = leader.get("/reports/view.php?id=%d" % rid)
    s, _, kvb = kacho.get("/reports/view.php?id=%d" % rid)
    check("確認者には隠れる", "限定のため表示しません" in lvb and lvb.count("在庫管理") < kvb.count("在庫管理"), (lvb.count("在庫管理"), kvb.count("在庫管理")))
    check("課長には復号して見える", '<div class="view-value">在庫管理</div>' in kvb)
    post_field(adm, f["system_type"], {"confidentiality": "normal"})
    v = db.execute("SELECT value_text, is_encrypted FROM report_field_values WHERE report_id = ? AND field_id = ?", (rid, f["system_type"])).fetchone()
    check("「限定」を外すと平文に戻る", v and v[1] == 0 and v[0] == "inventory", v)

    # ---- 並べ替え・有効/無効 ----
    s, _, lb = kacho.get("/admin/fields.php?dept=%d" % dept_a)
    before = db.execute("SELECT sort_order FROM report_fields WHERE id = ?", (ai,)).fetchone()[0]
    s, _, _ = kacho.post("/admin/fields.php?dept=%d" % dept_a, {"csrf_token": csrf(lb), "request_key": rkey(lb), "action": "move_up", "field_id": ai})
    after = db.execute("SELECT sort_order FROM report_fields WHERE id = ?", (ai,)).fetchone()[0]
    check("並べ替えで順番が変わる", after < before, (before, after))
    s, _, lb = kacho.get("/admin/fields.php?dept=%d" % dept_a)
    kacho.post("/admin/fields.php?dept=%d" % dept_a, {"csrf_token": csrf(lb), "request_key": rkey(lb), "action": "deactivate", "field_id": ai})
    s, _, eb = a1.get("/reports/edit.php?period=" + cur)
    check("無効にした項目は新しい報告に出ない", "AI の利用状況" not in eb)
    s, _, lb = kacho.get("/admin/fields.php?dept=%d" % dept_a)
    kacho.post("/admin/fields.php?dept=%d" % dept_a, {"csrf_token": csrf(lb), "request_key": rkey(lb), "action": "activate", "field_id": ai})
    s, _, eb = a1.get("/reports/edit.php?period=" + cur)
    check("有効に戻すと出る", "AI の利用状況" in eb)
    s, _, lb = adm.get("/admin/fields.php?dept=common")
    s, _, _ = adm.post("/admin/fields.php?dept=common", {"csrf_token": csrf(lb), "request_key": rkey(lb), "action": "deactivate", "field_id": f["project"]})
    act = db.execute("SELECT is_active FROM report_fields WHERE id = ?", (f["project"],)).fetchone()[0]
    check("案件の項目は無効にできない", act == 1, act)

    # ---- プレビュー・部下の権限 ----
    s, _, pb = kacho.get("/admin/field_edit.php?preview=%d" % dept_a)
    check("プレビューに追加した項目が出る", s == 200 and "AI の利用状況" in pb, s)
    s, _, _ = a1.get("/admin/field_edit.php?dept=%d" % dept_a)
    check("部下は項目の作成画面を開けない（403）", s == 403, s)
    n = db.execute("SELECT COUNT(*) FROM access_logs WHERE action = 'admin' AND target LIKE 'field:%'").fetchone()[0]
    check("項目の変更が記録される", n >= 8, n)
    return summary()


if __name__ == "__main__":
    sys.exit(main())
