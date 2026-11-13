#!/usr/bin/env python3
"""要望・依頼（G-22）の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。K-044

- 課長・確認者が出し、管理者・補佐が対応した内容・検討の結果を返信して状態を変える
- 状態は未受付・対応検討中・対応中・検証中・リリース待ち・リリース済み（ほかに完了・見送り・取り下げ）。ボタンで変え、返信が空なら定型の返信（見送りは返信が必須）
- 各画面の「この画面について要望・依頼を出す」で、画面と開いていた画面（決まった項目だけ）と端末が入る
- 出した人のホームに「回答あり」。開くと消える
- 見られるのは出した本人と管理者・補佐だけ。部下は出せない
- 件名・本文・返信は暗号化して保存する

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_requests.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import json
import os
import re
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, csrf, login, summary  # noqa: E402

REQUEST_TITLE = "提出状況の色分けをしてほしい"
REQUEST_BODY = "提出済みと未作成が同じ色で、ぱっと見で分かりません。\n色を分けてください。"
STAFF_ANSWER = "提出済みを緑、未作成を灰色に分けました。次の更新で反映します。"


def main():
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--base", default="http://127.0.0.1:8000")
    argument_parser.add_argument("--db", required=True)
    arguments = argument_parser.parse_args()
    test_dir = os.path.dirname(os.path.abspath(__file__))
    user_info_by_login_id = json.load(open(os.path.join(test_dir, "output_dev_users.json"), encoding="utf-8"))["users"]
    database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)

    def post_form(client, path, form_values):
        _status_code, _location, page_html = client.get(path)
        form_values = dict(form_values, csrf_token=csrf(page_html), request_key=os.urandom(16).hex())
        return client.post(path, form_values)

    def create_request(client, title_text, body_text, kind="improvement", extra_form_values=None):
        form_values = {"action": "create", "kind": kind, "title": title_text, "body": body_text, "screen_code": "G-08", "urgency": "normal"}
        form_values.update(extra_form_values or {})
        status_code, location, page_html = post_form(client, "/requests/index.php", form_values)
        found = re.search(r"/requests/view\.php\?id=(\d+)", location or "")
        return status_code, (int(found.group(1)) if found else 0), page_html

    kacho_client = login(arguments.base, "kacho_a", user_info_by_login_id["kacho_a"])
    admin_client = login(arguments.base, "admin01", user_info_by_login_id["admin01"])
    member_client = login(arguments.base, "ippan_a1", user_info_by_login_id["ippan_a1"])
    leader_client = login(arguments.base, "leader_a", user_info_by_login_id["leader_a"])
    deputy_client = login(arguments.base, "hosa01", user_info_by_login_id["hosa01"])

    # ---- 出す ----
    _status_code, _location, kacho_home_html = kacho_client.get("/index.php")
    check("課長のメニューに「要望・依頼」", "/requests/index.php" in kacho_home_html)
    status_code, request_id, _page_html = create_request(kacho_client, REQUEST_TITLE, REQUEST_BODY)
    check("課長が要望・依頼を出せる", status_code in (302, 303) and request_id > 0, status_code)
    stored_row = database.execute("SELECT title_enc, body_enc, status, department_id, kind FROM support_requests WHERE id = ?", (request_id,)).fetchone()
    check("件名・本文は暗号化して保存する", stored_row is not None and REQUEST_TITLE not in stored_row[0] and "色を分けて" not in stored_row[1] and stored_row[0] != "")
    check("出した時点は「未受付」、出した人の課を残す", stored_row is not None and stored_row[2] == "open" and stored_row[3] is not None)
    client_text = database.execute("SELECT client_text FROM support_requests WHERE id = ?", (request_id,)).fetchone()[0]
    check("出したときの端末（PC とブラウザー）を残す", client_text.startswith("PC（"), client_text)

    # 各画面の「この画面について要望・依頼を出す」
    _status_code, _location, status_page_html = kacho_client.get("/status/index.php?period=2026-01&filter=all")
    link_found = re.search(r'href="([^"]*/requests/index\.php\?screen=G-08[^"]*)"', status_page_html)
    check("提出状況の画面に「この画面について要望・依頼を出す」（画面 G-08）", link_found is not None and "この画面について要望・依頼を出す" in status_page_html)
    _status_code, _location, linked_form_html = kacho_client.get("/requests/index.php?screen=G-08&from=" + "/status/index.php%3Fperiod%3D2026-01%26q%3D%E6%A0%AA%E5%BC%8F%E4%BC%9A%E7%A4%BE")
    check("リンクから来ると、画面のボタンが選ばれ、新しく出す欄が開いている",
          re.search(r'value="G-08" checked', linked_form_html) is not None and re.search(r'<details class="support-request-new" id="new" open', linked_form_html) is not None)
    check("開いていた画面は決まった項目だけ残す（自由な文字は落とす）",
          'name="source_path" value="/status/index.php?period=2026-01"' in linked_form_html)
    status_code, linked_request_id, _page_html = create_request(kacho_client, "提出状況の並び順", "名前の順でなく、未提出を先にしてほしい。",
                                                                extra_form_values={"screen_code": "system", "source_path": "/status/index.php?period=2026-01&q=secret"})
    linked_row = database.execute("SELECT screen_code, source_path FROM support_requests WHERE id = ?", (linked_request_id,)).fetchone()
    check("全体的な要望をボタンで選べ、開いていた画面を残す", linked_row is not None and tuple(linked_row) == ("system", "/status/index.php?period=2026-01"), linked_row)
    _status_code, _location, member_home_html = member_client.get("/index.php")
    check("部下の画面には「この画面について要望・依頼を出す」を出さない", "この画面について要望・依頼を出す" not in member_home_html)
    status_code, _request_id, error_html = create_request(kacho_client, "", "")
    check("件名・内容が空なら出せない", status_code == 200 and "件名を入れてください" in error_html, status_code)
    status_code, _location, _page_html = member_client.get("/requests/index.php")
    check("部下は要望・依頼の画面を開けない（課長を通す）", status_code == 403, status_code)
    status_code, leader_request_id, _page_html = create_request(leader_client, "コメントの定型文を増やしたい", "30 件では足りません。")
    check("確認者（リーダー）も出せる", status_code in (302, 303) and leader_request_id > 0, status_code)
    status_code, _location, _page_html = leader_client.get("/requests/view.php?id=%d" % request_id)
    check("ほかの人が出した要望・依頼は見られない", status_code == 403, status_code)
    status_code, _location, _page_html = member_client.get("/requests/view.php?id=%d" % request_id)
    check("部下も見られない", status_code == 403, status_code)

    # ---- 管理者側が受ける ----
    _status_code, _location, admin_home_html = admin_client.get("/index.php")
    check("管理者のホームに未受付の数", "要望・依頼: 未受付 3 件" in admin_home_html)
    _status_code, _location, admin_top_html = admin_client.get("/admin/index.php")
    check("管理のトップに要望・依頼のカード", "未受付 3 件" in admin_top_html and "/requests/index.php" in admin_top_html)
    _status_code, _location, admin_list_html = admin_client.get("/requests/index.php")
    check("管理者の一覧に全員の要望・依頼（件名・出した人）", REQUEST_TITLE in admin_list_html and "A課長" in admin_list_html and "Aリーダー" in admin_list_html)
    status_code, _location, deputy_list_html = deputy_client.get("/requests/index.php")
    check("補佐も一覧を開ける（代行）", status_code == 200 and REQUEST_TITLE in deputy_list_html, status_code)

    _status_code, _location, staff_view_html = admin_client.get("/requests/view.php?id=%d" % request_id)
    check("管理者側の詳細に状態のボタン（未受付〜リリース済み）", all(
        'name="status" value="%s"' % status_code_text in staff_view_html for status_code_text in ["reviewing", "in_progress", "testing", "release_waiting", "released", "declined"]))
    check("管理者側の詳細に端末", "PC（" in staff_view_html)
    status_code, _location, error_html = post_form(admin_client, "/requests/view.php?id=%d" % request_id, {"action": "staff_reply", "body": "", "status": "declined"})
    check("見送りにするときは返信が必須", status_code == 200 and "見送りにするときは、検討の結果を返信に書いてください" in error_html, status_code)
    status_code, _location, error_html = post_form(admin_client, "/requests/view.php?id=%d" % request_id, {"action": "staff_reply", "body": "", "status": ""})
    check("返信も状態もなければ送れない", status_code == 200 and "返信を入れるか、状態のボタンを押してください" in error_html, status_code)
    status_code, _location, _page_html = post_form(admin_client, "/requests/view.php?id=%d" % request_id, {"action": "staff_reply", "body": "", "status": "reviewing"})
    check("ボタンだけで「対応検討中」にできる（定型の返信を送る）", status_code in (302, 303)
          and database.execute("SELECT status FROM support_requests WHERE id = ?", (request_id,)).fetchone()[0] == "reviewing"
          and database.execute("SELECT COUNT(*) FROM support_request_messages WHERE request_id = ? AND is_staff = 1 AND body_enc <> ''", (request_id,)).fetchone()[0] == 1, status_code)
    _status_code, _location, kacho_view_html = kacho_client.get("/requests/view.php?id=%d" % request_id)
    check("出した人に定型の返信が届く", "ご要望を受け付けました。対応するかどうかを検討しています。" in kacho_view_html)
    for next_status_code in ["in_progress", "testing", "release_waiting"]:
        post_form(admin_client, "/requests/view.php?id=%d" % request_id, {"action": "staff_reply", "body": "", "status": next_status_code})
    check("対応中 → 検証中 → リリース待ちと進められる", database.execute("SELECT status, closed_at FROM support_requests WHERE id = ?", (request_id,)).fetchone() == ("release_waiting", None))

    # 出した人の補足 → 管理者側に「返信待ち」
    status_code, _location, _page_html = post_form(kacho_client, "/requests/view.php?id=%d" % request_id, {"action": "reply", "body": "注意の札も別の色にしてください。"})
    check("出した人が補足を返信できる", status_code in (302, 303), status_code)
    _status_code, _location, admin_home_html = admin_client.get("/index.php")
    check("管理者のホームに返信待ちの数", "返信待ち 1 件" in admin_home_html)

    status_code, _location, _page_html = post_form(admin_client, "/requests/view.php?id=%d" % request_id, {"action": "staff_reply", "body": STAFF_ANSWER, "status": "released"})
    closed_row = database.execute("SELECT status, closed_at FROM support_requests WHERE id = ?", (request_id,)).fetchone()
    check("対応した内容を返信して「リリース済み」にできる（閉じる）", status_code in (302, 303) and closed_row[0] == "released" and closed_row[1] is not None, (status_code, closed_row))
    message_body_list = [row[0] for row in database.execute("SELECT body_enc FROM support_request_messages WHERE request_id = ?", (request_id,))]
    check("返信も暗号化して保存する", message_body_list and all(STAFF_ANSWER not in body_text for body_text in message_body_list))
    check("状態の変更を記録する", database.execute("SELECT COUNT(*) FROM access_logs WHERE target = ?", ("support_request:%d:status_released" % request_id,)).fetchone()[0] == 1)

    # ---- 出した人が返信を受け取る ----
    _status_code, _location, kacho_home_html = kacho_client.get("/index.php")
    check("出した人のホームに「回答があります」", "要望・依頼にシステム管理者から回答があります（1 件）" in kacho_home_html, re.findall(r"回答があります（\d+ 件）", kacho_home_html))
    _status_code, _location, kacho_list_html = kacho_client.get("/requests/index.php?status=all")
    check("出した人の一覧に「回答あり」と「リリース済み」", "回答あり" in kacho_list_html and "リリース済み" in kacho_list_html)
    _status_code, _location, kacho_view_html = kacho_client.get("/requests/view.php?id=%d" % request_id)
    check("出した人が対応の内容（返信）を読める", STAFF_ANSWER in kacho_view_html and "システム管理者" in kacho_view_html)
    _status_code, _location, kacho_home_html = kacho_client.get("/index.php")
    check("開いたあとはホームの「回答あり」が消える", "回答があります" not in kacho_home_html)
    status_code, _location, _page_html = post_form(kacho_client, "/requests/view.php?id=%d" % request_id, {"action": "reply", "body": "ありがとうございます"})
    check("閉じた要望・依頼には返信できない", status_code in (302, 303) and database.execute(
        "SELECT COUNT(*) FROM support_request_messages WHERE request_id = ? AND is_staff = 0", (request_id,)).fetchone()[0] == 1, status_code)
    status_code, _location, _page_html = post_form(kacho_client, "/requests/view.php?id=%d" % request_id, {"action": "staff_reply", "body": "x", "status": "open"})
    check("出した人は管理者側の返信・状態の変更ができない", status_code == 403, status_code)

    # ---- 見送り・取り下げ ----
    status_code, _location, _page_html = post_form(deputy_client, "/requests/view.php?id=%d" % leader_request_id,
                                                   {"action": "staff_reply", "body": "件数の上限は今のままにします（検討の結果）。", "status": "declined"})
    check("補佐が検討の結果を返信して「見送り」にできる（代行）", status_code in (302, 303)
          and database.execute("SELECT status FROM support_requests WHERE id = ?", (leader_request_id,)).fetchone()[0] == "declined", status_code)
    status_code, withdraw_request_id, _page_html = create_request(kacho_client, "取り下げる要望", "まちがえて出しました。", kind="question")
    status_code, _location, _page_html = post_form(kacho_client, "/requests/view.php?id=%d" % withdraw_request_id, {"action": "withdraw", "body": ""})
    check("未受付のうちは取り下げられる", status_code in (302, 303)
          and database.execute("SELECT status FROM support_requests WHERE id = ?", (withdraw_request_id,)).fetchone()[0] == "withdrawn", status_code)
    status_code, _location, _page_html = post_form(admin_client, "/requests/view.php?id=%d" % withdraw_request_id, {"action": "staff_reply", "body": "確認しました", "status": "in_progress"})
    check("取り下げたものは管理者側も変えられない", database.execute("SELECT status FROM support_requests WHERE id = ?", (withdraw_request_id,)).fetchone()[0] == "withdrawn", status_code)
    return summary()


if __name__ == "__main__":
    sys.exit(main())
