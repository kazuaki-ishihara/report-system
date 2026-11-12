"""検証の道具の共通部品（Python 3.8 以上の標準ライブラリだけ）。"""
import base64
import hashlib
import hmac
import http.cookiejar
import re
import struct
import time
import urllib.error
import urllib.parse
import urllib.request

RESULTS = []


def check(name, ok, detail=""):
    RESULTS.append((name, bool(ok), detail))
    print(("OK  " if ok else "NG  ") + name + ("" if ok else "  " + str(detail)))


def summary():
    ng = [r for r in RESULTS if not r[1]]
    print("\n%d 件中 %d 件 OK" % (len(RESULTS), len(RESULTS) - len(ng)))
    return 1 if ng else 0


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Client:
    def __init__(self, base):
        if "127.0.0.1" not in base and "localhost" not in base:
            raise SystemExit("ローカル検証環境（127.0.0.1）以外には実行しません")
        self.base = base.rstrip("/")
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)

    def req(self, method, path, data=None):
        url = path if path.startswith("http") else self.base + path
        body = None
        if data is not None:
            body = urllib.parse.urlencode(data, doseq=True).encode()
        r = urllib.request.Request(url, data=body, method=method)
        try:
            resp = self.opener.open(r, timeout=20)
        except urllib.error.HTTPError as e:
            resp = e
        text = resp.read().decode("utf-8", "replace")
        status = getattr(resp, "status", None) or resp.code
        return status, resp.headers.get("Location", ""), text

    def get(self, path):
        return self.req("GET", path)

    def post(self, path, data):
        return self.req("POST", path, data)

    def follow_get(self, path):
        s, loc, t = self.get(path)
        n = 0
        while s in (301, 302, 303) and loc and n < 5:
            s, loc, t = self.get(loc)
            n += 1
        return s, t


def csrf(html):
    m = re.search(r'name="csrf_token" value="([0-9a-f]+)"', html)
    return m.group(1) if m else ""


def rkey(html):
    m = re.search(r'name="request_key" value="([0-9a-f]+)"', html)
    return m.group(1) if m else ""


def totp(secret_b32, offset=0):
    s = secret_b32.replace(" ", "").upper()
    key = base64.b32decode(s + "=" * ((8 - len(s) % 8) % 8))
    step = int(time.time() // 30) + offset
    h = hmac.new(key, struct.pack(">Q", step), hashlib.sha1).digest()
    o = h[19] & 0x0F
    v = ((h[o] & 0x7F) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3]
    return "%06d" % (v % 1000000)


_used_steps = {}


def login(base, login_id, info):
    """パスワードと TOTP でログインした Client を返す（同じ人の同じ 30 秒区間のコードは再利用できないので待つ）。"""
    c = Client(base)
    s, _, body = c.get("/login.php")
    s, loc, _ = c.post("/login.php", {"csrf_token": csrf(body), "action": "password", "login_id": login_id, "password": info["password"]})
    if "step=2" not in loc:
        raise SystemExit("ログインできません: %s %s %s" % (login_id, s, loc))
    step = int(time.time() // 30)
    if _used_steps.get(login_id) == step:
        time.sleep(31 - time.time() % 30)
    s, _, body = c.get("/login.php?step=2")
    s, loc, _ = c.post("/login.php", {"csrf_token": csrf(body), "action": "code", "code": totp(info["totp"]), "remember": "0"})
    _used_steps[login_id] = int(time.time() // 30)
    if "/index.php" not in loc:
        raise SystemExit("2 段階認証で失敗: %s %s %s" % (login_id, s, loc))
    return c
