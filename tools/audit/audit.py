#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
レポート管理システム 監査スクリプト

次の3種類の監査を行い、結果を画面と HTML レポートに出力する。
  1. 静的監査 : リポジトリ内の PHP ソースを読み、既知の問題パターンを検出する
  2. HTTP監査 : 稼働サイトへアクセスし、認証・権限・公開範囲・セキュリティヘッダーを確認する
  3. DB監査   : SQLite ファイルを読み取り専用で開き、データ整合性を確認する

Python 3.8 以上の標準ライブラリだけで動作する（pip での追加インストールは不要）。

HTTP監査でサーバーへ送る POST はログインのみ。その他はすべて GET で、
データを登録・更新・削除する処理は呼び出さない。

使い方:
    python3 audit.py                 # 全部実行
    python3 audit.py --skip-http     # サーバーにアクセスしない
    python3 audit.py --help          # オプション一覧
"""

import argparse
import getpass
import html
import http.cookiejar
import json
import os
import re
import sqlite3
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import webbrowser
from datetime import datetime
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent

SEVERITIES = ["高", "中", "低", "情報"]
SEV_ORDER = {s: i for i, s in enumerate(SEVERITIES)}

# レポートの項目（コメント対象 field_name の許可値）。詳細設計書 4.1 と同じ。
ALLOWED_FIELDS = ["report_date", "name", "subject", "content", "issues", "remarks"]
# 役職。DB の CHECK 制約と同じ。
ALLOWED_ROLES = ["一般", "主任", "課長代理", "課長", "部長"]

USER_AGENT = "ReportSystemAudit/1.0 (Python urllib)"


# ============================================================
# 結果の収集
# ============================================================

class Result:
    def __init__(self):
        self.findings = []
        self.passes = []
        self.notes = []
        self.http_matrix = None
        self.db_info = None
        self.db_expected_visible = None

    def finding(self, category, id_, severity, title, location, detail, recommendation):
        assert severity in SEV_ORDER
        if isinstance(location, str):
            location = [location]
        self.findings.append({
            "category": category,
            "id": id_,
            "severity": severity,
            "title": title,
            "location": [x for x in (location or []) if x],
            "detail": detail,
            "recommendation": recommendation,
        })

    def ok(self, category, id_, title, detail):
        self.passes.append({"category": category, "id": id_, "title": title, "detail": detail})

    def note(self, category, text):
        self.notes.append({"category": category, "text": text})


def step(text):
    print("  - " + text)


# ============================================================
# 1. 静的監査
# ============================================================

def find_lines(lines, pattern):
    rx = re.compile(pattern)
    return [(i + 1, line.strip()) for i, line in enumerate(lines) if rx.search(line)]


def static_audit(res, repo_root):
    cat = "静的"
    print("[1/3] 静的監査（ソースコード）")

    skip_dirs = {".git", "tools", "vendor", "node_modules"}
    src = {}
    text = {}
    for path in sorted(repo_root.rglob("*.php")):
        rel_parts = path.relative_to(repo_root).parts
        if any(p in skip_dirs for p in rel_parts[:-1]):
            continue
        rel = path.relative_to(repo_root).as_posix()
        content = path.read_text(encoding="utf-8", errors="replace")
        src[rel] = content.splitlines()
        text[rel] = content

    if not src:
        res.note(cat, "PHP ファイルが見つかりません（リポジトリ: {}）。静的監査を省略しました。".format(repo_root))
        return

    rels = sorted(src)
    step("対象 PHP ファイル: {} 件".format(len(rels)))

    # ---- S-01 requireLogin() の相対パスリダイレクト ----
    if "lib/auth.php" in src:
        hits = find_lines(src["lib/auth.php"], r"Location:\s*login\.php")
        callers = [r for r in rels
                   if re.match(r"^public/[^/]+/", r) and re.search(r"require(Login|Admin)\s*\(", text[r])]
        if hits and callers:
            res.finding(cat, "S-01", "高", "未ログイン時のリダイレクト先が相対パス",
                        ["lib/auth.php:{}".format(hits[0][0])] + callers,
                        "requireLogin() が 'Location: login.php' を返す。サブディレクトリ配下の {} ファイル"
                        "（reports/・comments/・users/）から呼ばれると、ブラウザは存在しない URL"
                        "（例: reports/login.php）へ遷移する。".format(len(callers)),
                        "ログイン画面の URL をアプリのルート基準で組み立てる（呼び出し元の階層に依存しないパス）。")
        else:
            res.ok(cat, "S-01", "requireLogin() のリダイレクト先", "相対パスの問題は検出されませんでした。")

    all_text = "\n".join(text.values())

    # ---- S-02 外部キー制約が無効 ----
    if "foreign_keys" not in all_text:
        loc = ["config/database.php"] if "config/database.php" in src else []
        res.finding(cat, "S-02", "高", "SQLite の外部キー制約が有効化されていない", loc,
                    "PRAGMA foreign_keys = ON を実行している箇所がない。SQLite は既定で外部キーを無視するため、"
                    "comments の ON DELETE CASCADE 等が動作せず、レポート削除時にコメントが孤立して残る。",
                    "PDO 接続直後に $pdo->exec('PRAGMA foreign_keys = ON') を実行する。"
                    "あわせて既存の孤立データを確認する（DB監査 D-02）。")
    else:
        res.ok(cat, "S-02", "外部キー制約の有効化", "foreign_keys の設定が見つかりました。")

    # ---- S-03 CSRF 対策のない POST 処理 ----
    post_rx = re.compile(r"REQUEST_METHOD'\]\s*[!=]==\s*'POST'|INPUT_POST|\$_POST\[")
    no_csrf_high, no_csrf_low, with_csrf = [], [], []
    for r in rels:
        if not post_rx.search(text[r]):
            continue
        if "hash_equals" in text[r] and "csrf" in text[r]:
            with_csrf.append(r)
        elif re.search(r"(^|/)(login|setup_admin)\.php$", r):
            no_csrf_low.append(r)
        else:
            no_csrf_high.append(r)
    if no_csrf_high:
        res.finding(cat, "S-03", "高", "CSRF 対策のない更新処理", no_csrf_high,
                    "POST を受け付けるがトークン検証（hash_equals + csrf_token）がない処理が {} 件ある。"
                    "ログイン中の利用者が罠ページを開くと、本人の意図しない登録・更新・削除が実行される可能性がある。"
                    .format(len(no_csrf_high)),
                    "ユーザー管理（users/）と同じトークン方式を共通関数化し、すべての POST 処理と送信元フォームに適用する。")
    if no_csrf_low:
        res.finding(cat, "S-03b", "低", "ログイン・初期設定フォームに CSRF 対策なし", no_csrf_low,
                    "ログイン CSRF（攻撃者のアカウントでログインさせられる）等のリスクがある。影響は限定的。",
                    "必要に応じてログインフォームにもトークンを付与する。")
    if with_csrf:
        res.ok(cat, "S-03", "CSRF トークン検証あり", ", ".join(with_csrf))

    # ---- S-04 GET でのログアウト ----
    if "public/logout.php" in text and "REQUEST_METHOD" not in text["public/logout.php"]:
        res.finding(cat, "S-04", "低", "GET リクエストでログアウトできる", "public/logout.php",
                    "画像タグ等の埋め込みで利用者を強制的にログアウトさせられる。",
                    "POST + CSRF トークンでのログアウトに変更する。")

    # ---- S-05 項目定義の重複 ----
    field_files = [r for r in rels if "'report_date'" in text[r] and "'remarks'" in text[r]]
    if len(field_files) > 1:
        res.finding(cat, "S-05", "中", "レポート項目の定義が複数ファイルに重複", field_files,
                    "項目（report_date〜remarks）の一覧・許可リストが {} ファイルに個別に記述されている。"
                    "項目の追加・変更時に修正漏れが起きやすい。".format(len(field_files)),
                    "項目定義を1か所（共通定義ファイルまたは項目マスタテーブル）に集約し、各画面・処理から参照する。")

    # ---- S-06 閲覧範囲 SQL の重複・循環リスク ----
    cte_files = [r for r in rels if re.search(r"WITH\s+RECURSIVE", text[r])]
    if len(cte_files) > 1:
        res.finding(cat, "S-06", "中", "再帰 SQL（閲覧範囲・配下判定）が重複", cte_files,
                    "WITH RECURSIVE による配下ユーザー取得が {} ファイルに個別実装されている。"
                    "権限ルール変更時に一部だけ修正される恐れがある。".format(len(cte_files)),
                    "閲覧可能ユーザー ID の取得を共通関数にまとめる。")
    union_all = [r for r in cte_files if re.search(r"UNION\s+ALL", text[r])]
    if union_all:
        res.finding(cat, "S-06b", "中", "再帰 SQL で UNION ALL を使用（循環データで無限再帰）", union_all,
                    "上司設定に循環がある場合、UNION ALL の再帰 CTE は終了しない。"
                    "他のファイルは UNION（重複除去）を使っており、実装が統一されていない。",
                    "UNION に統一する。")

    # ---- S-07 エスケープされていない出力（要確認） ----
    raw_out = []
    for r in rels:
        for ln, t in find_lines(src[r], r"<\?=\s*(?!h\(|\(int\)|count\(|date\(|htmlspecialchars\()"):
            raw_out.append("{}:{}  {}".format(r, ln, t))
    if raw_out:
        res.finding(cat, "S-07", "情報", "h() を通さない出力（要目視確認）", raw_out[:30],
                    "<?= ?> で h() / (int) を通さずに出力している箇所が {} 件ある。数値など安全な値の可能性が高いが、"
                    "利用者の入力値でないことを確認する。".format(len(raw_out)),
                    "利用者入力や DB の値を出力する場合は必ず h() を使う。")
    else:
        res.ok(cat, "S-07", "HTML 出力のエスケープ", "h() を通さない <?= 出力は検出されませんでした。")

    # ---- S-08 例外メッセージの画面出力 ----
    leak = []
    for r in rels:
        for ln, t in find_lines(src[r], r"getMessage\(\)"):
            if "error_log" not in t:
                leak.append("{}:{}".format(r, ln))
    if leak:
        res.finding(cat, "S-08", "中", "例外メッセージを画面に出力", leak,
                    "例外の詳細（ファイルパス等）が利用者に表示される。",
                    "画面には汎用メッセージのみを出し、詳細は error_log に記録する。")

    # ---- S-09 / S-10 セットアップスクリプト ----
    if "public/setup.php" in text:
        t = text["public/setup.php"]
        if not re.search(r"require(Login|Admin)\s*\(", t):
            detail = "認証なしで実行できるセットアップ処理が公開ディレクトリにある。"
            if re.search(r"CREATE\s+TABLE", t) and "user_id" not in t:
                detail += " 中の CREATE TABLE は user_id 列を持たない旧スキーマで、現行 DB と一致しない。"
            res.finding(cat, "S-09", "中", "認証なしのセットアップスクリプトが公開されている", "public/setup.php",
                        detail, "削除する。必要ならスキーマ定義は公開ディレクトリ外の SQL ファイルで管理する。")
    if (repo_root / "setup_admin.php").exists():
        res.finding(cat, "S-10", "低", "初期管理者登録スクリプトが残っている", "setup_admin.php",
                    "ファイル内で「直ちに削除してください」と案内しているが残存している。ユーザーが存在する間は"
                    "実行不可だが、不要なファイルは攻撃面になる。HTTP監査 H-05 で Web から到達できるかを確認する。",
                    "本番環境から削除する（リポジトリには残してもよいが、デプロイ対象から外す）。")

    # ---- S-11 DB ファイルのリポジトリ同梱 ----
    db_suffixes = (".sqlite", ".sqlite3", ".db", ".sqlite.backup")
    db_files = []
    for p in repo_root.rglob("*"):
        if not p.is_file() or not p.name.endswith(db_suffixes):
            continue
        parts = p.relative_to(repo_root).parts
        if ".git" in parts or "tools" in parts:
            continue
        db_files.append(p.relative_to(repo_root).as_posix())
    if db_files:
        gi = repo_root / ".gitignore"
        ignored = gi.exists() and "sqlite" in gi.read_text(encoding="utf-8", errors="replace")
        if not ignored:
            res.finding(cat, "S-11", "中", "DB ファイルがリポジトリに含まれている", sorted(db_files),
                        "パスワードハッシュを含む DB がバージョン管理されている。リポジトリの閲覧者全員がデータを取得でき、"
                        "デプロイ時に本番 DB を上書きする事故も起こり得る。",
                        ".gitignore で除外し、スキーマは SQL ファイルとして管理する。")

    # ---- S-12 デバッグ出力の残存 ----
    debug = []
    for r in rels:
        for ln, t in find_lines(src[r], r"\b(var_dump|print_r|phpinfo|var_export)\s*\(|display_errors"):
            debug.append("{}:{}  {}".format(r, ln, t))
    if debug:
        res.finding(cat, "S-12", "低", "デバッグ用の出力が残っている", debug,
                    "内部情報が表示される恐れがある。", "削除する。")
    else:
        res.ok(cat, "S-12", "デバッグ出力", "var_dump / print_r / phpinfo は検出されませんでした。")

    # ---- S-13 SQL への変数埋め込み（要確認） ----
    interp = []
    for r in rels:
        for ln, t in find_lines(src[r], r'"[^"]*\b(SELECT|INSERT|UPDATE|DELETE|WHERE|FROM)\b[^"]*\$[A-Za-z_]'):
            interp.append("{}:{}  {}".format(r, ln, t))
    if interp:
        res.finding(cat, "S-13", "中", "SQL 文字列への変数埋め込み（要確認）", interp,
                    "ダブルクォート文字列内の SQL に変数を展開している。利用者入力が含まれると SQL インジェクションになる。",
                    "プレースホルダとバインドを使う。")
    prepare_count = sum(1 for r in rels if "->prepare(" in text[r])
    res.ok(cat, "S-13", "プリペアドステートメントの使用", "{} ファイルで prepare() を使用。".format(prepare_count))

    # ---- S-14 登録と更新で入力チェックが不一致 ----
    store, update = "public/reports/store.php", "public/reports/update.php"
    if store in text and update in text:
        if "strlen(" in text[update] and "strlen(" not in text[store]:
            res.finding(cat, "S-14", "中", "レポート登録時に長さチェックがない", [store, update],
                        "更新処理（update.php）は氏名 300 バイト・件名 600 バイトの上限を検証するが、"
                        "登録処理（store.php）には上限チェックがない。HTML の maxlength はブラウザ側の制限で回避できる。",
                        "入力チェックを共通関数にし、登録と更新で同じ検証を行う。")

    # ---- S-15 役職リストの重複 ----
    role_files = [r for r in rels if "'課長代理'" in text[r]]
    if len(role_files) > 1:
        res.finding(cat, "S-15", "低", "役職リストが複数ファイルに重複", role_files,
                    "役職の一覧が {} ファイルと DB の CHECK 制約に個別に定義されている。".format(len(role_files)),
                    "共通定義に集約する。")

    # ---- S-16 ログイン試行回数の制限 ----
    if "public/login.php" in text:
        if not re.search(r"(?i)attempt|lock|throttle|sleep\(|usleep\(", text["public/login.php"]):
            res.finding(cat, "S-16", "低", "ログイン試行回数の制限がない", "public/login.php",
                        "パスワードの総当たり攻撃を抑止する仕組みがない。",
                        "失敗回数に応じた待機・一時ロック等を検討する。")

    # ---- S-17 コメント操作ボタンの表示制御 ----
    detail_php = "public/reports/detail.php"
    if detail_php in text:
        if "comment-edit-button" in text[detail_php] and "comment['user_id']" not in text[detail_php]:
            res.finding(cat, "S-17", "低", "権限のないコメントにも編集・削除ボタンが表示される", detail_php,
                        "ボタン表示時に投稿者を判定していない（サーバー側では 403 で拒否される）。投稿者名も表示されない。",
                        "投稿者本人または管理者のときだけボタンを表示し、投稿者名を表示する。")

    # ---- 良好な点 ----
    if "password_hash(" in all_text and "password_verify(" in all_text:
        res.ok(cat, "P-01", "パスワードのハッシュ化", "password_hash / password_verify を使用。")
    if re.search(r"session_regenerate_id\(\s*true\s*\)", all_text):
        res.ok(cat, "P-02", "セッション固定攻撃対策", "ログイン時に session_regenerate_id(true) を実行。")
    if "config/database.php" in text and "/../data/" in text["config/database.php"]:
        res.ok(cat, "P-03", "DB ファイルの配置",
               "DB は public/ の外（data/）に配置。ただし実際に Web から取得できないかは HTTP監査 H-04 で確認。")


# ============================================================
# 2. HTTP 監査
# ============================================================

class _NoRedirect(urllib.request.HTTPRedirectHandler):
    """リダイレクトを自動で追わない（3xx を HTTPError として受け取る）"""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class HttpResponse:
    def __init__(self, url, status, headers=None, body=b"", error=None):
        self.url = url
        self.status = status
        self.headers = headers
        self.bytes = body or b""
        self.body = self.bytes.decode("utf-8", errors="replace")
        self.error = error
        self.location = headers.get("Location") if headers is not None else None
        self.location_abs = urllib.parse.urljoin(url, self.location) if self.location else None

    def header(self, name):
        return self.headers.get(name) if self.headers is not None else None

    def header_all(self, name):
        if self.headers is None:
            return []
        return self.headers.get_all(name) or []


class HttpClient:
    def __init__(self, delay_ms):
        self.delay = delay_ms / 1000.0

    def request(self, url, method="GET", data=None, jar=None, max_bytes=512 * 1024):
        time.sleep(self.delay)
        if jar is None:
            jar = http.cookiejar.CookieJar()
        opener = urllib.request.build_opener(_NoRedirect, urllib.request.HTTPCookieProcessor(jar))
        body = None
        headers = {"User-Agent": USER_AGENT}
        if data is not None:
            body = urllib.parse.urlencode(data).encode("utf-8")
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        req = urllib.request.Request(url, data=body, headers=headers, method=method)
        try:
            resp = opener.open(req, timeout=30)
            try:
                return HttpResponse(url, resp.status, resp.headers, resp.read(max_bytes))
            finally:
                resp.close()
        except urllib.error.HTTPError as e:
            try:
                content = e.read(max_bytes)
            except Exception:
                content = b""
            finally:
                e.close()
            return HttpResponse(url, e.code, e.headers, content)
        except (urllib.error.URLError, OSError) as e:
            return HttpResponse(url, 0, None, b"", error=str(e))


def is_redirect(status):
    return status in (301, 302, 303, 307, 308)


def ids_from_html(body, pattern):
    return sorted({int(m) for m in re.findall(pattern, body)})


def http_audit(res, base_url, users, password, delay_ms, max_probe):
    cat = "HTTP"
    print("[2/3] HTTP監査（稼働サイト）")

    if not base_url:
        res.note(cat, "base_url が未設定のため HTTP監査を省略しました（audit_config.json または --base-url で指定）。")
        return
    step("対象: " + base_url)
    client = HttpClient(delay_ms)

    # ---- H-01 疎通 ----
    login = client.request(base_url + "login.php")
    if login.status != 200:
        res.finding(cat, "H-01", "高", "ログイン画面に接続できない", base_url + "login.php",
                    "HTTP {} {}".format(login.status, login.error or ""),
                    "URL・ネットワーク・サーバー状態を確認する。以降の HTTP監査は省略しました。")
        return
    res.ok(cat, "H-01", "ログイン画面への接続", "HTTP 200")

    # ---- H-02 セキュリティヘッダー / Cookie 属性 ----
    step("セキュリティヘッダーと Cookie 属性")
    is_https = base_url.startswith("https://")
    xfo = login.header("X-Frame-Options")
    csp = login.header("Content-Security-Policy")
    if not xfo and not (csp and "frame-ancestors" in csp):
        res.finding(cat, "H-02a", "中", "クリックジャッキング対策ヘッダーがない", "login.php",
                    "X-Frame-Options / CSP frame-ancestors が設定されていない。他サイトの iframe に埋め込まれ、"
                    "ボタンを誤クリックさせられる恐れがある。",
                    "X-Frame-Options: DENY（または SAMEORIGIN）を返す。")
    else:
        res.ok(cat, "H-02a", "クリックジャッキング対策", "X-Frame-Options: {} / CSP: {}".format(xfo, csp))

    if not login.header("X-Content-Type-Options"):
        res.finding(cat, "H-02b", "低", "X-Content-Type-Options ヘッダーがない", "login.php",
                    "MIME スニッフィング対策が未設定。", "X-Content-Type-Options: nosniff を返す。")
    if is_https and not login.header("Strict-Transport-Security"):
        res.finding(cat, "H-02c", "低", "HSTS ヘッダーがない", "login.php",
                    "Strict-Transport-Security が未設定。初回アクセス時に HTTP へ誘導される余地がある。",
                    "運用上問題がなければ Strict-Transport-Security を設定する。")
    if login.header("X-Powered-By"):
        res.finding(cat, "H-02d", "低", "X-Powered-By で PHP バージョンを公開", "login.php",
                    "X-Powered-By: " + login.header("X-Powered-By"), "expose_php = Off にする。")

    cookies = login.header_all("Set-Cookie")
    if cookies:
        # レポートにセッション ID を残さないよう値を伏せる
        set_cookie = re.sub(r"(PHPSESSID=)[^;,\s]+", r"\1********", " | ".join(cookies))
        missing = []
        if not re.search(r"(?i)httponly", set_cookie):
            missing.append("HttpOnly")
        if is_https and not re.search(r"(?i);\s*secure", set_cookie):
            missing.append("Secure")
        if not re.search(r"(?i)samesite", set_cookie):
            missing.append("SameSite")
        if missing:
            sev = "中" if ("HttpOnly" in missing or "Secure" in missing) else "低"
            res.finding(cat, "H-02e", sev, "セッション Cookie に属性がない: " + ", ".join(missing), "login.php",
                        "Set-Cookie: " + set_cookie,
                        "session_set_cookie_params() または php.ini で httponly / secure / samesite=Lax を設定する。")
        else:
            res.ok(cat, "H-02e", "セッション Cookie 属性", set_cookie)
    else:
        res.note(cat, "ログイン画面で Set-Cookie が返らなかったため Cookie 属性は確認できませんでした。")

    # ---- H-03 HTTP から HTTPS への誘導 ----
    if is_https:
        plain = "http://" + base_url[len("https://"):] + "login.php"
        r = client.request(plain)
        if r.status == 200:
            res.finding(cat, "H-03", "中", "HTTP（平文）でもアクセスできる", plain,
                        "HTTPS へリダイレクトされず、パスワードが平文で送信され得る。",
                        ".htaccess 等で HTTPS へ 301 リダイレクトする。")
        elif is_redirect(r.status) and r.location_abs and r.location_abs.startswith("https://"):
            res.ok(cat, "H-03", "HTTPS への誘導", "HTTP {} → {}".format(r.status, r.location_abs))
        else:
            res.ok(cat, "H-03", "HTTP アクセス", "HTTP {} {}".format(r.status, r.error or ""))

    # ---- H-04 / H-05 公開ディレクトリ外のファイル ----
    step("公開ディレクトリ外のファイルへの到達可否")
    m = re.match(r"^(.*/)public/$", base_url)
    if m:
        app_root = m.group(1)
        probes = [
            ("data/reports.sqlite", "sqlite"),
            ("data/reports.sqlite.backup", "sqlite"),
            (".git/HEAD", "git"),
            ("setup_admin.php", "setup"),
            ("config/database.php", "php"),
            ("lib/auth.php", "php"),
        ]
        php_reachable = []
        for path, kind in probes:
            url = app_root + path
            # DB は先頭 16 バイトだけ読む（中身はダウンロードしない）
            r = client.request(url, max_bytes=16 if kind == "sqlite" else 4096)
            if r.status != 200:
                res.ok(cat, "H-04", "到達不可: " + path, "HTTP {}".format(r.status))
                continue
            if kind == "sqlite":
                if r.bytes.startswith(b"SQLite format 3"):
                    res.finding(cat, "H-04", "高", "DB ファイルを Web からダウンロードできる: " + path, url,
                                "ユーザー名・パスワードハッシュ・レポート内容を含む DB を誰でも取得できる"
                                "（本監査は先頭 16 バイトのみ取得）。",
                                "data/ に .htaccess（Require all denied）を置く、またはドキュメントルートを public/ にする。")
                else:
                    res.finding(cat, "H-04", "中", "DB パスが HTTP 200 を返す（要確認）: " + path, url,
                                "SQLite の署名ではないため独自エラーページの可能性があるが、ブラウザで確認すること。",
                                "data/ への HTTP アクセスを拒否する。")
            elif kind == "git":
                if r.body.startswith("ref:"):
                    res.finding(cat, "H-04", "高", ".git ディレクトリが公開されている", url,
                                "ソースコード・履歴（DB を含む）を復元される恐れがある。",
                                ".git を公開領域から外す、またはアクセスを拒否する。")
            elif kind == "setup":
                res.finding(cat, "H-05", "高", "初期管理者登録スクリプトが Web から実行できる", url,
                            "setup_admin.php が公開されている。ユーザーが 0 件になった場合、誰でも管理者を作成できる。",
                            "サーバーから削除する。")
            elif kind == "php":
                php_reachable.append(url)
        if php_reachable:
            res.finding(cat, "H-04b", "中", "公開ディレクトリ外の PHP が Web から実行できる", php_reachable,
                        "ドキュメントルートが public/ ではなく、アプリのルートが公開されている。"
                        "public/ 外に置いたファイルの保護が効いていない。",
                        "config/・lib/ に .htaccess（Require all denied）を置く、またはドキュメントルートを public/ にする。")
    else:
        res.note(cat, "base_url が .../public/ 形式でないため、公開ディレクトリ外ファイルの確認（H-04/H-05）を省略しました。")

    # ---- H-06 未ログイン時のアクセス制御 ----
    step("未ログイン時のアクセス制御")
    protected = [
        "index.php",
        "reports/create.php",
        "reports/detail.php?id=1",
        "reports/edit.php?id=1",
        "comments/comment_edit.php?id=1",
        "users/users.php",
    ]
    broken, exposed, ok_list = [], [], []
    for p in protected:
        r = client.request(base_url + p)
        if is_redirect(r.status):
            f = client.request(r.location_abs)
            if f.status == 200 and 'name="password"' in f.body:
                ok_list.append(p)
            else:
                broken.append("{} → Location: {} → {} [HTTP {}]".format(p, r.location, r.location_abs, f.status))
        elif r.status == 200:
            exposed.append(p)
        else:
            ok_list.append("{} (HTTP {})".format(p, r.status))
    if exposed:
        res.finding(cat, "H-06", "高", "未ログインで閲覧できる保護ページ", exposed,
                    "ログインせずに HTTP 200 が返った。", "該当ページで requireLogin() を呼ぶ。")
    if broken:
        res.finding(cat, "H-06b", "高", "未ログイン時の転送先が存在しない（ログイン画面に戻れない）", broken,
                    "サブディレクトリのページで未ログイン時に存在しない URL へ転送される（静的監査 S-01 の実害）。"
                    "セッション切れの利用者がエラー画面になる。",
                    "requireLogin() のリダイレクト先をアプリのルート基準の URL にする。")
    if ok_list:
        res.ok(cat, "H-06", "未ログイン時にログイン画面へ誘導", ", ".join(ok_list))

    # ---- H-07 ログイン後の権限 ----
    if not users:
        res.note(cat, "audit_config.json に users が未設定のため、ログイン後の権限監査を省略しました。")
        return
    if password is None:
        if sys.stdin.isatty():
            print("")
            password = getpass.getpass("テストユーザー共通パスワード（空欄でログイン後の監査を省略）: ")
        else:
            password = ""
    if not password:
        res.note(cat, "パスワード未入力のため、ログイン後の権限監査を省略しました。")
        return

    step("テストユーザー {} 名でログインして権限を確認".format(len(users)))
    sessions = []
    for u in users:
        jar = http.cookiejar.CookieJar()
        r = client.request(base_url + "login.php", method="POST",
                           data={"username": u, "password": password}, jar=jar)
        if not (is_redirect(r.status) and r.location and "index.php" in r.location):
            sessions.append({"user": u, "logged_in": False, "is_admin": False, "visible": [], "editable": [],
                             "users_status": None, "jar": None, "problems": ["ログイン失敗"]})
            res.finding(cat, "H-07", "情報", "テストユーザーでログインできない: " + u, "login.php",
                        "HTTP {}。パスワード変更・削除済みの可能性。".format(r.status),
                        "テストユーザーの状態を確認する。")
            continue
        idx = client.request(base_url + "index.php", jar=jar)
        up = client.request(base_url + "users/users.php", jar=jar)
        sessions.append({
            "user": u,
            "logged_in": True,
            "is_admin": "users/users.php" in idx.body,
            "visible": ids_from_html(idx.body, r"reports/detail\.php\?id=(\d+)"),
            "editable": ids_from_html(idx.body, r"reports/edit\.php\?id=(\d+)"),
            "users_status": up.status,
            "jar": jar,
            "problems": [],
        })

    logged_in = [s for s in sessions if s["logged_in"]]
    admins = [s for s in logged_in if s["is_admin"]]
    source = admins
    if not admins:
        source = logged_in
        res.note(cat, "管理者のテストユーザーがいないため、全レポートの一覧は各ユーザーの閲覧結果の和集合で代用しました。")
    all_ids = sorted({i for s in source for i in s["visible"]})

    leak_detail, leak_edit, leak_admin = [], [], []
    for s in logged_in:
        if s["is_admin"]:
            if s["users_status"] != 200:
                s["problems"].append("管理者なのにユーザー管理が HTTP {}".format(s["users_status"]))
            continue
        if s["users_status"] == 200:
            leak_admin.append(s["user"])
            s["problems"].append("ユーザー管理画面を開ける")
        hidden = [i for i in all_ids if i not in s["visible"]][:max_probe]
        for i in hidden:
            r = client.request(base_url + "reports/detail.php?id={}".format(i), jar=s["jar"])
            if r.status == 200:
                leak_detail.append("{} → reports/detail.php?id={}".format(s["user"], i))
                s["problems"].append("閲覧範囲外の詳細 id={}".format(i))
        not_editable = [i for i in s["visible"] if i not in s["editable"]][:max_probe]
        for i in not_editable:
            r = client.request(base_url + "reports/edit.php?id={}".format(i), jar=s["jar"])
            if r.status == 200:
                leak_edit.append("{} → reports/edit.php?id={}".format(s["user"], i))
                s["problems"].append("他人のレポート編集画面 id={}".format(i))

    if leak_admin:
        res.finding(cat, "H-07a", "高", "管理者以外がユーザー管理画面を開ける", leak_admin,
                    "users/users.php が HTTP 200 を返した。", "requireAdmin() を確認する。")
    else:
        res.ok(cat, "H-07a", "管理画面の保護", "管理者以外は users/users.php にアクセスできない。")
    if leak_detail:
        res.finding(cat, "H-07b", "高", "閲覧範囲外のレポートを URL 直打ちで参照できる", leak_detail,
                    "一覧に表示されないレポートの詳細画面が HTTP 200 を返した。", "detail.php の権限判定を確認する。")
    else:
        res.ok(cat, "H-07b", "閲覧範囲の保護",
               "各ユーザー最大 {} 件の範囲外レポートで 403 を確認。".format(max_probe))
    if leak_edit:
        res.finding(cat, "H-07c", "高", "他人のレポートの編集画面を開ける", leak_edit,
                    "編集ボタンが表示されないレポートの編集画面が HTTP 200 を返した。",
                    "edit.php / update.php の権限判定を確認する。")
    else:
        res.ok(cat, "H-07c", "編集権限の保護",
               "各ユーザー最大 {} 件の編集不可レポートで 403 を確認。".format(max_probe))

    # ---- H-08 不正パラメータ ----
    if logged_in:
        jar = logged_in[0]["jar"]
        r1 = client.request(base_url + "reports/detail.php?id=999999999", jar=jar)
        r2 = client.request(base_url + "reports/detail.php?id=abc", jar=jar)
        if r1.status == 404 and r2.status == 400:
            res.ok(cat, "H-08", "不正なレポートID の扱い", "存在しない ID は 404、不正な ID は 400。")
        else:
            res.finding(cat, "H-08", "低", "不正なレポートID への応答が想定と異なる", "reports/detail.php",
                        "id=999999999 → HTTP {}（想定 404）、id=abc → HTTP {}（想定 400）".format(r1.status, r2.status),
                        "入力チェックを確認する。")

    for s in logged_in:
        client.request(base_url + "logout.php", jar=s["jar"])

    res.http_matrix = sessions


# ============================================================
# 3. DB 監査
# ============================================================

def db_audit(res, db_path):
    cat = "DB"
    print("[3/3] DB監査（SQLite）")

    if not db_path.exists():
        res.note(cat, "DB ファイルが見つかりません（{}）。DB監査を省略しました。".format(db_path))
        return
    step("対象: {}".format(db_path))

    for suffix in ("-wal", "-journal"):
        side = Path(str(db_path) + suffix)
        if side.exists() and side.stat().st_size > 0:
            res.finding(cat, "D-00", "中", "{} ファイルがある".format(suffix), str(side),
                        "アプリが書き込み中の可能性がある。結果が最新の状態と異なる場合がある。",
                        "アプリへのアクセスが無い時間帯に再監査する。")

    # 読み取り専用で開く（ファイルを変更しない）
    try:
        con = sqlite3.connect(db_path.resolve().as_uri() + "?mode=ro", uri=True)
        con.row_factory = sqlite3.Row
        integrity = con.execute("PRAGMA integrity_check").fetchone()[0]
    except sqlite3.Error as e:
        res.finding(cat, "D-01", "高", "DB ファイルを開けない", str(db_path), str(e),
                    "ファイルが破損していないか確認する。")
        return

    try:
        if integrity != "ok":
            res.finding(cat, "D-01", "高", "DB ファイルが破損している（integrity_check）", str(db_path),
                        str(integrity), "バックアップから復旧する。")
        else:
            res.ok(cat, "D-01", "DB ファイルの整合性", "PRAGMA integrity_check = ok")

        master = [dict(r) for r in con.execute("SELECT type, name, sql FROM sqlite_master")]
        tables = sorted(r["name"] for r in master if r["type"] == "table")
        if not {"users", "reports", "comments"} <= set(tables):
            res.finding(cat, "D-01", "高", "必要なテーブルがない", str(db_path),
                        "存在するテーブル: " + ", ".join(tables),
                        "users / reports / comments が揃っているか確認する。")
            return

        users = [dict(r) for r in con.execute("SELECT * FROM users ORDER BY id")]
        reports = [dict(r) for r in con.execute("SELECT * FROM reports ORDER BY id")]
        comments = [dict(r) for r in con.execute("SELECT * FROM comments ORDER BY id")]
    finally:
        con.close()

    step("users {} 件 / reports {} 件 / comments {} 件".format(len(users), len(reports), len(comments)))
    user_by_id = {u["id"]: u for u in users}
    report_ids = {r["id"] for r in reports}

    # ---- D-02 孤立コメント ----
    orphan = [c for c in comments if c["report_id"] not in report_ids]
    if orphan:
        res.finding(cat, "D-02", "高", "削除済みレポートに紐づくコメント（孤立データ）",
                    ["comments.id={} (report_id={}, field={})".format(c["id"], c["report_id"], c["field_name"])
                     for c in orphan],
                    "{} 件。外部キー制約が無効なため、レポート削除時にコメントが残っている（静的監査 S-02）。".format(len(orphan)),
                    "外部キーを有効化したうえで、孤立コメントを削除する。")
    else:
        res.ok(cat, "D-02", "孤立コメント", "なし")

    # ---- D-03 存在しないユーザーへの参照 ----
    bad = []
    bad += ["reports.id={} user_id={}".format(r["id"], r["user_id"]) for r in reports if r["user_id"] not in user_by_id]
    bad += ["comments.id={} user_id={}".format(c["id"], c["user_id"]) for c in comments if c["user_id"] not in user_by_id]
    bad += ["users.id={} manager_id={}".format(u["id"], u["manager_id"]) for u in users
            if u["manager_id"] is not None and u["manager_id"] not in user_by_id]
    if bad:
        res.finding(cat, "D-03", "中", "存在しないユーザーを参照しているデータ", bad,
                    "{} 件。".format(len(bad)), "参照先を修正するか、データを整理する。")
    else:
        res.ok(cat, "D-03", "ユーザー参照の整合性", "reports / comments / users.manager_id の参照先はすべて存在。")

    # ---- D-04 上司設定の循環 ----
    cycles = []
    for u in users:
        seen = set()
        cur = u
        while cur is not None and cur["manager_id"] is not None:
            mid = cur["manager_id"]
            if mid == u["id"]:
                cycles.append(u["username"])
                break
            if mid in seen:
                break
            seen.add(mid)
            cur = user_by_id.get(mid)
    if cycles:
        res.finding(cat, "D-04", "高", "上司・部下の設定が循環している", cycles,
                    "閲覧範囲の再帰 SQL が想定外の結果になる（UNION ALL の箇所は無限再帰）。",
                    "ユーザー管理画面で上司設定を修正する。")
    else:
        res.ok(cat, "D-04", "上司設定の循環", "なし")

    # ---- D-05 管理者数 ----
    admins = [u for u in users if u["is_admin"] == 1]
    if not admins:
        res.finding(cat, "D-05", "高", "管理者が 0 人", "users", "ユーザー管理ができない。",
                    "DB を直接修正して管理者を設定する。")
    elif len(admins) == 1:
        res.finding(cat, "D-05", "情報", "管理者が 1 人のみ", [a["username"] for a in admins],
                    "退職・パスワード紛失時に管理できなくなる。", "必要に応じて予備の管理者を設ける。")
    else:
        res.ok(cat, "D-05", "管理者数", "{} 人".format(len(admins)))

    # ---- D-06 コメント対象項目 ----
    bad_field = [c for c in comments if c["field_name"] not in ALLOWED_FIELDS]
    if bad_field:
        res.finding(cat, "D-06", "中", "定義外の項目に対するコメント",
                    ["comments.id={} field_name={}".format(c["id"], c["field_name"]) for c in bad_field],
                    "画面に表示されず、編集・削除もできない（400 エラー）。", "項目定義と照合してデータを整理する。")
    else:
        res.ok(cat, "D-06", "コメント対象項目", "すべて許可値（" + ", ".join(ALLOWED_FIELDS) + "）。")

    # ---- D-07 役職 ----
    bad_role = [u for u in users if u["role_title"] not in ALLOWED_ROLES]
    if bad_role:
        res.finding(cat, "D-07", "中", "定義外の役職", ["{}: {}".format(u["username"], u["role_title"]) for u in bad_role],
                    "編集画面で役職が選択されない状態になる。", "役職を修正する。")
    else:
        res.ok(cat, "D-07", "役職", "すべて許可値。")

    # ---- D-08 パスワードハッシュ形式 ----
    weak = [u["username"] for u in users if not re.match(r"^\$(2[aby]\$\d\d\$|argon2)", str(u["password_hash"] or ""))]
    if weak:
        res.finding(cat, "D-08", "高", "password_hash() 形式でないパスワード", weak,
                    "平文または弱いハッシュの可能性がある（ハッシュ値そのものはレポートに出力しない）。",
                    "パスワードを再設定する。")
    else:
        res.ok(cat, "D-08", "パスワードハッシュ形式", "すべて bcrypt / argon2 形式。")

    # ---- D-09 日付・日時の形式 ----
    bad_date = []
    for r in reports:
        try:
            datetime.strptime(str(r["report_date"]), "%Y-%m-%d")
            if not re.match(r"^\d{4}-\d{2}-\d{2}$", str(r["report_date"])):
                raise ValueError
        except ValueError:
            bad_date.append("reports.id={} report_date={}".format(r["id"], r["report_date"]))
    dt_rx = re.compile(r"^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$")
    for name, rows, cols in (("users", users, ["created_at"]),
                             ("reports", reports, ["created_at", "updated_at"]),
                             ("comments", comments, ["created_at", "updated_at"])):
        for row in rows:
            for col in cols:
                if not dt_rx.match(str(row[col])):
                    bad_date.append("{}.id={} {}={}".format(name, row["id"], col, row[col]))
            if "updated_at" in cols and str(row["updated_at"]) < str(row["created_at"]):
                bad_date.append("{}.id={} updated_at が created_at より前".format(name, row["id"]))
    if bad_date:
        res.finding(cat, "D-09", "低", "日付・日時の形式不正", bad_date,
                    "{} 件。".format(len(bad_date)), "データを修正する。")
    else:
        res.ok(cat, "D-09", "日付・日時の形式", "すべて正常。")

    # ---- D-10 必須項目の空白 ----
    blank = []
    for r in reports:
        for col in ("name", "subject"):
            v = str(r[col] or "")
            if v.strip() == "":
                blank.append("reports.id={} {} が空".format(r["id"], col))
            elif v != v.strip():
                blank.append("reports.id={} {} に前後の空白".format(r["id"], col))
    for u in users:
        if str(u["username"]) != str(u["username"]).strip():
            blank.append("users.id={} username に前後の空白".format(u["id"]))
    if blank:
        res.finding(cat, "D-10", "低", "必須項目の空白・前後の空白", blank,
                    "登録処理が trim 前の値を保存しているために発生し得る（詳細設計書 3.2）。",
                    "データを修正し、保存前に trim する。")
    else:
        res.ok(cat, "D-10", "必須項目の空白", "なし")

    # ---- 統計情報 ----
    children = {}
    for u in users:
        if u["manager_id"] is not None:
            children.setdefault(u["manager_id"], []).append(u["id"])

    def descendants(uid):
        seen, stack = set(), list(children.get(uid, []))
        while stack:
            c = stack.pop()
            if c in seen or c == uid:
                continue
            seen.add(c)
            stack.extend(children.get(c, []))
        return seen

    reports_by_user = {}
    for r in reports:
        reports_by_user[r["user_id"]] = reports_by_user.get(r["user_id"], 0) + 1

    user_stats = []
    expected = {}
    for u in users:
        desc = descendants(u["id"])
        if u["is_admin"] == 1:
            visible = len(reports)
        else:
            visible = sum(reports_by_user.get(i, 0) for i in ({u["id"]} | desc))
        expected[u["username"]] = visible
        mgr = user_by_id.get(u["manager_id"])
        user_stats.append({
            "id": u["id"], "username": u["username"], "role": u["role_title"],
            "manager": mgr["username"] if mgr else "", "admin": u["is_admin"] == 1,
            "own": reports_by_user.get(u["id"], 0), "subordinates": len(desc), "visible": visible,
        })
    res.db_expected_visible = expected

    field_stats = {f: 0 for f in ALLOWED_FIELDS}
    for c in comments:
        field_stats[c["field_name"]] = field_stats.get(c["field_name"], 0) + 1

    st = db_path.stat()
    res.db_info = {
        "path": str(db_path),
        "size": st.st_size,
        "mtime": datetime.fromtimestamp(st.st_mtime).strftime("%Y-%m-%d %H:%M:%S"),
        "sqlite_version": sqlite3.sqlite_version,
        "tables": tables,
        "indexes": sorted(r["name"] for r in master if r["type"] == "index"),
        "counts": {"users": len(users), "reports": len(reports), "comments": len(comments)},
        "user_stats": user_stats,
        "field_stats": field_stats,
        "fk_count": sum(len(re.findall("REFERENCES", r["sql"] or "")) for r in master),
    }


# ============================================================
# レポート出力
# ============================================================

CSS = """
:root { --text:#1f2933; --muted:#5f6c7b; --line:#d9e2ec; --bg:#f5f7fa; --surface:#fff;
        --high:#c62828; --mid:#e08600; --low:#2e6db4; --info:#6b7785; --ok:#2e7d32; }
* { box-sizing:border-box; }
body { margin:0; background:var(--bg); color:var(--text); line-height:1.6;
       font-family:"Noto Sans CJK JP","Noto Sans JP","Segoe UI","Meiryo",sans-serif; }
.page { max-width:1200px; margin:0 auto; padding:24px 16px 48px; }
h1 { margin:0 0 4px; font-size:1.6rem; }
h2 { margin:32px 0 12px; font-size:1.25rem; border-bottom:2px solid var(--line); padding-bottom:6px; }
h3 { margin:20px 0 8px; font-size:1.05rem; }
.meta { color:var(--muted); font-size:.9rem; word-break:break-all; }
.cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin:20px 0; }
.card { background:var(--surface); border:1px solid var(--line); border-radius:8px; padding:14px; }
.card .n { font-size:2rem; font-weight:700; line-height:1.1; font-variant-numeric:tabular-nums; }
.card .l { color:var(--muted); font-size:.85rem; }
.card.high .n { color:var(--high); } .card.mid .n { color:var(--mid); }
.card.low .n { color:var(--low); } .card.info .n { color:var(--info); } .card.ok .n { color:var(--ok); }
table { width:100%; border-collapse:collapse; background:var(--surface); font-size:.9rem; margin:8px 0 16px; }
th, td { border:1px solid var(--line); padding:8px 10px; text-align:left; vertical-align:top; }
th { background:#eef2f6; white-space:nowrap; }
td.num { text-align:right; font-variant-numeric:tabular-nums; }
.badge { display:inline-block; min-width:2.6em; padding:1px 8px; border-radius:10px; color:#fff;
         font-size:.8rem; font-weight:700; text-align:center; white-space:nowrap; }
.sev-high { background:var(--high); } .sev-mid { background:var(--mid); }
.sev-low { background:var(--low); } .sev-info { background:var(--info); } .sev-ok { background:var(--ok); }
.loc { font-family:"DejaVu Sans Mono",Consolas,monospace; font-size:.8rem; color:#334e68; word-break:break-all; }
.rec { color:#1b5e20; }
.note { background:#fff8e1; border-left:4px solid #f0b400; padding:10px 14px; margin:8px 0; }
.small { color:var(--muted); font-size:.85rem; }
.table-wrap { overflow-x:auto; }
@media print { body { background:#fff; } .card, table { break-inside:avoid; } }
"""

SEV_CLASS = {"高": "high", "中": "mid", "低": "low", "情報": "info"}


def esc(v):
    return html.escape("" if v is None else str(v))


def write_html_report(res, path, repo_root, base_url, db_path):
    out = []
    w = out.append
    counts = {s: 0 for s in SEVERITIES}
    for f in res.findings:
        counts[f["severity"]] += 1

    w('<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8">')
    w('<meta name="viewport" content="width=device-width, initial-scale=1">')
    w("<title>監査レポート | レポート管理システム</title><style>" + CSS + "</style></head><body><div class=\"page\">")
    w("<h1>監査レポート：レポート管理システム</h1>")
    w('<p class="meta">実行日時 {} ／ リポジトリ {} ／ 対象URL {} ／ DB {} ／ Python {}</p>'.format(
        esc(datetime.now().strftime("%Y-%m-%d %H:%M:%S")), esc(repo_root), esc(base_url), esc(db_path),
        esc(sys.version.split()[0])))

    w('<div class="cards">')
    for sev in SEVERITIES:
        w('<div class="card {}"><div class="n">{}</div><div class="l">{}</div></div>'.format(
            SEV_CLASS[sev], counts[sev], sev))
    w('<div class="card ok"><div class="n">{}</div><div class="l">問題なし（確認済み）</div></div>'.format(len(res.passes)))
    w("</div>")
    w('<p class="small">重要度　高: 不具合・情報漏えい・権限回避につながる ／ 中: 整合性・保守性・防御の不足 ／'
      ' 低: 軽微な改善 ／ 情報: 確認事項</p>')

    for n in res.notes:
        w('<div class="note">[{}] {}</div>'.format(esc(n["category"]), esc(n["text"])))

    sections = [("静的", "1. 静的監査（ソースコード）"), ("HTTP", "2. HTTP監査（稼働サイト）"), ("DB", "3. DB監査（データ整合性）")]
    for cat, heading in sections:
        w("<h2>{}</h2>".format(heading))
        fs = sorted([f for f in res.findings if f["category"] == cat], key=lambda f: (SEV_ORDER[f["severity"]], f["id"]))
        if fs:
            w('<div class="table-wrap"><table><thead><tr><th>重要度</th><th>ID</th><th>指摘</th><th>該当箇所</th>'
              "<th>対応案</th></tr></thead><tbody>")
            for f in fs:
                w('<tr><td><span class="badge sev-{}">{}</span></td><td>{}</td><td><strong>{}</strong><br>{}</td>'
                  '<td class="loc">{}</td><td class="rec">{}</td></tr>'.format(
                      SEV_CLASS[f["severity"]], f["severity"], esc(f["id"]), esc(f["title"]), esc(f["detail"]),
                      "<br>".join(esc(x) for x in f["location"]), esc(f["recommendation"])))
            w("</tbody></table></div>")
        else:
            w('<p class="small">指摘事項はありません。</p>')

        ps = [p for p in res.passes if p["category"] == cat]
        if ps:
            w('<h3>問題がなかった確認項目</h3><div class="table-wrap"><table><thead><tr><th>結果</th><th>ID</th>'
              "<th>確認項目</th><th>内容</th></tr></thead><tbody>")
            for p in ps:
                w('<tr><td><span class="badge sev-ok">OK</span></td><td>{}</td><td>{}</td><td class="loc">{}</td></tr>'
                  .format(esc(p["id"]), esc(p["title"]), esc(p["detail"])))
            w("</tbody></table></div>")

        if cat == "HTTP" and res.http_matrix:
            w("<h3>ユーザー別の閲覧・編集範囲（一覧画面の表示件数）</h3>")
            w('<div class="table-wrap"><table><thead><tr><th>ユーザー</th><th>ログイン</th><th>管理者</th>'
              "<th>閲覧可能</th><th>編集可能</th><th>ユーザー管理</th><th>同梱DBから算出した閲覧可能件数</th>"
              "<th>問題</th></tr></thead><tbody>")
            for s in res.http_matrix:
                exp = ""
                if res.db_expected_visible and s["user"] in res.db_expected_visible:
                    exp = res.db_expected_visible[s["user"]]
                w('<tr><td>{}</td><td>{}</td><td>{}</td><td class="num">{}</td><td class="num">{}</td>'
                  '<td class="num">{}</td><td class="num">{}</td><td>{}</td></tr>'.format(
                      esc(s["user"]), "○" if s["logged_in"] else "×", "○" if s["is_admin"] else "",
                      len(s["visible"]), len(s["editable"]), esc(s["users_status"]), esc(exp),
                      esc(" / ".join(s["problems"]))))
            w("</tbody></table></div>")
            w('<p class="small">「同梱DBから算出」はリポジトリ内の DB の上司設定から計算した期待値。'
              "サーバーの DB と同梱 DB が同じ内容の場合のみ比較の意味がある。</p>")

        if cat == "DB" and res.db_info:
            i = res.db_info
            w('<h3>DB 概要</h3><div class="table-wrap"><table><tbody>')
            w('<tr><th>ファイル</th><td class="loc">{}</td></tr>'.format(esc(i["path"])))
            w("<tr><th>サイズ / 更新日時</th><td>{:,} バイト / {}</td></tr>".format(i["size"], esc(i["mtime"])))
            w("<tr><th>テーブル</th><td>{}</td></tr>".format(esc(", ".join(i["tables"]))))
            w("<tr><th>インデックス</th><td>{}</td></tr>".format(esc(", ".join(i["indexes"]))))
            w("<tr><th>件数</th><td>users {users} / reports {reports} / comments {comments}</td></tr>".format(**i["counts"]))
            w("<tr><th>外部キー定義</th><td>{} 件（PRAGMA foreign_keys は接続ごとの設定でファイルに保存されないため、"
              "有効かどうかは静的監査 S-02 を参照）</td></tr>".format(i["fk_count"]))
            w("<tr><th>SQLite ライブラリ</th><td>{}</td></tr>".format(esc(i["sqlite_version"])))
            w("</tbody></table></div>")

            w('<h3>ユーザーと閲覧範囲（同梱DBから算出）</h3><div class="table-wrap"><table><thead><tr><th>ID</th>'
              "<th>ユーザー名</th><th>役職</th><th>上司</th><th>管理者</th><th>自分のレポート</th><th>配下の人数</th>"
              "<th>閲覧可能なレポート</th></tr></thead><tbody>")
            for u in i["user_stats"]:
                w('<tr><td class="num">{}</td><td>{}</td><td>{}</td><td>{}</td><td>{}</td><td class="num">{}</td>'
                  '<td class="num">{}</td><td class="num">{}</td></tr>'.format(
                      u["id"], esc(u["username"]), esc(u["role"]), esc(u["manager"]), "○" if u["admin"] else "",
                      u["own"], u["subordinates"], u["visible"]))
            w("</tbody></table></div>")

            w('<h3>項目別コメント件数</h3><div class="table-wrap"><table><thead><tr><th>field_name</th><th>件数</th>'
              "</tr></thead><tbody>")
            for k, v in i["field_stats"].items():
                w('<tr><td>{}</td><td class="num">{}</td></tr>'.format(esc(k), v))
            w("</tbody></table></div>")

    w('<p class="small">本レポートは audit.py により自動生成。HTTP監査で送信した POST はログインのみで、'
      "データの登録・更新・削除は行っていない。</p>")
    w("</div></body></html>")
    path.write_text("\n".join(out), encoding="utf-8")


# ============================================================
# メイン
# ============================================================

def load_config(path):
    if path and path.exists():
        with path.open(encoding="utf-8") as f:
            return json.load(f)
    return {}


def main(argv=None):
    ap = argparse.ArgumentParser(description="レポート管理システム 監査スクリプト（Python 3 標準ライブラリのみ）")
    ap.add_argument("--repo-root", help="リポジトリのルート（既定: このスクリプトの2階層上）")
    ap.add_argument("--config", help="設定ファイル（既定: 同じフォルダの audit_config.json）")
    ap.add_argument("--base-url", help="稼働サイトの public/ の URL（設定ファイルより優先）")
    ap.add_argument("--db-path", help="監査する SQLite ファイル（既定: <リポジトリ>/data/reports.sqlite）")
    ap.add_argument("--password", help="テストユーザー共通パスワード（省略時は環境変数 AUDIT_PASSWORD、なければ実行時に入力）")
    ap.add_argument("--output-dir", help="HTML レポートの出力先（既定: 同じフォルダの reports/）")
    ap.add_argument("--skip-static", action="store_true", help="静的監査を省略")
    ap.add_argument("--skip-http", action="store_true", help="HTTP監査を省略")
    ap.add_argument("--skip-db", action="store_true", help="DB監査を省略")
    ap.add_argument("--open", action="store_true", help="終了後にレポートをブラウザで開く")
    args = ap.parse_args(argv)

    # 端末の文字コードで表示できない文字があっても止まらないようにする
    try:
        sys.stdout.reconfigure(errors="replace")
    except AttributeError:
        pass

    repo_root = Path(args.repo_root).resolve() if args.repo_root else SCRIPT_DIR.parent.parent
    config_path = Path(args.config) if args.config else SCRIPT_DIR / "audit_config.json"
    config = load_config(config_path)

    base_url = args.base_url or config.get("base_url") or ""
    if base_url and not base_url.endswith("/"):
        base_url += "/"
    db_path = Path(args.db_path) if args.db_path else repo_root / "data" / "reports.sqlite"
    output_dir = Path(args.output_dir) if args.output_dir else SCRIPT_DIR / "reports"
    users = list(config.get("users") or [])
    delay_ms = int(config.get("request_delay_ms", 300))
    max_probe = int(config.get("max_probe_per_user", 5))
    password = args.password if args.password is not None else os.environ.get("AUDIT_PASSWORD")

    print("")
    print("==== レポート管理システム 監査 ====")
    print("リポジトリ: {}".format(repo_root))
    print("")

    res = Result()
    jobs = [
        ("静的", args.skip_static, "--skip-static", lambda: static_audit(res, repo_root)),
        ("DB", args.skip_db, "--skip-db", lambda: db_audit(res, db_path)),
        ("HTTP", args.skip_http, "--skip-http",
         lambda: http_audit(res, base_url, users, password, delay_ms, max_probe)),
    ]
    for cat, skip, flag, fn in jobs:
        if skip:
            res.note(cat, flag + " により省略しました。")
            continue
        try:
            fn()
        except KeyboardInterrupt:
            raise
        except Exception as e:  # 1つの監査が失敗しても他の監査とレポート出力は続ける
            res.note(cat, "{}監査でエラー: {}: {}".format(cat, type(e).__name__, e))
            print("  ! エラー: {}: {}".format(type(e).__name__, e))

    # 画面出力
    color = sys.stdout.isatty() and os.environ.get("NO_COLOR") is None
    ansi = {"高": "\033[31m", "中": "\033[33m", "低": "\033[36m", "情報": "\033[90m"}
    reset = "\033[0m"
    print("")
    print("==== 結果 ====")
    for f in sorted(res.findings, key=lambda f: (SEV_ORDER[f["severity"]], f["category"], f["id"])):
        line = "[{}] {:<4} {:<6} {}".format(f["severity"], f["category"], f["id"], f["title"])
        print(ansi[f["severity"]] + line + reset if color else line)
    for n in res.notes:
        print("[注記] {}: {}".format(n["category"], n["text"]))

    counts = {s: sum(1 for f in res.findings if f["severity"] == s) for s in SEVERITIES}
    print("")
    print("高 {} 件 / 中 {} 件 / 低 {} 件 / 確認済み {} 項目".format(counts["高"], counts["中"], counts["低"], len(res.passes)))

    output_dir.mkdir(parents=True, exist_ok=True)
    report_path = output_dir / "audit-report_{}.html".format(datetime.now().strftime("%Y%m%d_%H%M%S"))
    write_html_report(res, report_path, repo_root, base_url, db_path)
    print("レポート: {}".format(report_path))

    if args.open:
        webbrowser.open(report_path.resolve().as_uri())

    return 1 if counts["高"] > 0 else 0


if __name__ == "__main__":
    sys.exit(main())
