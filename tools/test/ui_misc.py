#!/usr/bin/env python3
"""画面の補助の残り（コピー・言葉の照合・項目の編集・1 問ずつ・部品が無いとき。ローカル検証環境専用。dev_seed.php の直後に実行する）。

    python3 tools/test/ui_misc.py --base http://127.0.0.1:8000 --db app/data/verify.sqlite
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

# 画面の JavaScript が動く前に、決めた部品を抜く・足す（__strip に書いた処理を、HTML を読み終えたときに 1 回だけ動かす）
EARLY_JS = "document.addEventListener('readystatechange', function () { if (document.readyState === 'interactive') { (%s)(); } });"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8000")
    ap.add_argument("--db", required=True)
    ap.add_argument("--show", action="store_true")
    a = ap.parse_args()
    here = os.path.dirname(os.path.abspath(__file__))
    seed = json.load(open(os.path.join(here, "output_dev_users.json"), encoding="utf-8"))
    users = seed["users"]
    db = sqlite3.connect(a.db, timeout=10, isolation_level=None)
    field_ids = dict(db.execute("SELECT code, id FROM report_fields"))
    project = db.execute("SELECT id FROM projects WHERE code = 'P-0001'").fetchone()[0]
    period = datetime.date.today().strftime("%Y-%m")
    edit_url = a.base + "/reports/edit.php?period=" + period
    duties = "#b0_%d" % field_ids["duties"]
    b = Browser(headless=not a.show)

    def login_as(login_id):
        b.clear_cookies()
        for cookie in lib_http.login(a.base, login_id, users[login_id]).jar:
            b.call("Network.setCookie", {"name": cookie.name, "value": cookie.value, "url": a.base + "/", "path": "/", "httpOnly": True})

    def no_js_errors(title):
        errors = [e for e in b.errors if e.startswith("JS")]
        check(title + ": JavaScript のエラーがない", not errors, errors[:2])
        b.errors.clear()

    def without(title, script, url):
        """部品が無いときも、エラーにならず、画面が開く"""
        script_id = b.call("Page.addScriptToEvaluateOnNewDocument", {"source": EARLY_JS % script})["identifier"]
        b.errors.clear()
        b.goto(url)
        return script_id

    try:
        b.goto(a.base + "/login.php")
        b.eval("localStorage.setItem('reportEditMode', 'list')")

        section("G-16", "コピー・表示の切り替え（部品が無い・空のとき）")
        login_as("ippan_a1")
        b.goto(a.base + "/account.php?tab=password")
        result = "document.querySelector('.copy-text[data-copy-from=\"new_password\"]').parentNode.querySelector('.copy-result').textContent"
        b.click('.copy-text[data-copy-from="new_password"]')
        check("空の欄のコピーは、文字がないと知らせる", b.wait_until(result + ".indexOf('コピーする文字がありません') >= 0", 4))
        b.fill("#new_password", "Abcdefgh12345")
        b.eval("window.__written = ''; Object.defineProperty(navigator, 'clipboard', {value: {writeText: function (t) { window.__written = t; return Promise.resolve(); }}, configurable: true}); true")
        b.click('.copy-text[data-copy-from="new_password"]')
        check("入力欄の値をコピーする", b.wait_until("window.__written === 'Abcdefgh12345'", 4))
        b.eval("(function () { var s = document.createElement('span'); s.id = 'plain-copy'; s.textContent = ' そのままの文 '; document.body.appendChild(s); var x = document.createElement('button'); x.type = 'button'; x.className = 'copy-text'; x.setAttribute('data-copy-from', 'plain-copy'); document.body.appendChild(x); x.click(); return true; })()")
        check("文のコピー（前後の空白を除く）", b.wait_until("window.__written === 'そのままの文'", 4))
        b.eval("Object.defineProperty(navigator, 'clipboard', {value: undefined, configurable: true}); document.execCommand = function () { throw new Error('x'); }; true")
        b.click('.copy-text[data-copy-from="new_password"]')
        check("コピーの命令も使えないときは、できなかったと知らせる", b.wait_until(result + ".indexOf('コピーできませんでした') >= 0", 4))
        b.eval("(function () { ['copy-text', 'toggle-password', 'use-backup-code', 'generate-password'].forEach(function (name) { var x = document.createElement('button'); x.type = 'button'; x.className = name; x.setAttribute('data-copy-from', 'none'); x.setAttribute('data-target', 'none'); document.body.appendChild(x); if (name !== 'generate-password') { x.click(); } }); return true; })()")
        no_js_errors("コピー先・欄が無いボタン")

        section("G-16", "自分の色（残り）")
        b.goto(a.base + "/account.php?tab=theme")
        b.eval("document.querySelector('input[name=\"ui_theme\"][value=\"light\"]').checked = true; true")
        b.click("#custom_accent")
        check("自分の色を触ると、テーマの「自分の色」を選んだことになる", b.eval("document.querySelector('input[name=\"ui_theme\"][value=\"custom\"]').checked") is True)
        b.click('input[name="custom_base"][value="light"]')
        b.fill("#custom_background", "#222222")
        time.sleep(0.3)
        check("背景の色と文字の色が近いときの知らせ", "背景の色と文字の色が近く" in b.eval("document.querySelector('.custom-color-check').textContent"))

        section("G-04", "客先名らしい言葉の照合・必須の数・1 問ずつの守り")
        b.goto(edit_url)
        b.fill("#b0_project", str(project))
        word = db.execute("SELECT word FROM warning_words ORDER BY id LIMIT 1").fetchone()[0]
        b.fill(duties, word)
        time.sleep(0.2)
        check("注意の言葉で注意が出る", b.visible('.field[data-code="duties"] .field-warning'), word)
        wide = "".join(chr(ord(c) + 0xFEE0) if "!" <= c <= "~" else c for c in "株式会社ABC")
        b.fill(duties, wide)
        time.sleep(0.2)
        check("全角の英字が混ざっていても注意が出る", b.visible('.field[data-code="duties"] .field-warning'))
        b.eval("(function () { var t = document.createElement('textarea'); t.setAttribute('data-free-text', '1'); document.body.appendChild(t); t.value = 'x'; t.dispatchEvent(new Event('input', {bubbles: true})); var d = document.createElement('div'); d.className = 'field'; d.appendChild(t); document.body.appendChild(d); t.dispatchEvent(new Event('input', {bubbles: true})); return true; })()")
        no_js_errors("注意の枠が無い欄")
        db.execute("INSERT INTO report_fields (code, label, field_type, department_id, field_scope, confidentiality, sort_order, is_active, is_required, settings_json, created_at, updated_at) VALUES ('misc_req', '必須の文', 'single_text', NULL, 'project', 'normal', 5, 1, 1, '{}', 'x', 'x')")
        required_id = db.execute("SELECT id FROM report_fields WHERE code = 'misc_req'").fetchone()[0]
        b.goto(edit_url)
        b.fill("#b0_project", str(project))
        time.sleep(0.2)
        count_text = b.eval("document.querySelector('.required-remaining').textContent")
        b.fill("#b0_%d" % required_id, "入れた")
        time.sleep(0.2)
        check("文の必須を入れると、未入力の数が 1 減る", int("".join(c for c in count_text if c.isdigit()) or 0) - int("".join(c for c in b.eval("document.querySelector('.required-remaining').textContent") if c.isdigit()) or 0) == 1, count_text)
        b.mobile(True)
        b.eval("localStorage.setItem('reportEditMode', 'step')")
        b.goto(edit_url)
        b.fill("#b0_project", str(project))
        for _ in range(20):
            if b.eval("!!document.querySelector('.wizard-current.is-required')"):
                break
            b.click(".wizard-next")
        progress = b.eval("document.querySelector('.wizard-progress').textContent")
        b.click(".wizard-next")
        check("1 問ずつ: 必須が空なら進まず、知らせる", b.eval("document.querySelector('.wizard-progress').textContent") == progress and b.visible(".wizard-message"))
        b.click(".wizard-prev")
        check("1 問ずつ: 途中で戻れる", b.eval("document.querySelector('.wizard-progress').textContent") != progress)
        b.eval("(function () { var f = HTMLElement.prototype.focus; HTMLElement.prototype.focus = function (o) { if (o) { throw new Error('no options'); } return f.call(this); }; return true; })()")
        b.click(".wizard-next")
        no_js_errors("焦点の選び方（preventScroll）が使えない")
        check("それでも進む", b.eval("document.querySelector('.wizard-progress').textContent") == progress)
        b.eval("localStorage.setItem('reportEditMode', 'list')")
        b.mobile(False)
        db.execute("DELETE FROM report_field_options WHERE field_id = ?", (required_id,))
        db.execute("UPDATE report_fields SET is_active = 0, is_required = 0 WHERE id = ?", (required_id,))

        section("共通", "送信の残り（Enter での送信・30 秒後・別タブ・GET）")
        b.call("Page.addScriptToEvaluateOnNewDocument", {"source": "(function () { var t = window.setTimeout; window.setTimeout = function (c, d) { return t(c, d === 30000 ? 300 : d); }; })();"})
        b.goto(edit_url)
        b.fill("#b0_project", str(project))
        b.fill(duties, "Enter での送信")
        b.mark()
        b.eval("(function () { var f = document.getElementById('report-form'); var h = document.createElement('input'); h.type = 'hidden'; h.name = 'action'; h.value = 'draft'; f.appendChild(h); f.requestSubmit(); return true; })()")
        time.sleep(0.4)
        b.wait_load()
        check("ボタンを使わない送信でも、確かめてから保存する", "下書きを保存しました" in b.text(), b.text()[:100])
        b.goto(edit_url)
        b.eval("(function () { var f = document.getElementById('report-form'); f.dataset.sessionChecked = ''; window.__stopped = 0; f.addEventListener('submit', function (e) { if (f.dataset.sessionChecked === '1') { window.__stopped++; e.preventDefault(); } }); return true; })()")
        b.fill(duties, "止める")
        b.click('button[value="draft"]')
        check("確かめたあとに画面側で止めた送信は、送らない", b.wait_until("window.__stopped === 1", 5) and "/reports/edit.php" in b.eval("location.pathname"))
        b.eval("(function () { var f = document.createElement('form'); f.method = 'post'; f.action = '/health.php'; f.target = 'mr_frame'; var i = document.createElement('iframe'); i.name = 'mr_frame'; document.body.appendChild(i); document.body.appendChild(f); f.requestSubmit(); window.__plain = f; return true; })()")
        time.sleep(1)
        check("移らない送信のあと、30 秒たつと、もう一度押せる", b.wait_until("window.__plain.dataset.submitting === '0'", 5))
        b.eval("(function () { var f = document.createElement('form'); f.method = 'get'; f.action = '/health.php'; f.target = 'mr_frame'; document.body.appendChild(f); f.requestSubmit(); window.__get = f; return true; })()")
        check("GET のフォームは、確かめずに送る", b.wait_until("window.__get.dataset.submitting === '1' || window.__get.dataset.submitting === '0'", 4))

        section("G-09", "横並びのセルの通信エラー")
        kacho = lib_http.login(a.base, "kacho_a", users["kacho_a"])
        member = lib_http.login(a.base, "ippan_a1", users["ippan_a1"])
        _, _, body = member.get("/reports/edit.php?period=" + period)
        member.post("/reports/save.php", {"csrf_token": lib_http.csrf(body), "request_key": lib_http.rkey(body), "action": "submit", "period": period, "b[0][project]": project,
                                           "b[0][f][%d]" % field_ids["duties"]: "セルの中身", "b[0][f][%d]" % field_ids["progress"]: "on_track", "b[0][f][%d]" % field_ids["continuation"]: "continuing"})
        check("準備: 提出", db.execute("SELECT status FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NULL", (users["ippan_a1"]["id"], period)).fetchone()[0] == "submitted")
        login_as("kacho_a")
        b.goto(a.base + "/status/compare.php?period=" + period)
        b.eval("window.fetch = function () { return Promise.reject(new Error('x')); }; true")
        b.click("button.open-cell")
        check("セルを開けないとき、通信エラーと出る", b.wait_until("document.querySelector('button.open-cell').textContent.indexOf('通信エラー') >= 0", 4))

        section("G-12", "項目の編集（残り）")
        b.goto(a.base + "/admin/field_edit.php?dept=%d" % seed["dept_a"])
        b.fill("#field_type", "radio")
        b.eval("(function () { var i = document.createElement('input'); i.name = 'opt_label[' + document.querySelectorAll('fieldset[data-field-types~=\"radio\"] tbody tr').length + ']'; i.type = 'hidden'; document.getElementById('field_type').closest('form').appendChild(i); return true; })()")
        b.click("button.add-option-row")
        names = b.eval("Array.prototype.map.call(document.querySelectorAll('input[name^=\"opt_label[\"]'), function (x) { return x.name; })")
        check("選択肢の行の番号が重ならない", len(names) == len(set(names)), names)
        b.click('input[name="field_scope"][value="project"]')
        b.fill("#show_when_field", "progress")
        b.click('input[name="field_scope"][value="report"]')
        time.sleep(0.2)
        check("区分を変えると、合わない表示の条件は外れる", b.eval("document.getElementById('show_when_field').value") == "")

        section("G-14", "役を付けるときの確かめ（data-confirm-on）")
        login_as("admin01")
        b.goto(a.base + "/admin/user_edit.php?id=%d" % users["ippan_a2"]["id"])
        b.eval("window.confirm = function () { window.__asked = (window.__asked || 0) + 1; return false; }; true")
        b.click('input[name="is_admin"]')
        b.eval("document.querySelector('input[name=\"is_admin\"]').closest('form').querySelector('button[type=submit], button:not([type])').click(); true")
        time.sleep(0.4)
        check("管理者にする確かめで「いいえ」なら、保存しない", b.eval("window.__asked") >= 1 and db.execute("SELECT is_admin FROM users WHERE id = ?", (users["ippan_a2"]["id"],)).fetchone()[0] == 0)

        section("共通", "部品が無いとき（画面の作りが変わっても、ほかの補助を止めない）")
        pages = [
            ("写す先の欄が無い", "function () { document.querySelectorAll('.block-new').forEach(function (x) { x.classList.remove('block-new'); }); }", edit_url),
            ("タブが 1 つ", "function () { var x = document.querySelectorAll('section[data-tab-title]'); for (var i = 1; i < x.length; i++) { x[i].remove(); } }", a.base + "/admin/org.php?tab=departments&edit=%d" % seed["dept_a"]),
            ("絞り込む表が無い", "function () { var x = document.querySelector('#user-table'); if (x) { x.remove(); } }", a.base + "/admin/org.php?tab=users"),
            ("分野の並びが無い", "function () { document.querySelectorAll('#faq-chat .chat-initial.chat-choices').forEach(function (x) { x.remove(); }); }", a.base + "/faq.php"),
            ("トークンの欄が無い", "function () { document.querySelectorAll('.faq-chat-csrf').forEach(function (x) { x.remove(); }); }", a.base + "/faq.php"),
        ]
        if os.path.exists(os.path.join(here, "..", "..", "public", "goals", "criteria.php")):
            pages.append(("横スライドの枠が無い", "function () { document.querySelectorAll('.goal-criteria-slider').forEach(function (x) { x.remove(); }); }", a.base + "/goals/criteria.php"))
        for title, script, url in pages:
            login_as("ippan_a2" if url == edit_url else "admin01")
            script_id = without(title, script, url)
            check(title + ": 画面が開く", b.eval("!!document.querySelector('main')") is True)
            if "faq.php" in url:
                b.fill("#faq_search_query", "使い方がわからない")
                b.click("#faq-chat form.faq-chat-input button[type=submit]")
                check(title + ": 返事は出る", b.wait_until("!document.querySelector('#faq-chat .chat-waiting') && document.querySelectorAll('#faq-chat .chat-bot').length >= 2", 8))
            no_js_errors(title)
            b.call("Page.removeScriptToEvaluateOnNewDocument", {"identifier": script_id})
        if os.path.exists(os.path.join(here, "..", "..", "public", "goals", "criteria.php")):
            b.goto(a.base + "/goals/criteria.php")
            b.eval("document.querySelector('.goal-criteria-slider').click(); true")
            no_js_errors("横スライドの、ボタンでない所を押す")
            login_as("ippan_a1")
            b.goto(a.base + "/expenses/edit.php")
            b.fill('input[name="rows[0][amount]"]', "１，２００円")
            check("精算: 全角の金額も合計に入る", b.eval("document.querySelector('.expense-subtotal').textContent").replace(",", "") == "1200")
        login_as("admin01")
        b.goto(a.base + "/admin/index.php")
        b.eval("(function () { var s = document.createElement('select'); s.className = 'support-reply-template-select'; document.body.appendChild(s); return true; })()")
        no_js_errors("最後まで")
    except Exception as error:  # noqa: BLE001
        check("自動操作が最後まで動く", False, repr(error))
    finally:
        b.quit()
    return summary()


if __name__ == "__main__":
    sys.exit(main())
