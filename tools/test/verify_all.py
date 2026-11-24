#!/usr/bin/env python3
"""自動検証: 構文・互換性・秘密の確認と、すべての検証スクリプトをまとめて流し、記録を残す。

ローカル検証環境専用（テストデータを作り直す。config.local.php に allow_dev_seed が必要）。
Python 3.8 以上の標準ライブラリだけで動く。PHP は --php か環境変数 PHP_BIN で指定する（なければ自動で探す）。

    python3 tools/test/verify_all.py              # 自動検証
    python3 tools/test/verify_all.py --ui         # 画面の自動操作検証も行う（Chrome / Edge / Chromium が必要）
    python3 tools/test/verify_all.py --ui --show  # ブラウザーを表示して目視で確認する
    python3 tools/test/verify_all.py --only G-25,G-16   # その画面の確かめを含む工程だけ流す（工程の名前 auth なども使える）
    python3 tools/test/verify_all.py --list       # 工程と、工程ごとの画面の番号を出す（流さない）

確かめの一つ一つには、画面の番号（G-01〜G-25）か共通の単位（コード・共通・DB・道具）を付ける（lib_http.section()）。
結果は画面ごとにも数え、検証項目一覧（画面ごとの確かめの一覧）を記録に残す。すべての工程を流して成功したときは、
docs/verification_items.md も書き直す。

途中で止めたとき（Ctrl+C・止める操作・サーバーが止まった）は、残りの工程を流さず、判定を「中断」として残す。

記録: tools/test/output/検証記録/<日時>/結果.md・検証項目一覧.md と、各工程のログ。一覧は tools/test/output/検証記録/一覧.csv。
"""
import argparse
import concurrent.futures
import csv
import datetime
import glob
import json
import os
import re
import shutil
import signal
import socket
import subprocess
import sys
import time
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))

# 画面の番号・共通の単位（lib_http.section() で付ける印）。並びは結果の画面ごとの表の並び
SCREEN_CODE_PATTERN = re.compile(r"^(G-\d\d|共通|DB|道具|コード)$")
COMMON_UNIT_LABELS = {"コード": "コード（構文・PHP 8.3・秘密・文言の一覧。数はファイルの数）", "共通": "共通（全画面の決まり。00_common）", "DB": "DB（01_database。暗号化・保管期限）", "道具": "道具（tools/。配布・確認の道具）"}


class Interrupted(Exception):
    """途中で止めた（Ctrl+C・止める操作・サーバーが止まった）"""


def say(text):
    """表示する。表示先が無くなった（パイプの先が止められた）ときは、途中で止めたとみなす"""
    try:
        print(text, flush=True)
    except (OSError, ValueError):
        raise Interrupted("表示先が無くなった")


def stop_on_signal(signal_number, _frame):
    raise Interrupted("止める合図（%s）" % signal_number)

# PHP 8.4 以降でしか動かない書き方（本番の PHP 8.3 で動かなくなる）。構文の誤りは「PHP の構文」（php -l。8.3）でも見つかる
PHP84_PATTERNS = [
    (r"\barray_(find|find_key|any|all)\s*\(", "array_find() などの配列の関数"),
    (r"\bmb_(trim|ltrim|rtrim|ucfirst|lcfirst)\s*\(", "mb_trim() などの文字列の関数"),
    (r"\b(public|protected|private)\(set\)", "非対称の可視性（private(set) など）"),
    (r"\bnew\s+\w+\([^()]*\)->", "かっこで囲まない new のあとのメソッド呼び出し"),
    (r"#\[\\?Deprecated\b", "Deprecated 属性"),
    (r"\bbc(divmod|round|floor|ceil)\s*\(", "bcmath の新しい関数"),
    (r"\brequest_parse_body\s*\(", "request_parse_body()"),
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


# 検証で立てる PHP の組み込みサーバーのセッションのファイル（sess_<ID>）の置き場所。
# 指定しないと一時フォルダー（%TEMP%）の直下に溜まるので、リポジトリに入らない検証の出力の下にまとめる
PHP_SESSION_DIR = os.path.join(ROOT, "tools", "test", "output", "php_sessions")
PHP_SESSION_KEEP_SECONDS = 24 * 60 * 60


def php_server_command(php_path, port_number):
    """検証用の PHP の組み込みサーバーを立てるコマンド（セッションは PHP_SESSION_DIR に置く）。
    前回までに溜まった 1 日より古いセッションのファイルは、ここで消す"""
    os.makedirs(PHP_SESSION_DIR, exist_ok=True)
    oldest_kept_time = time.time() - PHP_SESSION_KEEP_SECONDS
    for session_file_path in glob.glob(os.path.join(PHP_SESSION_DIR, "sess_*")):
        try:
            if os.path.getmtime(session_file_path) < oldest_kept_time:
                os.remove(session_file_path)
        except OSError:
            pass
    return [php_path, "-d", "session.save_path=" + PHP_SESSION_DIR,
            "-S", "127.0.0.1:%d" % port_number, "-t", os.path.join(ROOT, "public")]


class Runner:
    def __init__(self, out_dir):
        self.out = out_dir
        self.steps = []

    def run(self, name, cmd, env=None, timeout=900, default_screen="共通"):
        t0 = time.time()
        log_path = os.path.join(self.out, re.sub(r"[^\w\-]+", "_", name) + ".log")
        e = dict(os.environ, PYTHONIOENCODING="utf-8")
        if env:
            e.update(env)
        if cmd and cmd[0] == sys.executable and "-I" in cmd:
            cmd = [cmd[0], "-X", "utf8"] + list(cmd[1:])   # -I は PYTHONIOENCODING を見ないので、UTF-8 の出力を -X utf8 で決める
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
        step["not_done"] = re.findall(r"^--  (.*)$", out, re.M)   # 確かめられなかった（ソフトが無いなど）。結果に「未実施」として出す
        step["screen_counts"] = count_by_screen(out, default_screen)
        step["items"] = check_items(out, default_screen)
        self.steps.append(step)
        say("%s  %s（OK %d / NG %d、%.1f 秒）" % ("成功" if step["ok"] else "失敗", name, ok_n, len(ng_lines), step["seconds"]))
        for line in ng_lines[:10]:
            say("    " + line)
        for not_done_text in step["not_done"]:
            say("    未実施: " + not_done_text)
        return step

    def add(self, name, ok, passed, ng, seconds=0.0, default_screen="共通"):
        self.steps.append({"name": name, "ok": ok, "code": 0 if ok else 1, "passed": passed, "failed": len(ng),
                           "ng": ng[:30], "seconds": seconds, "log": "", "tail": "",
                           "screen_counts": {default_screen: [passed, len(ng)]}, "items": [(default_screen, name, ok)]})
        say("%s  %s（OK %d / NG %d）" % ("成功" if ok else "失敗", name, passed, len(ng)))
        for line in ng[:10]:
            say("    " + line)


def parse_check_line(line, default_screen):
    """「OK  [G-25] 名前」の行を (画面, 名前, OK か) にする。印が無い・画面の番号でない印は、工程の既定の画面"""
    match = re.match(r"^(OK|NG)  (?:\[([^\]]+)\] )?(.*)$", line)
    if not match:
        return None
    screen_code = match.group(2) if match.group(2) and SCREEN_CODE_PATTERN.match(match.group(2)) else default_screen
    check_name = match.group(3) if screen_code == match.group(2) or not match.group(2) else "[%s] %s" % (match.group(2), match.group(3))
    check_name = re.sub(r"  .*$", "", check_name) if match.group(1) == "NG" else check_name
    return screen_code, check_name.strip(), match.group(1) == "OK"


def check_items(output_text, default_screen):
    return [item for item in (parse_check_line(line, default_screen) for line in output_text.splitlines()) if item]


def count_by_screen(output_text, default_screen):
    counts = {}
    for screen_code, _name, is_ok in check_items(output_text, default_screen):
        counts.setdefault(screen_code, [0, 0])[0 if is_ok else 1] += 1
    return counts


def design_screens():
    """詳細設計書の画面（G-01〜G-25）の番号と名前"""
    screens = {}
    for path in sorted(glob.glob(os.path.join(ROOT, "docs", "detailed_design", "G*.md"))):
        with open(path, encoding="utf-8") as fh:
            heading = fh.readline().strip()
        match = re.match(r"^# (G-\d\d) (.+)$", heading)
        if match:
            screens[match.group(1)] = match.group(2)
    return screens


def screen_sort_key(screen_code):
    order = ["コード", "共通", "DB"]
    if screen_code in order:
        return (0, order.index(screen_code), "")
    if screen_code.startswith("G-"):
        return (1, 0, screen_code)
    return (2, 0, screen_code)


def script_screens(script_name):
    """検証のスクリプトの中の section("G-..") から、その工程が確かめる画面の番号を取り出す（流さずに分かる）"""
    path = os.path.join(HERE, script_name)
    if not os.path.isfile(path):
        return []
    with open(path, encoding="utf-8") as fh:
        source_text = fh.read()
    return sorted(set(re.findall(r'section\(\s*"([^"]+)"', source_text)), key=screen_sort_key)


def php_files():
    out = []
    # 道具が作った出力（反映用の一式など。リポジトリの外）は数えない（同じファイルの写しが二重に数えられるため）
    generated_output_dirs = (os.path.join(ROOT, "tools", "deploy", "output") + os.sep,
                             os.path.join(ROOT, "tools", "test", "output") + os.sep)
    for d in ("app", "public", "tools"):
        for path in glob.glob(os.path.join(ROOT, d, "**", "*.php"), recursive=True):
            if path.startswith(generated_output_dirs):
                continue
            out.append(path)
    return sorted(out)


def step_lint(r, php):
    t0 = time.time()
    ng = []
    files = php_files()

    def lint_one(path):
        return path, subprocess.run([php, "-l", path], stdout=subprocess.PIPE, stderr=subprocess.STDOUT)

    # 1 つずつ PHP を起動すると遅いので、同時に流す（結果の並びはファイルの順のまま）
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
        for f, p in executor.map(lint_one, files):
            if p.returncode != 0:
                ng.append("NG  構文エラー: %s %s" % (os.path.relpath(f, ROOT), p.stdout.decode("utf-8", "replace").strip()[:200]))
    r.add("PHP の構文", not ng, len(files) - len(ng), ng, round(time.time() - t0, 1), default_screen="コード")


def step_php83(r):
    ng = []
    files = php_files()
    for f in files:
        with open(f, encoding="utf-8") as fh:
            for n, line in enumerate(fh, 1):
                if line.lstrip().startswith(("//", "*", "#!")):
                    continue
                for pat, label in PHP84_PATTERNS:
                    if re.search(pat, line):
                        ng.append("NG  PHP 8.4 の書き方（%s）: %s:%d" % (label, os.path.relpath(f, ROOT), n))
    r.add("PHP 8.3 で動く書き方", not ng, len(files), ng, default_screen="コード")


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
    r.add("リポジトリに秘密が入っていない", not ng, len(tracked), ng, default_screen="コード")


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


# 検証の工程（並びは流す順）。
#   key: --skip・--only で使う名前 / name: 工程の名前 / script: tools/test/ のスクリプト /
#   screen: 印の無い確かめの画面 / secrets: 流したあとに DB に平文の秘密がないかも確かめる
# 工程ごとにテストデータを作り直す（前の工程の操作が、次の工程の確かめに混ざらないように）
STEP_TABLE = [
    {"key": "auth", "name": "ログイン・2 段階認証・アカウント設定", "script": "smoke_auth.py", "screen": "G-01"},
    {"key": "reports", "name": "報告の作成・提出・閲覧の権限・コメント・チェック・確認完了", "script": "smoke_reports.py", "screen": "G-04", "secrets": True},
    {"key": "copy", "name": "過去の報告から写す", "script": "smoke_copy.py", "screen": "G-04"},
    {"key": "fields", "name": "項目マスタ（追加・編集・権限・変更の決まり・限定・並べ替え・有効/無効）", "script": "smoke_fields.py", "screen": "G-12", "secrets": True},
    {"key": "more", "name": "変更なし・提出状況・横並び・臨時の調査・出力・記録・削除データ", "script": "smoke_more.py", "screen": "G-08", "secrets": True},
    {"key": "surveys_all", "name": "全課共通の臨時の調査（管理者・補佐が作る・全課の集計と課ごとのまとめ・出力）", "script": "smoke_surveys_all.py", "screen": "G-11", "secrets": True},
    {"key": "fixes", "name": "件数の数え方・権限と状態の確認・入力の確認・文言（APP-001〜012）", "script": "smoke_fixes.py", "screen": "共通"},
    {"key": "features", "name": "報告まわりの便利機能（コメント一覧・定型文・検索・管理者のホーム など）", "script": "smoke_features.py", "screen": "共通"},
    {"key": "screens", "name": "設計書の検証の観点の残り（ログイン・再設定・ホーム・出力・項目・案件・利用者・アカウント）", "script": "smoke_screens.py", "screen": "共通"},
    {"key": "edges", "name": "設計書で未検証だった項目（時間に頼るもの・同時の新規作成・境目・二重送信 など）", "script": "smoke_edges.py", "screen": "共通"},
    {"key": "viewpoints", "name": "設計書の検証の観点の突き合わせで見つかった残り（G-03〜G-16）", "script": "smoke_viewpoints.py", "screen": "共通"},
    {"key": "org", "name": "組織（ラインごと・異動・管理担当・課長がいない課）", "script": "smoke_org.py", "screen": "G-14"},
    {"key": "deputy", "name": "システム管理者補佐（代行できること・見られないもの）", "script": "smoke_deputy.py", "screen": "G-14"},
    {"key": "theme", "name": "自分の色（土台・強調・背景、読めない組み合わせを断る）", "script": "smoke_theme.py", "screen": "G-16"},
    {"key": "messages", "name": "メッセージ（課長が文言を変える）", "script": "smoke_messages.py", "screen": "G-17"},
    {"key": "requests", "name": "要望・依頼（課長・確認者 → システム管理者の返信）", "script": "smoke_requests.py", "screen": "G-22", "secrets": True},
    {"key": "ledger", "name": "案件台帳の道具・案件の一覧の CSV 出力", "script": "smoke_project_ledger.py", "screen": "G-13"},
    {"key": "conveniences", "name": "便利機能 A〜J（お知らせ・定型文・予定・CSV・メンテナンス・予約・バックアップ・使い方・よくある質問）", "script": "smoke_conveniences.py", "screen": "G-25"},
    {"key": "additions", "name": "あとから足した機能（コメントを直す・調査の見本・提出のお知らせ・チャットの通信の失敗）", "script": "smoke_additions.py", "screen": "G-08"},
    {"key": "actions", "name": "検証に無かった操作（取り消し・再開・締め切り・複製・戻す・並べ替え・自動保存の応答）", "script": "smoke_actions.py", "screen": "共通", "secrets": True},
    {"key": "inputs", "name": "すべての入力部品と、取り下げ・削除・絞り込み・個人の回答の出力", "script": "smoke_inputs.py", "screen": "G-04", "secrets": True},
    {"key": "refusals", "name": "断る処理（誤った入力・無い番号・権限・状態。DB が変わらないこと）", "script": "smoke_refusals.py", "screen": "共通", "secrets": True},
    {"key": "rest", "name": "残りの画面・状態（一覧の中身・件数・組織の CSV・ロック・チャット・古い DB・コマンドの誤り）", "script": "smoke_rest.py", "screen": "共通", "secrets": True},
    {"key": "limits", "name": "時間切れ・同時の保存・上限・壊れたデータ・DB の障害", "script": "smoke_limits.py", "screen": "共通", "secrets": True},
    {"key": "cli", "name": "コマンド（移行・バックアップ・予約の反映・取り込み）", "script": "smoke_cli.py", "screen": "DB"},
]
STEP_KEYS = [step["key"] for step in STEP_TABLE] + ["ui", "ui_session", "ui_chat", "ui_misc", "retention", "units", "office"]


def selected_steps(skip_keys, only_values, with_ui):
    """流す工程を決める。--only は工程の名前（auth など）か画面の番号（G-25 など）。画面の番号は、その画面の確かめを含む工程"""
    chosen_keys = []
    candidate_list = [dict(step) for step in STEP_TABLE]
    if with_ui:
        candidate_list.append({"key": "ui", "name": "画面の自動操作", "script": "ui_check.py", "screen": "共通"})
        candidate_list.append({"key": "ui_session", "name": "自動保存・ログイン切れ・画面の中の送信（実際に届いたか）", "script": "ui_session.py", "screen": "共通"})
        candidate_list.append({"key": "ui_chat", "name": "チャットの返し分け・通信の失敗・代わりの処理（画面）", "script": "ui_chat.py", "screen": "G-25"})
        candidate_list.append({"key": "ui_misc", "name": "画面の補助の残り（コピー・照合・項目の編集・部品が無いとき）", "script": "ui_misc.py", "screen": "共通"})
    candidate_list.append({"key": "retention", "name": "保管期限による削除", "script": "", "screen": "DB"})
    for step in candidate_list:
        step["screens"] = sorted(set(script_screens(step["script"]) if step["script"] else []) | {step["screen"]}, key=screen_sort_key)
        if step["key"] in skip_keys:
            continue
        if only_values and step["key"] not in only_values and not (set(step["screens"]) | {step["screen"]}) & only_values:
            continue
        chosen_keys.append(step["key"])
    return [step for step in candidate_list if step["key"] in chosen_keys]


def write_items_list(path, steps, stamp, is_complete):
    """検証項目一覧（画面ごとの確かめの一覧）を書く"""
    screen_names = design_screens()
    items_by_screen = {}
    for step in steps:
        for screen_code, check_name, is_ok in step.get("items", []):
            items_by_screen.setdefault(screen_code, []).append((check_name, is_ok, step["name"]))
    all_codes = sorted(set(items_by_screen) | set(screen_names) | {"共通", "DB"}, key=screen_sort_key)
    lines = ["# 検証項目一覧", "",
             "自動検証（`tools/test/verify_all.py`）が確かめていることを、詳細設計書の画面（G-01〜G-25）と共通の単位ごとに並べたもの。"
             "確かめの一つ一つの頭の印（`lib_http.section()`）で分けている。流した結果から作るので、手で直さない。", "",
             "| 項目 | 内容 |", "|---|---|",
             "| 作った日時 | %s |" % stamp,
             "| 範囲 | %s |" % ("すべての工程（画面の自動操作を含む）" if is_complete else "一部の工程（--skip・--only を付けて流した）"), "",
             "## 画面ごとの数", "", "| 画面 | 名前 | 確かめの数 | 少ない |", "|---|---|---|---|"]
    for screen_code in all_codes:
        item_count = len(items_by_screen.get(screen_code, []))
        lines.append("| %s | %s | %d | %s |" % (screen_code, screen_names.get(screen_code, COMMON_UNIT_LABELS.get(screen_code, "")), item_count,
                                              "要確認" if item_count < 5 else ""))
    for screen_code in all_codes:
        screen_items = items_by_screen.get(screen_code, [])
        lines += ["", "## %s %s（%d 件）" % (screen_code, screen_names.get(screen_code, COMMON_UNIT_LABELS.get(screen_code, "")), len(screen_items)), ""]
        if not screen_items:
            lines.append("（確かめがない）")
            continue
        current_step_name = None
        for check_name, is_ok, step_name in screen_items:
            if step_name != current_step_name:
                lines.append("- **%s**" % step_name)
                current_step_name = step_name
            lines.append("  - %s%s" % (check_name.replace("|", "｜"), "" if is_ok else "（NG）"))
    with open(path, "w", encoding="utf-8") as fh:
        fh.write("\n".join(lines) + "\n")


def main():
    # 出力先の文字コード（Windows の cp932 など）で表せない文字があっても、表示で止まらないようにする（以降の工程が流れなくなるため）
    for output_stream in (sys.stdout, sys.stderr):
        if hasattr(output_stream, "reconfigure"):
            output_stream.reconfigure(errors="replace")
    ap = argparse.ArgumentParser()
    ap.add_argument("--php", default="")
    ap.add_argument("--ui", action="store_true", help="画面の自動操作検証も行う")
    ap.add_argument("--show", action="store_true", help="自動操作検証でブラウザーを表示する")
    ap.add_argument("--browser-port", type=int, default=0, help="起動済みのブラウザーにつなぐ（--remote-debugging-port の番号）")
    ap.add_argument("--skip", default="", help="省く工程（カンマ区切り）: " + ",".join(STEP_KEYS))
    ap.add_argument("--only", default="", help="流す工程（カンマ区切り）。工程の名前か画面の番号（G-25 など。その画面の確かめを含む工程）")
    ap.add_argument("--list", action="store_true", help="工程と、工程ごとの画面の番号を出して終わる（流さない）")
    a = ap.parse_args()
    skip = set(x for x in a.skip.split(",") if x)
    only = set(x for x in a.only.split(",") if x)
    unknown_values = [x for x in skip | only if x not in STEP_KEYS and not SCREEN_CODE_PATTERN.match(x)]
    if unknown_values:
        print("知らない工程・画面の番号: %s（工程: %s）" % (", ".join(sorted(unknown_values)), ", ".join(STEP_KEYS)))
        return 2
    steps_to_run = selected_steps(skip, only, a.ui)
    if a.list:
        for step in selected_steps(set(), set(), True):
            print("%-13s %-60s %s" % (step["key"], step["name"], " ".join(step["screens"])))
        return 0

    php = find_php(a.php)
    if not php:
        print("PHP が見つかりません（--php で指定してください）")
        return 2
    stamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    rec_root = os.path.join(HERE, "output", "検証記録")
    out = os.path.join(rec_root, stamp)
    os.makedirs(out, exist_ok=True)
    r = Runner(out)
    # 強制終了（止める操作でプロセスごと止められた）ときも分かるように、先に「途中で止まった」と書いておく（最後まで流れたら書き直す）
    with open(os.path.join(out, "結果.md"), "w", encoding="utf-8") as f:
        f.write("".join(line + chr(10) for line in ["# 自動検証の結果 %s" % stamp, "", "| 判定 | 中断（最後まで流れなかった。強制終了など） |", "|---|---|"]))
    # 検証は自分用の DB を使う（画面を見ている dev.sqlite のテーマ・ログインに触れない）。
    # PHP（サーバー・dev_seed.php など）は環境変数 MR_VERIFY_DB を見て、この DB と「2 段階認証を飛ばさない」設定で動く（app/config/config.php）
    db = os.path.join(ROOT, "app", "data", "verify.sqlite")
    os.environ["MR_VERIFY_DB"] = db
    py = sys.executable
    for signal_name in ("SIGTERM", "SIGBREAK", "SIGINT"):
        if hasattr(signal, signal_name):
            signal.signal(getattr(signal, signal_name), stop_on_signal)
    interrupted_reason = ""
    server = None
    try:
        say("自動検証 %s（PHP: %s）" % (stamp, php))
        step_lint(r, php)
        step_php83(r)
        step_repo(r)
        r.run("メッセージの一覧が最新", [sys.executable, "-I", os.path.join(ROOT, "tools", "lib", "msg_catalog.py"), "--check"], default_screen="コード")
        # 操作マニュアル（docs/operation_manual.md）が使う画面の画像がそろい、使っていない画像がない（tools/lib/manual_capture.py）
        # 入力の確かめの正規表現は、文字列全体の先頭・末尾（\A・\z）で書く（^・$ は改行を混ぜた値を通すおそれ）
        r.run("正規表現は文字列全体で確かめる", [sys.executable, "-I", os.path.join(HERE, "check_regex_anchors.py")], default_screen="コード")
        # 画面の操作（action・app.js の補助）が検証に入っているか。入れ忘れを NG にする
        r.run("操作が自動検証に入っている", [sys.executable, "-I", os.path.join(HERE, "check_action_coverage.py")], default_screen="コード")
        r.run("操作マニュアルの画像がそろっている", [sys.executable, "-I", os.path.join(ROOT, "tools", "lib", "manual_capture.py"), "--check"], default_screen="共通")
        # よくある質問のチャットの判定（システムに寄る・グレーゾーン・明確にアウトの入力。tools/test/faq_input_cases.tsv）。サーバー・DB は使わない
        r.run("よくある質問の自動入力検証", [php, os.path.join("tools", "test", "faq_input_check.php")], default_screen="G-25")
        # 部品（前置き・たとえ・中心の言葉・語尾）のすべての組み合わせ（tools/test/faq_combo_cases.txt）。言い方を変えても判定が崩れないか
        r.run("よくある質問の組み合わせ入力検証", [php, os.path.join("tools", "test", "faq_combo_check.php")], default_screen="G-25")
        r.run("ライブラリの関数の誤り・異常系", [php, os.path.join("tools", "test", "check_libs.php")], default_screen="共通")
        if "units" not in skip and (not only or only & {"units", "G-10", "G-03", "共通"}):
            # 画面を通さずに確かめる決まり（検証の印・対象月の切り替え・出力の数式・XML）。サーバー・検証の DB は使わない
            r.run("関数の確かめ（検証の印・対象月・出力の数式・XML）", [php, os.path.join("tools", "test", "check_units.php")], default_screen="G-10")
        if "office" not in skip and (not only or "office" in only or "G-10" in only):
            # 出力の xlsx・CSV を表計算のソフト（Excel・あれば LibreOffice）で実際に開く
            r.run("表計算のソフトで開く（出力の xlsx・CSV）", [py, "-I", os.path.join(HERE, "check_office.py"), "--php", php], default_screen="G-10", timeout=600)

        port = free_port()
        base = "http://127.0.0.1:%d" % port
        server = subprocess.Popen(php_server_command(php, port),
                                  cwd=ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if not wait_server(base):
            r.add("サーバーの起動", False, 0, ["NG  ローカルのサーバーが起動しません"])
        else:
            seed = [php, os.path.join("tools", "test", "dev_seed.php"), "--yes"]
            for step in steps_to_run:
                if server.poll() is not None:
                    raise Interrupted("サーバーが止まった")
                if step["key"] == "retention":
                    r.run("保管期限による削除", [php, os.path.join("tools", "test", "check_retention.php")], default_screen="DB")
                    continue
                r.run("テストデータの作成（%s）" % step["key"], seed)
                if step["key"] == "auth":
                    created = subprocess.run([php, os.path.join("app", "bin", "create_user.php"), "--login=verify01", "--name=検証", "--dept=A課", "--admin"],
                                             cwd=ROOT, stdout=subprocess.PIPE)
                    temp_password_match = re.search(r"仮のパスワード: (\S+)", created.stdout.decode("utf-8", "replace"))
                    command = [py, "-I", os.path.join(HERE, "smoke_auth.py"), "--base", base, "--login", "verify01",
                               "--temp-password", temp_password_match.group(1) if temp_password_match else "x", "--lock-user", "ippan_b1", "--admin"]
                elif step["key"] == "ui":
                    command = [py, "-I", os.path.join(HERE, "ui_check.py"), "--base", base, "--db", db, "--out", os.path.join(out, "画面")]
                    if a.show:
                        command.append("--show")
                    if a.browser_port:
                        command += ["--browser-port", str(a.browser_port)]
                elif step["key"] in ("edges", "cli", "rest", "limits"):
                    # 同時の新規作成で、同じ DB のサーバーをもう 1 本立てるので PHP を渡す
                    command = [py, "-I", os.path.join(HERE, step["script"]), "--base", base, "--db", db, "--php", php]
                else:
                    command = [py, "-I", os.path.join(HERE, step["script"]), "--base", base, "--db", db]
                r.run(step["name"], command, default_screen=step["screen"])
                if step.get("secrets"):
                    r.run("DB に平文の秘密がない（%s）" % step["key"], [py, "-I", os.path.join(HERE, "check_db_secrets.py"), db], default_screen="DB")
    except (Interrupted, KeyboardInterrupt) as stop_error:
        interrupted_reason = str(stop_error) or "Ctrl+C"
    finally:
        if server is not None:
            server.terminate()
            try:
                server.wait(timeout=10)
            except Exception:  # noqa: BLE001
                server.kill()

    passed = sum(s["passed"] for s in r.steps)
    failed = sum(s["failed"] for s in r.steps) + sum(1 for s in r.steps if not s["ok"] and s["failed"] == 0)
    ok = all(s["ok"] for s in r.steps) and not interrupted_reason
    verdict = "中断" if interrupted_reason else ("成功" if ok else "失敗")
    is_complete = not skip and not only and a.ui and not interrupted_reason
    lines = ["# 自動検証の結果 %s" % stamp, "", "| 判定 | %s |" % verdict, "|---|---|",
             "| 確認の数 | OK %d / NG %d |" % (passed, failed), "| PHP | %s |" % php, "| 画面の自動操作 | %s |" % ("実施" if a.ui else "省略"),
             "| 流した工程 | %s |" % ("すべて" if not skip and not only else "一部（--skip %s / --only %s）" % (a.skip or "なし", a.only or "なし"))]
    if interrupted_reason:
        lines.append("| 中断の理由 | %s（残りの工程は流していない） |" % interrupted_reason)
    lines += ["", "## 工程", "", "| 工程 | 判定 | OK | NG | 秒 | ログ |", "|---|---|---|---|---|---|"]
    for s in r.steps:
        lines.append("| %s | %s | %d | %d | %s | %s |" % (s["name"], "成功" if s["ok"] else "失敗", s["passed"], s["failed"], s["seconds"], s["log"]))
    screen_totals = {}
    for s in r.steps:
        for screen_code, (screen_ok, screen_ng) in s.get("screen_counts", {}).items():
            total = screen_totals.setdefault(screen_code, [0, 0])
            total[0] += screen_ok
            total[1] += screen_ng
    screen_names = design_screens()
    lines += ["", "## 画面ごと", "", "| 画面 | 名前 | OK | NG |", "|---|---|---|---|"]
    for screen_code in sorted(set(screen_totals) | set(screen_names), key=screen_sort_key):
        screen_ok, screen_ng = screen_totals.get(screen_code, [0, 0])
        lines.append("| %s | %s | %d | %d |" % (screen_code, screen_names.get(screen_code, COMMON_UNIT_LABELS.get(screen_code, "")), screen_ok, screen_ng))
    not_done_list = [(s["name"], text) for s in r.steps for text in s.get("not_done", [])]
    if not_done_list:
        lines += ["", "## 未実施（確かめられなかったもの）", ""] + ["- %s: %s" % (step_name, text) for step_name, text in not_done_list]
    bad = [s for s in r.steps if not s["ok"]]
    if bad:
        lines += ["", "## 失敗の内容", ""]
        for s in bad:
            lines.append("### " + s["name"])
            lines += ["- " + x for x in s["ng"]] or ["```", s["tail"], "```"]
    with open(os.path.join(out, "結果.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(lines) + "\n")
    write_items_list(os.path.join(out, "検証項目一覧.md"), r.steps, stamp, is_complete)
    if is_complete and ok:
        write_items_list(os.path.join(ROOT, "docs", "verification_items.md"), r.steps, stamp, True)
    index = os.path.join(rec_root, "一覧.csv")
    new = not os.path.exists(index)
    with open(index, "a", encoding="utf-8-sig", newline="") as f:
        w = csv.writer(f)
        if new:
            w.writerow(["日時", "判定", "OK", "NG", "画面の自動操作"])
        w.writerow([stamp, verdict, passed, failed, "実施" if a.ui else "省略"])
    try:
        print("\n判定: %s（OK %d / NG %d%s）%s" % (verdict, passed, failed, "・未実施 %d" % len(not_done_list) if not_done_list else "", "　中断の理由: " + interrupted_reason if interrupted_reason else ""))
        print("記録: " + os.path.join(out, "結果.md"))
    except (OSError, ValueError):
        pass
    return 0 if ok else (130 if interrupted_reason else 1)


if __name__ == "__main__":
    sys.exit(main())
