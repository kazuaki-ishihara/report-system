#!/usr/bin/env python3
"""チャットの返し分け・通信の失敗・古いブラウザー向けの代わりの処理（画面。ローカル検証環境専用。dev_seed.php の直後に実行する）。

サーバーの応答を 1 つずつ差し替えて、画面がその応答どおりに返すかを見る（サーバーの判定は faq_*_check.php・smoke_*.py が見る）。

    python3 tools/test/ui_chat.py --base http://127.0.0.1:8000 --db app/data/verify.sqlite
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

MOCK_JS = """
(function () {
  var realFetch = window.fetch, realTimeout = window.setTimeout, realInterval = window.setInterval;
  window.__sent = [];
  window.setTimeout = function (callback, delay) { return realTimeout(callback, (delay === 25000 || delay === 25010 || delay === 10000) ? 400 : (delay === 30000 ? 1500 : delay)); };
  window.setInterval = function (callback, delay) { return realInterval(callback, delay === 60000 ? 700 : delay); };
  window.fetch = function (url, options) {
    var body = options && options.body && options.body.get ? options.body.get('action') : '';
    window.__sent.push([String(url), body]);
    var mock = window.__mock;
    if (!mock || (mock.match && String(url).indexOf(mock.match) < 0) || (mock.action && mock.action !== body)) { return realFetch.apply(window, arguments); }
    if (mock.kind === 'network') { return Promise.reject(new TypeError('Failed to fetch')); }
    if (mock.kind === 'hang') { return new Promise(function (resolve, reject) { if (options && options.signal) { options.signal.addEventListener('abort', function () { reject(new DOMException('aborted', 'AbortError')); }); } else if (mock.late) { realTimeout(function () { resolve(new Response('{"ok":true,"matches":[]}')); }, 900); } }); }
    if (mock.kind === 'slowbody') { return Promise.resolve({ status: 200, ok: true, json: function () { return new Promise(function (resolve) { realTimeout(function () { resolve({ ok: true, matches: [] }); }, 800); }); } }); }
    if (mock.kind === 'redirect') { return Promise.resolve({ redirected: true, url: location.href + '#redirected', status: 200, ok: true, json: function () { return Promise.resolve({}); } }); }
    return Promise.resolve(new Response(mock.text !== undefined ? mock.text : JSON.stringify(mock.json), { status: mock.status || 200, headers: { 'Content-Type': 'application/json' } }));
  };
})();
"""


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
    b = Browser(headless=not a.show)

    def login_as(login_id):
        b.clear_cookies()
        for cookie in lib_http.login(a.base, login_id, users[login_id]).jar:
            b.call("Network.setCookie", {"name": cookie.name, "value": cookie.value, "url": a.base + "/", "path": "/", "httpOnly": True})

    def mock(**values):
        b.eval("window.__mock = %s; true" % json.dumps(values if values else None, ensure_ascii=False))

    def ask(text="質問"):
        b.fill("#faq_search_query", text)
        b.click("#faq-chat form.faq-chat-input button[type=submit]")

    def said(part, timeout=8):
        return b.wait_until("Array.prototype.some.call(document.querySelectorAll('#faq-chat .chat-bot'), function (x) { return !x.closest('[hidden]') && x.textContent.indexOf(%s) >= 0; })" % json.dumps(part), timeout)

    def choose(label):
        js = "(function () { var list = Array.prototype.filter.call(document.querySelectorAll('#faq-chat .chat-choices button'), function (x) { return x.textContent.indexOf(%s) === 0 && !x.disabled; }); if (!list.length) { return false; } list[list.length - 1].click(); return true; })()" % json.dumps(label)
        return b.wait_until(js, 6)

    def open_faq():
        b.goto(a.base + "/faq.php")

    faq = {"id": 1, "question": "載っている質問", "answer": "載っている答え\n2 行目", "category": "login", "answer_html": ""}
    other = dict(faq, id=2, question="別の質問", answer="別の答え")

    def answer(**values):
        base = {"ok": True, "log_id": 1, "matches": [], "ignored": False, "vague": False}
        base.update(values)
        return base

    try:
        b.call("Page.addScriptToEvaluateOnNewDocument", {"source": MOCK_JS})
        admin = lib_http.login(a.base, "admin01", users["admin01"])
        _, _, body = admin.get("/faq.php")
        admin.post("/faq.php", {"csrf_token": lib_http.csrf(body), "request_key": lib_http.rkey(body), "action": "import_standard"})

        section("G-25", "チャットの返し分け（応答ごと）")
        login_as("ippan_a1")
        for title, values, part in (
                ("緊急", {"emergency": True}, "119 番"), ("退職", {"retirement": True}, "上司（課長）に相談"), ("心の不調", {"distress": "concern", "matches": [faq]}, "おつかれさまです"),
                ("心の不調（命）", {"distress": "urgent"}, "つらい気持ちを書いてくれて"), ("ハラスメント", {"distress": "urgent", "harassment": True}, "上司からのハラスメント"),
                ("要配慮", {"sensitive": True, "matches": [faq]}, "個人的な事情"), ("破壊", {"sabotage": True}, "壊す・消す・持ち出す"), ("暴力", {"violence": True}, "カウンセリング"),
                ("不適切", {"abusive": True}, "不適切な表現"), ("関係ない", {"unrelated": True}, "その質問には返答できません"), ("用件なし", {"closing": True}, "わかりました"),
                ("呼びかけ", {"opener": True}, "どういったご用件"), ("あいまい", {"vague": True}, "どのことについて"), ("話題を補う", {"context": "提出", "matches": [faq]}, "「提出」についての答え"),
                ("見つからない", {}, "近い質問は見つかりませんでした")):
            open_faq()
            mock(match="format=json", json=answer(**values))
            ask()
            check("返事: " + title, said(part))
            if values.get("matches"):
                check("答えも出す: " + title, said("載っている答え"))
        open_faq()
        mock(match="format=json", json=answer(ignored=True))
        count = b.eval("document.querySelectorAll('#faq-chat .chat-bot').length")
        ask()
        time.sleep(0.6)
        check("続けて関係のない質問には返事をしない", b.eval("document.querySelectorAll('#faq-chat .chat-bot').length") == count)
        b.click("#faq-chat form.faq-chat-input button[type=submit]")
        check("空の質問は送らない", b.eval("document.activeElement.id") == "faq_search_query")

        section("G-25", "選ぶ・解決しないとき")
        open_faq()
        mock(match="format=json", json=answer(matches=[faq, other]))
        ask()
        check("答えを出す", said("載っている答え"))
        mock(match="related=", json={"ok": True, "matches": [other]})
        check("「解決しない」を押せる", choose("解決しない"))
        check("関係がありそうな質問を出す", said("関係がありそうな質問"))
        check("選ぶと答えが出る", choose("別の質問") and said("別の答え"))
        mock(match="related=", json={"ok": True, "matches": [other]})
        choose("解決しない")
        said("関係がありそうな質問")
        check("「この中にない」で詳しく聞き直す", choose("この中にない") and said("もう少し詳しく"))
        mock(match="related=", json={"ok": True, "matches": []})
        check("「だれに聞けばよいか」の案内", choose("だれに聞けばよいか") and said("課長・確認者（課長代理・主任）に聞いて"))
        for kind in ("empty", "network"):
            open_faq()
            mock(match="format=json", json=answer(matches=[faq]))
            ask()
            said("載っている答え")
            mock(**({"match": "related=", "json": {"ok": True, "matches": []}} if kind == "empty" else {"match": "related=", "kind": "network"}))
            choose("解決しない")
            check("関係がありそうな質問がない・取れないとき（%s）は、聞き直す" % kind, said("もう少し詳しく"))
        choose("詳しく入れ直す")
        check("前の質問が欄に入る", b.eval("document.getElementById('faq_search_query').value") == "質問")
        open_faq()
        mock(match="format=json", json=answer())
        ask()
        said("近い質問は見つかりませんでした")
        check("見つからないとき「言葉を変えて入れる」", choose("言葉を変えて入れる") and b.eval("document.getElementById('faq_search_query').value") == "質問")
        open_faq()
        mock(match="format=json", json=answer())
        ask()
        said("近い質問は見つかりませんでした")
        check("見つからないとき「分野から選ぶ」", choose("分野から選ぶ") and b.eval("document.querySelectorAll('#faq-chat a[data-category]').length") > 1)
        mock(match="category=", json=answer(matches=[]))
        b.eval("(function () { var list = document.querySelectorAll('#faq-chat a[data-category]'); list[list.length - 1].click(); list[list.length - 1].click(); return true; })()")
        check("質問のない分野", said("この分野の質問は、まだ載っていません"))
        open_faq()
        mock(match="category=", json=answer(matches=[faq, other]))
        b.eval("document.querySelector('#faq-chat a[data-category]').click(); true")
        check("分野の質問を出す", said("近いものを選んでください"))
        check("分野の質問「この中にない」", choose("この中にない") and said("もう少し詳しく"))

        section("G-25", "聞く人の立場ごと（課長・管理者）")
        login_as("kacho_a")
        open_faq()
        mock(match="format=json", json=answer())
        ask("課長の質問")
        check("課長には「質問として送る」を出す", said("質問として送ってください") and choose("質問として送る"))
        check("送る欄に質問が入る", b.wait_until("!document.getElementById('faq-ask').hidden && document.getElementById('faq_ask_question').value === '課長の質問'", 5))
        for title, values, part in (("ログイン切れ", {"json": {"ok": False, "reason": "login"}, "status": 401}, "ログイン"), ("入力の誤り", {"json": {"ok": False, "reason": "invalid", "errors": ["送れない理由"]}}, "送れない理由"),
                                    ("通信の失敗", {"kind": "network"}, ""), ("500", {"text": "<html>", "status": 500}, "")):
            mock(action=b.eval("document.querySelector('#faq-ask form input[name=action]').value"), **values)
            b.click("#faq-ask form button[type=submit]")
            check("質問として送る: %s は、理由を出して欄を残す" % title, b.wait_until("!!document.querySelector('#faq-ask .flash-error') && !document.querySelector('#faq-ask form button[type=submit]').disabled && document.querySelector('#faq-ask .flash-error').textContent.indexOf(%s) >= 0" % json.dumps(part), 6))
        mock(action=b.eval("document.querySelector('#faq-ask form input[name=action]').value"), kind="redirect")
        b.click("#faq-ask form button[type=submit]")
        check("送り直しで処理済みなら、行き先へ移る", b.wait_until("location.hash === '#redirected'", 6))
        open_faq()
        mock(match="format=json", json=answer(matches=[faq]))
        ask()
        said("載っている答え")
        mock(match="related=", json={"ok": True, "matches": []})
        choose("解決しない")
        check("課長の聞き直しに「質問として送る」", said("もう少し詳しく") and choose("質問として送る"))
        login_as("admin01")
        open_faq()
        mock(match="format=json", json=answer())
        ask()
        check("管理者には、載せるよう案内する", said("質問を受ける側のため"))

        section("G-25", "通信の失敗・時間切れ")
        login_as("ippan_a1")
        for title, values, part in (("ログイン切れ", {"json": {"ok": False, "reason": "login"}, "status": 401}, "ログインの有効期限"), ("JSON でない", {"text": "<html>"}, "")):
            open_faq()
            mock(match="format=json", **values)
            ask()
            check("通信の失敗: " + title, said(part) if part else choose("もう一度送る"))
            mock()
        for title, values, setup in (("応答がない", {"kind": "hang"}, ""), ("本文の途中", {"kind": "slowbody"}, ""), ("AbortController がない", {"kind": "hang", "late": True}, "window.AbortController = undefined;")):
            open_faq()
            b.eval(setup + "true")
            mock(match="format=json", **values)
            ask()
            ok = b.wait_until("Array.prototype.some.call(document.querySelectorAll('#faq-chat .chat-choices button'), function (x) { return x.textContent.indexOf('もう一度送る') === 0; })", 5)
            check("時間切れ: " + title + "（もう一度送れる）", ok)
            check("時間切れ: " + title + "（サーバーに記録を送る）", b.eval("window.__sent.some(function (x) { return x[1] === 'chat_client_error'; })"))

        section("G-04", "自動保存の応答ごとの表示")
        period = datetime.date.today().strftime("%Y-%m")
        duties = "#b0_%d" % field_ids["duties"]

        def status_shows(part):
            return b.wait_until("(document.querySelector('.autosave-status') || {textContent: ''}).textContent.indexOf(%s) >= 0" % json.dumps(part), 6)

        for title, values, part in (
                ("入力の誤り", {"json": {"ok": False, "reason": "invalid"}}, "入力を確かめてください"), ("ほかの画面で作られた", {"json": {"ok": False, "reason": "conflict"}}, "自動保存を止めました"),
                ("提出済み", {"json": {"ok": False, "reason": "not_draft"}}, "提出済みのため"), ("404 の画面", {"text": "<html>", "status": 404}, "エラーのため"), ("500 の JSON", {"json": {"ok": False, "reason": "error", "status": 500}, "status": 500}, "エラーのため"),
                ("応答がない", {"kind": "hang"}, "通信が切れたため")):
            b.eval("localStorage.setItem('reportEditMode', 'list')")
            b.goto(a.base + "/reports/edit.php?period=" + period)
            mock(action="autosave", **values)
            b.fill("#b0_project", str(project))
            b.fill(duties, "応答の確かめ")
            check("自動保存: " + title, status_shows(part), b.eval("document.querySelector('.autosave-status').textContent"))
        mock()

        section("共通", "送信の前の確認・確かめられないとき")
        for title, values in (("503", {"text": "x", "status": 503}), ("JSON でない", {"text": "x"})):
            b.goto(a.base + "/reports/edit.php?period=" + period)
            mock(match="session_check.php", **values)
            b.fill("#b0_project", str(project))
            b.fill(duties, "確かめられない " + title)
            b.click('button[value="draft"]', navigate=True)
            check("確かめられないとき（%s）は、そのまま送る" % title, "下書きを保存しました" in b.text())
        b.goto(a.base + "/reports/edit.php?period=" + period)
        mock(match="session_check.php", kind="hang")
        b.fill(duties, "応答がない")
        b.click('button[value="draft"]')
        check("確かめの応答がないときは送らずに知らせる", b.wait_until("(document.querySelector('.session-notice') || {textContent: ''}).textContent.indexOf('通信できないため') >= 0", 6))
        check("知らせたあとは、もう一度押せる", b.eval("document.getElementById('report-form').dataset.submitting") == "0")

        section("共通", "代わりの処理（古いブラウザー・壊れた設定）")
        b.goto(a.base + "/reports/edit.php?period=" + period)
        for title, script in (("文言の設定が壊れている", "document.addEventListener('readystatechange', function () { if (document.body) { document.body.setAttribute('data-msgs', '{'); } });"),
                              ("言葉の設定が壊れている", "document.addEventListener('readystatechange', function () { var f = document.querySelector('#report-form'); if (f) { f.setAttribute('data-words', '{'); } });"),
                              ("保存場所が使えない", "Object.defineProperty(window, 'localStorage', {get: function () { throw new Error('denied'); }});"),
                              ("requestSubmit がない", "HTMLFormElement.prototype.requestSubmit = undefined;"),
                              ("AbortController がない", "window.AbortController = undefined;")):
            script_id = b.call("Page.addScriptToEvaluateOnNewDocument", {"source": script})["identifier"]
            b.errors.clear()
            b.mobile(True)
            b.goto(a.base + "/reports/edit.php?period=" + period)
            b.eval("(function () { var x = document.querySelector('.wizard-toggle'); if (x && x.offsetParent) { x.click(); } return true; })()")
            b.fill("#b0_project", str(project))
            b.fill(duties, title)
            b.click('button[value="draft"]', navigate=True)
            check(title + "ても、保存できる", "下書きを保存しました" in b.text(), b.text()[:120])
            check(title + "ても、JavaScript のエラーがない", not [e for e in b.errors if e.startswith("JS")], [e for e in b.errors if e.startswith("JS")][:2])
            b.call("Page.removeScriptToEvaluateOnNewDocument", {"identifier": script_id})
            b.mobile(False)

        section("G-05", "確認の表示で「いいえ」を押す")
        b.eval("localStorage.setItem('reportEditMode', 'list')")
        b.goto(a.base + "/reports/edit.php?period=" + period)
        b.eval("window.confirm = function () { return false; }; true")
        sent = b.eval("window.__sent.length")
        b.click('button.danger[value="delete"]')
        time.sleep(0.3)
        check("「いいえ」なら削除しない", b.eval("window.__sent.length") == sent and "/reports/edit.php" in b.eval("location.pathname"))
        b.fill(duties, "株式会社サンプル")
        time.sleep(0.2)
        b.click('button[value="submit"]')
        time.sleep(0.3)
        check("客先名の注意で「いいえ」なら提出しない", db.execute("SELECT status FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NULL", (users["ippan_a1"]["id"], period)).fetchone()[0] == "draft")
        js_errors = [e for e in b.errors if e.startswith("JS")]
        check("JavaScript のエラーがない", not js_errors, js_errors[:3])
    except Exception as error:  # noqa: BLE001
        check("自動操作が最後まで動く", False, repr(error))
    finally:
        b.quit()
    return summary()


if __name__ == "__main__":
    sys.exit(main())
