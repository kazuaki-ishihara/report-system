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


# Edge は新しいプロファイルでも、Windows のアカウントで自動的にサインインする。検証ではサインインさせない
NO_SIGNIN_FEATURES = "msImplicitSignin,msEdgeSignInPromo,msSyncConsent"


def browser_args(port, user_data_dir, width=1280, height=900, headless=True):
    """検証用のブラウザーの起動の引数（実行ファイルと最初に開く URL は含まない）。"""
    args = ["--remote-debugging-port=%d" % port, "--user-data-dir=" + user_data_dir,
            "--no-first-run", "--no-default-browser-check", "--disable-extensions", "--disable-sync",
            "--window-size=%d,%d" % (width, height), "--disable-features=Translate," + NO_SIGNIN_FEATURES]
    if headless:
        args.insert(0, "--headless=new")
    return args


def _is_work_profile(user_data_dir):
    """一時フォルダーの mr_* は、検証のために作ったプロファイル（消してよい）。"""
    temp_dir = os.path.normcase(os.path.realpath(tempfile.gettempdir()))
    folder = os.path.normcase(os.path.realpath(user_data_dir))
    return os.path.dirname(folder) == temp_dir and os.path.basename(folder).startswith("mr_")


def _remove_profile(user_data_dir):
    # ブラウザーが閉じきるまでファイルを掴んでいることがあるので、少し待ってやり直す
    for _ in range(10):
        shutil.rmtree(user_data_dir, ignore_errors=True)
        if not os.path.exists(user_data_dir):
            return
        time.sleep(0.5)


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
        """attach_port を指定すると、起動済みのブラウザー（--remote-debugging-port で起動したもの）につなぐ。

        つないだあと、検証と関係のないタブを閉じ、サインインしていないことを確かめる。
        つないだ先が作業用のブラウザー（一時フォルダーの mr_*）でサインインしていたら、閉じてプロファイルを消し（サインアウト）、
        自分で起動し直す。利用者自身のブラウザーでサインインしていたら、触らずに止める。
        """
        self._op = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        self.slow_seconds = 0.0  # 見学用: 0 より大きいと、操作の前に枠を付けてこの秒数だけ待つ
        self.ws = None
        if attach_port:
            self._connect(int(attach_port), attach=True)
            profile_dir = self._profile_dir()
            if not self._is_signed_in(profile_dir):
                return
            user_data_dir = os.path.dirname(profile_dir)
            if not _is_work_profile(user_data_dir):
                self.quit()
                raise RuntimeError("つないだブラウザーはアカウントにサインインしています。検証には使いません"
                                   "（サインアウトするか、--browser-port を付けずに実行して作業用のブラウザーを起動させてください）")
            print("作業用のブラウザーがサインインしていたため、閉じてプロファイルを消しました（サインアウト）。起動し直します")
            self._close_browser()
            _remove_profile(user_data_dir)
        self._launch(headless, width, height)
        if self._is_signed_in(self._profile_dir()):
            self.quit()
            raise RuntimeError("起動したブラウザーが自動でサインインしました。検証を止めます")

    def _launch(self, headless, width, height):
        self.bin = find_browser()
        if not self.bin:
            raise RuntimeError("Chrome / Edge / Chromium が見つかりません（BROWSER_BIN で指定できます）")
        port = random.randint(20000, 40000)
        self.profile = tempfile.mkdtemp(prefix="mr_ui_")
        args = [self.bin] + browser_args(port, self.profile, width, height, headless) + ["about:blank"]
        creation_flags = 0
        if os.name == "nt":
            # 呼び出し元のコンソールから切り離して起動する（切り離さないと、応答しないことがある）
            creation_flags = 0x00000008 | 0x00000200  # DETACHED_PROCESS | CREATE_NEW_PROCESS_GROUP
        self.proc = subprocess.Popen(args, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                     creationflags=creation_flags)
        self._connect(port, attach=False)

    def _connect(self, port, attach):
        self.port = port
        if attach:
            self.proc = None
            self.profile = None
        self.target_id = None
        ws_url = None
        deadline = time.time() + 30
        while time.time() < deadline:
            try:
                if attach:
                    # 起動済みのブラウザーには、専用のタブを新しく開いて使う
                    req = urllib.request.Request("http://127.0.0.1:%d/json/new?about:blank" % self.port, method="PUT")
                    t = json.loads(self._op.open(req, timeout=3).read())
                    self.target_id = t["id"]
                    ws_url = t["webSocketDebuggerUrl"]
                    break
                pages = json.loads(self._op.open("http://127.0.0.1:%d/json/list" % self.port, timeout=2).read())
                pages = [p for p in pages if p.get("type") == "page"]
                if pages:
                    self.own_tab_id = pages[0]["id"]
                    ws_url = pages[0]["webSocketDebuggerUrl"]
                    break
            except Exception:  # noqa: BLE001
                time.sleep(0.2)
        if not ws_url:
            self.quit()
            raise RuntimeError("ブラウザーに 30 秒つながりません（起動済みのブラウザーにつなぐときは --browser-port を使います。"
                               "作業端末の Claude Code の PowerShell から起動したブラウザーは応答しないので、Bash から実行します）")
        if attach:
            self.own_tab_id = self.target_id
        self.ws = WebSocket(ws_url)
        self.next_id = 0
        self.events = []
        self.dialogs = []
        self.errors = []
        for m in ("Page.enable", "Runtime.enable", "Log.enable"):
            self.call(m)
        self._close_other_tabs()

    def _close_other_tabs(self):
        """検証に使うタブ以外（前の実行の残り・始めに開くページなど）を閉じる。"""
        try:
            pages = json.loads(self._op.open("http://127.0.0.1:%d/json/list" % self.port, timeout=3).read())
        except Exception:  # noqa: BLE001
            return
        for page in pages:
            if page.get("type") == "page" and page.get("id") != self.own_tab_id:
                try:
                    self._op.open("http://127.0.0.1:%d/json/close/%s" % (self.port, page["id"]), timeout=3).read()
                except Exception:  # noqa: BLE001
                    pass

    def _profile_dir(self):
        """使っているプロファイルのフォルダー（edge://version・chrome://version の「プロファイル パス」）。"""
        try:
            self.call("Page.navigate", {"url": "chrome://version"})
            time.sleep(0.5)
            profile_dir = self.eval("(document.querySelector('#profile_path') || {}).textContent || ''") or ""
            self.call("Page.navigate", {"url": "about:blank"})
            time.sleep(0.2)
        except Exception:  # noqa: BLE001
            profile_dir = ""
        if not profile_dir and self.profile:
            profile_dir = os.path.join(self.profile, "Default")
        return profile_dir.strip()

    @staticmethod
    def _is_signed_in(profile_dir):
        """プロファイルにアカウントの情報があればサインインしている（Preferences の account_info）。"""
        preferences_path = os.path.join(profile_dir, "Preferences") if profile_dir else ""
        if not preferences_path or not os.path.isfile(preferences_path):
            return False
        try:
            with open(preferences_path, encoding="utf-8") as preferences_file:
                preferences = json.load(preferences_file)
        except Exception:  # noqa: BLE001
            return False
        return any(isinstance(account, dict) and account.get("email") for account in preferences.get("account_info") or [])

    def _close_browser(self):
        try:
            self.call("Browser.close", timeout=10)
        except Exception:  # noqa: BLE001
            pass
        try:
            self.ws.close()
        except Exception:  # noqa: BLE001
            pass
        time.sleep(2)

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
        if self.slow_seconds > 0:
            time.sleep(self.slow_seconds)

    def mark(self):
        self.eval("window.__mr_marker = true")

    # ---- 見学用（slow_seconds > 0 のとき。人が目で追えるように、操作する場所に枠を付けて待つ） ----
    def _show_target(self, selector):
        if self.slow_seconds <= 0:
            return
        js = ("(() => { const el = document.querySelector(%s); if (!el) return false;"
              " el.scrollIntoView({block: 'center'});"
              " el.style.outline = '3px solid #e8541c'; el.style.outlineOffset = '2px';"
              " setTimeout(() => { el.style.outline = ''; el.style.outlineOffset = ''; }, %d);"
              " return true; })()") % (json.dumps(selector), int(self.slow_seconds * 1000) + 400)
        self.eval(js)
        time.sleep(self.slow_seconds)

    def caption(self, text, is_ok=None):
        """画面の下に字幕を出す（見学用。ページを移ると消える）。is_ok は True=OK、False=NG、None=説明。"""
        if self.slow_seconds <= 0:
            return
        background_color = {True: "#1e7b34", False: "#b3261e"}.get(is_ok, "#333")
        label = {True: "OK  ", False: "NG  "}.get(is_ok, "")
        js = ("(() => { if (!document.body) return false; let box = document.getElementById('__mr_caption');"
              " if (!box) { box = document.createElement('div'); box.id = '__mr_caption'; document.body.appendChild(box); }"
              " box.style.cssText = 'position:fixed;left:8px;right:8px;bottom:8px;z-index:2147483647;padding:8px 12px;"
              "border-radius:6px;font:14px/1.5 sans-serif;color:#fff;pointer-events:none;opacity:.93;background:' + %s;"
              " box.textContent = %s; return true; })()") % (json.dumps(background_color), json.dumps(label + text))
        try:
            self.eval(js)
        except Exception:  # noqa: BLE001
            pass

    def fill(self, selector, value):
        self._show_target(selector)
        # 見学のときは 1 文字ずつ入れて見せる（長い値は途中を飛ばして、全体で 1 秒ほどに収める）
        typing_delay_ms = 0
        if self.slow_seconds > 0 and isinstance(value, str) and value:
            typing_delay_ms = max(10, min(60, 1000 // len(value)))
        js = ("(async () => { const el = document.querySelector(%s); if (!el) throw new Error('not found: ' + %s);"
              " el.focus(); const value = %s; const delayMs = %d;"
              " if (delayMs > 0 && el.tagName !== 'SELECT') { for (let i = 1; i < value.length; i++) {"
              " el.value = value.slice(0, i); await new Promise(r => setTimeout(r, delayMs)); } }"
              " el.value = value; el.dispatchEvent(new Event('input', {bubbles: true}));"
              " el.dispatchEvent(new Event('change', {bubbles: true})); return true; })()") % (
            json.dumps(selector), json.dumps(selector), json.dumps(value), typing_delay_ms)
        return self.eval(js)

    def click(self, selector, navigate=False, user_gesture=False):
        """user_gesture=True は、人が押したものとして押す（選んでコピーの命令など、人の操作のときだけ働く機能を確かめるとき）。"""
        self._show_target(selector)
        if navigate:
            self.mark()
        js = ("(() => { const el = document.querySelector(%s); if (!el) throw new Error('not found: ' + %s);"
              " el.scrollIntoView(); el.click(); return true; })()") % (json.dumps(selector), json.dumps(selector))
        if user_gesture:
            result = self.call("Runtime.evaluate", {"expression": js, "returnByValue": True, "userGesture": True})
            if "exceptionDetails" in result:
                raise RuntimeError("JS error: " + str(result["exceptionDetails"].get("exception", {}).get("description", result["exceptionDetails"])))
        else:
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

    def detach(self):
        """ブラウザーを閉じずに切り離す（見学のあと、画面を開いたままにする）。"""
        try:
            self.ws.close()
        except Exception:  # noqa: BLE001
            pass

    def quit(self):
        if self.proc is not None and self.ws is not None:
            # 自分で起動したブラウザーは、子のプロセスも含めて閉じる
            self._close_browser()
        try:
            if self.ws is not None:
                self.ws.close()
        except Exception:  # noqa: BLE001
            pass
        if getattr(self, "target_id", None):
            try:
                self._op.open("http://127.0.0.1:%d/json/close/%s" % (self.port, self.target_id), timeout=3).read()
            except Exception:  # noqa: BLE001
                pass
        if getattr(self, "proc", None) is not None:
            try:
                self.proc.terminate()
                self.proc.wait(timeout=10)
            except Exception:  # noqa: BLE001
                pass
        if getattr(self, "profile", None):
            _remove_profile(self.profile)
