#!/usr/bin/env python3
"""メニューの「ログアウト」が、いろいろな画面の大きさで押せるかを確かめる（ローカル検証環境専用）。

他の端末で「ippan_b1 の端末で、メニューのログアウトが画面内に収まらず押せない」と指摘があったため（2026-11-16。11-18 に確かめ）。
確かめ用の DB（app/data/menu_check.sqlite）にテストデータを作り、別の番号のサーバーを立て、
スマートフォンの縦・横、小さい PC、拡大表示の PC の大きさで、役割ごとにメニューを開いて、
メニューの中をスクロールしたあとのログアウトのボタンが画面の中にあり、その位置を押すとボタンに当たるかを見る。
テーマ（さくらの花びらなど）がボタンにかぶらないかも、テーマを変えて見る。

    python tools/test/check_menu_logout.py
"""
import json
import os
import socket
import subprocess
import sys
import time
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import lib_http  # noqa: E402
from verify_all import php_server_command  # noqa: E402
from cdp import Browser  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
DB_PATH = os.path.join(ROOT, "app", "data", "menu_check.sqlite")

# 画面の大きさ（CSS の px）: 名前, 幅, 高さ, スマートフォンか
SCREEN_SIZES = [
    ("スマホ縦 320x480（小さい古い機種）", 320, 480, True),
    ("スマホ縦 320x568（iPhone SE 第1世代）", 320, 568, True),
    ("スマホ縦 360x640（Android）", 360, 640, True),
    ("スマホ縦 375x667（iPhone 8・SE）", 375, 667, True),
    ("スマホ縦 390x844（iPhone 13〜15）", 390, 844, True),
    ("スマホ縦 412x915（Android 大きめ）", 412, 915, True),
    ("スマホ横 568x320", 568, 320, True),
    ("スマホ横 640x360", 640, 360, True),
    ("スマホ横 667x375", 667, 375, True),
    ("スマホ横 844x390", 844, 390, True),
    ("PC 1024x600（小さいノート）", 1024, 600, False),
    ("PC 1366x768", 1366, 768, False),
    ("PC 拡大 200%（640x360 相当）", 640, 360, False),
    ("PC 拡大 300%（427x240 相当）", 427, 240, False),
]
ROLES = ["ippan_b1", "kacho_a", "admin01"]
THEMES = ["light", "dark", "sakura"]


def free_port():
    with socket.socket() as probe_socket:
        probe_socket.bind(("127.0.0.1", 0))
        return probe_socket.getsockname()[1]


def find_php():
    php_directory = os.environ.get("MR_PHP_DIR", "")
    if php_directory:
        return os.path.join(php_directory, "php.exe" if os.name == "nt" else "php")
    return "php"


def main():
    php = find_php()
    os.environ["MR_VERIFY_DB"] = DB_PATH
    subprocess.run([php, os.path.join("tools", "test", "dev_seed.php"), "--yes"], cwd=ROOT, check=True, stdout=subprocess.DEVNULL)
    users = json.load(open(os.path.join(ROOT, "tools", "test", "output_dev_users.json"), encoding="utf-8"))["users"]
    port = free_port()
    base = "http://127.0.0.1:%d" % port
    server = subprocess.Popen(php_server_command(php, port), cwd=ROOT,
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    browser = None
    ng_list = []
    checked_count = 0
    try:
        for _attempt in range(50):
            try:
                urllib.request.urlopen(base + "/health.php", timeout=2)
                break
            except Exception:  # noqa: BLE001
                time.sleep(0.2)
        browser = Browser(headless=True)
        for login_id in ROLES:
            browser.call("Emulation.clearDeviceMetricsOverride")
            browser.clear_cookies()
            browser.goto(base + "/login.php")
            browser.fill("#login_id", login_id)
            browser.fill("#password", users[login_id]["password"])
            browser.click("form.form button[type=submit]", navigate=True)
            if browser.exists("#code"):
                browser.fill("#code", lib_http.next_login_code(login_id, users[login_id]["totp"]))
                browser.click("form.form button[type=submit]", navigate=True)
            if "ホーム" not in (browser.eval("document.title") or ""):
                ng_list.append("%s: ログインできない" % login_id)
                continue
            for theme_name in THEMES:
                for size_name, width, height, is_mobile in SCREEN_SIZES:
                    browser.call("Emulation.setDeviceMetricsOverride", {"width": width, "height": height, "deviceScaleFactor": 2 if is_mobile else 1, "mobile": is_mobile})
                    browser.goto(base + "/index.php")
                    browser.eval("document.documentElement.setAttribute('data-theme', '%s'); true" % theme_name)
                    # メニューを開き、ユーザーがメニューの中を下までスクロールしたのと同じにする（ページそのものは動かさない）
                    result = browser.eval("""(function () {
                        var menu = document.querySelector('details.app-menu');
                        var summary = menu.querySelector('summary');
                        summary.click();
                        var nav = menu.querySelector('nav');
                        nav.scrollTop = nav.scrollHeight;
                        var button = nav.querySelector('form button');
                        var rect = button.getBoundingClientRect();
                        var centerX = rect.left + rect.width / 2, centerY = rect.top + rect.height / 2;
                        var hit = document.elementFromPoint(centerX, centerY);
                        return {
                            open: menu.open,
                            inside: rect.top >= 0 && rect.left >= 0 && rect.bottom <= window.innerHeight && rect.right <= window.innerWidth,
                            hitsButton: hit === button,
                            hitName: hit ? (hit.tagName + '.' + hit.className) : '',
                            rect: [Math.round(rect.left), Math.round(rect.top), Math.round(rect.right), Math.round(rect.bottom)],
                            viewport: [window.innerWidth, window.innerHeight],
                            navScrollable: nav.scrollHeight > nav.clientHeight,
                            pageHScroll: document.documentElement.scrollWidth > window.innerWidth + 1
                        };
                    })()""") or {}
                    checked_count += 1
                    if not (result.get("open") and result.get("inside") and result.get("hitsButton")) or result.get("pageHScroll"):
                        ng_list.append("%s / %s / %s: 中に収まる=%s 押すとボタンに当たる=%s（当たったもの %s） ボタンの位置=%s 画面=%s 横のはみ出し=%s" % (
                            login_id, theme_name, size_name, result.get("inside"), result.get("hitsButton"), result.get("hitName"),
                            result.get("rect"), result.get("viewport"), result.get("pageHScroll")))
                    else:
                        print("OK  %s / %s / %s（メニューの中のスクロール %s）" % (login_id, theme_name, size_name, "あり" if result.get("navScrollable") else "なし"))
    finally:
        if browser is not None:
            browser.quit()
        server.terminate()
    for ng_text in ng_list:
        print("NG  " + ng_text)
    print("%s  メニューのログアウト（%d 通り。押せないもの %d）" % ("OK" if not ng_list else "NG", checked_count, len(ng_list)))
    return 0 if not ng_list else 1


if __name__ == "__main__":
    sys.exit(main())
