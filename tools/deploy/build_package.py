#!/usr/bin/env python3
"""本番（さくらのレンタルサーバー）への反映用の一式を作る。

    python3 tools/deploy/build_package.py            # コミット済みの HEAD から作る
    python3 tools/deploy/build_package.py --ref main # 指定したブランチ・コミットから作る

出力: tools/deploy/output/<日時>_<コミット>/
    report-system/      ← この中身を、サーバーの ~/www/report-system/ にそのまま上書きで上げる
        .htaccess  login.php  ...  （public/ の中身）
        app/                         （画面の処理。Web からは .htaccess で拒否）
    report-system.zip   ← 上の report-system/ を zip にしたもの（展開できる道具を使うとき）
    反映手順.txt         ← 課長に渡す手順（サーバーには上げない）

入れないもの: docs/ tools/ __pycache__ と、app/config/config.local.example.php（ローカル検証専用）。
app/data（DB）・app/keys（暗号の鍵）・app/logs・app/backups・config.local.php はリポジトリに無いので入らない。
→ 上書きで上げても、本番の DB・鍵・記録は消えない。

コミットした内容だけを使う（作業中の変更は入らない）。Python 3.8 以上の標準ライブラリと git だけで動く。
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
TOP = "report-system"

# 一式に入れてはいけないもの（入っていたら作るのをやめる）
FORBIDDEN_SUFFIXES = (".md", ".py", ".pyc", ".sqlite", ".sqlite3", ".key", ".json", ".log", ".bak")
FORBIDDEN_NAMES = ("config.local.php", "config.local.example.php")
# 一式に必ず入っていなければならないもの
REQUIRED = (".htaccess", "_boot.php", "login.php", "health.php", "app/.htaccess", "app/bootstrap.php", "app/config/config.php")

STEPS = """月次報告システム 反映手順（{stamp} / {ref} {commit}）

■ 上げるもの
  同じフォルダーの「report-system」フォルダーの中身を、サーバーの
  ~/www/report-system/ にそのまま上書きで上げてください。
  （zip を展開できる道具を使うときは report-system.zip でも同じです）

■ 注意
  1. 「.htaccess」が 2 つあります（report-system/.htaccess と report-system/app/.htaccess）。
     名前が「.」で始まるファイルは、道具によっては表示されず、上がらないことがあります。
     上げたあと、サーバー側に 2 つとも有ることを必ず確かめてください。
     無いと、app/ の中身（設定など）が Web から見える恐れがあります。
  2. サーバーにある app/data（DB）・app/keys（暗号の鍵）・app/logs・app/backups は
     消さないでください。この一式には入っていないので、上書きしても消えません。
  3. 前回までに上げた docs/・tools/・public/・data/ フォルダー（旧システムのものを含む）が
     ~/www/report-system/ の直下に残っていたら消してください（本番では使いません）。
     消し忘れても、この一式の .htaccess で Web からは取れなくなります。
     ※ app/data（今のシステムの DB）は消さないでください。
     app/config/config.local.php がサーバーにあったら消してください（ローカル検証専用の設定です）。
  4. このファイル（反映手順.txt）はサーバーに上げないでください。

■ 上げたあとの確認（開発担当が行います）
  python3 tools/deploy/check_prod.py https://toyama-mai.sakura.ne.jp/report-system
  （画面を見るだけの確認です。ログインやデータの変更はしません）

■ 入っているもの
{listing}
"""


def git(*args, binary=False):
    out = subprocess.run(["git", "-C", ROOT] + list(args), check=True, stdout=subprocess.PIPE).stdout
    return out if binary else out.decode("utf-8").strip()


def main():
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--ref", default="HEAD", help="作るもとのブランチ・コミット（既定: HEAD）")
    a = ap.parse_args()

    commit = git("rev-parse", "--short", a.ref)
    dirty = git("status", "--porcelain", "--", "public", "app")
    if dirty and a.ref == "HEAD":
        print("注意: public/ か app/ にコミットしていない変更があります。一式には入りません。\n" + dirty + "\n")

    tar_bytes = git("archive", "--format=tar", a.ref, "public", "app", binary=True)
    files = {}  # 一式の中の場所 -> 中身
    with tarfile.open(fileobj=io.BytesIO(tar_bytes)) as tar:
        for m in tar.getmembers():
            if not m.isfile():
                continue
            if m.name.startswith("public/"):
                dest = m.name[len("public/"):]
            elif m.name.startswith("app/"):
                dest = m.name
            else:
                continue
            if os.path.basename(dest) == "config.local.example.php":
                continue
            files[dest] = tar.extractfile(m).read()

    bad = [p for p in files if p.endswith(FORBIDDEN_SUFFIXES) or os.path.basename(p) in FORBIDDEN_NAMES
           or "__pycache__" in p.split("/")]
    missing = [p for p in REQUIRED if p not in files]
    if bad or missing:
        sys.exit("一式を作れません。入れてはいけないもの: %s / 足りないもの: %s" % (bad, missing))

    stamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    out = os.path.join(HERE, "output", "%s_%s" % (stamp, commit))
    for rel, data in files.items():
        path = os.path.join(out, TOP, *rel.split("/"))
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "wb") as f:
            f.write(data)

    with zipfile.ZipFile(os.path.join(out, TOP + ".zip"), "w", zipfile.ZIP_DEFLATED) as z:
        for rel in sorted(files):
            z.writestr(TOP + "/" + rel, files[rel])

    listing = "\n".join("  " + TOP + "/" + p for p in sorted(files))
    with open(os.path.join(out, "反映手順.txt"), "w", encoding="utf-8", newline="\r\n") as f:
        f.write(STEPS.format(stamp=stamp, ref=a.ref, commit=commit, listing=listing))

    pub = sum(1 for p in files if not p.startswith("app/"))
    print("作りました: %s" % out)
    print("  入り口など %d ファイル + app/ %d ファイル（%s %s）" % (pub, len(files) - pub, a.ref, commit))


if __name__ == "__main__":
    main()
