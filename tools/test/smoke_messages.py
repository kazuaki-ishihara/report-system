#!/usr/bin/env python3
"""メッセージ（G-17）の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

課長だけが文言を変えられること、変えた文言が画面・エラー画面・JavaScript に出ること、
差し込みや空の確かめ、既定に戻す、使われていない文言の削除、G-01・G-02 の文言が対象外であることを確かめる。

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_messages.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import datetime
import html
import json
import os
import re
import sqlite3
import sys
import urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, csrf, login, rkey, summary  # noqa: E402


def main():
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--base", default="http://127.0.0.1:8000")
    argument_parser.add_argument("--db", required=True)
    arguments = argument_parser.parse_args()
    test_dir = os.path.dirname(os.path.abspath(__file__))
    seed_info = json.load(open(os.path.join(test_dir, "output_dev_users.json"), encoding="utf-8"))
    user_info_by_login_id = seed_info["users"]
    database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)
    catalog_text = open(os.path.join(test_dir, "..", "..", "app", "lib", "messages_catalog.php"), encoding="utf-8").read()
    current_month = datetime.date.today().strftime("%Y-%m")

    def post_message(client, action, default_text, new_text=None, path="/admin/messages.php"):
        """メッセージの画面から送る。戻り値: (HTTP の状態, 転送先, 本文)"""
        status_code, _location, page_html = client.get(path)
        form_values = {"csrf_token": csrf(page_html), "request_key": os.urandom(16).hex(), "action": action, "msg_key": default_text}
        if new_text is not None:
            form_values["body"] = new_text
        return client.post(path, form_values)

    def override_text_of(default_text):
        override_row = database.execute("SELECT body FROM ui_messages WHERE msg_key = ?", (default_text,)).fetchone()
        return override_row[0] if override_row else None

    # ---- 開ける人 ----
    kacho_client = login(arguments.base, "kacho_a", user_info_by_login_id["kacho_a"])
    status_code, _location, page_html = kacho_client.get("/admin/messages.php")
    check("課長はメッセージの画面を開ける", status_code == 200 and "画面に出す文言" in page_html, status_code)
    check("課長のメニューに「メッセージ」", "/admin/messages.php" in kacho_client.get("/index.php")[2])
    for login_id, role_name in [("ippan_a1", "部下"), ("leader_a", "確認者"), ("admin01", "管理者（課長でない）")]:
        other_client = login(arguments.base, login_id, user_info_by_login_id[login_id])
        status_code, _location, _page_html = other_client.get("/admin/messages.php")
        check("%sは開けない（403）" % role_name, status_code == 403, status_code)
        check("%sのメニューに「メッセージ」が出ない" % role_name, "/admin/messages.php" not in other_client.get("/index.php")[2])
    admin_client = login(arguments.base, "admin01", user_info_by_login_id["admin01"])
    status_code, _location, _page_html = post_message(admin_client, "save", "下書きを保存しました", "変えてみる")
    check("管理者（課長でない）は送っても変えられない（403）", status_code == 403 and override_text_of("下書きを保存しました") is None, status_code)

    # ---- 変えた文言が画面に出る ----
    status_code, location, _page_html = post_message(kacho_client, "save", "下書きを保存しました", "下書きを保存しました（検証）")
    check("課長は文言を変えられる", status_code == 303 and override_text_of("下書きを保存しました") == "下書きを保存しました（検証）", (status_code, location))
    member_client = login(arguments.base, "ippan_a1", user_info_by_login_id["ippan_a1"])
    status_code, _location, edit_html = member_client.get("/reports/edit.php?period=" + current_month)
    member_client.post("/reports/save.php", {"csrf_token": csrf(edit_html), "request_key": rkey(edit_html), "period": current_month, "action": "draft"})
    status_code, _location, edit_html = member_client.get("/reports/edit.php?period=" + current_month)
    check("変えた文言がほかの課の人の画面に出る", "下書きを保存しました（検証）" in edit_html)

    post_message(kacho_client, "save", "対象のデータが見つかりません。", "見つかりませんでした（検証）。")
    status_code, _location, error_html = member_client.get("/reports/view.php?id=999999")
    check("エラー画面にも変えた文言が出る", status_code == 404 and "見つかりませんでした（検証）。" in error_html, status_code)

    post_message(kacho_client, "save", "自動保存しました {time}", "{time} に自動保存しました")
    home_html = member_client.get("/index.php")[2]
    body_messages_match = re.search(r'<body data-msgs="([^"]*)"', home_html)
    body_messages = json.loads(html.unescape(body_messages_match.group(1))) if body_messages_match else {}
    check("JavaScript の文言にも変えた文言が渡る", body_messages.get("自動保存しました {time}") == "{time} に自動保存しました", body_messages.get("自動保存しました {time}"))

    # ---- 確かめ ----
    status_code, _location, page_html = post_message(kacho_client, "save", "{label}を入れてください", "入力してください")
    check("差し込みを消すと保存できない", status_code == 200 and "そのまま残してください" in page_html and override_text_of("{label}を入れてください") is None, status_code)
    status_code, _location, page_html = post_message(kacho_client, "save", "{label}を入れてください", "{label}を {other} 入れてください")
    check("差し込みを足すと保存できない", "そのまま残してください" in page_html and override_text_of("{label}を入れてください") is None)
    status_code, _location, page_html = post_message(kacho_client, "save", "下書きを削除しました", "")
    check("空では保存できない", "文言を入れてください" in page_html and override_text_of("下書きを削除しました") is None)
    status_code, _location, page_html = post_message(kacho_client, "save", "下書きを削除しました", "あ" * 501)
    check("500 文字を超えると保存できない", "500 文字以内" in page_html and override_text_of("下書きを削除しました") is None)
    post_message(kacho_client, "save", "下書きを削除しました", "1 行目\r\n2 行目")
    check("改行を入れられる（LF にそろえる）", override_text_of("下書きを削除しました") == "1 行目\n2 行目", override_text_of("下書きを削除しました"))
    status_code, _location, edit_html = member_client.get("/reports/edit.php?period=" + current_month)
    member_client.post("/reports/save.php", {"csrf_token": csrf(edit_html), "request_key": rkey(edit_html), "period": current_month, "action": "delete"})
    status_code, _location, home_html = member_client.get("/index.php")
    check("改行した文言は、画面で改行して出る", "1 行目<br>" in home_html, status_code)
    post_message(kacho_client, "reset", "下書きを削除しました")
    status_code, _location, _page_html = post_message(kacho_client, "save", "一覧にない文言", "x")
    check("一覧にない文言は変えられない（404）", status_code == 404, status_code)
    post_message(kacho_client, "save", "{label}を入れてください", "{label}は必須です")
    check("差し込みを残せば保存できる", override_text_of("{label}を入れてください") == "{label}は必須です")
    post_message(kacho_client, "save", "下書きを削除しました", "下書きを削除しました")
    check("既定と同じ文言で保存すると、変えた記録を残さない", override_text_of("下書きを削除しました") is None)

    # ---- 既定に戻す・使われていない文言 ----
    post_message(kacho_client, "reset", "下書きを保存しました")
    check("既定に戻せる", override_text_of("下書きを保存しました") is None)
    database.execute("INSERT INTO ui_messages (msg_key, body, updated_by, updated_at) VALUES ('昔の文言', '昔の変更', ?, '2026-01-01 00:00:00')",
                     (user_info_by_login_id["kacho_a"]["id"],))
    status_code, _location, page_html = kacho_client.get("/admin/messages.php")
    check("使われていない文言が出る", "使われていない文言" in page_html and "昔の変更" in page_html)
    status_code, _location, _page_html = post_message(kacho_client, "delete_unused", "下書きを保存しました（なし）")
    check("変えていない文言は消せない（404）", status_code == 404, status_code)
    post_message(kacho_client, "delete_unused", "昔の文言")
    check("使われていない文言を消せる", override_text_of("昔の文言") is None)

    # ---- 絞り込み・記録・対象外 ----
    status_code, _location, page_html = kacho_client.get("/admin/messages.php?changed=1")
    check("「変えた文言だけ」で絞り込める", "見つかりませんでした（検証）。" in page_html and "前月の報告の内容を写しています" not in page_html)
    status_code, _location, page_html = kacho_client.get("/admin/messages.php?q=" + urllib.parse.quote("下書きを削除"))
    check("文言で探せる", "下書きを削除しました" in page_html and "提出しました" not in page_html)
    log_count = database.execute("SELECT COUNT(*) FROM access_logs WHERE action = 'admin' AND target LIKE 'message:%'").fetchone()[0]
    check("変更・戻す・消すが記録に残る", log_count >= 6, log_count)
    check("G-01・G-02 の文言は一覧にない（固定）", "ログイン ID かコードが違います" not in catalog_text and "ログイン ID かコードが違います" not in kacho_client.get("/admin/messages.php")[2])

    return summary()


if __name__ == "__main__":
    sys.exit(main())
