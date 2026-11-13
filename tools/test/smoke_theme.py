#!/usr/bin/env python3
"""自分の色（G-16 §4.6。K-046）の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

- 土台・強調の色・背景の色を選んで保存でき、その人の画面にだけ自分の色の CSS が付く
- 文字が読みにくい組み合わせは、保存したうえで注意を出す（K-046 決定）。色の形が違うものは保存しない
- 自分の色の CSS は、ログインしている本人の色だけを返す

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_theme.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import json
import os
import re
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import Client, check, csrf, login, summary  # noqa: E402


def main():
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--base", default="http://127.0.0.1:8000")
    argument_parser.add_argument("--db", required=True)
    arguments = argument_parser.parse_args()
    test_dir = os.path.dirname(os.path.abspath(__file__))
    user_info_by_login_id = json.load(open(os.path.join(test_dir, "output_dev_users.json"), encoding="utf-8"))["users"]
    database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)

    def save_theme(client, form_values):
        _status_code, _location, page_html = client.get("/account.php?tab=theme")
        return client.post("/account.php", dict(form_values, csrf_token=csrf(page_html), action="theme_save"))

    member_client = login(arguments.base, "ippan_a1", user_info_by_login_id["ippan_a1"])
    other_client = login(arguments.base, "ippan_a2", user_info_by_login_id["ippan_a2"])

    _status_code, _location, theme_html = member_client.get("/account.php?tab=theme")
    check("テーマに「自分の色」がある", 'value="custom"' in theme_html and 'id="custom_accent"' in theme_html and 'id="custom_background"' in theme_html)

    # 読みにくい組み合わせ（明るい土台に、黒に近い背景）は、保存したうえで注意を出す（K-046 決定）
    status_code, location, _page_html = save_theme(member_client, {"ui_theme": "custom", "custom_base": "light", "custom_accent": "#1f6feb", "custom_background": "#111111"})
    user_row = database.execute("SELECT ui_theme, ui_custom_colors FROM users WHERE login_id = 'ippan_a1'").fetchone()
    check("読みにくい組み合わせでも保存できる", user_row[0] == "custom" and json.loads(user_row[1] or "{}").get("background") == "#111111", user_row)
    _status_code, _location, theme_html = member_client.get("/account.php?tab=theme")
    check("読みにくい組み合わせのときは注意を出す", "保存しましたが、読みにくい組み合わせです。" in theme_html and "文字が読みにくくなります" in theme_html)
    # 色の形が違うものは保存しない
    save_theme(member_client, {"ui_theme": "light"})
    status_code, location, _page_html = save_theme(member_client, {"ui_theme": "custom", "custom_base": "light", "custom_accent": "javascript:alert(1)", "custom_background": "#ffffff"})
    check("色の形が違えば保存しない", database.execute("SELECT ui_theme FROM users WHERE login_id = 'ippan_a1'").fetchone()[0] == "light")
    _status_code, _location, theme_html = member_client.get("/account.php?tab=theme")
    check("色の形が違うときは理由を出す", "強調の色が正しくありません" in theme_html)

    # 読める組み合わせは保存できる
    status_code, location, _page_html = save_theme(member_client, {"ui_theme": "custom", "custom_base": "dark", "custom_accent": "#58a6ff", "custom_background": "#0b1d2a"})
    user_row = database.execute("SELECT ui_theme, ui_custom_colors FROM users WHERE login_id = 'ippan_a1'").fetchone()
    stored_colors = json.loads(user_row[1] or "{}")
    check("自分の色を保存できる（暗い土台・強調・背景）", user_row[0] == "custom" and stored_colors == {"base": "dark", "accent": "#58a6ff", "background": "#0b1d2a"}, user_row)
    _status_code, _location, home_html = member_client.get("/index.php")
    stylesheet_match = re.search(r'href="([^"]*/css/user_theme\.php\?v=[0-9a-f]+)"', home_html)
    check("自分の色の人の画面は、暗い土台に自分の色の CSS を重ねる",
          'data-theme="dark"' in home_html and 'data-custom-colors="1"' in home_html and stylesheet_match is not None)
    status_code, _location, css_text = member_client.get("/css/user_theme.php?v=x")
    check("自分の色の CSS は本人の色を返す", status_code == 200 and "--primary: #58a6ff" in css_text and "--bg: #0b1d2a" in css_text, css_text[:120])
    check("ボタンの文字の色は、強調の色に合わせて白か黒", "--primary-text: #000000" in css_text or "--primary-text: #ffffff" in css_text)
    status_code, _location, other_css_text = other_client.get("/css/user_theme.php?v=x")
    check("ほかの人（自分の色を使っていない人）には色を返さない", "--primary" not in other_css_text)
    anonymous_client = Client(arguments.base)
    status_code, _location, anonymous_css_text = anonymous_client.get("/css/user_theme.php?v=x")
    check("ログインしていなければ色を返さない", "--primary" not in anonymous_css_text)
    _status_code, _location, other_home_html = other_client.get("/index.php")
    check("ほかの人の画面には自分の色の CSS を付けない", "user_theme.php" not in other_home_html and "data-custom-colors" not in other_home_html)

    # ほかのテーマに戻すと、自分の色は使わない（選んだ色は残る）
    save_theme(member_client, {"ui_theme": "sakura"})
    _status_code, _location, home_html = member_client.get("/index.php")
    check("ほかのテーマに戻すと、自分の色の CSS を付けない", 'data-theme="sakura"' in home_html and "user_theme.php" not in home_html)
    check("選んだ自分の色は残る（もう一度選べる）", json.loads(database.execute("SELECT ui_custom_colors FROM users WHERE login_id = 'ippan_a1'").fetchone()[0])["accent"] == "#58a6ff")
    return summary()


if __name__ == "__main__":
    sys.exit(main())
