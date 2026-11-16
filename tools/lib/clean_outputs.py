#!/usr/bin/env python3
"""検証の出力と、作った一式の古いものを消す（リポジトリに入らない、作り直せるものだけ）。

    python3 tools/lib/clean_outputs.py            消すものを出すだけ（消さない）
    python3 tools/lib/clean_outputs.py --yes      消す
    python3 tools/lib/clean_outputs.py --keep 5 --yes

消すもの:
  - tools/test/output/検証記録/<日時>/ の古い実行（新しい --keep 回分を残す。一覧.csv も残した行だけにする）
  - tools/deploy/output/local_*（ローカル検証環境の一式）のうち、いちばん新しいもの以外
消さないもの: 本番反映の一式（tools/deploy/output/<日時>_<コミット>/）、DB・鍵・他端末検証/。
Python 3.8 以上の標準ライブラリだけで動く。
"""
import argparse
import os
import shutil

REPOSITORY_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
RECORD_DIR = os.path.join(REPOSITORY_ROOT, "tools", "test", "output", "検証記録")
DEPLOY_OUTPUT_DIR = os.path.join(REPOSITORY_ROOT, "tools", "deploy", "output")


def folder_size(folder_path):
    total_bytes = 0
    for root_path, _dir_names, file_names in os.walk(folder_path):
        for file_name in file_names:
            total_bytes += os.path.getsize(os.path.join(root_path, file_name))
    return total_bytes


def main():
    parser = argparse.ArgumentParser(description="検証の出力と古い一式を消す")
    parser.add_argument("--keep", type=int, default=3, help="残す検証記録の回数（既定 3）")
    parser.add_argument("--yes", action="store_true", help="本当に消す（付けなければ、消すものを出すだけ）")
    arguments = parser.parse_args()

    removed_path_list = []
    kept_run_set = set()
    if os.path.isdir(RECORD_DIR):
        run_name_list = sorted(name for name in os.listdir(RECORD_DIR)
                               if os.path.isdir(os.path.join(RECORD_DIR, name)) and name[:8].isdigit())
        keep_count = max(arguments.keep, 0)
        kept_run_set = set(run_name_list[-keep_count:]) if keep_count else set()
        removed_path_list += [os.path.join(RECORD_DIR, name) for name in run_name_list if name not in kept_run_set]
    if os.path.isdir(DEPLOY_OUTPUT_DIR):
        kit_name_list = sorted(name for name in os.listdir(DEPLOY_OUTPUT_DIR) if name.startswith("local_"))
        removed_path_list += [os.path.join(DEPLOY_OUTPUT_DIR, name) for name in kit_name_list[:-1]]

    removed_bytes = sum(folder_size(path) for path in removed_path_list)
    for removed_path in removed_path_list:
        print(("消す: " if arguments.yes else "消すもの: ") + os.path.relpath(removed_path, REPOSITORY_ROOT))
        if arguments.yes:
            shutil.rmtree(removed_path)

    index_path = os.path.join(RECORD_DIR, "一覧.csv")
    if arguments.yes and os.path.isfile(index_path):
        # 元の BOM の有無と改行のまま、残した実行の行だけにする
        with open(index_path, "rb") as index_file:
            index_bytes = index_file.read()
        has_bom = index_bytes.startswith(b"\xef\xbb\xbf")
        index_line_list = index_bytes.decode("utf-8-sig").splitlines(keepends=True)
        if index_line_list:
            kept_line_list = [index_line_list[0]] + [line for line in index_line_list[1:] if line.split(",")[0] in kept_run_set]
            with open(index_path, "w", encoding="utf-8-sig" if has_bom else "utf-8", newline="") as index_file:
                index_file.write("".join(kept_line_list))

    print("%s %d 件、%.1f MB" % ("消した" if arguments.yes else "消すもの", len(removed_path_list), removed_bytes / 1024 / 1024))
    if not arguments.yes and removed_path_list:
        print("消すときは --yes を付けて実行してください。")


if __name__ == "__main__":
    main()
