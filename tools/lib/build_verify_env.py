#!/usr/bin/env python3
"""検証環境（開発環境とは別の、手元で画面を試す環境）を作り直す。本番には使わない。

    python tools/lib/build_verify_env.py --replace            # コミット済みの HEAD から作る
    python tools/lib/build_verify_env.py --replace --worktree # 作業中の変更（コミット前）も入れる

1. tools/deploy/build_local_kit.py でローカル検証環境の一式を作る（Windows 用。PHP を同梱）
2. リポジトリの隣の verify/report-system-local に写す（--replace のときは、今の検証環境を消してから。DB も消える）
3. 起動の .bat のポートを 8100 にする（開発のローカル検証環境の 8000 と同時に動かせるように）
4. テストデータに、お試し課（tools/test/trial_seed.php）と組織図どおりの課・人と全員回答の全課共通の調査
   （tools/test/org_seed.php）を足すようにし、いまテストデータを作る

Python 3.8 以上の標準ライブラリだけで動く（Windows 用。.bat を書き換えるため）。
"""
import argparse
import glob
import os
import shutil
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
REPOSITORY_ROOT = os.path.dirname(os.path.dirname(HERE))
DEFAULT_TARGET_DIRECTORY = os.path.join(os.path.dirname(REPOSITORY_ROOT), "verify")
KIT_NAME = "report-system-local"
VERIFY_PORT = "8100"
SEED_TOOL_LIST = ["seed_helpers.php", "trial_seed.php", "org_seed.php"]
BACKSLASH = chr(92)
DEV_SEED_MARKER = "tools" + BACKSLASH + "test" + BACKSLASH + "dev_seed.php --yes --with-faq"


def build_kit(include_worktree):
    """一式を作り、できたフォルダー（report-system-local の親）を返す"""
    command = [sys.executable, os.path.join(REPOSITORY_ROOT, "tools", "deploy", "build_local_kit.py")]
    if include_worktree:
        command.append("--worktree")
    subprocess.run(command, check=True, cwd=REPOSITORY_ROOT)
    output_directory_list = sorted(glob.glob(os.path.join(REPOSITORY_ROOT, "tools", "deploy", "output", "local_*")), key=os.path.getmtime)
    output_directory_list = [path for path in output_directory_list if not path.endswith("_x86") and os.path.isdir(os.path.join(path, KIT_NAME))]
    if not output_directory_list:
        raise SystemExit("一式が見つかりません")
    return output_directory_list[-1]


def add_seed_lines(bat_path, error_line_prefix):
    """.bat の dev_seed の行のあとに、お試し課と組織図のテストデータを作る行を足す（失敗したら止める行も）"""
    with open(bat_path, encoding="cp932", newline="") as bat_file:
        line_list = bat_file.read().split("\r\n")
    output_line_list = []
    for line in line_list:
        output_line_list.append(line)
        if line.lstrip().startswith(error_line_prefix):
            indent = line[:len(line) - len(line.lstrip())]
            for seed_tool in SEED_TOOL_LIST[1:]:
                output_line_list.append(indent + '"%PHP_EXE%" tools' + BACKSLASH + "test" + BACKSLASH + seed_tool + " --yes")
                output_line_list.append(line)
    with open(bat_path, "w", encoding="cp932", newline="") as bat_file:
        bat_file.write("\r\n".join(output_line_list))


def set_port(bat_path):
    with open(bat_path, encoding="cp932", newline="") as bat_file:
        text = bat_file.read()
    with open(bat_path, "w", encoding="cp932", newline="") as bat_file:
        bat_file.write(text.replace("8000", VERIFY_PORT))


def main():
    argument_parser = argparse.ArgumentParser(description="検証環境を作り直す（開発環境とは別。ポート 8100）")
    argument_parser.add_argument("--target", default=DEFAULT_TARGET_DIRECTORY, help="置き場所（既定: リポジトリの隣の verify）")
    argument_parser.add_argument("--worktree", action="store_true", help="作業中の変更（コミット前）も入れる")
    argument_parser.add_argument("--replace", action="store_true", help="今の検証環境を消して作り直す（DB も消える）")
    arguments = argument_parser.parse_args()

    kit_directory = os.path.join(arguments.target, KIT_NAME)
    if os.path.exists(kit_directory) and not arguments.replace:
        raise SystemExit("検証環境がもうあります: %s（作り直すときは --replace。DB も消えます）" % kit_directory)
    built_directory = build_kit(arguments.worktree)
    if os.path.exists(kit_directory):
        shutil.rmtree(kit_directory)
    os.makedirs(arguments.target, exist_ok=True)
    shutil.copytree(os.path.join(built_directory, KIT_NAME), kit_directory)
    for seed_tool in SEED_TOOL_LIST:
        shutil.copy2(os.path.join(REPOSITORY_ROOT, "tools", "test", seed_tool), os.path.join(kit_directory, "tools", "test", seed_tool))

    for bat_path in glob.glob(os.path.join(kit_directory, "*.bat")):
        set_port(bat_path)
    add_seed_lines(os.path.join(kit_directory, "1_起動.bat"), "if errorlevel 1 (chcp 932 >nul & echo テストデータを作れませんでした。")
    add_seed_lines(os.path.join(kit_directory, "2_テストデータを作り直す.bat"), "if errorlevel 1 (chcp 932 >nul & echo 作り直せませんでした。")

    environment = dict(os.environ, MR_PHP_DIR=os.path.join(kit_directory, "php"))
    php_executable = os.path.join(kit_directory, "php", "php.exe")
    for seed_command in (["tools/test/dev_seed.php", "--yes", "--with-faq"], ["tools/test/trial_seed.php", "--yes"], ["tools/test/org_seed.php", "--yes"]):
        subprocess.run([php_executable] + seed_command, check=True, cwd=kit_directory, env=environment)
    print("検証環境を作りました: %s（起動は 1_起動.bat、http://127.0.0.1:%s/）" % (kit_directory, VERIFY_PORT))
    return 0


if __name__ == "__main__":
    sys.exit(main())
