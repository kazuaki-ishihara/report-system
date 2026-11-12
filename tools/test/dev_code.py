#!/usr/bin/env python3
"""ローカル検証環境のテスト用利用者のログイン情報と、今の 2 段階認証のコードを表示する。

    python3 tools/test/dev_code.py            # 全員
    python3 tools/test/dev_code.py kacho_a    # 1 人

tools/test/dev_seed.php で作ったテスト用の利用者だけ（本番の利用者には使えない）。
"""
import json
import os
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import totp  # noqa: E402

ROLES = {
    "admin01": "管理者", "kacho_a": "A課 課長", "dairi_a": "A課 確認者（課長代理）", "leader_a": "A課 確認者（リーダー）",
    "ippan_a1": "A課 部下", "ippan_a2": "A課 部下", "kacho_b": "B課 課長", "ippan_b1": "B課 部下",
}


def main():
    path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "output_dev_users.json")
    users = json.load(open(path, encoding="utf-8"))["users"]
    names = sys.argv[1:] or list(users.keys())
    left = 30 - int(time.time()) % 30
    print("2 段階認証のコード（あと %d 秒有効）" % left)
    print("%-10s %-24s %-24s %s" % ("ログインID", "役割", "パスワード", "コード"))
    for n in names:
        if n in users:
            print("%-10s %-24s %-24s %s" % (n, ROLES.get(n, ""), users[n]["password"], totp(users[n]["totp"])))


if __name__ == "__main__":
    main()
