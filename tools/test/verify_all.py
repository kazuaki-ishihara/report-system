#!/usr/bin/env python3
"""自動検証: 構文・互換性・秘密の確認と、すべての検証スクリプトをまとめて流し、記録を残す。

ローカル検証環境専用（テストデータを作り直す。config.local.php に allow_dev_seed が必要）。
Python 3.8 以上の標準ライブラリだけで動く。PHP は --php か環境変数 PHP_BIN で指定する（なければ自動で探す）。

    python3 tools/test/verify_all.py              # 自動検証
    python3 tools/test/verify_all.py --ui         # 画面の自動操作検証も行う（Chrome / Edge / Chromium が必要）
    python3 tools/test/verify_all.py --ui --show  # ブラウザーを表示して目視で確認する

記録: tools/test/output/検証記録/<日時>/結果.md と、各工程のログ。一覧は tools/test/output/検証記録/一覧.csv。
"""
import argparse
import csv
import datetime
import glob
import json
import os
import re
import shutil
import socket
import subprocess
import sys
import time
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))

# PHP 8.0 以降でしか動かない書き方（本番の PHP 7.4 で動かなくなる）
PHP8_PATTERNS = [
    (r"\bstr_contains\s*\(", "str_contains()"),
    (r"\bstr_starts_with\s*\(", "str_starts_with()"),
    (r"\bstr_ends_with\s*\(", "str_ends_with()"),
    (r"\bmatch\s*\([^)]*\)\s*\{", "match 式"),
    (r"\?->", "nullsafe 演算子"),
    (r"^\s*enum\s+\w+", "enum"),
    (r"\breadonly\s+(public|private|protected|function|\$)", "readonly"),
    (r"\):\s*never\b", "never 型"),
    (r"\):\s*mixed\b", "mixed 型"),
    (r"\bfunction\s*\([^)]*\b\w+\s*\|\s*\w+\s+\$", "union 型（引数）"),
    (r"\barray_is_list\s*\(", "array_is_list()"),
    (r"\bget_debug_type\s*\(", "get_debug_type()"),
    (r"#\[", "属性（attribute）"),
]


def find_php(arg):
    for c in [arg, os.environ.get("PHP_BIN", ""), shutil.which("php") or ""]:
        if c and os.path.isfile(c):
            return c
    local = os.environ.get("LOCALAPPDATA", "")
    for c in glob.glob(os.path.join(local, "Microsoft", "WinGet", "Packages", "PHP.PHP*", "php.exe")):
        return c
    return None


def free_port():
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    p = s.getsockname()[1]
    s.close()
    return p


class Runner:
    def __init__(self, out_dir):
        self.out = out_dir
        self.steps = []

    def run(self, name, cmd, env=None, timeout=900):
        t0 = time.time()
        log_path = os.path.join(self.out, re.sub(r"[^\w\-]+", "_", name) + ".log")
        e = dict(os.environ, PYTHONIOENCODING="utf-8")
        if env:
            e.update(env)
        try:
            p = subprocess.run(cmd, cwd=ROOT, env=e, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=timeout)
            out = p.stdout.decode("utf-8", "replace")
            code = p.returncode
        except subprocess.TimeoutExpired as ex:
            out = (ex.stdout or b"").decode("utf-8", "replace") + "\n時間切れ"
            code = 124
        with open(log_path, "w", encoding="utf-8") as f:
            f.write(out)
        ok_n = len(re.findall(r"^OK  ", out, re.M))
        ng_lines = re.findall(r"^NG  .*$", out, re.M)
        step = {"name": name, "ok": code == 0, "code": code, "passed": ok_n, "failed": len(ng_lines),
                "ng": ng_lines[:30], "seconds": round(time.time() - t0, 1), "log": os.path.basename(log_path), "tail": out[-1500:]}
        self.steps.append(step)
        print("%s  %s（OK %d / NG %d、%.1f 秒）" % ("成功" if step["ok"] else "失敗", name, ok_n, len(ng_lines), step["seconds"]))
        for line in ng_lines[:10]:
            print("    " + line)
        return step

    def add(self, name, ok, passed, ng, seconds=0.0):
        self.steps.append({"name": name, "ok": ok, "code": 0 if ok else 1, "passed": passed, "failed": len(ng),
                           "ng": ng[:30], "seconds": seconds, "log": "", "tail": ""})
        print("%s  %s（OK %d / NG %d）" % ("成功" if ok else "失敗", name, passed, len(ng)))
        for line in ng[:10]:
            print("    " + line)


def php_files():
    out = []
    for d in ("app", "public", "tools"):
        for path in glob.glob(os.path.join(ROOT, d, "**", "*.php"), recursive=True):
            out.append(path)
    return sorted(out)


def step_lint(r, php):
    t0 = time.time()
    ng = []
    files = php_files()
    for f in files:
        p = subprocess.run([php, "-l", f], stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        if p.returncode != 0:
            ng.append("NG  構文エラー: %s %s" % (os.path.relpath(f, ROOT), p.stdout.decode("utf-8", "replace").strip()[:200]))
    r.add("PHP の構文", not ng, len(files) - len(ng), ng, round(time.time() - t0, 1))


def step_php74(r):
    ng = []
    files = php_files()
    for f in files:
        with open(f, encoding="utf-8") as fh:
            for n, line in enumerate(fh, 1):
                if line.lstrip().startswith(("//", "*", "#!")):
                    continue
                for pat, label in PHP8_PATTERNS:
                    if re.search(pat, line):
                        ng.append("NG  PHP 8 の書き方（%s）: %s:%d" % (label, os.path.relpath(f, ROOT), n))
    r.add("PHP 7.4 で動く書き方", not ng, len(files), ng)


def step_repo(r):
    """リポジトリ（Git の管理対象）に DB・鍵・ローカルの設定・ログが入っていないか"""
    ng = []
    try:
        p = subprocess.run(["git", "ls-files"], cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
        tracked = p.stdout.decode("utf-8", "replace").splitlines()
    except FileNotFoundError:
        gitexe = r"C:\Program Files\Git\cmd\git.exe"
        p = subprocess.run([gitexe, "ls-files"], cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
        tracked = p.stdout.decode("utf-8", "replace").splitlines()
    bad = re.compile(r"(\.sqlite|\.sqlite3|\.db|\.key|\.bak|\.backup|\.log)$|config\.local\.php$|^app/(data|keys|logs|backups)/|output_.*\.json$")
    for t in tracked:
        if bad.search(t):
            ng.append("NG  リポジトリに入れてはいけないファイル: " + t)
    # 本番の URL・パスワードらしい文字が設定ファイルに直書きされていないか
    for t in tracked:
        if not t.endswith((".php", ".py", ".json", ".js")):
            continue
        with open(os.path.join(ROOT, t), encoding="utf-8", errors="replace") as fh:
            text = fh.read()
        if re.search(r"(password|passwd|secret)\s*[=:]\s*['\"][^'\"$]{6,}['\"]", text, re.I) and "tools/test/" not in t:
            ng.append("NG  パスワードらしい値の直書き: " + t)
    r.add("リポジトリに秘密が入っていない", not ng, len(tracked), ng)


def wait_server(base, timeout=15):
    op = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    end = time.time() + timeout
    while time.time() < end:
        try:
            if op.open(base + "/health.php", timeout=2).read().strip() == b"ok":
                return True
        except Exception:  # noqa: BLE001
            time.sleep(0.2)
    return False


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--php", default="")
    ap.add_argument("--ui", action="store_true", help="画面の自動操作検証も行う")
    ap.add_argument("--show", action="store_true", help="自動操作検証でブラウザーを表示する")
    ap.add_argument("--browser-port", type=int, default=0, help="起動済みのブラウザーにつなぐ（--remote-debugging-port の番号）")
    ap.add_argument("--skip", default="", help="省く工程（カンマ区切り）: auth,reports,more,retention")
    a = ap.parse_args()
    skip = set(x for x in a.skip.split(",") if x)

    php = find_php(a.php)
    if not php:
        print("PHP が見つかりません（--php で指定してください）")
        return 2
    stamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    rec_root = os.path.join(HERE, "output", "検証記録")
    out = os.path.join(rec_root, stamp)
    os.makedirs(out, exist_ok=True)
    r = Runner(out)
    db = os.path.join(ROOT, "app", "data", "dev.sqlite")
    py = sys.executable
    print("自動検証 %s（PHP: %s）" % (stamp, php))

    step_lint(r, php)
    step_php74(r)
    step_repo(r)

    port = free_port()
    base = "http://127.0.0.1:%d" % port
    server = subprocess.Popen([php, "-S", "127.0.0.1:%d" % port, "-t", os.path.join(ROOT, "public")],
                              cwd=ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        if not wait_server(base):
            r.add("サーバーの起動", False, 0, ["NG  ローカルのサーバーが起動しません"])
        else:
            seed = [php, os.path.join("tools", "test", "dev_seed.php"), "--yes"]
            if "auth" not in skip:
                r.run("テストデータの作成（ログイン）", seed)
                p = subprocess.run([php, os.path.join("app", "bin", "create_user.php"), "--login=verify01", "--name=検証", "--dept=A課", "--admin"],
                                   cwd=ROOT, stdout=subprocess.PIPE)
                m = re.search(r"仮のパスワード: (\S+)", p.stdout.decode("utf-8", "replace"))
                r.run("ログイン・2 段階認証・アカウント設定", [py, "-I", os.path.join(HERE, "smoke_auth.py"), "--base", base, "--login", "verify01",
                                                       "--temp-password", m.group(1) if m else "x", "--lock-user", "ippan_b1", "--admin"])
            if "reports" not in skip:
                r.run("テストデータの作成（報告）", seed)
                r.run("報告の作成・提出・閲覧の権限・コメント・チェック・確認完了・管理画面", [py, "-I", os.path.join(HERE, "smoke_reports.py"), "--base", base, "--db", db])
                r.run("DB に平文の秘密がない（報告）", [py, "-I", os.path.join(HERE, "check_db_secrets.py"), db])
            if "more" not in skip:
                r.run("テストデータの作成（段階 2・3）", seed)
                r.run("変更なし・提出状況・横並び・臨時の調査・出力・記録・削除データ", [py, "-I", os.path.join(HERE, "smoke_more.py"), "--base", base, "--db", db])
                r.run("DB に平文の秘密がない（段階 2・3）", [py, "-I", os.path.join(HERE, "check_db_secrets.py"), db])
            if a.ui:
                r.run("テストデータの作成（画面）", seed)
                ui = [py, "-I", os.path.join(HERE, "ui_check.py"), "--base", base, "--db", db, "--out", os.path.join(out, "画面")]
                if a.show:
                    ui.append("--show")
                if a.browser_port:
                    ui += ["--browser-port", str(a.browser_port)]
                r.run("画面の自動操作", ui)
            if "retention" not in skip:
                r.run("保管期限による削除", [php, os.path.join("tools", "test", "check_retention.php")])
    finally:
        server.terminate()
        try:
            server.wait(timeout=10)
        except Exception:  # noqa: BLE001
            server.kill()

    passed = sum(s["passed"] for s in r.steps)
    failed = sum(s["failed"] for s in r.steps) + sum(1 for s in r.steps if not s["ok"] and s["failed"] == 0)
    ok = all(s["ok"] for s in r.steps)
    lines = ["# 自動検証の結果 %s" % stamp, "", "| 判定 | %s |" % ("成功" if ok else "失敗"), "|---|---|",
             "| 確認の数 | OK %d / NG %d |" % (passed, failed), "| PHP | %s |" % php, "| 画面の自動操作 | %s |" % ("実施" if a.ui else "省略"), "",
             "## 工程", "", "| 工程 | 判定 | OK | NG | 秒 | ログ |", "|---|---|---|---|---|---|"]
    for s in r.steps:
        lines.append("| %s | %s | %d | %d | %s | %s |" % (s["name"], "成功" if s["ok"] else "失敗", s["passed"], s["failed"], s["seconds"], s["log"]))
    bad = [s for s in r.steps if not s["ok"]]
    if bad:
        lines += ["", "## 失敗の内容", ""]
        for s in bad:
            lines.append("### " + s["name"])
            lines += ["- " + x for x in s["ng"]] or ["```", s["tail"], "```"]
    with open(os.path.join(out, "結果.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(lines) + "\n")
    index = os.path.join(rec_root, "一覧.csv")
    new = not os.path.exists(index)
    with open(index, "a", encoding="utf-8-sig", newline="") as f:
        w = csv.writer(f)
        if new:
            w.writerow(["日時", "判定", "OK", "NG", "画面の自動操作"])
        w.writerow([stamp, "成功" if ok else "失敗", passed, failed, "実施" if a.ui else "省略"])
    print("\n判定: %s（OK %d / NG %d）" % ("成功" if ok else "失敗", passed, failed))
    print("記録: " + os.path.join(out, "結果.md"))
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
