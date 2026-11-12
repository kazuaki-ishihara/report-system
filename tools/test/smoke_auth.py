#!/usr/bin/env python3
"""ログイン・2 段階認証・アカウント設定の確認（ローカル検証環境専用）。

Python 3.8 以上の標準ライブラリだけで動く。本番には向けない。

    python3 tools/test/smoke_auth.py --base http://127.0.0.1:8000 \
        --login admin01 --temp-password XXXX
"""
import argparse
import base64
import hashlib
import hmac
import http.cookiejar
import re
import struct
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

RESULTS = []


def check(name, ok, detail=""):
    RESULTS.append((name, bool(ok), detail))
    mark = "OK  " if ok else "NG  "
    print(mark + name + ("" if ok else "  " + str(detail)))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Client:
    def __init__(self, base):
        self.base = base.rstrip("/")
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)

    def req(self, method, path, data=None):
        url = path if path.startswith("http") else self.base + path
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        r = urllib.request.Request(url, data=body, method=method)
        try:
            resp = self.opener.open(r, timeout=15)
        except urllib.error.HTTPError as e:
            resp = e
        text = resp.read().decode("utf-8", "replace")
        loc = resp.headers.get("Location", "")
        return resp.status if hasattr(resp, "status") else resp.code, loc, text, resp.headers

    def get(self, path):
        return self.req("GET", path)

    def post(self, path, data):
        return self.req("POST", path, data)

    def follow(self, path, data=None, limit=5):
        status, loc, text, headers = self.req("POST" if data is not None else "GET", path, data)
        n = 0
        while status in (301, 302, 303) and loc and n < limit:
            status, loc2, text, headers = self.get(loc)
            last = loc
            loc = loc2
            n += 1
        return status, text


def csrf(html):
    m = re.search(r'name="csrf_token" value="([0-9a-f]+)"', html)
    return m.group(1) if m else ""


def rkey(html):
    m = re.search(r'name="request_key" value="([0-9a-f]+)"', html)
    return m.group(1) if m else ""


def totp(secret_b32, t=None, step_offset=0):
    key = base64.b32decode(secret_b32.replace(" ", "").upper() + "=" * ((8 - len(secret_b32.replace(" ", "")) % 8) % 8))
    step = int((t or time.time()) // 30) + step_offset
    h = hmac.new(key, struct.pack(">Q", step), hashlib.sha1).digest()
    o = h[19] & 0x0F
    v = ((h[o] & 0x7F) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3]
    return "%06d" % (v % 1000000)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8000")
    ap.add_argument("--login", required=True)
    ap.add_argument("--temp-password", required=True)
    ap.add_argument("--new-password", default="test-pass-phrase-123")
    ap.add_argument("--lock-user", default="")
    ap.add_argument("--admin", action="store_true", help="ログインする利用者が管理者のとき")
    a = ap.parse_args()

    if "127.0.0.1" not in a.base and "localhost" not in a.base:
        print("ローカル検証環境（127.0.0.1）以外には実行しません")
        return 2

    # ---- 共通 ----
    c = Client(a.base)
    s, _, body, h = c.get("/health.php")
    check("health が ok を返す", s == 200 and body.strip() == "ok", (s, body[:50]))
    s, loc, body, h = c.get("/index.php")
    check("未ログインのホームはログインへ転送", s == 303 and "/login.php" in loc, (s, loc))
    s, _, body, h = c.get("/login.php")
    check("Cache-Control: no-store", h.get("Cache-Control") == "no-store", h.get("Cache-Control"))
    check("CSP がある", "default-src 'self'" in (h.get("Content-Security-Policy") or ""))
    check("X-Frame-Options: DENY", h.get("X-Frame-Options") == "DENY")
    s, _, body, _ = c.post("/login.php", {"action": "password", "login_id": a.login, "password": "x"})
    check("CSRF トークンなしの POST は 403", s == 403, s)

    # ---- 存在しない ID とパスワード違いは同じ表示 ----
    s, _, body, _ = c.get("/login.php")
    s1, _, b1, _ = c.post("/login.php", {"csrf_token": csrf(body), "action": "password", "login_id": "nobody-xyz", "password": "wrong-password"})
    s2, _, b2, _ = c.post("/login.php", {"csrf_token": csrf(body), "action": "password", "login_id": a.login, "password": "wrong-password"})
    msg = "ログイン ID またはパスワードが違います"
    check("存在しない ID とパスワード違いで同じ表示", s1 == s2 == 200 and msg in b1 and msg in b2, (s1, s2))

    # ---- 初回ログイン → 2 段階認証の設定 ----
    s, loc, body, _ = c.post("/login.php", {"csrf_token": csrf(body), "action": "password", "login_id": a.login,
                                             "password": a.temp_password, "next": "//evil.example/"})
    check("初回は 2 段階認証の設定へ", s == 303 and "/account.php?tab=totp" in loc, (s, loc))
    s, loc, _, _ = c.get("/index.php")
    check("設定前はホームを開けない", s == 303 and "/account.php?tab=totp" in loc, (s, loc))
    s, _, body, _ = c.get("/account.php?tab=totp")
    m = re.search(r'<p class="secret">([A-Z2-7 ]+)</p>', body)
    check("秘密の値が表示される", m is not None)
    secret = m.group(1) if m else ""
    s, _, body2, _ = c.post("/account.php?tab=totp", {"csrf_token": csrf(body), "action": "totp_confirm", "code": "000000"})
    check("違うコードでは設定できない", "コードが違います" in body2)
    s, loc, _, _ = c.post("/account.php?tab=totp", {"csrf_token": csrf(body), "action": "totp_confirm", "code": totp(secret), "remember": "1"})
    check("正しいコードで設定できる", s == 303 and "tab=codes" in loc, (s, loc))
    s, _, body, _ = c.get("/account.php?tab=codes")
    codes = re.findall(r"<li>([a-z0-9]{4}-[a-z0-9]{6})</li>", body)
    check("予備のコードが 8 個表示される", len(codes) == 8, len(codes))
    s, _, body_again, _ = c.get("/account.php?tab=codes")
    # 仮のパスワードの間はパスワード変更へ回されるので、コードが再表示されないことだけを確かめる
    check("予備のコードは二度と表示されない", not re.findall(r"<li>([a-z0-9]{4}-[a-z0-9]{6})</li>", body_again))
    check("外のサイトへの next は使われない", "evil.example" not in body)

    # ---- 仮のパスワード → 変更 ----
    s, loc, _, _ = c.get("/index.php")
    check("仮のパスワードの間はパスワード変更へ", s == 303 and "tab=password" in loc, (s, loc))
    s, _, body, _ = c.get("/account.php?tab=password")
    s, loc, body2, _ = c.post("/account.php?tab=password", {"csrf_token": csrf(body), "action": "password_change",
                                                            "new_password": "short", "new_password_confirm": "short"})
    check("短いパスワードは断られる", "文字以上" in body2)
    s, loc, _, _ = c.post("/account.php?tab=password", {"csrf_token": csrf(body), "action": "password_change",
                                                         "new_password": a.new_password, "new_password_confirm": a.new_password})
    check("パスワードを変更できる", s == 303 and "/index.php" in loc, (s, loc))
    s, _, body, _ = c.get("/index.php")
    check("ホームが表示される", s == 200 and "さん" in body and "の報告" in body, s)
    if a.admin:
        check("管理者のメニューが出る", "組織・ユーザー" in body)
    else:
        check("管理者でなければ管理者のメニューが出ない", "組織・ユーザー" not in body)

    # ---- ログアウト → 記憶した端末では 2 段階認証なしで入れる ----
    s, loc, _, _ = c.post("/logout.php", {"csrf_token": csrf(body)})
    check("ログアウトできる", s == 303 and "/login.php" in loc, (s, loc))
    s, _, body, _ = c.get("/login.php")
    s, loc, _, _ = c.post("/login.php", {"csrf_token": csrf(body), "action": "password", "login_id": a.login, "password": a.new_password})
    check("記憶した端末ではコードなしでログイン", s == 303 and "/index.php" in loc, (s, loc))

    # ---- 別の端末（Cookie なし）は 2 段階認証が必要。予備のコードで入る ----
    d = Client(a.base)
    s, _, body, _ = d.get("/login.php")
    s, loc, _, _ = d.post("/login.php", {"csrf_token": csrf(body), "action": "password", "login_id": a.login, "password": a.new_password})
    check("別の端末では手順 2 へ", s == 303 and "step=2" in loc, (s, loc))
    s, _, body, _ = d.get("/index.php")
    check("手順 2 の途中ではホームを開けない", s == 303, s)
    s, _, body, _ = d.get("/login.php?step=2")
    s, _, body2, _ = d.post("/login.php", {"csrf_token": csrf(body), "action": "code", "code": "123456"})
    check("違うコードは断られる", "コードが違います" in body2)
    s, loc, _, _ = d.post("/login.php", {"csrf_token": csrf(body), "action": "code", "code": codes[0]})
    check("予備のコードで入れる", s == 303 and "/index.php" in loc, (s, loc))
    e = Client(a.base)
    s, _, body, _ = e.get("/login.php")
    e.post("/login.php", {"csrf_token": csrf(body), "action": "password", "login_id": a.login, "password": a.new_password})
    s, _, body, _ = e.get("/login.php?step=2")
    s, loc, body2, _ = e.post("/login.php", {"csrf_token": csrf(body), "action": "code", "code": codes[0]})
    check("使用済みの予備のコードは使えない", "コードが違います" in body2, (s, loc))

    # ---- 別の端末から「すべての端末からログアウト」→ 最初の端末も切れる ----
    s, _, body, _ = d.get("/account.php?tab=devices")
    s, loc, _, _ = d.post("/account.php?tab=devices", {"csrf_token": csrf(body), "action": "logout_all"})
    s, loc, _, _ = c.get("/index.php")
    check("すべての端末からログアウトで、ほかの端末のセッションも切れる", s == 303 and "/login.php" in loc, (s, loc))

    # ---- ロック ----
    if a.lock_user:
        f = Client(a.base)
        s, _, body, _ = f.get("/login.php")
        tok = csrf(body)
        last = ""
        for i in range(10):
            s, _, last, _ = f.post("/login.php", {"csrf_token": tok, "action": "password", "login_id": a.lock_user, "password": "wrong-%d" % i})
        check("10 回の失敗でロックされる", "しばらくログインできません" in last)

    ng = [r for r in RESULTS if not r[1]]
    print("\n%d 件中 %d 件 OK" % (len(RESULTS), len(RESULTS) - len(ng)))
    return 1 if ng else 0


if __name__ == "__main__":
    sys.exit(main())
