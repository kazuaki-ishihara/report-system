#!/usr/bin/env python3
"""正規表現の入力の確かめが、文字列全体の先頭・末尾（\\A・\\z）で書かれているかを確かめる（詳細設計書 共通編）。

app/ と public/ の PHP の preg_match・preg_match_all で、正規表現が ^ で始まるもの・$ で終わるものを NG にする。
（^・$ は行頭・行末にも合い、$ は末尾の改行の前にも合うので、改行を混ぜた値が確かめを抜けるおそれがある）
移行の道具（app/bin/）は、画面の入力を確かめないので見ない。

    python3 tools/test/check_regex_anchors.py

Python 3.8 以上の標準ライブラリだけで動く。
"""
import glob
import os
import re
import sys

REPOSITORY_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
CALL_PATTERN = re.compile(r"(preg_match_all|preg_match)\(\s*'([/#])((?:[^'\\]|\\.)*?)(?:\2[a-zA-Z]*'|'\s*\.)")
CONCAT_END_PATTERN = re.compile(r"\.\s*'[^']*\$[/#][a-zA-Z]*'")


def main():
    for output_stream in (sys.stdout, sys.stderr):
        if hasattr(output_stream, "reconfigure"):
            output_stream.reconfigure(errors="replace")
    checked_count = 0
    problem_list = []
    for path in sorted(glob.glob(os.path.join(REPOSITORY_ROOT, "app", "**", "*.php"), recursive=True)
                       + glob.glob(os.path.join(REPOSITORY_ROOT, "public", "**", "*.php"), recursive=True)):
        relative_path = os.path.relpath(path, REPOSITORY_ROOT).replace(os.sep, "/")
        if relative_path.startswith("app/bin/"):
            continue
        for line_number, line_text in enumerate(open(path, encoding="utf-8"), 1):
            for match in CALL_PATTERN.finditer(line_text):
                checked_count += 1
                pattern_body = match.group(3)
                if pattern_body.startswith("^") or (pattern_body.endswith("$") and not pattern_body.endswith("\\$")):
                    problem_list.append("%s:%d %s" % (relative_path, line_number, match.group(0)[:70]))
            if "preg_match" in line_text and CONCAT_END_PATTERN.search(line_text):
                problem_list.append("%s:%d（つないだ正規表現の末尾が $）" % (relative_path, line_number))
    for problem_text in problem_list:
        print("NG  [コード] 正規表現の先頭・末尾は \\A・\\z にする: " + problem_text)
    if not problem_list:
        print("OK  [コード] 入力の確かめの正規表現は文字列全体（\\A・\\z）で確かめている（%d か所）" % checked_count)
    return 1 if problem_list else 0


if __name__ == "__main__":
    sys.exit(main())
