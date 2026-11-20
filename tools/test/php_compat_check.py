#!/usr/bin/env python3
"""新しい PHP（8.4・8.5・8.6 など）で、このシステムのコードが動くかを調べる。Windows・Linux 共通。

    python3 tools/test/php_compat_check.py --php C:\\ReportSystem\\php-8.6.0-x64\\php.exe
    python3 tools/test/php_compat_check.py --php /usr/bin/php8.6
    python3 tools/test/php_compat_check.py --php /usr/bin/php8.6 --verify                    自動検証も、その PHP で流す
    python3 tools/test/php_compat_check.py --php /usr/bin/php8.6 --verify --only G-05,G-10   流す工程をしぼる

調べること（1〜3 は数十秒。4 は --verify のときだけ）:
  1. その PHP の版と、このシステムに要る拡張（pdo_sqlite・sqlite3・mbstring・openssl）
  2. すべての PHP のファイルの構文（php -l）。コンパイルのときに出る「非推奨（Deprecated）」も拾う
  3. 書き方の確認: 8.4・8.5 で非推奨・削除になった書き方・関数を探す（下の COMPATIBILITY_PATTERNS。目当ての版までのものだけ）
     8.6 以降の変更は、ここには書いていない。2 と 4（その PHP で実際に動かす）で見つける
  4. 自動検証（tools/test/verify_all.py）をその PHP で流す。このシステムは警告・非推奨を例外にするので、
     実行のときの非推奨は検証の失敗と app.log の ERROR に出る。流したあと app.log に増えた ERROR を並べる
新しい PHP は tools/lib/php_version_check.py --download 系列（Windows）で取ってくる。Linux は apt で入れる（同じ道具が方法を出す）。
Python 3.8 以上の標準ライブラリだけで動く。
"""
import argparse
import concurrent.futures
import glob
import os
import re
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
REPOSITORY_ROOT = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, os.path.join(REPOSITORY_ROOT, "tools", "lib"))
import php_version_check  # noqa: E402  （版を聞く・要る拡張は同じものを使う）

APP_LOG_PATH = os.path.join(REPOSITORY_ROOT, "app", "logs", "app.log")

# 版 => [(正規表現, 名前, 直し方)]。その版で非推奨・削除になったもの。行の頭がコメント（//・*・#）の行は見ない
COMPATIBILITY_PATTERNS = {
    (8, 4): [
        (r"\b(fgetcsv|str_getcsv)\s*\(", "fgetcsv()・str_getcsv() の escape を省く（8.4 で非推奨）",
         "escape まで書く: fgetcsv($handle, 0, ',', '\"', '')（\" を重ねる標準の CSV は ''。\\ で囲みを外す CSV は '\\\\'）", "escape_argument"),
        (r"\bfputcsv\s*\(", "fputcsv() の escape を省く（8.4 で非推奨）", "引数をすべて書く: fputcsv($handle, $row, ',', '\"', '')", "escape_argument"),
        (r"\bE_STRICT\b", "E_STRICT（8.4 で非推奨）", "使わない（E_ALL に含まれていない）", ""),
        (r"\btrigger_error\s*\([^;]*E_USER_ERROR", "trigger_error() の E_USER_ERROR（8.4 で非推奨）", "例外を投げるか exit する", ""),
        (r"\blcg_value\s*\(", "lcg_value()（8.4 で非推奨）", "random_int() などを使う", ""),
        (r"\bfunction\b[^)]*\b[A-Za-z_\\\\][\w\\\\]*\s+\$\w+\s*=\s*null\b", "型の付いた引数の既定値 null で、? を付けない（8.4 で非推奨）",
         "型に ? を付ける: ?string $name = null", "implicit_nullable"),
        (r"\bDATE_RFC7231\b|\bDateTimeInterface::RFC7231\b", "DATE_RFC7231（8.4 で非推奨）", "DATE_RFC7231 を使わない書式にする", ""),
        (r"\bmhash\w*\s*\(", "mhash 関数（8.1 で非推奨、8.4 で削除の対象）", "hash() を使う", ""),
    ],
    (8, 5): [
        (r"`[^`]*`", "バッククォートの外部コマンドの実行（8.5 で非推奨）", "shell_exec() を使う", "backtick"),
        (r"\((boolean|integer|double|binary)\)", "(boolean)・(integer)・(double)・(binary) の型変換（8.5 で非推奨）",
         "(bool)・(int)・(float)・(string) を使う", ""),
        (r"^\s*case\s[^:;?]*;\s*$", "case のあとのセミコロン（8.5 で非推奨）", "case 'x': のようにコロンにする", ""),
        (r"\b(curl_close|curl_share_close|finfo_close|xml_parser_free|imagedestroy)\s*\(", "何もしなくなった後片付けの関数（8.5 で非推奨）",
         "呼ばない（変数が要らなくなれば自動で片付く）", ""),
        (r"\$http_response_header\b", "$http_response_header（8.5 で非推奨）", "http_get_last_response_headers() を使う", ""),
        (r"\bPDO::SQLITE_\w+", "PDO::SQLITE_ のような、DB の種類ごとの PDO の定数（8.5 で非推奨）", "Pdo\\Sqlite:: の定数を使う（8.4 以降）", ""),
        (r"\bsocket_set_timeout\s*\(", "socket_set_timeout()（8.5 で非推奨）", "stream_set_timeout() を使う", ""),
        (r"->setAccessible\s*\(", "ReflectionProperty::setAccessible()（8.5 で非推奨。8.1 から何もしない）", "呼ばない", ""),
    ],
}


def say(text=""):
    print(text, flush=True)


def php_file_list():
    """自動検証と同じ範囲（app・public・tools。道具が作った出力は除く）"""
    generated_output_dir_tuple = (os.path.join(REPOSITORY_ROOT, "tools", "deploy", "output") + os.sep,
                                  os.path.join(REPOSITORY_ROOT, "tools", "test", "output") + os.sep)
    found_path_list = []
    for top_dir_name in ("app", "public", "tools"):
        for php_path in glob.glob(os.path.join(REPOSITORY_ROOT, top_dir_name, "**", "*.php"), recursive=True):
            if not php_path.startswith(generated_output_dir_tuple):
                found_path_list.append(php_path)
    return sorted(found_path_list)


def relative_path(path):
    return os.path.relpath(path, REPOSITORY_ROOT)


def php_environment(php_path):
    """ローカル検証環境の一式の php.ini は、拡張の場所を MR_PHP_DIR で指す"""
    child_environment = dict(os.environ)
    child_environment.setdefault("MR_PHP_DIR", os.path.dirname(os.path.abspath(php_path)))
    return child_environment


def lint_one_file(php_path, source_path):
    """1 つのファイルの構文と、コンパイルのときの非推奨。問題が無ければ空の文字"""
    completed = subprocess.run([php_path, "-d", "error_reporting=-1", "-d", "display_errors=stderr", "-d", "log_errors=0", "-l", source_path],
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=php_environment(php_path), timeout=60)
    message_text = (completed.stdout + completed.stderr).decode("utf-8", "replace")
    noticed_line_list = [line_text.strip() for line_text in message_text.splitlines()
                         if line_text.strip() and not line_text.startswith("No syntax errors detected")]
    if completed.returncode != 0 or noticed_line_list:
        return "%s: %s" % (relative_path(source_path), " / ".join(noticed_line_list)[:300])
    return ""


def check_lint(php_path, php_path_list):
    """すべてのファイルを、同時に 8 つずつ確かめる（1 つずつ PHP を起動すると遅いため）。NG の行の並びを返す"""
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
        result_list = list(executor.map(lambda source_path: lint_one_file(php_path, source_path), php_path_list))
    return [problem_text for problem_text in result_list if problem_text]


def is_escape_argument_given(line_text):
    """fgetcsv・fputcsv・str_getcsv の呼び出しに、escape まで（5 つ目・4 つ目）の引数が書いてあるか（1 行に収まる呼び出しだけ見る）"""
    call_match = re.search(r"\b(fgetcsv|fputcsv|str_getcsv)\s*\((.*)", line_text)
    if call_match is None:
        return True
    argument_text = call_match.group(2)
    depth_count = 1
    comma_count = 0
    in_quote_char = ""
    for character in argument_text:
        if in_quote_char:
            if character == in_quote_char:
                in_quote_char = ""
            continue
        if character in "'\"":
            in_quote_char = character
        elif character in "([":
            depth_count += 1
        elif character in ")]":
            depth_count -= 1
            if depth_count == 0:
                break
        elif character == "," and depth_count == 1:
            comma_count += 1
    needed_comma_count = {"fgetcsv": 4, "fputcsv": 4, "str_getcsv": 3}[call_match.group(1)]
    return comma_count >= needed_comma_count


def check_patterns(target_branch_tuple, php_path_list):
    """目当ての版までの、非推奨・削除の書き方を探す。(版, 場所, 名前, 直し方) の並びを返す"""
    found_list = []
    for source_path in php_path_list:
        with open(source_path, encoding="utf-8", errors="replace") as source_file:
            source_line_list = source_file.read().splitlines()
        for line_number, line_text in enumerate(source_line_list, 1):
            stripped_text = line_text.lstrip()
            if stripped_text.startswith(("//", "*", "#", "/*")):
                continue
            for branch_tuple, pattern_list in sorted(COMPATIBILITY_PATTERNS.items()):
                if branch_tuple > target_branch_tuple:
                    continue
                for pattern_text, name_text, fix_text, special_kind in pattern_list:
                    if not re.search(pattern_text, line_text):
                        continue
                    if special_kind == "escape_argument" and is_escape_argument_given(line_text):
                        continue
                    if special_kind == "backtick" and ("'" in line_text.split("`")[0] or '"' in line_text.split("`")[0]):
                        continue  # 文字列の中のバッククォート（Markdown の書き方など）
                    if special_kind == "implicit_nullable" and re.search(r"\?\s*[A-Za-z_\\][\w\\]*\s+\$\w+\s*=\s*null\b", line_text):
                        continue
                    found_list.append(("%d.%d" % branch_tuple, "%s:%d" % (relative_path(source_path), line_number), name_text, fix_text))
    return found_list


def app_log_size():
    try:
        return os.path.getsize(APP_LOG_PATH)
    except OSError:
        return 0


def app_log_error_lines_since(start_size):
    try:
        with open(APP_LOG_PATH, "rb") as log_file:
            log_file.seek(start_size)
            added_text = log_file.read().decode("utf-8", "replace")
    except OSError:
        return []
    return [line_text for line_text in added_text.splitlines() if " ERROR " in line_text or "Deprecated" in line_text]


def run_verify(php_path, only_text):
    command_list = [sys.executable, os.path.join(HERE, "verify_all.py"), "--php", php_path]
    if only_text:
        command_list += ["--only", only_text]
    say("  流す: %s" % " ".join(command_list))
    completed = subprocess.run(command_list, cwd=REPOSITORY_ROOT, env=php_environment(php_path))
    return completed.returncode == 0


def main():
    parser = argparse.ArgumentParser(description="新しい PHP で、このシステムのコードが動くかを調べる（Windows・Linux）")
    parser.add_argument("--php", required=True, help="調べる PHP の実行ファイル")
    parser.add_argument("--target", help="書き方の確認の目当ての系列（8.6 など。省くと --php の版）")
    parser.add_argument("--verify", action="store_true", help="自動検証も、その PHP で流す（時間がかかる）")
    parser.add_argument("--only", help="--verify で流す工程・画面の番号（verify_all.py の --only と同じ）")
    args = parser.parse_args()

    ng_count = 0
    say("1. PHP の版と拡張")
    php_info = php_version_check.read_php_info(args.php)
    if php_info is None:
        say("  NG  動かない: %s" % args.php)
        return 2
    missing_list = php_version_check.missing_extension_list(php_info)
    say("  %s  %s（%d ビット・%s）。要る拡張: %s" % ("OK" if not missing_list else "NG", php_info["version"], php_info["bits"],
                                               php_info["thread_safety"].upper(), "すべてある" if not missing_list else "足りない " + "、".join(missing_list)))
    ng_count += 1 if missing_list else 0
    target_branch_tuple = php_version_check.version_tuple(args.target or php_info["version"])[:2]

    php_path_list = php_file_list()
    say()
    say("2. 構文とコンパイルのときの非推奨（%d ファイル）" % len(php_path_list))
    lint_problem_list = check_lint(args.php, php_path_list)
    for problem_text in lint_problem_list:
        say("  NG  " + problem_text)
    say("  %s  問題 %d 件" % ("OK" if not lint_problem_list else "NG", len(lint_problem_list)))
    ng_count += len(lint_problem_list)

    say()
    say("3. 非推奨・削除の書き方（%d.%d まで。8.6 以降の変更は 2・4 で見つける）" % target_branch_tuple)
    pattern_found_list = check_patterns(target_branch_tuple, php_path_list)
    for branch_text, location_text, name_text, fix_text in pattern_found_list:
        say("  NG  [%s] %s  %s → %s" % (branch_text, location_text, name_text, fix_text))
    say("  %s  見つかったもの %d 件" % ("OK" if not pattern_found_list else "NG", len(pattern_found_list)))
    ng_count += len(pattern_found_list)

    if args.verify:
        say()
        say("4. 自動検証をこの PHP で流す")
        log_start_size = app_log_size()
        verify_succeeded = run_verify(args.php, args.only)
        added_error_list = app_log_error_lines_since(log_start_size)
        for error_line_text in added_error_list[:50]:
            say("  app.log: " + error_line_text[:300])
        say("  %s  自動検証 %s。app.log に増えた ERROR %d 件" % ("OK" if verify_succeeded and not added_error_list else "NG",
                                                         "成功" if verify_succeeded else "失敗", len(added_error_list)))
        ng_count += (0 if verify_succeeded else 1) + len(added_error_list)
    else:
        say()
        say("4. 自動検証は流していない（--verify で流す。実行のときの非推奨はここで見つかる）")

    say()
    say("判定: %s（PHP %s・問題 %d 件）" % ("このまま動く見込み" if ng_count == 0 else "直す所がある", php_info["version"], ng_count))
    return 0 if ng_count == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
