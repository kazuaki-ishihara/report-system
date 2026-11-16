#!/usr/bin/env python3
"""便利機能 A〜J の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

A 更新のお知らせ / B 返信の定型文 / C 重複をまとめる / D 対応の予定 / E 要望の CSV /
F メンテナンス中 / G 組織の改訂の予約 / H バックアップの状態 / I 画面ごとの使い方 / J よくある質問

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_conveniences.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import datetime
import json
import os
import re
import sqlite3
import sys
import urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import Client, check, csrf, login, summary  # noqa: E402


def main():
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--base", default="http://127.0.0.1:8000")
    argument_parser.add_argument("--db", required=True)
    arguments = argument_parser.parse_args()
    test_dir = os.path.dirname(os.path.abspath(__file__))
    seed_info = json.load(open(os.path.join(test_dir, "output_dev_users.json"), encoding="utf-8"))
    user_info_by_login_id = seed_info["users"]
    database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)
    today = datetime.date.today()

    def post_form(client, path, form_values):
        _status_code, _location, page_html = client.get(path)
        return client.post(path, dict(form_values, csrf_token=csrf(page_html), request_key=os.urandom(16).hex()))

    def create_request(client, title_text, kind="improvement"):
        _status_code, location, _page_html = post_form(client, "/requests/index.php", {
            "action": "create", "kind": kind, "title": title_text, "body": title_text + " の内容", "screen_code": "system", "urgency": "normal"})
        found = re.search(r"id=(\d+)", location or "")
        return int(found.group(1)) if found else 0

    admin_client = login(arguments.base, "admin01", user_info_by_login_id["admin01"])
    member_client = login(arguments.base, "ippan_a1", user_info_by_login_id["ippan_a1"])
    kacho_client = login(arguments.base, "kacho_a", user_info_by_login_id["kacho_a"])
    leader_client = login(arguments.base, "leader_a", user_info_by_login_id["leader_a"])

    # ---------------- A 更新のお知らせ ----------------
    check("A: 部下は更新のお知らせの管理を開けない", member_client.get("/admin/notices.php")[0] == 403)
    status_code, _location, _page_html = post_form(admin_client, "/admin/notices.php", {
        "action": "save", "id": "", "source_request_id": "", "title": "提出状況の札を色分けしました", "body": "提出済みは緑、未作成は灰色です。",
        "show_from": today.isoformat(), "show_until": (today + datetime.timedelta(days=14)).isoformat()})
    check("A: 管理者が更新のお知らせを書ける", status_code in (302, 303), status_code)
    post_form(admin_client, "/admin/notices.php", {
        "action": "save", "id": "", "source_request_id": "", "title": "終わったお知らせ", "body": "",
        "show_from": (today - datetime.timedelta(days=30)).isoformat(), "show_until": (today - datetime.timedelta(days=1)).isoformat()})
    _status_code, _location, member_home_html = member_client.get("/index.php")
    check("A: 全員のホームに、出す期間のお知らせが出る", "提出状況の札を色分けしました" in member_home_html)
    check("A: 出す期間を過ぎたお知らせは出ない", "終わったお知らせ" not in member_home_html)
    status_code, _location, error_html = post_form(admin_client, "/admin/notices.php", {
        "action": "save", "id": "", "source_request_id": "", "title": "逆の期間", "body": "",
        "show_from": today.isoformat(), "show_until": (today - datetime.timedelta(days=1)).isoformat()})
    check("A: 出す最後の日が出す日より前なら保存しない", status_code == 200 and "出す最後の日は" in error_html, status_code)

    # ---------------- I 画面ごとの使い方 ----------------
    check("I: ホームに「この画面の使い方」", "この画面の使い方" in member_home_html)
    _status_code, _location, edit_html = member_client.get("/reports/edit.php?period=" + today.strftime("%Y-%m"))
    check("I: 報告の作成にも使い方（下書き保存・必須の説明）", "この画面の使い方" in edit_html and "下書き保存" in edit_html)

    # ---------------- J よくある質問 ----------------
    status_code, _location, _page_html = post_form(admin_client, "/faq.php", {
        "action": "save", "id": "", "source_request_id": "", "question": "締め日を過ぎても直せますか", "answer": "当月と過去 3 か月の報告は直せます。"})
    check("J: 管理者がよくある質問を載せられる", status_code in (302, 303), status_code)
    status_code, _location, faq_html = member_client.get("/faq.php")
    check("J: 部下もよくある質問を見られる", status_code == 200 and "締め日を過ぎても直せますか" in faq_html and "当月と過去 3 か月" in faq_html, status_code)
    check("J: 部下には直す・消すを出さない", 'value="delete"' not in faq_html)
    status_code, _location, _page_html = member_client.post("/faq.php", {"csrf_token": csrf(faq_html), "request_key": os.urandom(16).hex(), "action": "save", "question": "x", "answer": "y"})
    check("J: 部下は載せられない", status_code == 403, status_code)
    check("J: メニューに「よくある質問」", "/faq.php" in member_home_html)

    # J よくある質問を探す・見つからない質問を送る（AI は使わない。文字の並びの一致と言い換え）
    post_form(admin_client, "/faq.php", {
        "action": "save", "id": "", "source_request_id": "", "question": "2段階認証のスマートフォンを買い替えたら",
        "answer": "システム管理者に 2 段階認証の解除を頼み、新しいスマートフォンで設定し直してください。"})
    status_code, _location, search_html = member_client.get("/faq.php?q=" + urllib.parse.quote("締切後に修正したい"))
    check("J: 言い換え（締切・修正）でも近い質問が出る（チャットの答えの吹き出し）", status_code == 200 and 'class="chat-question">Q. 締め日を過ぎても直せますか' in search_html, status_code)
    status_code, _location, json_text = member_client.get("/faq.php?format=json&q=" + urllib.parse.quote("スマホを機種変更した"))
    chat_result = json.loads(json_text) if status_code == 200 else {}
    check("J: チャットの答えの口（JSON）が、載っている質問と答えをそのまま返す",
          chat_result.get("ok") is True and [entry["question"] for entry in chat_result.get("matches", [])][:1] == ["2段階認証のスマートフォンを買い替えたら"]
          and "新しいスマートフォンで設定し直してください" in chat_result["matches"][0]["answer"], (status_code, json_text[:200]))
    status_code, _location, json_text = member_client.get("/faq.php?format=json&q=" + urllib.parse.quote("昼ごはん"))
    check("J: 見つからないときは、チャットの答えは空", status_code == 200 and json.loads(json_text).get("ok") is True and json.loads(json_text).get("matches") == [], json_text[:200])
    _status_code, _location, chat_html = kacho_client.get("/faq.php")
    check("J: 課長・確認者には、探す前から送る欄を隠して置いておく（チャットで「解決しない」と出す）", 'id="faq-ask"' in chat_html and 'value="ask"' in chat_html and 'data-ask-role="ask"' in chat_html)
    status_code, _location, search_html = member_client.get("/faq.php?q=" + urllib.parse.quote("スマホを機種変更した"))
    check("J: 「スマホ」で「スマートフォン」の質問が出る", "2段階認証のスマートフォンを買い替えたら" in search_html.split("すべての質問")[0])
    check("J: 部下には送る欄を出さず、課長・確認者に聞くよう案内する", 'value="ask"' not in search_html and "課長・確認者（課長代理・リーダー）に聞いてください" in search_html)
    status_code, _location, _page_html = member_client.post("/faq.php", {"csrf_token": csrf(search_html), "request_key": os.urandom(16).hex(), "action": "ask", "question": "x", "screen_code": "", "source_path": ""})
    check("J: 部下は質問を送れない（403）", status_code == 403, status_code)
    status_code, _location, search_html = kacho_client.get("/faq.php?q=" + urllib.parse.quote("システムの稼働時間帯はいつまでか"))
    check("J: 見つからないときは、その旨と送る欄を出す", "近い質問は見つかりませんでした" in search_html and 'value="ask"' in search_html and "システムの稼働時間帯はいつまでか</textarea>" in search_html)
    status_code, _location, error_html = post_form(kacho_client, "/faq.php?q=x", {"action": "ask", "question": "", "screen_code": "", "source_path": ""})
    check("J: 空の質問は送れない", status_code == 200 and "質問を入れてください" in error_html, status_code)
    _status_code, _location, help_link_html = kacho_client.get("/index.php")
    check("J: 「この画面の使い方」のよくある質問に画面の番号を付ける", "/faq.php?screen=G-03" in help_link_html)
    request_count_before = database.execute("SELECT COUNT(*) FROM support_requests").fetchone()[0]
    status_code, location, _page_html = post_form(kacho_client, "/faq.php?q=x", {
        "action": "ask", "question": "システムの稼働時間帯はいつまでか\n補足: 毎月変わるようです", "screen_code": "G-03", "source_path": "/index.php?q=secret"})
    asked_row = database.execute("SELECT id, kind, status, screen_code, source_path FROM support_requests ORDER BY id DESC LIMIT 1").fetchone()
    check("J: 見つからない質問を、要望・依頼の「質問」として自動で起票する",
          status_code == 303 and "/requests/view.php?id=" in (location or "") and database.execute("SELECT COUNT(*) FROM support_requests").fetchone()[0] == request_count_before + 1
          and tuple(asked_row[1:]) == ("question", "open", "G-03", "/index.php"), (status_code, location, asked_row))
    _status_code, _location, asked_view_html = kacho_client.get("/requests/view.php?id=%d" % asked_row[0])
    check("J: 件名は質問の最初の行、内容は質問の全文", "システムの稼働時間帯はいつまでか" in asked_view_html and "補足: 毎月変わるようです" in asked_view_html)
    check("J: 送った質問は記録に残る", database.execute("SELECT COUNT(*) FROM access_logs WHERE target = ?", ("support_request:%d:create" % asked_row[0],)).fetchone()[0] == 1)
    _status_code, _location, admin_search_html = admin_client.get("/faq.php?q=" + urllib.parse.quote("システムの稼働時間帯"))
    check("J: 管理者（確認者でない）には送る欄を出さない", 'value="ask"' not in admin_search_html and "質問を受ける側" in admin_search_html)

    # J 標準のよくある質問の取り込み・分野・曖昧な質問の聞き返し（G-25 §2.3・§2.4）
    standard_source = open(os.path.join(test_dir, "..", "..", "app", "lib", "faq_standard.php"), encoding="utf-8").read()
    standard_entry_list = re.findall(r"^    \['(\w*)', '((?:[^'\\]|\\.)*)',$", standard_source, re.M)
    faq_count_before = database.execute("SELECT COUNT(*) FROM faq_entries").fetchone()[0]
    check("J: 標準のよくある質問は 100 件以上", len(standard_entry_list) >= 100, len(standard_entry_list))
    status_code, _location, _page_html = post_form(member_client, "/faq.php", {"action": "import_standard"})
    check("J: 部下は標準の質問を取り込めない（403）", status_code == 403, status_code)
    status_code, _location, _page_html = post_form(admin_client, "/faq.php", {"action": "import_standard"})
    faq_count_after = database.execute("SELECT COUNT(*) FROM faq_entries").fetchone()[0]
    check("J: 管理者が標準のよくある質問を取り込める", status_code in (302, 303) and faq_count_after == faq_count_before + len(standard_entry_list), (status_code, faq_count_before, faq_count_after))
    post_form(admin_client, "/faq.php", {"action": "import_standard"})
    check("J: もう一度取り込んでも重ならない（載っていない質問だけ足す）", database.execute("SELECT COUNT(*) FROM faq_entries").fetchone()[0] == faq_count_after)
    check("J: 取り込んだ質問に分野が入る", database.execute("SELECT COUNT(*) FROM faq_entries WHERE category = 'login'").fetchone()[0] >= 10)
    check("J: 取り込みを記録に残す", database.execute("SELECT COUNT(*) FROM access_logs WHERE target LIKE 'faq:import_standard;added=%'").fetchone()[0] == 2)
    self_miss_list = []
    for _category, standard_question in standard_entry_list:
        _status_code, _location, json_text = admin_client.get("/faq.php?format=json&q=" + urllib.parse.quote(standard_question))   # 管理者だけの分野も含めて確かめる
        chat_result = json.loads(json_text)
        if chat_result.get("result") in ("retirement", "emergency", "distress", "sensitive"):
            continue   # 相談を促す・119 番を案内する質問は、答えを探さない（狙いどおり）
        chat_matches = chat_result.get("matches", [])
        if not chat_matches or chat_matches[0]["question"] != standard_question:
            self_miss_list.append(standard_question)
    check("J: 標準の質問は、その質問の文で探すといちばん上に出る（取り違えがない）", self_miss_list == [], self_miss_list[:3])
    for vague_text in ["使い方がわからない", "教えて", "このシステムの使い方", "困った"]:
        _status_code, _location, json_text = member_client.get("/faq.php?format=json&q=" + urllib.parse.quote(vague_text))
        check("J: 曖昧な質問（%s）は分野を聞き返す" % vague_text, (json.loads(json_text)["vague"], json.loads(json_text)["unrelated"], json.loads(json_text)["matches"]) == (True, False, []), json_text[:120])
    for digit_text in ["10時に報告を出したい", "2026年の報告", "１２月の締め日", "12"]:
        status_code, _location, json_text = member_client.get("/faq.php?format=json&q=" + urllib.parse.quote(digit_text))
        check("J: 2 けた以上の数字が入った質問（%s）でもエラーにならない" % digit_text, status_code == 200 and json.loads(json_text).get("ok") is True, (status_code, json_text[:80]))
    _status_code, _location, json_text = member_client.get("/faq.php?format=json&q=" + urllib.parse.quote("ログインできない"))
    check("J: 中身のある質問（ログインできない）は聞き返さずに答える", json.loads(json_text)["vague"] is False and len(json.loads(json_text)["matches"]) >= 1)
    # J セキュリティ: 分野「システムの管理」は管理者・補佐にだけ見せる。答えにシステムの内部の情報を書かない
    for other_client, other_name in [(member_client, "部下"), (kacho_client, "課長")]:
        _status_code, _location, json_text = other_client.get("/faq.php?format=json&category=admin")
        check("J: %sには、分野「システムの管理」の質問を出さない" % other_name, json.loads(json_text)["matches"] == [], json_text[:120])
        _status_code, _location, other_faq_html = other_client.get("/faq.php")
        check("J: %sの画面に、分野「システムの管理」のボタンも一覧も出さない" % other_name,
              'data-category="admin"' not in other_faq_html and 'id="faq-category-admin"' not in other_faq_html and "システム管理者の引き継ぎ" not in other_faq_html)
        _status_code, _location, json_text = other_client.get("/faq.php?format=json&q=" + urllib.parse.quote("システム管理者が交代する"))
        check("J: %sのチャットでも、管理者・補佐だけの答えを出さない" % other_name, all(not entry["question"].startswith("システム管理者の引き継ぎ") for entry in json.loads(json_text)["matches"]))
    # J セキュリティ: ID・パスワードを求める質問には、受け渡ししないと答える（管理者の名前などを返さない）
    for credential_text in ["システム管理者を引き継ぐことになった。IDとパスワードがほしい。", "管理者のパスワードを教えて"]:
        _status_code, _location, json_text = kacho_client.get("/faq.php?format=json&q=" + urllib.parse.quote(credential_text))
        credential_matches = json.loads(json_text)["matches"]
        check("J: ID・パスワードを求める質問（%s）には、受け渡ししないと答える" % credential_text[:10], credential_matches[:1] and credential_matches[0]["question"].startswith("ほかの人の ID・パスワード"), credential_matches[:1])
    _status_code, _location, json_text = kacho_client.get("/faq.php?format=json&q=" + urllib.parse.quote("システム管理者が急逝した。システム管理者権限を私としたい。"))
    check("J: 課長が管理者の権限を求めると、権限の付け方を答える（管理者だけの分野は使わない）", json.loads(json_text)["matches"][0]["question"].startswith("システム管理者の権限がほしい"))
    # J ID・パスワードを求める質問は、システム管理者と補佐の両方に知らせる（K-057）
    deputy_notice_client = login(arguments.base, "hosa01", user_info_by_login_id["hosa01"])

    def ask_chat_for_notice(client, question_text):
        _status_code, _location, chat_json_text = client.get("/faq.php?format=json&q=" + urllib.parse.quote(question_text))
        return json.loads(chat_json_text)

    credential_result = ask_chat_for_notice(kacho_client, "前任の管理者のパスワードを教えてほしい")
    credential_row = database.execute("SELECT distress_level, handled_at FROM faq_chat_logs WHERE id = ?", (credential_result["log_id"],)).fetchone()
    check("J: ID・パスワードを求める質問に、知らせる印（credential）を付ける", tuple(credential_row) == ("credential", None), credential_row)
    check("J: 自分のパスワードを忘れた質問には、知らせる印を付けない",
          database.execute("SELECT distress_level FROM faq_chat_logs WHERE id = ?", (ask_chat_for_notice(kacho_client, "パスワードを忘れた")["log_id"],)).fetchone()[0] is None)
    check("J: 管理者のホームに、ID・パスワードを求める質問を知らせる", "ID・パスワードを求める質問があります" in admin_client.get("/index.php")[2])
    check("J: 補佐のホームにも、ID・パスワードを求める質問を知らせる", "ID・パスワードを求める質問があります" in deputy_notice_client.get("/index.php")[2])
    check("J: 補佐の管理のトップのカードにも出す", "ID・パスワードを求める質問（未対応）" in deputy_notice_client.get("/admin/index.php")[2])
    check("J: 部下のホームには知らせない", "ID・パスワードを求める質問があります" not in member_client.get("/index.php")[2])
    _status_code, _location, deputy_notice_html = deputy_notice_client.get("/admin/faq_logs.php")
    check("J: 補佐の記録の画面の欄に、ID・パスワードを求める質問を出す", "前任の管理者のパスワードを教えてほしい" in deputy_notice_html.split('id="distress"')[1].split("答えられなかった質問")[0])
    status_code, _location, _page_html = post_form(deputy_notice_client, "/admin/faq_logs.php", {"action": "distress_handled", "log_id": str(credential_result["log_id"])})
    check("J: 補佐が ID・パスワードを求める質問に「対応した」を押せる", database.execute("SELECT handled_by FROM faq_chat_logs WHERE id = ?", (credential_result["log_id"],)).fetchone()[0] == user_info_by_login_id["hosa01"]["id"], status_code)
    for credential_row_id in database.execute("SELECT id FROM faq_chat_logs WHERE distress_level = 'credential' AND handled_at IS NULL").fetchall():
        post_form(deputy_notice_client, "/admin/faq_logs.php", {"action": "distress_handled", "log_id": str(credential_row_id[0])})
    check("J: すべて対応すると、補佐のホームの知らせが消える", "ID・パスワードを求める質問があります" not in deputy_notice_client.get("/index.php")[2])
    distress_for_deputy = ask_chat_for_notice(member_client, "もう死にたい")
    status_code, _location, _page_html = post_form(deputy_notice_client, "/admin/faq_logs.php", {"action": "distress_handled", "log_id": str(distress_for_deputy["log_id"])})
    check("J: 補佐は、心の不調の記録には「対応した」を押せない（403）", status_code == 403, status_code)
    post_form(admin_client, "/admin/faq_logs.php", {"action": "distress_handled", "log_id": str(distress_for_deputy["log_id"])})
    # J 無い ID でのログインは、システム管理者と補佐の両方に知らせる（K-058）
    stranger_client = Client(arguments.base)
    _status_code, _location, stranger_login_html = stranger_client.get("/login.php")
    stranger_client.post("/login.php", {"csrf_token": csrf(stranger_login_html), "action": "password", "login_id": "no_such_user_9", "password": "wrong-password-123"})
    check("J: 管理者のホームに、無い ID でのログインを知らせる", "無い ID でログインしようとした記録があります" in admin_client.get("/index.php")[2])
    check("J: 補佐のホームにも、無い ID でのログインを知らせる", "無い ID でログインしようとした記録があります" in deputy_notice_client.get("/index.php")[2])
    check("J: 補佐の管理のトップのカードにも出す", "無い ID でのログイン（未確認）" in deputy_notice_client.get("/admin/index.php")[2])
    check("J: 課長のホームには知らせない", "無い ID でログインしようとした記録があります" not in kacho_client.get("/index.php")[2])
    _status_code, _location, unknown_logs_html = deputy_notice_client.get("/admin/logs.php?kind=auth_unknown")
    check("J: 記録の画面で、無い ID でのログインだけを出せる（入れた ID が見える）", "no_such_user_9" in unknown_logs_html and "login:ok" not in unknown_logs_html)
    status_code, _location, _page_html = post_form(deputy_notice_client, "/admin/logs.php", {"action": "unknown_login_checked"})
    check("J: 補佐が「確かめた」を押すと、知らせが消える", "無い ID でログインしようとした記録があります" not in admin_client.get("/index.php")[2], status_code)
    # J お悔やみは、亡くなったと書かれたときだけ添える（いなくなった・交代した、だけでは決めつけない）
    _status_code, _location, json_text = kacho_client.get("/faq.php?format=json&q=" + urllib.parse.quote("管理者が急逝し、代表取締役から管理者権限を与えられた。どうしたらよいか"))
    death_matches = json.loads(json_text)["matches"]
    check("J: 亡くなったと書かれた質問は、関係のある質問として答える", death_matches[:1] and death_matches[0]["question"].startswith("システム管理者の権限がほしい"), death_matches[:1])
    check("J: 亡くなったと書かれたときは、お悔やみを添える", death_matches[:1] and death_matches[0]["answer"].startswith("心よりお悔やみ申し上げます。"))
    _status_code, _location, json_text = kacho_client.get("/faq.php?format=json&q=" + urllib.parse.quote("システム管理者が急にいなくなった。管理者の権限がほしい"))
    gone_matches = json.loads(json_text)["matches"]
    check("J: いなくなっただけの質問には、お悔やみを添えない", gone_matches[:1] and "お悔やみ" not in gone_matches[0]["answer"], gone_matches[:1])
    check("J: 標準の答えに、亡くなったと決めつけたお悔やみを書かない", "亡くなられたときは" not in standard_source)
    # J 他の端末の確かめで見つかった、緊急の見落とし・取り違え（2026-11-16）
    for emergency_text, expected_result in [
        ("30分くらい前から胸がすごく痛いんですが、少し横になって様子を見たほうがいいですか？", "emergency"),
        ("2歳の子供がミニトマトを喉に詰まらせたようです。顔色が真っ青で声も出せません", "emergency"),
        ("2歳の子がキッチンハイター（塩素系漂白剤）を飲んでしまいました", "emergency"),
        ("工場の事故で作業員が大型機械に巻き込まれました", "emergency"),
        ("朝起きたら同居人が布団の中で冷たくなっていました", "emergency"),
        ("対象社員は業務負荷により限界を迎えており、毎日倒れそうになりながら出社している", "distress"),
        ("昨日、辛いラーメンを食べたら口の中が火事になりました。", "unrelated"),
    ]:
        _status_code, _location, json_text = kacho_client.get("/faq.php?format=json&q=" + urllib.parse.quote(emergency_text))
        check("J: 「%s」は %s" % (emergency_text[:14], expected_result), json.loads(json_text)["result"] == expected_result, json.loads(json_text)["result"])
    for incident_text, expected_question in [("サーバーが死亡しました", "システムが止まった"), ("不正アクセスによってシステムの管理者がロックアウトされました", "不正アクセス")]:
        _status_code, _location, json_text = kacho_client.get("/faq.php?format=json&q=" + urllib.parse.quote(incident_text))
        incident_matches = json.loads(json_text)["matches"]
        check("J: 「%s」に、障害・事故の答えを出す" % incident_text[:10], incident_matches[:1] and incident_matches[0]["question"].startswith(expected_question), incident_matches[:1])
    check("J: 緊急の返事に、迷うときの相談先（#7119・#8000）を書く", "#8000" in kacho_client.get("/faq.php")[2] or "#8000" in open(os.path.join(test_dir, "..", "..", "public", "js", "app.js"), encoding="utf-8").read())
    _status_code, _location, json_text = admin_client.get("/faq.php?format=json&category=admin")
    check("J: 管理者には、分野「システムの管理」の質問を出す", len(json.loads(json_text)["matches"]) >= 10)
    check("J: 標準のよくある質問に、コマンド・ファイルの場所・鍵の置き場所を書かない", re.search(r"app/|\.php|bin/|--admin|/keys", standard_source) is None)
    _status_code, _location, json_text = member_client.get("/faq.php?format=json&category=survey")
    survey_question_list = [entry["question"] for entry in json.loads(json_text)["matches"]]
    check("J: 分野を選ぶと、その分野の質問をすべて返す", survey_question_list == [question for category, question in standard_entry_list if category == "survey"], survey_question_list)
    _status_code, _location, vague_html = member_client.get("/faq.php?q=" + urllib.parse.quote("使い方がわからない"))
    check("J: JavaScript が無くても、曖昧な質問には分野のリンクを出す", "どのことについて知りたいですか" in vague_html and 'data-category="login"' in vague_html and 'role="status">近い質問は見つかりませんでした' not in vague_html)
    _status_code, _location, category_html = member_client.get("/faq.php?category=login")
    check("J: JavaScript が無くても、分野の質問を出す", "「ログイン・パスワード・2 段階認証」の質問です" in category_html and "パスワードを忘れたときは" in category_html)
    _status_code, _location, screen_html = member_client.get("/faq.php?screen=G-04&from=%2Freports%2Fedit.php")
    check("J: 「この画面の使い方」から来たときは、その画面の分野をすすめる", "開いていた画面の質問は「報告の作成・提出」" in screen_html and "chat-choice-recommended" in screen_html)
    _status_code, _location, all_html = member_client.get("/faq.php")
    check("J: すべての質問を分野ごとに分けて出す", 'id="faq-category-login"' in all_html and 'id="faq-category-department"' in all_html)
    status_code, _location, error_html = post_form(admin_client, "/faq.php", {"action": "save", "id": "", "source_request_id": "", "category": "nothing", "question": "x", "answer": "y"})
    check("J: 分野が正しくないと載せない", status_code == 200 and "分野を選んでください" in error_html, status_code)

    # J システムに関係のない質問（G-25 §2.6）: 返答できないと返し、5 回続いたら返事をしない
    def ask_chat(client, question_text):
        _status_code, _location, json_text = client.get("/faq.php?format=json&q=" + urllib.parse.quote(question_text))
        return json.loads(json_text)

    unrelated_text_list = ["明日の天気は？", "おすすめの映画を教えて", "野球の結果", "有給休暇の申請方法", "株価はどうなる", "東京の人口"]
    chat_result = ask_chat(leader_client, unrelated_text_list[0])
    check("J: システムに関係のない質問は、返答できないとする", chat_result["unrelated"] is True and chat_result["ignored"] is False and chat_result["matches"] == [], chat_result)
    for related_text in ["ログインできない", "修正したい", "画面が真っ白", "動かない", "スマホ変えた"]:
        chat_result = ask_chat(leader_client, related_text)
        check("J: システムの質問（%s）は返答できないとしない" % related_text, chat_result["unrelated"] is False and len(chat_result["matches"]) >= 1, chat_result.get("unrelated"))
    ignored_list = [ask_chat(leader_client, unrelated_text)["ignored"] for unrelated_text in unrelated_text_list[:5]]
    check("J: 関係のない質問が 5 回続くと、5 回目から返事をしない", ignored_list == [False, False, False, False, True], ignored_list)
    check("J: 6 回目以降も返事をしない", ask_chat(leader_client, unrelated_text_list[5])["ignored"] is True)
    _status_code, _location, ignored_html = leader_client.get("/faq.php?q=" + urllib.parse.quote("週末どこ行く"))
    check("J: JavaScript が無くても、続いている間は返事をしない", "その質問には返答できません。</div>" not in ignored_html and "週末どこ行く" in ignored_html)
    check("J: システムの質問をすると、また答える", ask_chat(leader_client, "パスワードを忘れた")["matches"] != [])
    recount_result = ask_chat(leader_client, unrelated_text_list[0])
    check("J: 数え直しのあとは、関係のない質問にまた「返答できません」と返す", (recount_result["unrelated"], recount_result["ignored"], recount_result["matches"]) == (True, False, []), recount_result)
    _status_code, _location, unrelated_html = member_client.get("/faq.php?q=" + urllib.parse.quote("今日の昼ごはん何にする"))
    check("J: JavaScript が無くても「その質問には返答できません。」と出し、送る欄は隠す",
          "その質問には返答できません。</div>" in unrelated_html and 'id="faq-ask"  hidden' in unrelated_html.replace('data-has-error="1"', ''))
    ask_chat(leader_client, "明日の天気は？")
    ask_chat(leader_client, "明日の天気は？")
    _status_code, _location, json_text = leader_client.get("/faq.php?format=json&category=login")
    check("J: 分野を選ぶと数え直す", json.loads(json_text)["matches"] != [] and ask_chat(leader_client, "明日の天気は？")["ignored"] is False)

    # J 不適切な表現（G-25 §2.6）: システムの言葉があっても答えず、関係のない質問と一緒に数える
    ask_chat(leader_client, "パスワードを忘れた")
    for abusive_text in ["バカなの？", "ふざけるな、ログインできないぞ", "ポンコツ"]:
        chat_result = ask_chat(leader_client, abusive_text)
        check("J: 不適切な表現（%s）には答えない" % abusive_text, (chat_result["abusive"], chat_result["result"], chat_result["matches"]) == (True, "abusive", []), chat_result)
    for normal_text in ["ばかりで困る", "カスタマイズしたい", "ところすでに提出した"]:
        check("J: ふつうの文（%s）を不適切な表現にしない" % normal_text, ask_chat(leader_client, normal_text)["abusive"] is False)
    mixed_ignored_list = [ask_chat(leader_client, mixed_text)["ignored"] for mixed_text in ["バカなの？", "明日の天気は？", "ポンコツ", "野球の結果", "黙れ"]]
    check("J: 不適切な表現と関係のない質問を合わせて 5 回続くと、5 回目から返事をしない", mixed_ignored_list == [False, False, False, False, True], mixed_ignored_list)
    ask_chat(leader_client, "パスワードを忘れた")
    _status_code, _location, abusive_html = member_client.get("/faq.php?q=" + urllib.parse.quote("無能なシステムだな"))
    check("J: JavaScript が無くても「不適切な表現が含まれているため、返答できません。」と出す", "不適切な表現が含まれているため、返答できません。</div>" in abusive_html)

    # J チャットの記録（G-25 §2.7）: 質問と返した答え・反応を別の表に残す
    leader_user_id = user_info_by_login_id["leader_a"]["id"]
    chat_result = ask_chat(leader_client, "締め日はいつ")
    log_row = database.execute("SELECT user_id, role, result, question_enc, shown_faq_ids, feedback FROM faq_chat_logs WHERE id = ?", (chat_result["log_id"],)).fetchone()
    check("J: 聞いた質問を、聞いた人・役割・結果・出した答えと一緒に記録する",
          log_row is not None and (log_row[0], log_row[1], log_row[2], log_row[5]) == (leader_user_id, "reviewer", "matched", "")
          and log_row[4].split(",")[0] == str(chat_result["matches"][0]["id"]), log_row)
    check("J: 記録の質問の文は暗号化する", log_row is not None and str(log_row[3]).startswith("v1:"))
    check("J: 操作の記録（access_logs）には質問の文を残さない", database.execute("SELECT COUNT(*) FROM access_logs WHERE target LIKE ?", ("%締め日はいつ%",)).fetchone()[0] == 0)
    _status_code, _location, leader_faq_html = leader_client.get("/faq.php")
    feedback_status, _location, _page = leader_client.post("/faq.php", {"csrf_token": csrf(leader_faq_html), "action": "chat_feedback", "log_id": str(chat_result["log_id"]),
                                                                       "feedback": "solved", "faq_id": str(chat_result["matches"][0]["id"])})
    feedback_row = database.execute("SELECT feedback, feedback_faq_id FROM faq_chat_logs WHERE id = ?", (chat_result["log_id"],)).fetchone()
    check("J: 「解決した」を記録に残す", feedback_status == 200 and tuple(feedback_row) == ("solved", chat_result["matches"][0]["id"]), (feedback_status, feedback_row))
    _status_code, _location, member_faq_html = member_client.get("/faq.php")
    other_status, _location, _page = member_client.post("/faq.php", {"csrf_token": csrf(member_faq_html), "action": "chat_feedback", "log_id": str(chat_result["log_id"]), "feedback": "unsolved", "faq_id": ""})
    check("J: ほかの人の記録には反応を付けられない（400）", other_status == 400 and database.execute("SELECT feedback FROM faq_chat_logs WHERE id = ?", (chat_result["log_id"],)).fetchone()[0] == "solved", other_status)
    no_match_result = ask_chat(leader_client, "システムの帳票レイアウトの余白")
    check("J: 見つからない質問を「見つからない」として記録する", database.execute("SELECT result FROM faq_chat_logs WHERE id = ?", (no_match_result["log_id"],)).fetchone()[0] in ("no_match", "matched"))
    status_code, location, _page_html = post_form(leader_client, "/faq.php?q=x", {"action": "ask", "question": "システムの帳票レイアウトの余白", "screen_code": "", "source_path": "", "chat_log_id": str(no_match_result["log_id"])})
    asked_log_row = database.execute("SELECT feedback, request_id FROM faq_chat_logs WHERE id = ?", (no_match_result["log_id"],)).fetchone()
    check("J: 質問として送ったことと、要望・依頼の番号を記録に残す", status_code == 303 and asked_log_row[0] == "asked" and asked_log_row[1] is not None
          and "id=%d" % asked_log_row[1] in (location or ""), (status_code, location, asked_log_row))
    abusive_log = database.execute("SELECT user_id, result, is_ignored FROM faq_chat_logs WHERE result = 'abusive' ORDER BY id DESC LIMIT 1").fetchone()
    check("J: 不適切な表現も、聞いた人と返事をしなかったかを記録する", abusive_log is not None and abusive_log[0] is not None and abusive_log[1] == "abusive")
    check("J: 返事をしなかった記録に印が付く", database.execute("SELECT COUNT(*) FROM faq_chat_logs WHERE is_ignored = 1").fetchone()[0] >= 2)

    # J よくある質問の記録の画面（G-25 §2.7）
    status_code, _location, log_page_html = admin_client.get("/admin/faq_logs.php")
    check("J: 管理者はよくある質問の記録を開ける", status_code == 200 and "答えられなかった質問" in log_page_html and "システムの帳票レイアウトの余白" in log_page_html, status_code)
    check("J: 管理者には聞いた人の名前を出す", "（leader_a）" in log_page_html)
    check("J: 不適切な表現の欄に出す", "ふざけるな、ログインできないぞ" in log_page_html.split('id="abusive"')[1])
    check("J: 管理者のメニューに「よくある質問の記録」", "/admin/faq_logs.php" in admin_client.get("/index.php")[2])
    deputy_client = login(arguments.base, "hosa01", user_info_by_login_id["hosa01"])
    status_code, _location, deputy_log_html = deputy_client.get("/admin/faq_logs.php")
    check("J: 補佐も記録を開け、聞いた人の名前も見られる（K-051）", status_code == 200 and "（leader_a）" in deputy_log_html, status_code)
    check("J: 部下は記録を開けない（403）", member_client.get("/admin/faq_logs.php")[0] == 403)
    check("J: 課長（管理者でない）も記録を開けない（403）", kacho_client.get("/admin/faq_logs.php")[0] == 403)
    status_code, _location, unanswered_html = admin_client.get("/admin/faq_logs.php?result=unanswered&days=7")
    check("J: 答えられなかったもので絞り込める", status_code == 200 and "システムの帳票レイアウトの余白" in unanswered_html.split("新しい順")[1] and "締め日はいつ" not in unanswered_html.split("新しい順")[1])
    status_code, _location, draft_html = admin_client.get("/faq.php?draft_log=%d" % no_match_result["log_id"])
    check("J: 記録の質問から「よくある質問に載せる」と、質問の欄に入る", status_code == 200 and 'value="システムの帳票レイアウトの余白"' in draft_html, status_code)
    check("J: 部下は記録から載せる画面を開けない（403）", member_client.get("/faq.php?draft_log=%d" % no_match_result["log_id"])[0] == 403)
    check("J: 記録を見たことを、操作の記録に残す", database.execute("SELECT COUNT(*) FROM access_logs WHERE target LIKE 'faq_chat_logs:view%'").fetchone()[0] >= 3)
    _status_code, _location, admin_home_html = admin_client.get("/admin/index.php")
    check("J: 管理のトップに、不適切な表現の数を目立たせて出す", "不適切な表現（7 日）" in admin_home_html)

    # J 心の不調らしい文（G-25 §2.8）: 気づかいの言葉を返し、管理者・補佐に知らせる
    ippan_a2_client = login(arguments.base, "ippan_a2", user_info_by_login_id["ippan_a2"])
    _status_code, _location, member_home_before_html = member_client.get("/index.php")
    check("J: 部下のホームには、心の不調の知らせを出さない", "心の不調らしい文や退職に関わる質問があります" not in member_home_before_html)
    urgent_result = ask_chat(ippan_a2_client, "もう死にたい")
    check("J: 命に関わる言葉には、気づかいの言葉を返す（返答できないとしない・答えは出さない）",
          (urgent_result["result"], urgent_result["distress"], urgent_result["ignored"], urgent_result["matches"]) == ("distress", "urgent", False, []), urgent_result)
    concern_result = ask_chat(ippan_a2_client, "助けて、ログインできない")
    check("J: 心配な言葉で、システムの質問も入っていれば、答えも返す", concern_result["distress"] == "concern" and len(concern_result["matches"]) >= 1, concern_result)
    limit_result = ask_chat(ippan_a2_client, "もう限界です")
    check("J: 心配な言葉だけの文には、答えを出さない（心配な言葉そのもので探さない）", limit_result["distress"] == "concern" and limit_result["matches"] == [], limit_result["matches"][:1])
    check("J: 「仕事を辞めたい」にも答えを出さない（弱い当たりは出さない）", ask_chat(ippan_a2_client, "仕事を辞めたい")["matches"] == [])
    quit_result = ask_chat(ippan_a2_client, "会社を辞めたい")
    check("J: 「辞めたい」には、退職の手続きは示さず、相談を促す言葉だけを返す", quit_result["distress"] == "concern" and quit_result["matches"] == [], quit_result["matches"][:1])
    for normal_text in ["使いづらい", "過去の報告をうつす", "お疲れ様です"]:
        check("J: ふつうの文（%s）を心の不調としない" % normal_text, ask_chat(ippan_a2_client, normal_text)["distress"] == "", normal_text)
    distress_log = database.execute("SELECT user_id, result, distress_level, handled_at, question_enc FROM faq_chat_logs WHERE id = ?", (urgent_result["log_id"],)).fetchone()
    check("J: 心の不調らしい文を、聞いた人・段階と一緒に記録する（文は暗号化）",
          distress_log is not None and (distress_log[0], distress_log[1], distress_log[2], distress_log[3]) == (user_info_by_login_id["ippan_a2"]["id"], "distress", "urgent", None)
          and str(distress_log[4]).startswith("v1:"), distress_log)
    ask_chat(ippan_a2_client, "パスワードを忘れた")   # 数え直してから試す
    ignore_sequence = [ask_chat(ippan_a2_client, text)["ignored"] for text in ["明日の天気は？", "野球の結果", "株価はどうなる", "東京の人口", "もう疲れた", "明日の天気は？"]]
    check("J: 心の不調らしい文は、返事をしない回数に数えない（数え直す）", ignore_sequence == [False] * 6, ignore_sequence)
    _status_code, _location, care_html = ippan_a2_client.get("/faq.php?q=" + urllib.parse.quote("もう限界です"))
    check("J: JavaScript が無くても、気づかいの言葉を出す（知らせたことは返事に出さない）", "上司やシステム管理者に相談してください" in care_html and "システム管理者・補佐に知らせています" not in care_html)
    check("J: チャットの画面に、管理者・補佐に知らせることは書かない", "システム管理者・補佐に知らせます" not in care_html)
    _status_code, _location, admin_home_page_html = admin_client.get("/index.php")
    check("J: 管理者のホームに、未対応の心の不調らしい文を知らせる", "心の不調らしい文や退職に関わる質問があります" in admin_home_page_html and "うち命に関わる言葉 1 件" in admin_home_page_html)
    check("J: 補佐のホームには知らせない（K-054）", "心の不調らしい文や退職に関わる質問があります" not in deputy_client.get("/index.php")[2])
    check("J: 補佐の管理のトップのカードにも出さない", "心の不調・退職に関わる質問（未対応）" not in deputy_client.get("/admin/index.php")[2])
    _status_code, _location, deputy_distress_html = deputy_client.get("/admin/faq_logs.php")
    check("J: 補佐の記録の画面には、心の不調の文を欄にも一覧にも出さない", "もう死にたい" not in deputy_distress_html and "もう限界です" not in deputy_distress_html)
    status_code, _location, _page_html = post_form(deputy_client, "/admin/faq_logs.php", {"action": "distress_handled", "log_id": str(urgent_result["log_id"])})
    check("J: 補佐は「対応した」を押せない（403）", status_code == 403, status_code)
    check("J: 管理のトップのカードにも出す", "心の不調・退職に関わる質問（未対応）" in admin_client.get("/admin/index.php")[2])
    _status_code, _location, distress_log_html = admin_client.get("/admin/faq_logs.php")
    distress_section_html = distress_log_html.split('id="distress"')[1].split("答えられなかった質問")[0]
    check("J: 記録の画面に、心の不調らしい文を聞いた人つきで出す（命に関わる言葉を先に）",
          "もう死にたい" in distress_section_html and "（ippan_a2）" in distress_section_html
          and distress_section_html.index("もう死にたい") < distress_section_html.index("もう限界です"))
    status_code, _location, _page_html = post_form(member_client, "/admin/faq_logs.php", {"action": "distress_handled", "log_id": str(urgent_result["log_id"])})
    check("J: 部下は「対応した」を押せない（403）", status_code == 403, status_code)
    for distress_row in database.execute("SELECT id FROM faq_chat_logs WHERE distress_level IS NOT NULL AND handled_at IS NULL").fetchall():
        post_form(admin_client, "/admin/faq_logs.php", {"action": "distress_handled", "log_id": str(distress_row[0])})
    handled_row = database.execute("SELECT handled_at, handled_by FROM faq_chat_logs WHERE id = ?", (urgent_result["log_id"],)).fetchone()
    check("J: 管理者が「対応した」を押すと、日時と対応した人を残す", handled_row[0] is not None and handled_row[1] == user_info_by_login_id["admin01"]["id"], handled_row)
    check("J: 「対応した」を操作の記録に残す", database.execute("SELECT COUNT(*) FROM access_logs WHERE target = ?", ("faq_chat_log:%d:distress_handled" % urgent_result["log_id"],)).fetchone()[0] == 1)
    check("J: すべて対応すると、ホームの知らせが消える", "心の不調らしい文や退職に関わる質問があります" not in admin_client.get("/index.php")[2])

    # J 要配慮個人情報に触れる文（G-25 §2.9。K-053）: 寄り添う返事にし、文は記録しない。管理者には知らせない
    for sensitive_text in ["病気で入院する", "がんと診断された", "障がいがある", "宗教上の理由で", "逮捕された", "妊娠しました"]:
        sensitive_result = ask_chat(ippan_a2_client, sensitive_text)
        check("J: 要配慮の文（%s）には寄り添う返事（返答できないとしない）" % sensitive_text,
              (sensitive_result["result"], sensitive_result["sensitive"], sensitive_result["ignored"], sensitive_result["distress"]) == ("sensitive", True, False, ""), sensitive_result)
    sensitive_row = database.execute("SELECT result, question_enc, distress_level FROM faq_chat_logs WHERE id = ?", (sensitive_result["log_id"],)).fetchone()
    check("J: 要配慮の文も、文を記録する（暗号化）", (sensitive_row[0], str(sensitive_row[1])[:3], sensitive_row[2]) == ("sensitive", "v1:", None), sensitive_row)
    hospital_result = ask_chat(ippan_a2_client, "入院するので報告が出せない")
    check("J: 要配慮の文でも、はっきり知りたいことがあれば答えも出す", hospital_result["sensitive"] is True and hospital_result["matches"][:1] and hospital_result["matches"][0]["question"].startswith("報告を作れない"), hospital_result["matches"][:1])
    for normal_text in ["システム障害が起きた", "がんばって報告を出したい"]:
        check("J: ふつうの文（%s）を要配慮としない" % normal_text, ask_chat(ippan_a2_client, normal_text)["sensitive"] is False)
    check("J: セクハラなどの被害は、心の不調らしい文として知らせる", ask_chat(ippan_a2_client, "セクハラを受けている")["distress"] == "concern")
    for distress_row in database.execute("SELECT id FROM faq_chat_logs WHERE distress_level IS NOT NULL AND handled_at IS NULL").fetchall():
        post_form(admin_client, "/admin/faq_logs.php", {"action": "distress_handled", "log_id": str(distress_row[0])})
    ask_chat(ippan_a2_client, "持病がある")
    check("J: 要配慮の文は、管理者のホームに知らせない", "心の不調らしい文や退職に関わる質問があります" not in admin_client.get("/index.php")[2])
    _status_code, _location, sensitive_page_html = ippan_a2_client.get("/faq.php?q=" + urllib.parse.quote("持病がある"))
    check("J: JavaScript が無くても、寄り添う返事を出す", "大切なことを書いてくれて、ありがとうございます" in sensitive_page_html)
    # J 退職に関わる質問（G-25 §2.10。K-054）: 返事はふつうの答え。システム管理者・補佐に知らせる
    retirement_result = ask_chat(ippan_a2_client, "今度、退職するのだが、どうすればいい？")
    retirement_row = database.execute("SELECT result, distress_level, handled_at FROM faq_chat_logs WHERE id = ?", (retirement_result["log_id"],)).fetchone()
    check("J: 自分の退職の質問は、手続きを示さず相談を促し、知らせる印を付ける",
          (retirement_result["result"], retirement_result["retirement"], retirement_result["matches"]) == ("retirement", True, [])
          and tuple(retirement_row) == ("retirement", "retirement", None), (retirement_result, retirement_row))
    subordinate_retirement_result = ask_chat(ippan_a2_client, "部下が退職した。どうすればいい？")
    subordinate_row = database.execute("SELECT distress_level FROM faq_chat_logs WHERE id = ?", (subordinate_retirement_result["log_id"],)).fetchone()
    check("J: 部下の退職の質問は、手続きの答えを返し、知らせる印を付ける",
          subordinate_retirement_result["result"] == "matched" and subordinate_retirement_result["matches"][0]["question"].startswith("部下が退職") and subordinate_row[0] == "retirement",
          (subordinate_retirement_result["matches"][:1], subordinate_row))
    _status_code, _location, retirement_page_html = ippan_a2_client.get("/faq.php?q=" + urllib.parse.quote("来月退職します"))
    check("J: JavaScript が無くても、自分の退職には相談を促す", "まず上司（課長）に相談してください" in retirement_page_html)
    check("J: 退職に関わる質問を、管理者のホームに知らせる", "心の不調らしい文や退職に関わる質問があります" in admin_client.get("/index.php")[2])
    check("J: 記録の画面の欄に「退職に関わる質問」と出す", "退職に関わる質問" in admin_client.get("/admin/faq_logs.php")[2].split('id="distress"')[1].split("答えられなかった質問")[0])
    post_form(admin_client, "/admin/faq_logs.php", {"action": "distress_handled", "log_id": str(retirement_result["log_id"])})
    check("J: 退職に関わる質問も「対応した」を押せる", database.execute("SELECT handled_at IS NOT NULL FROM faq_chat_logs WHERE id = ?", (retirement_result["log_id"],)).fetchone()[0] == 1)
    check("J: 要配慮の文（退職でない）は知らせない", database.execute("SELECT distress_level FROM faq_chat_logs WHERE id = ?", (sensitive_result["log_id"],)).fetchone()[0] is None)
    # J 命・体の緊急の状態（G-25 §2.12。K-055）: 119 番を案内し、返事をしない回数に数えない。文は記録しない
    ask_chat(ippan_a2_client, "パスワードを忘れた")
    for unrelated_text in ["明日の天気は？", "野球の結果", "株価はどうなる", "東京の人口"]:
        ask_chat(ippan_a2_client, unrelated_text)
    emergency_texts = ["家族が突然倒れて、呼びかけても反応がありません", "包丁で指を切って血が噴き出して止まりません", "子供が誤って洗剤を飲んだかも", "急に胸が締め付けられるように痛くて冷や汗が止まりません"]
    for emergency_text in emergency_texts:
        emergency_result = ask_chat(ippan_a2_client, emergency_text)
        check("J: 命・体の緊急の状態（%s）には 119 番を案内する（返事をしないにしない）" % emergency_text[:12],
              (emergency_result["result"], emergency_result["emergency"], emergency_result["ignored"]) == ("emergency", True, False), emergency_result)
    emergency_row = database.execute("SELECT result, question_enc, distress_level FROM faq_chat_logs WHERE id = ?", (emergency_result["log_id"],)).fetchone()
    check("J: 命・体の緊急の状態の文も記録する（知らせない）", (emergency_row[0], str(emergency_row[1])[:3], emergency_row[2]) == ("emergency", "v1:", None), emergency_row)
    overdose_result = ask_chat(ippan_a2_client, "さっき市販の風邪薬を1箱全部飲んでしまいました")
    overdose_row = database.execute("SELECT result, question_enc, distress_level FROM faq_chat_logs WHERE id = ?", (overdose_result["log_id"],)).fetchone()
    check("J: 薬を大量に飲んだなど、命に関わる言葉もあれば、119 番を案内して管理者に知らせる（文は残す）",
          overdose_result["emergency"] is True and overdose_row[0] == "emergency" and overdose_row[2] == "urgent" and str(overdose_row[1]).startswith("v1:"), overdose_row)
    _status_code, _location, emergency_page_html = ippan_a2_client.get("/faq.php?q=" + urllib.parse.quote("同僚が職場で突然けいれんを起こして倒れました"))
    check("J: JavaScript が無くても 119 番を案内する", "119 番に電話して救急車を呼んでください" in emergency_page_html)
    for hidden_text, expected_level in [("消えてしまいたい。誰にも迷惑をかけずにいなくなる方法は？", "urgent"), ("明日の朝、このまま目が覚めなければいいのに", "urgent"),
                                        ("生きている価値がないように感じる", "urgent"), ("夜中に何度も目が覚めて、朝も起きられません", "concern"),
                                        ("上司からの叱責が続き、自分が無能な人間だと思い込んでしまう", "concern"), ("お酒を飲まないと眠れなくなった", "concern")]:
        check("J: 心の不調の書き方（%s）を拾う" % hidden_text[:12], ask_chat(ippan_a2_client, hidden_text)["distress"] == expected_level, hidden_text)
    for normal_text in ["画面が真っ暗になった", "お酒を飲む会の報告", "メンタルヘルスの研修報告", "PDFを1箱分印刷", "10時に報告を出したい"]:
        normal_result = ask_chat(ippan_a2_client, normal_text)
        check("J: ふつうの文（%s）を緊急・心の不調としない" % normal_text, normal_result["emergency"] is False and normal_result["distress"] == "", normal_result.get("result"))
    for distress_row in database.execute("SELECT id FROM faq_chat_logs WHERE distress_level IS NOT NULL AND handled_at IS NULL").fetchall():
        post_form(admin_client, "/admin/faq_logs.php", {"action": "distress_handled", "log_id": str(distress_row[0])})
    _status_code, _location, sensitive_log_html = admin_client.get("/admin/faq_logs.php")
    check("J: 記録の画面には、要配慮の文の中身を出さない（管理者にも）", "持病がある" not in sensitive_log_html and "要配慮の内容のため表示しません" in sensitive_log_html)
    check("J: 要配慮の記録からは「よくある質問に載せる」を開けない（404）", admin_client.get("/faq.php?draft_log=%d" % sensitive_result["log_id"])[0] == 404)

    # ---------------- B 返信の定型文 / D 対応の予定 / C 重複をまとめる / E CSV ----------------
    status_code, _location, _page_html = post_form(admin_client, "/requests/index.php", {"action": "template_add", "template_kind": "", "template_body": "ユーザーを追加しました。仮のパスワードは別にお伝えします。"})
    post_form(admin_client, "/requests/index.php", {"action": "template_add", "template_kind": "bug", "template_body": "不具合を直しました。もう一度お試しください。"})
    check("B: 管理者が返信の定型文を種類ごとに足せる", database.execute("SELECT COUNT(*) FROM support_reply_templates").fetchone()[0] == 2, status_code)
    kacho_request_id = create_request(kacho_client, "提出状況を色分けしてほしい")
    leader_request_id = create_request(leader_client, "提出状況の色を分けてほしい")
    _status_code, _location, admin_view_html = admin_client.get("/requests/view.php?id=%d" % kacho_request_id)
    check("B: 返信の欄で定型文を選べる（すべての種類の定型文）", "support-reply-template-select" in admin_view_html and "ユーザーを追加しました" in admin_view_html)
    check("B: 要望の種類と違う種類の定型文は出さない（改善要望に不具合の定型文）", "不具合を直しました" not in admin_view_html)

    status_code, _location, _page_html = post_form(admin_client, "/requests/view.php?id=%d" % kacho_request_id, {"action": "plan_save", "plan_text": "2026年12月にリリース予定"})
    _status_code, _location, kacho_view_html = kacho_client.get("/requests/view.php?id=%d" % kacho_request_id)
    check("D: 対応の予定を出した人に見せる", "2026年12月にリリース予定" in kacho_view_html, status_code)
    _status_code, _location, kacho_list_html = kacho_client.get("/requests/index.php?status=all")
    check("D: 一覧にも対応の予定", "2026年12月にリリース予定" in kacho_list_html)

    status_code, _location, _page_html = post_form(admin_client, "/requests/view.php?id=%d" % leader_request_id, {"action": "merge", "merge_into": str(kacho_request_id)})
    merged_row = database.execute("SELECT status, merged_into_id FROM support_requests WHERE id = ?", (leader_request_id,)).fetchone()
    check("C: 同じ内容の要望にまとめて閉じる", tuple(merged_row) == ("done", kacho_request_id), merged_row)
    status_code, _location, error_html = post_form(admin_client, "/requests/view.php?id=%d" % kacho_request_id, {"action": "merge", "merge_into": str(kacho_request_id)})
    check("C: 自分自身にはまとめられない", status_code == 200 and "まとめる先の番号が正しくありません" in error_html, status_code)
    leader_client.get("/requests/view.php?id=%d" % leader_request_id)   # まとめた知らせは見たことにする
    post_form(admin_client, "/requests/view.php?id=%d" % kacho_request_id, {"action": "staff_reply", "body": "提出状況の札を色分けしました。", "status": "released"})
    _status_code, _location, leader_home_html = leader_client.get("/index.php")
    check("C: まとめた先の返信が、まとめた要望の人にも届く（ホームに回答あり）", "要望・依頼にシステム管理者から回答があります" in leader_home_html)
    _status_code, _location, leader_view_html = leader_client.get("/requests/view.php?id=%d" % leader_request_id)
    check("C: まとめた要望に、まとめた先の返信の中身が入る", "提出状況の札を色分けしました。" in leader_view_html and "同じ内容の要望 #%d での返信" % kacho_request_id in leader_view_html)
    check("C: 出した人には、まとめた先の要望の中身は開けない", leader_client.get("/requests/view.php?id=%d" % kacho_request_id)[0] == 403)
    _status_code, _location, admin_view_html = admin_client.get("/requests/view.php?id=%d" % kacho_request_id)
    check("A: リリース済みの要望から、お知らせを書く画面へ移れる", "/admin/notices.php?request=%d" % kacho_request_id in admin_view_html)

    status_code, _location, _csv_text = admin_client.get("/requests/index.php?status=all&csv=1")
    csv_text = admin_client.last_raw.decode("utf-8-sig")
    check("E: 管理者が要望・依頼の一覧を CSV に出せる（件名と状態）", status_code == 200 and "要望・依頼の一覧" in csv_text and "提出状況を色分けしてほしい" in csv_text and "の内容" not in csv_text, status_code)
    status_code, _location, _csv_text = admin_client.get("/requests/index.php?status=all&csv=1&body=1")
    check("E: 内容も含めて出せる", "提出状況を色分けしてほしい の内容" in admin_client.last_raw.decode("utf-8-sig"))
    check("E: 出力を記録する", database.execute("SELECT COUNT(*) FROM access_logs WHERE action = 'export' AND target LIKE 'export:support_requests%'").fetchone()[0] == 2)
    check("E: 課長は CSV に出せない", kacho_client.get("/requests/index.php?status=all&csv=1")[0] == 403)

    # ---------------- G 組織の改訂の予約 ----------------
    def preview_csv(csv_body_text):
        _status_code, _location, bulk_html = admin_client.get("/admin/org.php?tab=bulk")
        csv_bytes = ("ログイン ID,表示名,所属の課,班,役割,担当の班,項目管理者,有効\r\n" + csv_body_text).encode("utf-8")
        _status_code, _location, preview_html = admin_client.post_file("/admin/org.php?tab=bulk", {"csrf_token": csrf(bulk_html), "action": "bulk_preview"}, "org_csv", "org.csv", csv_bytes)
        token_match = re.search(r'name="plan_token" value="([0-9a-f]+)"', preview_html)
        return token_match.group(1) if token_match else "", preview_html

    plan_token, preview_html = preview_csv("ippan_a2,A一般2,B課,,,,,1\r\n")
    check("G: 取り込んだあと「すぐに反映」と「予約」を選べる", "すぐに反映する" in preview_html and 'name="apply_on"' in preview_html)
    tomorrow_text = (today + datetime.timedelta(days=1)).isoformat()
    status_code, _location, _page_html = admin_client.post("/admin/org.php?tab=bulk", {
        "csrf_token": csrf(preview_html), "request_key": os.urandom(16).hex(), "action": "bulk_schedule", "plan_token": plan_token, "apply_on": tomorrow_text})
    schedule_row = database.execute("SELECT id, status, apply_on FROM org_schedules ORDER BY id DESC LIMIT 1").fetchone()
    check("G: 明日に反映するよう予約できる", schedule_row is not None and schedule_row[1] == "scheduled" and schedule_row[2] == tomorrow_text, (status_code, schedule_row))
    check("G: 予約しただけでは変わらない", database.execute("SELECT d.name FROM users u JOIN departments d ON d.id = u.department_id WHERE u.login_id = 'ippan_a2'").fetchone()[0] == "A課")
    database.execute("UPDATE org_schedules SET apply_on = ? WHERE id = ?", (today.isoformat(), schedule_row[0]))   # 日が来たことにする
    member_client.get("/index.php")
    check("G: 予約した日が来ると、画面の表示で反映される", database.execute("SELECT d.name FROM users u JOIN departments d ON d.id = u.department_id WHERE u.login_id = 'ippan_a2'").fetchone()[0] == "B課"
          and database.execute("SELECT status FROM org_schedules WHERE id = ?", (schedule_row[0],)).fetchone()[0] == "applied")
    database.execute("INSERT INTO org_schedules (apply_on, csv_text, change_count, status, created_by, created_at) VALUES (?, ?, 1, 'scheduled', ?, datetime('now'))",
                     (today.isoformat(), "ログイン ID,表示名,所属の課,班,役割,担当の班,項目管理者,有効\r\nippan_a2,A一般2,無い課,,,,,1\r\n", user_info_by_login_id["admin01"]["id"]))
    member_client.get("/index.php")
    check("G: その時点で誤りがあれば反映せず「失敗」にする", database.execute("SELECT status FROM org_schedules ORDER BY id DESC LIMIT 1").fetchone()[0] == "failed")
    _status_code, _location, admin_top_html = admin_client.get("/admin/index.php")
    check("G: 管理のトップに、反映できなかった予約を知らせる", "反映できなかった予約" in admin_top_html)
    plan_token, preview_html = preview_csv("ippan_a1,A一般1,B課,,,,,1\r\n")
    admin_client.post("/admin/org.php?tab=bulk", {"csrf_token": csrf(preview_html), "request_key": os.urandom(16).hex(), "action": "bulk_schedule", "plan_token": plan_token, "apply_on": tomorrow_text})
    cancel_schedule_id = database.execute("SELECT id FROM org_schedules WHERE status = 'scheduled' ORDER BY id DESC LIMIT 1").fetchone()[0]
    post_form(admin_client, "/admin/org.php?tab=bulk", {"action": "schedule_cancel", "schedule_id": str(cancel_schedule_id)})
    check("G: 予約を取り消せる", database.execute("SELECT status FROM org_schedules WHERE id = ?", (cancel_schedule_id,)).fetchone()[0] == "cancelled")

    # ---------------- H バックアップの状態 ----------------
    check("H: 管理のトップに DB の大きさと最後のバックアップ", "DB の大きさ" in admin_top_html and "最後のバックアップ" in admin_top_html)
    _status_code, _location, kacho_top_html = kacho_client.get("/admin/index.php")
    check("H: 課長の管理のトップには出さない", "DB の大きさ" not in kacho_top_html)

    # ---------------- F メンテナンス中 ----------------
    until_text = (datetime.datetime.now() + datetime.timedelta(hours=1)).strftime("%Y-%m-%dT%H:%M")
    status_code, _location, _page_html = post_form(admin_client, "/admin/index.php", {"action": "maintenance_start", "until": until_text, "message": "新しい版に入れ替えています"})
    check("F: 管理者がメンテナンス中にできる", database.execute("SELECT COUNT(*) FROM app_state WHERE name = 'maintenance'").fetchone()[0] == 1, status_code)
    _status_code, _location, member_home_html = member_client.get("/index.php")
    check("F: 部下の画面に知らせを出す（一言も）", "メンテナンス中です" in member_home_html and "新しい版に入れ替えています" in member_home_html and 'data-maintenance="1"' in member_home_html)
    status_code, _location, _page_html = post_form(member_client, "/account.php?tab=theme", {"action": "theme_save", "ui_theme": "dark"})
    check("F: 部下の保存などの操作は受け付けない（503）", status_code == 503
          and database.execute("SELECT ui_theme FROM users WHERE login_id = 'ippan_a1'").fetchone()[0] != "dark", status_code)
    _status_code, _location, admin_home_html = admin_client.get("/index.php")
    check("F: 管理者の画面は止めない（知らせだけ）", 'data-maintenance="1"' not in admin_home_html and "メンテナンス中です（管理者・補佐の画面）" in admin_home_html)
    status_code, _location, _page_html = post_form(admin_client, "/admin/index.php", {"action": "maintenance_end"})
    check("F: メンテナンスを終えられる", database.execute("SELECT COUNT(*) FROM app_state WHERE name = 'maintenance'").fetchone()[0] == 0, status_code)
    status_code, _location, _page_html = post_form(member_client, "/account.php?tab=theme", {"action": "theme_save", "ui_theme": "dark"})
    check("F: 終えたあとは部下も保存できる", database.execute("SELECT ui_theme FROM users WHERE login_id = 'ippan_a1'").fetchone()[0] == "dark", status_code)
    database.execute("INSERT INTO app_state (name, value) VALUES ('maintenance', ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value",
                     (json.dumps({"until": "2000-01-01 00:00:00", "message": "", "started_by": 1, "started_at": "2000-01-01 00:00:00"}),))
    status_code, _location, _page_html = post_form(member_client, "/account.php?tab=theme", {"action": "theme_save", "ui_theme": "light"})
    check("F: 終わる日時を過ぎたら自動で終わる", database.execute("SELECT ui_theme FROM users WHERE login_id = 'ippan_a1'").fetchone()[0] == "light", status_code)
    return summary()


if __name__ == "__main__":
    sys.exit(main())
