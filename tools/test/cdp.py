"""Chrome / Edge を操作する最小限の部品（Chrome DevTools Protocol）。

Python 3.8 以上の標準ライブラリだけで動く（WebSocket も自前で実装）。
ローカル検証環境の画面の自動操作検証に使う。
"""
import base64
import json
import os
import random
import shutil
import socket
import struct
import subprocess
import tempfile
import time
import urllib.parse
import urllib.request


def find_browser():
    cands = [
        os.environ.get("BROWSER_BIN", ""),
        # Windows は標準で入っている Edge を優先する
        r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
        r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
        r"C:\Program Files\Google\Chrome\Application\chrome.exe",
        r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
        "/usr/bin/google-chrome", "/usr/bin/chromium", "/usr/bin/chromium-browser", "/snap/bin/chromium",
    ]
    for c in cands:
        if c and os.path.isfile(c):
            return c
    for name in ("google-chrome", "chromium", "chromium-browser", "chrome", "msedge"):
        p = shutil.which(name)
        if p:
            return p
    return None


class WebSocket:
    def __init__(self, url):
        u = urllib.parse.urlparse(url)
        self.sock = socket.create_connection((u.hostname, u.port), timeout=60)
        key = base64.b64encode(os.urandom(16)).decode()
        req = ("GET %s HTTP/1.1\r\nHost: %s:%d\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
               "Sec-WebSocket-Key: %s\r\nSec-WebSocket-Version: 13\r\n\r\n") % (u.path, u.hostname, u.port, key)
        self.sock.sendall(req.encode())
        buf = b""
        while b"\r\n\r\n" not in buf:
            chunk = self.sock.recv(4096)
            if not chunk:
                raise ConnectionError("handshake failed")
            buf += chunk
        if b" 101 " not in buf.split(b"\r\n", 1)[0]:
            raise ConnectionError("handshake failed: " + buf[:100].decode("latin1"))
        self.pending = buf.split(b"\r\n\r\n", 1)[1]

    def _recv_exact(self, n):
        while len(self.pending) < n:
            chunk = self.sock.recv(65536)
            if not chunk:
                raise ConnectionError("closed")
            self.pending += chunk
        out, self.pending = self.pending[:n], self.pending[n:]
        return out

    def send(self, text):
        data = text.encode("utf-8")
        head = bytearray([0x81])
        n = len(data)
        if n < 126:
            head.append(0x80 | n)
        elif n < 65536:
            head.append(0x80 | 126)
            head += struct.pack(">H", n)
        else:
            head.append(0x80 | 127)
            head += struct.pack(">Q", n)
        mask = os.urandom(4)
        head += mask
        masked = bytes(b ^ mask[i % 4] for i, b in enumerate(data))
        self.sock.sendall(bytes(head) + masked)

    def recv(self):
        message = b""
        while True:
            b1, b2 = self._recv_exact(2)
            fin, opcode = b1 & 0x80, b1 & 0x0F
            n = b2 & 0x7F
            if n == 126:
                n = struct.unpack(">H", self._recv_exact(2))[0]
            elif n == 127:
                n = struct.unpack(">Q", self._recv_exact(8))[0]
            if b2 & 0x80:
                mask = self._recv_exact(4)
                payload = bytes(b ^ mask[i % 4] for i, b in enumerate(self._recv_exact(n)))
            else:
                payload = self._recv_exact(n)
            if opcode == 0x9:  # ping
                self.sock.sendall(bytes([0x8A, len(payload)]) + payload)
                continue
            if opcode == 0x8:
                raise ConnectionError("closed by browser")
            message += payload
            if fin:
                return message.decode("utf-8")

    def close(self):
        try:
            self.sock.close()
        except OSError:
            pass


class Browser:
    """ブラウザーを起動して 1 つのタブを操作する。"""

    def __init__(self, headless=True, width=1280, height=900, attach_port=None):
        """attach_port を指定すると、起動済みのブラウザー（--remote-debugging-port で起動したもの）につなぐ。"""
        self.proc = None
        self.profile = None
        self.target_id = None
        op = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        if attach_port:
            self.port = int(attach_port)
        else:
            self.bin = find_browser()
            if not self.bin:
                raise RuntimeError("Chrome / Edge / Chromium が見つかりません（BROWSER_BIN で指定できます）")
            self.port = random.randint(20000, 40000)
            self.profile = tempfile.mkdtemp(prefix="mr_ui_")
            args = [self.bin, "--remote-debugging-port=%d" % self.port, "--user-data-dir=" + self.profile,
                    "--no-first-run", "--no-default-browser-check", "--disable-extensions", "--window-size=%d,%d" % (width, height),
                    "--disable-features=Translate", "about:blank"]
            if headless:
                args.insert(1, "--headless=new")
            self.proc = subprocess.Popen(args, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        ws_url = None
        for _ in range(100):
            try:
                if attach_port:
                    # 起動済みのブラウザーには、専用のタブを新しく開いて使う
                    req = urllib.request.Request("http://127.0.0.1:%d/json/new?about:blank" % self.port, method="PUT")
                    t = json.loads(op.open(req, timeout=3).read())
                    self.target_id = t["id"]
                    ws_url = t["webSocketDebuggerUrl"]
                    break
                pages = json.loads(op.open("http://127.0.0.1:%d/json/list" % self.port, timeout=2).read())
                pages = [p for p in pages if p.get("type") == "page"]
                if pages:
                    ws_url = pages[0]["webSocketDebuggerUrl"]
                    break
            except Exception:  # noqa: BLE001
                time.sleep(0.2)
        if not ws_url:
            self.quit()
            raise RuntimeError("ブラウザーに接続できません（起動済みのブラウザーにつなぐときは --browser-port を使います）")
        self._op = op
        self.ws = WebSocket(ws_url)
        self.next_id = 0
        self.events = []
        self.dialogs = []
        self.errors = []
        for m in ("Page.enable", "Runtime.enable", "Log.enable"):
            self.call(m)

    # ---- 送受信 ----
    def call(self, method, params=None, timeout=30):
        self.next_id += 1
        mid = self.next_id
        self.ws.send(json.dumps({"id": mid, "method": method, "params": params or {}}))
        end = time.time() + timeout
        while time.time() < end:
            msg = json.loads(self.ws.recv())
            if msg.get("id") == mid:
                if "error" in msg:
                    raise RuntimeError("%s: %s" % (method, msg["error"]))
                return msg.get("result", {})
            self._event(msg)
        raise TimeoutError(method)

    def _event(self, msg):
        method = msg.get("method")
        if not method:
            return
        p = msg.get("params", {})
        if method == "Page.javascriptDialogOpening":
            # 確認の表示は「はい」で閉じる（自動操作の想定どおり）
            self.dialogs.append(p.get("message", ""))
            self.next_id += 1
            self.ws.send(json.dumps({"id": self.next_id, "method": "Page.handleJavaScriptDialog", "params": {"accept": True}}))
        elif method == "Runtime.exceptionThrown":
            self.errors.append("JS: " + json.dumps(p.get("exceptionDetails", {}).get("text", ""), ensure_ascii=False))
        elif method == "Log.entryAdded":
            e = p.get("entry", {})
            if e.get("level") == "error" and "favicon" not in e.get("url", ""):
                self.errors.append("%s: %s" % (e.get("source"), e.get("text")))
        self.events.append(msg)

    # ---- 操作 ----
    def eval(self, js, timeout=30):
        r = self.call("Runtime.evaluate", {"expression": js, "returnByValue": True, "awaitPromise": True}, timeout)
        if "exceptionDetails" in r:
            raise RuntimeError("JS error: " + str(r["exceptionDetails"].get("exception", {}).get("description", r["exceptionDetails"])))
        return r.get("result", {}).get("value")

    def wait_load(self, timeout=20):
        end = time.time() + timeout
        while time.time() < end:
            try:
                if self.eval("document.readyState === 'complete' && !window.__mr_marker") is True:
                    return True
            except Exception:  # noqa: BLE001
                pass
            time.sleep(0.15)
        raise TimeoutError("page load")

    def goto(self, url):
        self.call("Page.navigate", {"url": url})
        time.sleep(0.2)
        self.wait_load()

    def mark(self):
        self.eval("window.__mr_marker = true")

    def fill(self, selector, value):
        js = ("(() => { const el = document.querySelector(%s); if (!el) throw new Error('not found: ' + %s);"
              " el.focus(); el.value = %s; el.dispatchEvent(new Event('input', {bubbles: true}));"
              " el.dispatchEvent(new Event('change', {bubbles: true})); return true; })()") % (
            json.dumps(selector), json.dumps(selector), json.dumps(value))
        return self.eval(js)

    def click(self, selector, navigate=False):
        if navigate:
            self.mark()
        js = ("(() => { const el = document.querySelector(%s); if (!el) throw new Error('not found: ' + %s);"
              " el.scrollIntoView(); el.click(); return true; })()") % (json.dumps(selector), json.dumps(selector))
        self.eval(js)
        if navigate:
            time.sleep(0.3)
            self.wait_load()

    def text(self):
        return self.eval("document.body ? document.body.innerText : ''") or ""

    def exists(self, selector):
        return bool(self.eval("!!document.querySelector(%s)" % json.dumps(selector)))

    def visible(self, selector):
        js = ("(() => { const el = document.querySelector(%s); if (!el) return false;"
              " if (el.hidden || el.closest('[hidden]')) return false; const r = el.getBoundingClientRect();"
              " return r.width > 0 && r.height > 0; })()") % json.dumps(selector)
        return bool(self.eval(js))

    def screenshot(self, path):
        r = self.call("Page.captureScreenshot", {"format": "png", "captureBeyondViewport": False})
        with open(path, "wb") as f:
            f.write(base64.b64decode(r["data"]))

    def mobile(self, on=True):
        if on:
            self.call("Emulation.setDeviceMetricsOverride", {"width": 375, "height": 812, "deviceScaleFactor": 2, "mobile": True})
            self.call("Emulation.setUserAgentOverride", {"userAgent": "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1"})
        else:
            self.call("Emulation.clearDeviceMetricsOverride")
            self.call("Emulation.setUserAgentOverride", {"userAgent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36"})

    def clear_cookies(self):
        self.call("Network.clearBrowserCookies")

    def quit(self):
        try:
            self.ws.close()
        except Exception:  # noqa: BLE001
            pass
        if self.target_id:
            try:
                self._op.open("http://127.0.0.1:%d/json/close/%s" % (self.port, self.target_id), timeout=3).read()
            except Exception:  # noqa: BLE001
                pass
        if self.proc is not None:
            try:
                self.proc.terminate()
                self.proc.wait(timeout=10)
            except Exception:  # noqa: BLE001
                pass
        if self.profile:
            shutil.rmtree(self.profile, ignore_errors=True)
