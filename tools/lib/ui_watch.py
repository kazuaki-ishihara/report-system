#!/usr/bin/env python3
"""画面の自動操作を、ブラウザーを表示して見学する道具（ローカル検証環境専用）。

画面の自動操作検証（tools/test/ui_check.py）と同じ操作を、人が目で追える速さで行う。
操作する場所に枠を付け、文字は 1 文字ずつ入れ、確かめた結果（OK / NG）を画面の下に字幕で出す。

    python tools/lib/ui_watch.py                     # 既定の速さ（1 操作 0.8 秒）で見学する
    python tools/lib/ui_watch.py --slow 1.5          # もっとゆっくり
    python tools/lib/ui_watch.py --keep-open         # 終わったあともブラウザーを開いたままにする
    python tools/lib/ui_watch.py --browser-port 29777  # 起動済みの Edge（--remote-debugging-port）につなぐ

ブラウザーは作業用のプロファイルで自分で起動し、サインインさせない。関係のないタブは閉じる（tools/test/cdp.py）。

自分用の DB（app/data/watch.sqlite）と、空いている番号のサーバーを使う。
画面を見ている dev.sqlite・config.local.php・自動検証の verify.sqlite には触れない（環境変数 MR_VERIFY_DB。app/config/config.php）。
見学のたびにテストデータを作り直す。画面の画像は tools/test/output/見学/<日時>/ に残る。
Python 3.8 以上の標準ライブラリだけで動く。
"""
import argparse
import datetime
import os
import subprocess
import sys

REPOSITORY_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
TEST_TOOL_DIR = os.path.join(REPOSITORY_ROOT, "tools", "test")
sys.path.insert(0, TEST_TOOL_DIR)
from verify_all import find_php, free_port, php_server_command, wait_server  # noqa: E402

WATCH_DB_PATH = os.path.join(REPOSITORY_ROOT, "app", "data", "watch.sqlite")
WATCH_OUTPUT_ROOT = os.path.join(TEST_TOOL_DIR, "output", "見学")


def main():
    for output_stream in (sys.stdout, sys.stderr):
        if hasattr(output_stream, "reconfigure"):
            output_stream.reconfigure(errors="replace")
    parser = argparse.ArgumentParser(description="画面の自動操作を、ブラウザーを表示して見学する")
    parser.add_argument("--slow", type=float, default=0.8, help="1 操作ごとに待つ秒数（既定 0.8）")
    parser.add_argument("--keep-open", action="store_true", help="終わったあともブラウザーを閉じない")
    parser.add_argument("--browser-port", type=int, default=0, help="起動済みのブラウザーにつなぐ（--remote-debugging-port の番号）")
    parser.add_argument("--php", default="", help="php の場所（省くと自動で探す）")
    parser.add_argument("--db", default=WATCH_DB_PATH, help="見学で作り直す DB（既定 app/data/watch.sqlite。見る用の記録を残したいときは別の名前にする）")
    arguments = parser.parse_args()
    if arguments.slow <= 0:
        print("--slow は 0 より大きい秒数を指定してください（速く流すだけなら verify_all.py --ui を使います）")
        return 2

    php_path = find_php(arguments.php)
    if not php_path:
        print("PHP が見つかりません（--php で指定してください）")
        return 2
    stamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    output_dir = os.path.join(WATCH_OUTPUT_ROOT, stamp)
    os.makedirs(output_dir, exist_ok=True)

    child_environment = dict(os.environ)
    child_environment["MR_VERIFY_DB"] = os.path.abspath(arguments.db)
    port_number = free_port()
    base_url = "http://127.0.0.1:%d" % port_number
    print("見学 %s（サーバー: %s、DB: %s）" % (stamp, base_url, os.path.abspath(arguments.db)))

    server_process = subprocess.Popen(php_server_command(php_path, port_number),
                                      cwd=REPOSITORY_ROOT, env=child_environment,
                                      stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        if not wait_server(base_url):
            print("ローカルのサーバーが起動しません")
            return 1
        print("テストデータを作り直しています…")
        seed_result = subprocess.run([php_path, os.path.join("tools", "test", "dev_seed.php"), "--yes"],
                                     cwd=REPOSITORY_ROOT, env=child_environment,
                                     stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        if seed_result.returncode != 0:
            print(seed_result.stdout.decode("utf-8", "replace"))
            print("テストデータを作れませんでした")
            return 1
        ui_command = [sys.executable, "-X", "utf8", "-I", os.path.join(TEST_TOOL_DIR, "ui_check.py"),
                      "--base", base_url, "--db", os.path.abspath(arguments.db), "--out", output_dir,
                      "--show", "--slow", str(arguments.slow)]
        if arguments.keep_open:
            ui_command.append("--keep-open")
        if arguments.browser_port:
            ui_command += ["--browser-port", str(arguments.browser_port)]
        print("ブラウザーで操作を始めます（止めるときは Ctrl + C）")
        ui_result = subprocess.run(ui_command, cwd=REPOSITORY_ROOT, env=child_environment)
        if arguments.keep_open:
            # 開いたままのブラウザーで画面を触れるよう、サーバーは Enter まで動かしておく
            try:
                input("ブラウザーは開いたままです。Enter でサーバーを止めて終わります: ")
            except (EOFError, KeyboardInterrupt):
                pass
        return ui_result.returncode
    except KeyboardInterrupt:
        print("\n中断しました")
        return 130
    finally:
        server_process.terminate()
        try:
            server_process.wait(timeout=10)
        except Exception:  # noqa: BLE001
            server_process.kill()


if __name__ == "__main__":
    sys.exit(main())
