#!/usr/bin/env python3
"""自動保存・ログイン切れ・画面の中の送信の検証（ローカル検証環境専用。dev_seed.php の直後に実行する）。

実際のブラウザーで操作し、DB と突き合わせて確かめる。JavaScript からの送信は、画面に出る文言だけでは
届いたかどうか分からないため、必ず送り先の応答か DB の値まで見る（APP-118〜120）。

    python3 tools/test/ui_session.py --base http://127.0.0.1:8000 --db app/data/verify.sqlite
"""
import argparse
import datetime
import json
import os
import sqlite3
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import lib_http  # noqa: E402
from cdp import Browser  # noqa: E402
from lib_http import check, section, summary  # noqa: E402

# 要望・依頼を出すときの値
REQUEST_FORM = {"action": "create", "kind": "improvement", "urgency": "normal", "screen_code": "G-04", "title": "検証の要望", "body": "検証の本文", "source_path": ""}

# 自動保存の間隔（60 秒）だけを短くし、送った要求の宛先と応答を控える
PROBE_JS = """
(function () {
  var realSetInterval = window.setInterval;
  window.setInterval = function (callback, delay) { return realSetInterval(callback, delay === 60000 ? 1200 : delay); };
  window.__requests = [];
  var realFetch = window.fetch;
  window.fetch = function (url, options) {
    if (window.__offline) { return Promise.reject(new TypeError('Failed to fetch')); }
    return realFetch.apply(window, arguments).then(function (response) {
      window.__requests.push([String(url), response.status]);
      return response;
    });
  };
})();
"""


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8000")
    ap.add_argument("--db", required=True)
    ap.add_argument("--show", action="store_true")
    a = ap.parse_args()
    if "127.0.0.1" not in a.base and "localhost" not in a.base:
        print("ローカル検証環境（127.0.0.1）以外には実行しません")
        return 2
    here = os.path.dirname(os.path.abspath(__file__))
    seed = json.load(open(os.path.join(here, "output_dev_users.json"), encoding="utf-8"))
    users = seed["users"]
    seed_dept = seed["dept_a"]
    db = sqlite3.connect("file:%s?mode=ro" % a.db, uri=True)
    field_ids = dict(db.execute("SELECT code, id FROM report_fields"))
    project_ids = dict(db.execute("SELECT code, id FROM projects"))
    today = datetime.date.today()
    period = today.strftime("%Y-%m")
    duties = "#b0_%d" % field_ids["duties"]
    edit_url = a.base + "/reports/edit.php?period=" + period

    b = Browser(headless=not a.show)

    def set_session(client):
        """lib_http でログインした Cookie をブラウザーに入れる（別のタブでログインしたのと同じ状態）"""
        for cookie in client_cookies(client):
            b.call("Network.setCookie", {"name": cookie[0], "value": cookie[1], "url": a.base + "/", "path": "/", "httpOnly": True})

    def client_cookies(client):
        jar = getattr(client, "jar", None)
        if jar is None:
            jar = getattr(client, "cj", None)
        if jar is not None and not isinstance(jar, dict):
            return [(c.name, c.value) for c in jar]
        return list((jar if isinstance(jar, dict) else client.cookies).items())

    def login_as(login_id):
        b.clear_cookies()
        set_session(lib_http.login(a.base, login_id, users[login_id]))

    def report_row(login_id, report_period):
        return db.execute("SELECT id, status FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NULL",
                          (users[login_id]["id"], report_period)).fetchone()

    def status_text():
        return b.eval("(document.querySelector('.autosave-status') || {}).textContent || ''")

    def wait_status(part):
        return b.wait_until("(document.querySelector('.autosave-status') || {textContent: ''}).textContent.indexOf(%s) >= 0" % json.dumps(part), 8)

    def notice_text():
        return b.eval("(document.querySelector('.session-notice') || {}).textContent || ''")

    def wait_notice(part):
        return b.wait_until("(document.querySelector('.session-notice') || {textContent: ''}).textContent.indexOf(%s) >= 0" % json.dumps(part), 8)

    try:
        b.call("Page.addScriptToEvaluateOnNewDocument", {"source": PROBE_JS})
        b.goto(a.base + "/login.php")
        b.eval("localStorage.setItem('reportEditMode', 'list')")
        b.mobile(True)

        section("G-04", "下書きの自動保存が実際に届く")
        login_as("ippan_a1")
        b.goto(edit_url)
        check("準備: まだ報告がない月の入力画面が開く", b.exists("#report-form") and report_row("ippan_a1", period) is None)
        b.fill("#b0_project", str(project_ids["P-0001"]))
        b.fill(duties, "自動保存の確かめ1")
        check("「自動保存しました」と出る", wait_status("自動保存しました"), status_text())
        requests = b.eval("window.__requests") or []
        check("自動保存の送り先は /reports/save.php で、応答は 200", any(r[0].endswith("/reports/save.php") and r[1] == 200 for r in requests), requests)
        check("DB に下書きができる", (report_row("ippan_a1", period) or (0, ""))[1] == "draft")
        b.goto(edit_url)
        check("画面を開き直しても、自動保存した入力が残る", b.eval("document.querySelector('%s').value" % duties) == "自動保存の確かめ1")
        b.fill(duties, "自動保存の確かめ2")
        check("2 回目の自動保存も成功する（同じ月の報告が別にあると誤らない）", wait_status("自動保存しました"), status_text())

        section("G-04", "ログインが切れたときの自動保存と、ログインし直したあと")
        b.fill(duties, "切れたあとの入力")
        b.clear_cookies()
        check("ログインが切れたと出る（「通信が切れた」ではない）", wait_status("ログインが切れたため自動保存できませんでした"), status_text())
        check("入力は画面に残る", b.eval("document.querySelector('%s').value" % duties) == "切れたあとの入力")
        set_session(lib_http.login(a.base, "ippan_a1", users["ippan_a1"]))
        check("別のタブでログインし直すと、自動保存がまた成功する", wait_status("自動保存しました"), status_text())
        b.eval("window.__offline = true; true")
        b.fill(duties, "通信が切れたときの入力")
        check("通信が切れたときは「通信が切れた」と出る", wait_status("通信が切れたため自動保存できませんでした"), status_text())
        b.click('button[value="draft"]')
        check("通信が切れたまま保存を押すと、送らずに知らせる", wait_notice("通信できないため、送信していません"), notice_text())
        b.eval("window.__offline = false; true")
        check("通信が戻ると自動保存される", wait_status("自動保存しました"), status_text())

        section("共通", "ログインが切れたまま送っても、入力が失われない")
        b.fill(duties, "切れたまま保存")
        b.clear_cookies()
        b.click('button[value="draft"]')
        check("下書き保存: 送らずに、ログインが切れたと知らせる", wait_notice("ログインが切れたため、送信していません"), notice_text())
        check("下書き保存: 画面は移らず、入力が残る",
              "/reports/edit.php" in (b.eval("location.pathname") or "") and b.eval("document.querySelector('%s').value" % duties) == "切れたまま保存")
        check("別の画面でログインするボタンが出る", b.eval("document.querySelector('.session-notice a').getAttribute('target')") == "_blank")
        check("スマートフォンの幅で横にはみ出さない", bool(b.eval("document.documentElement.scrollWidth <= window.innerWidth + 1")))
        # 別の利用者でログインしたときは送らない（その人の報告として保存されないように）
        set_session(lib_http.login(a.base, "ippan_b1", users["ippan_b1"]))
        b.click('button[value="draft"]')
        check("別の利用者でログインしたときは送らない", wait_notice("別の利用者でログインしている"), notice_text())
        check("別の利用者の報告は作られない", report_row("ippan_b1", period) is None)
        b.clear_cookies()
        set_session(lib_http.login(a.base, "ippan_a1", users["ippan_a1"]))
        b.click('button[value="draft"]', navigate=True)
        check("ログインし直して押すと、保存される", "下書きを保存しました" in b.text())
        check("保存した値は、切れる前に入れたもの", b.eval("document.querySelector('%s').value" % duties) == "切れたまま保存")

        # 提出（確認の表示のあるボタンを含む）。ふだんの送信も、確認が 2 回出ない
        b.click('input[name="b[0][f][%d]"][value="on_track"]' % field_ids["progress"])
        b.click('input[name="b[0][f][%d]"][value="continuing"]' % field_ids["continuation"])
        b.fill(duties, "株式会社サンプルの確かめ")
        b.clear_cookies()
        b.dialogs.clear()
        b.click('button[value="submit"]')
        check("提出: 送らずに知らせる", wait_notice("ログインが切れたため、送信していません"), notice_text())
        set_session(lib_http.login(a.base, "ippan_a1", users["ippan_a1"]))
        b.dialogs.clear()
        b.click('button[value="submit"]', navigate=True)
        check("提出: ログインし直すと提出できる", (report_row("ippan_a1", period) or (0, ""))[1] == "submitted", b.text()[:200])
        check("提出: 注意の確認は 1 回だけ出る", len(b.dialogs) == 1, b.dialogs)

        b.goto(edit_url)
        b.fill(duties, "提出済みの直し")
        b.clear_cookies()
        b.click('button[value="save"]')
        check("提出済みの保存: 送らずに知らせる", wait_notice("ログインが切れたため、送信していません"), notice_text())
        set_session(lib_http.login(a.base, "ippan_a1", users["ippan_a1"]))
        b.click('button[value="save"]', navigate=True)
        b.goto(edit_url)
        check("提出済みの保存: ログインし直すと保存される", b.eval("document.querySelector('%s').value" % duties) == "提出済みの直し")

        # コメント（確認者）
        login_as("kacho_a")
        report_id = report_row("ippan_a1", period)[0]
        b.goto(a.base + "/reports/view.php?id=%d" % report_id)
        comment_count = db.execute("SELECT COUNT(*) FROM report_field_comments").fetchone()[0]
        b.eval("document.querySelector('details.comment-form').open = true; true")
        b.fill("details.comment-form textarea[name=comment]", "切れたときのコメント")
        b.clear_cookies()
        b.click("details.comment-form button[type=submit]")
        check("コメント: 送らずに知らせ、入力が残る", wait_notice("ログインが切れたため") and
              b.eval("document.querySelector('details.comment-form textarea[name=comment]').value") == "切れたときのコメント", notice_text())
        set_session(lib_http.login(a.base, "kacho_a", users["kacho_a"]))
        b.click("details.comment-form button[type=submit]", navigate=True)
        check("コメント: ログインし直すと書ける", db.execute("SELECT COUNT(*) FROM report_field_comments").fetchone()[0] == comment_count + 1)

        section("共通", "ログインの状態の確認の入口（session_check.php）と、JavaScript なしの送信")
        anonymous = lib_http.Client(a.base)
        status_code, _, _ = anonymous.get("/session_check.php?format=json")
        check("ログインしていなければ 401", status_code == 401, status_code)
        member = lib_http.login(a.base, "ippan_a2", users["ippan_a2"])
        status_code, _, body = member.get("/session_check.php?format=json")
        check("ログインしていれば、利用者の番号とトークンを返す", status_code == 200 and json.loads(body).get("user_id") == users["ippan_a2"]["id"]
              and len(json.loads(body).get("csrf_token", "")) == 64, body[:80])
        anonymous = lib_http.Client(a.base)
        status_code, location, _ = anonymous.post("/reports/save.php", {"action": "draft", "period": period})
        _, _, login_body = anonymous.get("/login.php")
        check("切れたまま届いた送信は、保存していないことをログイン画面で知らせる", status_code == 303 and "送信した内容は保存されていません" in login_body, location)

        section("G-25", "チャットの反応（解決した）が実際に記録される")
        login_as("admin01")
        b.goto(a.base + "/faq.php")
        b.click('button[value="import_standard"]', navigate=True)
        login_as("ippan_a2")
        b.goto(a.base + "/faq.php")
        b.fill("#faq_search_query", "パスワードを忘れた")
        b.click("#faq-chat form.faq-chat-input button[type=submit]")
        solved_js = "Array.prototype.find.call(document.querySelectorAll('#faq-chat .chat-choices button'), function (x) { return x.textContent.indexOf('解決した') === 0; })"
        check("「解決した」のボタンが出る", b.wait_until("!!" + solved_js, 8))
        b.eval(solved_js + ".click(); true")
        check("反応の送信が 200 で届く", b.wait_until("window.__requests.filter(function (r) { return r[0].indexOf('/faq.php') >= 0 && r[1] === 200; }).length >= 2", 8), b.eval("window.__requests"))
        check("反応が DB に残る", any("solved" in [str(v) for v in row] for row in db.execute("SELECT * FROM faq_chat_logs")) if
              db.execute("SELECT 1 FROM sqlite_master WHERE name = 'faq_chat_logs'").fetchone() else False)

        section("共通", "画面の中の計算・補助（これまで画面では確かめていなかったもの）")
        b.mobile(False)
        if os.path.exists(os.path.join(here, "..", "..", "public", "expenses", "edit.php")):
            b.goto(a.base + "/expenses/edit.php")
            b.fill('input[name="rows[0][amount]"]', "12000")
            b.fill("#advance_amount", "2000")
            time.sleep(0.3)
            check("精算: 小計と合計がその場で出る", b.eval("[document.querySelector('.expense-subtotal').textContent, document.querySelector('.expense-total').textContent].join()").replace(",", "") == "1200010000",
                  b.eval("[document.querySelector('.expense-subtotal').textContent, document.querySelector('.expense-total').textContent].join(' / ')"))
        # コンピテンシー評価基準の横スライド
        if os.path.exists(os.path.join(here, "..", "..", "public", "goals", "criteria.php")):
            b.mobile(True)
            b.goto(a.base + "/goals/criteria.php")
            slider_js = "Array.prototype.find.call(document.querySelectorAll('.goal-criteria-block'), function (x) { return x.querySelector('.goal-criteria-slider') && !x.querySelector('.goal-criteria-slider').hidden && x.offsetParent; })"
            check("評価基準: 横に長い表につまみが出る", bool(b.eval("!!" + slider_js)))
            b.eval("(function () { var r = %s.querySelector('input[type=range]'); r.value = '500'; r.dispatchEvent(new Event('input', {bubbles: true})); return true; })()" % slider_js)
            check("評価基準: つまみで表が横に動く", b.eval(slider_js + ".querySelector('.goal-criteria-scroll').scrollLeft") > 0)
            left = b.eval(slider_js + ".querySelector('.goal-criteria-scroll').scrollLeft")
            b.eval(slider_js + ".querySelector('[data-criteria-step]').click(); true")
            check("評価基準: 矢印で表が横に動く", b.wait_until(slider_js + ".querySelector('.goal-criteria-scroll').scrollLeft !== %s" % left, 4))
            b.mobile(False)
        # 自分の色
        b.goto(a.base + "/account.php?tab=theme")
        b.fill("#custom_accent", "#ffffff")
        b.fill("#custom_background", "#ffffff")
        time.sleep(0.3)
        check("自分の色: 読みにくい組み合わせを知らせる", "読みにくくなります" in (b.eval("document.querySelector('.custom-color-check').textContent") or ""))
        b.fill("#custom_accent", "#003366")
        time.sleep(0.3)
        check("自分の色: 直すと「読みやすい」になる", "読みやすい" in (b.eval("document.querySelector('.custom-color-check').textContent") or ""),
              b.eval("document.querySelector('.custom-color-check').textContent"))
        # 必須の未入力の数・1 問ずつの最後まで・二重押し
        login_as("ippan_b1")
        b.mobile(True)
        b.goto(edit_url)
        b.fill("#b0_project", b.eval("Array.prototype.filter.call(document.getElementById('b0_project').options, function (o) { return o.value !== ''; })[0].value"))
        time.sleep(0.2)
        before_text = b.eval("document.querySelector('.required-remaining').textContent")
        b.click('input[name="b[0][f][%d]"][value="on_track"]' % field_ids["progress"])
        time.sleep(0.2)
        check("必須の未入力の数が減る", before_text != "" and b.eval("document.querySelector('.required-remaining').textContent") != before_text, before_text)
        b.eval("localStorage.setItem('reportEditMode', 'step')")
        b.goto(edit_url)
        b.fill("#b0_project", b.eval("Array.prototype.filter.call(document.getElementById('b0_project').options, function (o) { return o.value !== ''; })[0].value"))
        for _ in range(40):
            if b.eval("document.querySelector('.wizard-next').hidden"):
                break
            b.eval("(function () { var f = document.querySelector('.wizard-current.is-required'); if (f) { var r = f.querySelector('input[type=radio]'); if (r && !f.querySelector('input[type=radio]:checked')) { r.click(); } } return true; })()")
            b.click(".wizard-next")
            time.sleep(0.05)
        check("1 問ずつ: 最後まで進むと答えの一覧になり、提出のボタンが出る", b.visible('button[value="submit"]') and "答えの一覧" in b.text())
        b.click(".wizard-prev")
        time.sleep(0.2)
        check("1 問ずつ: 答えの一覧から戻れる", not b.visible('button[value="submit"]') and b.visible(".wizard-next"))
        b.eval("localStorage.setItem('reportEditMode', 'list')")
        b.goto(edit_url)
        b.fill("#b0_project", b.eval("Array.prototype.filter.call(document.getElementById('b0_project').options, function (o) { return o.value !== ''; })[0].value"))
        count_sql = "SELECT COUNT(*) FROM request_keys WHERE action = 'report.draft'"
        rows_before = db.execute(count_sql).fetchone()[0]
        b.mark()
        b.eval("(function () { var x = document.querySelector('button[value=draft]'); x.click(); x.click(); return true; })()")
        time.sleep(0.5)
        b.wait_load()
        check("二重に押しても、送るのは 1 回", db.execute(count_sql).fetchone()[0] == rows_before + 1)
        b.mobile(False)
        # 管理者の画面: 絞り込み・ライン・返信の定型文・メンテナンス
        login_as("admin01")
        b.goto(a.base + "/admin/org.php?tab=users")
        rows_js = "Array.prototype.filter.call(document.querySelectorAll('#user-table tbody tr'), function (r) { return !r.hidden; }).length"
        all_rows = b.eval(rows_js)
        b.fill("input.table-filter", "ippan_a1")
        time.sleep(0.2)
        check("一覧の絞り込みで、合う行だけ出る", 0 < b.eval(rows_js) < all_rows, (b.eval(rows_js), all_rows))
        b.fill("input.table-filter", "")
        time.sleep(0.2)
        check("絞り込みを消すと、全部の行に戻る", b.eval(rows_js) == all_rows)
        # 課長の選ぶ欄（札と候補の一覧。select は隠して値だけ持つ）
        b.goto(a.base + "/admin/org.php?tab=departments&edit=%d#dept-final" % seed_dept)
        ippan_b1_id = str(db.execute("SELECT id FROM users WHERE login_id = ?", ("ippan_b1",)).fetchone()[0])
        selected_before = b.eval("document.getElementById('final_user_id').value")
        chip_js = "(function () { var c = document.querySelector('.tag-picker-chip'); return c ? c.firstChild.textContent : ''; })()"
        option_text_js = "(function (v) { var o = document.querySelector('#final_user_id option[value=\"' + v + '\"]'); return o ? o.textContent : ''; })"
        list_values_js = "Array.prototype.map.call(document.querySelectorAll('.tag-picker-list li[role=option]'), function (li) { return li.getAttribute('data-value'); })"
        check("課長の札に、今の課長が出て、選ぶ欄そのものは隠れている",
              b.eval(chip_js) == b.eval(option_text_js + "(%s)" % json.dumps(selected_before)) and b.eval("document.getElementById('final_user_id').hidden") is True)
        b.fill("#final_user_id-search", "ippan_b1")
        time.sleep(0.2)
        check("課長の候補を探すと、合う人だけが一覧に出る（今の課長は出さない）", b.eval(list_values_js) == [ippan_b1_id], b.eval(list_values_js))
        check("一覧に、まとまりの見出しが出る", "ほかの人" in b.eval("document.querySelector('.tag-picker-list').textContent"))
        b.eval("document.getElementById('final_user_id-search').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))")
        time.sleep(0.2)
        check("Enter で選ぶと、札がその人になり、送る値も変わる（送信はしない）",
              b.eval("document.getElementById('final_user_id').value") == ippan_b1_id and b.eval(chip_js) == b.eval(option_text_js + "(%s)" % json.dumps(ippan_b1_id))
              and b.eval("document.querySelector('.tag-picker-list').hidden") is True)
        b.fill("#final_user_id-search", "だれにも合わない文字")
        time.sleep(0.2)
        check("合う人がいないときは、そう出す", "合う人がいません。" in b.eval("document.querySelector('.tag-picker-list').textContent"))
        b.eval("document.querySelector('.tag-picker-clear').click()")
        time.sleep(0.2)
        check("札の × で、課長なしに戻る", b.eval("document.getElementById('final_user_id').value") == "" and b.eval("document.querySelector('.tag-picker-chip')") is None)
        b.eval("(function () { var s = document.getElementById('final_user_id-search'); s.value = ''; s.dispatchEvent(new Event('input')); })()")
        time.sleep(0.2)
        mouse_target = b.eval(list_values_js)[0]
        b.eval("(function (v) { document.querySelector('.tag-picker-list li[data-value=\"' + v + '\"]').dispatchEvent(new MouseEvent('mousedown', { bubbles: true })); })(%s)" % json.dumps(mouse_target))
        time.sleep(0.2)
        check("候補を押すと、その人を選ぶ", b.eval("document.getElementById('final_user_id').value") == mouse_target)
        writable = sqlite3.connect(a.db, timeout=10, isolation_level=None)
        admin = lib_http.login(a.base, "admin01", users["admin01"])
        _, _, page_html = admin.get("/requests/index.php")
        admin.post("/requests/index.php", {"csrf_token": lib_http.csrf(page_html), "request_key": lib_http.rkey(page_html), "action": "template_add", "template_body": "検証の返信文"})
        kacho = lib_http.login(a.base, "kacho_a", users["kacho_a"])
        _, _, page_html = kacho.get("/requests/index.php")
        post_values = {m: v for m, v in REQUEST_FORM.items()}
        post_values.update({"csrf_token": lib_http.csrf(page_html), "request_key": lib_http.rkey(page_html)})
        kacho.post("/requests/index.php", post_values)
        request_id = writable.execute("SELECT MAX(id) FROM support_requests").fetchone()[0]
        check("要望を出せる", request_id is not None)
        if request_id is not None:
            b.goto(a.base + "/requests/view.php?id=%d" % request_id)
            b.fill("#staff_reply_template", "検証の返信文")
            time.sleep(0.2)
            check("返信の定型文を選ぶと返信の欄に入る", b.eval("document.getElementById('staff_reply_body').value") == "検証の返信文")
        b.goto(a.base + "/admin/index.php")
        b.dialogs.clear()
        b.click('button[value="maintenance_start"]', navigate=True)
        check("確認の表示（data-confirm）が 1 回出る", len(b.dialogs) == 1, b.dialogs)
        login_as("ippan_b1")
        b.goto(edit_url)
        check("メンテナンス中: 保存・提出のボタンが押せない", b.eval("document.querySelector('button[value=draft]').disabled") is True)
        check("メンテナンス中: ログアウトは押せる", b.eval("document.querySelector('form[action$=\"logout.php\"] button').disabled") is False)
        login_as("admin01")
        b.goto(a.base + "/admin/index.php")
        b.click('button[value="maintenance_end"]', navigate=True)
        writable.close()
        # 印刷
        exporter = lib_http.login(a.base, "kacho_a", users["kacho_a"])
        _, _, page_html = exporter.get("/export/index.php")
        status_code, _, print_html = exporter.post("/export/index.php", {
            "csrf_token": lib_http.csrf(page_html), "request_key": lib_http.rkey(page_html), "type": "report", "format": "print",
            "dept": str(seed_dept), "from": period, "to": period, "content": "both"})
        check("印刷用の画面に印刷のボタンがある", status_code == 200 and "print-button" in print_html, status_code)
        b.goto(a.base + "/index.php")
        b.eval("window.__printed = 0; window.print = function () { window.__printed++; }; var x = document.createElement('button'); x.className = 'print-button'; x.type = 'button'; document.body.appendChild(x); x.click(); true")
        check("印刷のボタンで印刷が呼ばれる", b.eval("window.__printed") == 1)
        b.clear_cookies()
        b.goto(a.base + "/login.php")
        b.click(".toggle-password")
        check("ログイン: パスワードの「表示」で文字が見える", b.eval("document.getElementById('password').type") == "text")
        missing = [r for r in (b.eval("window.__requests") or []) if r[1] == 404]
        check("JavaScript からの要求に 404 がない", not missing, missing)
        js_errors = [e for e in b.errors if e.startswith("JS")]
        check("JavaScript のエラーがない", not js_errors, js_errors[:3])
    except Exception as error:  # noqa: BLE001
        check("自動操作が最後まで動く", False, repr(error))
    finally:
        b.quit()
    return summary()


if __name__ == "__main__":
    sys.exit(main())
