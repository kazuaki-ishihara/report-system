#!/usr/bin/env python3
"""公開済みのサイトに、前の版からの差分だけを上げる一式を作る（DB・暗号の鍵・アカウントはそのまま残す）。

    python tools/deploy/build_diff_package.py --base <上げてある版のコミット> [--ref HEAD] [--site report-system]

    例: デモ用のサイト（~/www/report-system_onion/。dev_ono_check8_3 の b4c8a5c を上げてある）に今の版を上げる
        python tools/deploy/build_diff_package.py --base b4c8a5c --site report-system_onion

出力: tools/deploy/output/diff_<日時>_<base>_<ref>/
    <site>/            ← 追加・変更したファイルだけ。この中身を、サーバーの ~/www/<site>/ に上書きで上げる
    <site>.zip         ← 上の <site>/ を zip にしたもの
    差分公開範囲.txt   ← 上げるもの・消すもの・触らないもの（サーバーには上げない）
    反映手順.txt       ← 上げる人に渡す手順（サーバーには上げない）

入れないもの・確かめることは build_package.py と同じ（docs/・tools/・config.local*.php・DB・鍵は入らない）。
DB の形（app/lib/schema.php）が変わっていれば、手順に migrate.php の実行を入れる。
コミットした内容だけを使う。Python 3.8 以上の標準ライブラリと git だけで動く。
"""
import argparse
import datetime
import io
import os
import subprocess
import sys
import tarfile
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))

# build_package.py と同じ決まり
FORBIDDEN_SUFFIXES = (".md", ".py", ".pyc", ".sqlite", ".sqlite3", ".key", ".json", ".log", ".bak")
FORBIDDEN_NAMES = ("config.local.php", "config.local.example.php")
# DB の形が変わったかを見るファイル（変わっていれば migrate.php を実行する）
SCHEMA_PATH_LIST = ("app/lib/schema.php", "app/bin/migrate.php")

SCOPE_TEXT = """月次報告システム 差分公開範囲（{stamp}）

対象のサイト: ~/www/{site}/
今サーバーにある版: {base_commit}（{base_subject}）
上げる版:           {head_commit}（{head_subject}）

■ 上げるもの（追加 {added_count} ・変更 {modified_count} ファイル）
  同じフォルダーの「{site}」フォルダーの中身を、サーバーの ~/www/{site}/ に上書きで上げる。
{upload_listing}

■ サーバーから消すもの（{deleted_count} ファイル）
{delete_listing}

■ 触らないもの（消さない・上書きしない）
  app/data/（DB。アカウントと、これまでの報告・回答が入っている）
  app/keys/（暗号の鍵。消すと「限定」の項目や回答が読めなくなる）
  app/logs/・app/backups/・app/config/config.local.php（あれば）
  上の一覧にないファイル（前の版のまま使う）

■ DB の形の変更
{schema_note}
"""

STEPS_TEXT = """月次報告システム 差分の反映手順（{stamp}）

対象: ~/www/{site}/（今の版 {base_commit} → {head_commit}）
すでにアカウントを配って使っているサイトなので、DB・暗号の鍵・アカウントは消さずに、差分だけを上げます。
上げるもの・消すものの一覧は「差分公開範囲.txt」にあります。

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
0. やってはいけないこと
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  - ~/www/{site}/ の中身をすべて消すこと（DB と鍵が消え、配ったアカウントが使えなくなります）
  - app/data・app/keys・app/logs・app/backups を消す・上書きすること
  - seed_template.php・create_user.php を実行すること
  - このファイルと差分公開範囲.txt をサーバーに上げること

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
1. DB の控えを取る（SSH）
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  cd ~/www/{site}
  php app/bin/backup.php
  □ 「バックアップ」の行が出て、エラーが出ないことを確かめる

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
2. ファイルを上げる
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  □ 「{site}」フォルダーの中身を、~/www/{site}/ に上書きで上げる（{upload_count} ファイル）
     （{site}/{site}/ にならないよう注意。~/www/{site}/surveys/results.php のようになれば正しい）
{delete_step}
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
3. DB の形を新しくする（SSH）
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{migrate_step}
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
4. 確かめる
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  □ https://<サーバー>/{site}/health.php が「ok」
  □ 配ったアカウントでログインでき、これまでの報告・回答が見える
  □ 困ったら: 2 の前に戻すには、1 の控え（app/backups/）と前の版の一式を開発担当に相談する
"""


def git(*args, binary=False):
    output = subprocess.run(["git", "-C", ROOT] + list(args), check=True, stdout=subprocess.PIPE).stdout
    return output if binary else output.decode("utf-8").strip()


def package_path(repository_path):
    """リポジトリの場所を、一式の中の場所にする（public/ は直下へ、app/ はそのまま）。入れないものは None"""
    if repository_path.startswith("public/"):
        destination = repository_path[len("public/"):]
    elif repository_path.startswith("app/"):
        destination = repository_path
    else:
        return None
    if os.path.basename(destination) in FORBIDDEN_NAMES or "__pycache__" in destination.split("/"):
        return None
    return destination


def main():
    argument_parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    argument_parser.add_argument("--base", required=True, help="サーバーに今ある版のコミット")
    argument_parser.add_argument("--ref", default="HEAD", help="上げる版（既定: HEAD）")
    argument_parser.add_argument("--site", default="report-system", help="サーバーのフォルダー名（~/www/<site>/。既定: report-system）")
    arguments = argument_parser.parse_args()

    base_commit = git("rev-parse", "--short", arguments.base)
    head_commit = git("rev-parse", "--short", arguments.ref)
    if git("status", "--porcelain", "--", "public", "app") and arguments.ref == "HEAD":
        print("注意: public/ か app/ にコミットしていない変更があります。一式には入りません。")

    status_line_list = git("-c", "core.quotepath=false", "diff", "--name-status", "--no-renames", arguments.base, arguments.ref, "--", "public", "app").splitlines()
    added_path_list, modified_path_list, deleted_path_list = [], [], []
    for status_line in status_line_list:
        status_code, repository_path = status_line.split("\t", 1)
        destination = package_path(repository_path)
        if destination is None:
            continue
        {"A": added_path_list, "M": modified_path_list, "D": deleted_path_list}.get(status_code[0], modified_path_list).append(destination)
    upload_path_list = sorted(added_path_list + modified_path_list)
    if not upload_path_list and not deleted_path_list:
        sys.exit("差分がありません（%s と %s で public/・app/ が同じ）" % (base_commit, head_commit))
    bad_path_list = [path for path in upload_path_list if path.endswith(FORBIDDEN_SUFFIXES)]
    if bad_path_list:
        sys.exit("入れてはいけないものが差分にあります: %s" % bad_path_list)

    # 上げるファイルの中身は、上げる版の内容（改行は git に入っているまま）
    tar_bytes = git("-c", "core.autocrlf=false", "archive", "--format=tar", arguments.ref, "public", "app", binary=True)
    content_by_destination = {}
    with tarfile.open(fileobj=io.BytesIO(tar_bytes)) as tar_file:
        for member in tar_file.getmembers():
            destination = package_path(member.name) if member.isfile() else None
            if destination in upload_path_list:
                content_by_destination[destination] = tar_file.extractfile(member).read()

    stamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    output_directory = os.path.join(HERE, "output", "diff_%s_%s_%s" % (stamp, base_commit, head_commit))
    for destination, content in content_by_destination.items():
        file_path = os.path.join(output_directory, arguments.site, *destination.split("/"))
        os.makedirs(os.path.dirname(file_path), exist_ok=True)
        with open(file_path, "wb") as output_file:
            output_file.write(content)
    with zipfile.ZipFile(os.path.join(output_directory, arguments.site + ".zip"), "w", zipfile.ZIP_DEFLATED) as zip_file:
        for destination in upload_path_list:
            zip_file.writestr(arguments.site + "/" + destination, content_by_destination[destination])

    schema_changed = any(path in SCHEMA_PATH_LIST for path in added_path_list + modified_path_list)
    mark_by_path = {path: "追加" for path in added_path_list}
    mark_by_path.update({path: "変更" for path in modified_path_list})
    format_values = {
        "stamp": stamp, "site": arguments.site,
        "base_commit": base_commit, "base_subject": git("log", "-1", "--format=%ad %s", "--date=format:%Y-%m-%d %H:%M", arguments.base),
        "head_commit": head_commit, "head_subject": git("log", "-1", "--format=%ad %s", "--date=format:%Y-%m-%d %H:%M", arguments.ref),
        "added_count": len(added_path_list), "modified_count": len(modified_path_list), "deleted_count": len(deleted_path_list),
        "upload_count": len(upload_path_list),
        "upload_listing": "\n".join("  [%s] %s" % (mark_by_path[path], path) for path in upload_path_list),
        "delete_listing": "\n".join("  %s" % path for path in sorted(deleted_path_list)) or "  なし",
        "schema_note": ("  変わっています。上げたあとに必ず migrate.php を 1 回実行する（反映手順.txt の 3）。\n"
                        "  何度実行しても同じ結果になる。実行すると「〜を追加」「〜を作り直した」の行が出る。") if schema_changed else "  変わっていません（migrate.php の実行は要りません）。",
        "delete_step": ("  □ 差分公開範囲.txt の「サーバーから消すもの」を、~/www/%s/ から消す\n" % arguments.site) if deleted_path_list else "",
        "migrate_step": ("  cd ~/www/%s\n  php app/bin/migrate.php\n  □ エラーが出ず、最後に「テーブルを確認しました」と出ることを確かめる\n"
                         "  □ 出た行（「〜を追加」「〜を作り直した」）を開発担当に送る\n" % arguments.site) if schema_changed else "  今回は要りません。\n",
    }
    for file_name, template_text in (("差分公開範囲.txt", SCOPE_TEXT), ("反映手順.txt", STEPS_TEXT)):
        with open(os.path.join(output_directory, file_name), "w", encoding="utf-8", newline="\r\n") as text_file:
            text_file.write(template_text.format(**format_values))

    print("作りました: %s" % output_directory)
    print("  上げる %d ファイル（追加 %d・変更 %d）・消す %d ファイル・migrate.php %s（%s → %s）"
          % (len(upload_path_list), len(added_path_list), len(modified_path_list), len(deleted_path_list),
             "要る" if schema_changed else "要らない", base_commit, head_commit))
    return 0


if __name__ == "__main__":
    sys.exit(main())
