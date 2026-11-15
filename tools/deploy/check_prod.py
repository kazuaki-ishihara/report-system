#!/usr/bin/env python3
"""反映したあとの外からの確認（画面を見るだけ。ログイン・データの変更はしない）。

    python3 tools/deploy/check_prod.py https://toyama-mai.sakura.ne.jp/report-system

確かめること: HTTPS への転送、ログイン画面とセキュリティヘッダー、Cookie の属性、health.php、
app/ docs/ tools/ や DB・鍵・設定・ソースが Web から取れないこと。
Python 3.8 以上の標準ライブラリだけで動く。
"""
import sys
import urllib.error
import urllib.request

RESULTS = []


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None


OPENER = urllib.request.build_opener(NoRedirect)


def get(url):
    req = urllib.request.Request(url, headers={"User-Agent": "report-system-deploy-check"})
    try:
        resp = OPENER.open(req, timeout=20)
    except urllib.error.HTTPError as e:
        resp = e
    body = resp.read()
    return (getattr(resp, "status", None) or resp.code), resp.headers, body


def check(name, ok, detail=""):
    RESULTS.append(ok)
    print(("OK  " if ok else "NG  ") + name + ("" if ok else "  " + str(detail)))


def main():
    if len(sys.argv) != 2 or not sys.argv[1].startswith("https://"):
        sys.exit("使い方: python3 tools/deploy/check_prod.py https://<ホスト>/report-system")
    base = sys.argv[1].rstrip("/")

    s, h, _ = get("http://" + base[len("https://"):] + "/login.php")
    check("HTTP は HTTPS に転送される", s in (301, 302) and h.get("Location", "").startswith("https://"), s)

    s, h, body = get(base + "/login.php")
    check("ログイン画面が開く", s == 200 and "csrf_token".encode() in body, s)
    for name in ("Content-Security-Policy", "X-Frame-Options", "X-Content-Type-Options", "Referrer-Policy"):
        check("ヘッダー " + name, bool(h.get(name)))
    cookie = h.get("Set-Cookie", "").lower()
    check("Cookie に secure / httponly", "secure" in cookie and "httponly" in cookie, cookie)
    check("画面に PHP のエラーが出ない", not any(w in body for w in (b"Warning:", b"Fatal error", b"Notice:", b"Deprecated:")))

    s, _, body = get(base + "/health.php")
    check("health.php が ok", s == 200 and body.strip() == b"ok", (s, body[:20]))

    s, _, _ = get(base + "/css/app.css")
    check("CSS が取れる", s == 200, s)

    # 取れてはいけないもの（403 か 404 なら良い）
    blocked = [
        "_boot.php", ".htaccess", "app/", "app/.htaccess", "app/bootstrap.php", "app/config/config.php",
        "app/config/config.local.php", "app/data/reports.sqlite", "app/keys/field_v1.key", "app/bin/migrate.php",
        "app/logs/app.log", "docs/", "docs/basic_design.md", "tools/", "tools/test/dev_seed.php",
        "tools/test/output_dev_users.json", "tools/test/__pycache__/lib_http.cpython-314.pyc",
        "public/", "public/login.php", ".git/HEAD", ".gitignore", "data/", "data/reports.sqlite", "reports.sqlite",
        "keys/", "backups/", "logs/", "config/", "lib/",
        "css/", "js/", "reports/",
    ]
    for p in blocked:
        s, _, _ = get(base + "/" + p)
        check("取れない: " + p, s in (403, 404), s)

    ng = RESULTS.count(False)
    print("\n%d 件中 %d 件 OK" % (len(RESULTS), len(RESULTS) - ng))
    return 1 if ng else 0


if __name__ == "__main__":
    sys.exit(main())
