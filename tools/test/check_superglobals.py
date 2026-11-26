#!/usr/bin/env python3
"""スーパーグローバル変数を、型を確かめずに文字列・数にしていないか（監査の指摘への対応。2026-11-26）。

app/ と public/ の PHP で、次の書き方を NG にする（配列で送られると 500 になる・黙って別の値になるため）:
- (string)・(int)・(float)・(bool)・(array) で $_GET・$_POST・$_COOKIE・$_REQUEST を直に変える
- trim() などに $_GET・$_POST を直に渡す
- $_REQUEST を使う（GET と POST の区別がなくなる）
読むときは get_str()・post_str()・input_id()（app/lib/util.php）か、is_string()・is_array() で確かめてから使う。

    python3 tools/test/check_superglobals.py
"""
import glob
import os
import re
import sys

REPOSITORY_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
NG_PATTERN_LIST = [
    (re.compile(r"\((?:string|int|float|bool|array)\)\s*\(?\s*\$_(?:GET|POST|COOKIE|REQUEST)\["), "型を確かめずに文字列・数にしている"),
    (re.compile(r"\b(?:trim|strtolower|strtoupper|intval|strval|htmlspecialchars|mb_strlen|strlen)\(\s*\$_(?:GET|POST|COOKIE|REQUEST)\["), "型を確かめずに関数に渡している"),
    (re.compile(r"\$_REQUEST\b"), "$_REQUEST を使っている"),
]


def main():
    for output_stream in (sys.stdout, sys.stderr):
        if hasattr(output_stream, "reconfigure"):
            output_stream.reconfigure(errors="replace")
    php_path_list = sorted(glob.glob(os.path.join(REPOSITORY_ROOT, "app", "**", "*.php"), recursive=True)
                           + glob.glob(os.path.join(REPOSITORY_ROOT, "public", "**", "*.php"), recursive=True))
    ng_count = 0
    for php_path in php_path_list:
        relative_path = os.path.relpath(php_path, REPOSITORY_ROOT).replace(os.sep, "/")
        for line_number, line_text in enumerate(open(php_path, encoding="utf-8").read().splitlines(), 1):
            for ng_pattern, reason_text in NG_PATTERN_LIST:
                if ng_pattern.search(line_text):
                    ng_count += 1
                    print("NG  %s:%d: %s（%s）" % (relative_path, line_number, reason_text, line_text.strip()[:120]))
    print("OK  %d ファイルを確かめた" % len(php_path_list) if ng_count == 0 else "NG %d か所" % ng_count)
    return 1 if ng_count else 0


if __name__ == "__main__":
    sys.exit(main())
