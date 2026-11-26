#!/usr/bin/env python3
"""GET の項目に配列を送っても 500 にしない（監査の指摘への対応。2026-11-26）の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

app/pages/ の PHP が読む GET の項目（get_str()・input_id()・$_GET['…']）を集め、その画面ごとに「項目[]=x」を送る。
管理者・課長・部下で開き、どれでも 500 にならないことを確かめる。監査の指摘のときに 500 になっていた画面は 400 で断ることも確かめる。
アプリの記録（app/logs/app.log）にエラーが増えないことも確かめる。

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_get_arrays.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import glob
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, login, section, summary  # noqa: E402

REPOSITORY_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
# 送ると別の動き（出力・削除・JSON の答え）になる項目は、その項目だけを送っても画面の動きを確かめられないので除く
SKIPPED_NAMES = {"csv", "format", "download", "logout"}


def read(path):
    with open(path, encoding="utf-8") as file_handle:
        return file_handle.read()


def page_get_names():
    """app/pages/<名前>.php => GET で読む項目の名前の集合"""
    names_by_page = {}
    for page_path in glob.glob(os.path.join(REPOSITORY_ROOT, "app", "pages", "*.php")):
        text = read(page_path)
        names = set(re.findall(r"get_str\('([a-z_0-9]+)'", text)) | set(re.findall(r"\$_GET\['([a-z_0-9]+)'\]", text))
        names |= set(re.findall(r"input_id\('([a-z_0-9]+)'\)", text)) | set(re.findall(r"input_id\('([a-z_0-9]+)', 'GET'\)", text))
        names -= SKIPPED_NAMES
        if names:
            names_by_page[os.path.basename(page_path)] = names
    return names_by_page


def page_urls():
    """app/pages/<名前>.php => その画面の URL（public/ がどのページを呼ぶかから作る）"""
    url_by_page = {}
    public_dir = os.path.join(REPOSITORY_ROOT, "public")
    for public_path in glob.glob(os.path.join(public_dir, "**", "*.php"), recursive=True):
        page_match = re.search(r"pages/([a-z_0-9]+\.php)", read(public_path))
        if page_match:
            url_by_page.setdefault(page_match.group(1), "/" + os.path.relpath(public_path, public_dir).replace(os.sep, "/"))
    return url_by_page


def error_line_count(log_path):
    if not os.path.exists(log_path):
        return 0
    return sum(1 for line_text in read(log_path).splitlines() if " ERROR " in line_text)


def main():
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--base", default="http://127.0.0.1:8000")
    argument_parser.add_argument("--db", required=True)
    arguments = argument_parser.parse_args()
    test_dir = os.path.dirname(os.path.abspath(__file__))
    user_info_by_login_id = json.load(open(os.path.join(test_dir, "output_dev_users.json"), encoding="utf-8"))["users"]
    client_list = [login(arguments.base, login_id, user_info_by_login_id[login_id]) for login_id in ("admin01", "kacho_a", "ippan_a1")]
    log_path = os.path.join(REPOSITORY_ROOT, "app", "logs", "app.log")
    error_count_before = error_line_count(log_path)

    section("共通", "GET の項目に配列を送っても 500 にしない（400 で断る）")
    url_by_page = page_urls()
    checked_count = 0
    for page_name, names in sorted(page_get_names().items()):
        url = url_by_page.get(page_name)
        if url is None:
            continue
        for name in sorted(names):
            status_code_list = [client.get("%s?%s[]=x" % (url, name))[0] for client in client_list]
            checked_count += 1
            # 読む場面が限られる項目（ほかの項目があるときだけ読むなど）は、その画面がふつうに開くこともある。500 にならないことを確かめる
            check("%s の「%s」に配列を送っても 500 にならない（管理者・課長・部下: %s）" % (url, name, "・".join(map(str, status_code_list))),
                  500 not in status_code_list, status_code_list)
    check("確かめた項目がある（%d 個）" % checked_count, checked_count >= 30, checked_count)
    # 監査の指摘のときに 500 になっていた画面は、400 で断る
    for path in ("/admin/org.php?tab[]=x", "/admin/logs.php?who[]=x", "/admin/org.php?tab=users&q[]=a", "/account.php?tab[]=x", "/admin/faq_logs.php?result[]=x"):
        check("%s は 400 で断る" % path, client_list[0].get(path)[0] == 400)
    check("アプリの記録にエラーが増えない", error_line_count(log_path) == error_count_before, error_line_count(log_path) - error_count_before)
    return summary()


if __name__ == "__main__":
    sys.exit(main())
