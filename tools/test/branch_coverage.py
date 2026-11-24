#!/usr/bin/env python3
"""自動検証が通っていない分岐を出す（未検証を NG にする）。

リポジトリの写しを一時フォルダーに作り、PHP（app/）と public/js/app.js の分岐に印を入れて、自動検証（verify_all.py --ui）を流す。
一度も通らなかった分岐のうち、coverage_exceptions.tsv（ファイル・行の中身・動かせない理由）に無いものが 1 つでもあれば NG。
開発の DB・設定には触れない。検証のサーバーとブラウザーは verify_all.py が終わりに閉じる。

    python3 tools/test/branch_coverage.py [--php <php>] [--keep]

分岐を通ることの確かめで、結果が正しいかは各検証が見る。Python 3.8 以上の標準ライブラリだけで動く。
"""
import argparse
import collections
import csv
import glob
import json
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
EXCEPTIONS = os.path.join(HERE, "coverage_exceptions.tsv")
SKIP_DIRS = {".git", "__pycache__", "他端末検証"}
SKIP_PATHS = {os.path.join("tools", "deploy", "output"), os.path.join("tools", "test", "output"), os.path.join("app", "data"), os.path.join("app", "logs"), os.path.join("app", "backups")}


def copy_tree(target):
    for folder, names, files in os.walk(ROOT):
        relative = os.path.relpath(folder, ROOT)
        names[:] = [n for n in names if n not in SKIP_DIRS and os.path.normpath(os.path.join(relative, n)) not in SKIP_PATHS]
        destination = os.path.normpath(os.path.join(target, relative))
        os.makedirs(destination, exist_ok=True)
        for name in files:
            if not name.endswith((".sqlite", ".sqlite-wal", ".sqlite-shm")):
                shutil.copy2(os.path.join(folder, name), os.path.join(destination, name))
    for name in ("data", "logs"):
        os.makedirs(os.path.join(target, "app", name), exist_ok=True)
    open(os.path.join(target, ".coverage_copy"), "w").close()


def main():
    for output_stream in (sys.stdout, sys.stderr):
        if hasattr(output_stream, "reconfigure"):
            output_stream.reconfigure(encoding="utf-8", errors="replace")
    ap = argparse.ArgumentParser()
    ap.add_argument("--php", default=os.environ.get("PHP_BIN", "") or shutil.which("php") or "")
    ap.add_argument("--keep", action="store_true", help="写しを残す（調べるとき）")
    ap.add_argument("--only", default="", help="verify_all.py の --only（一部だけ流して調べるとき。判定には使えない）")
    a = ap.parse_args()
    if not a.php:
        found = glob.glob(os.path.join(os.environ.get("LOCALAPPDATA", ""), "Microsoft", "WinGet", "Packages", "PHP.PHP*", "php.exe"))
        a.php = found[0] if found else ""
    if not a.php:
        print("PHP が見つかりません（--php で指定してください）")
        return 2
    work = os.path.realpath(tempfile.mkdtemp(prefix="mr_coverage_"))
    copy, hits = os.path.join(work, "copy"), os.path.join(work, "hits")
    os.makedirs(hits)
    try:
        copy_tree(copy)
        map_path = os.path.join(work, "map.json")
        subprocess.run([a.php, os.path.join(HERE, "coverage_instrument.php"), copy.replace(os.sep, "/"), map_path], check=True)
        prepend = os.path.join(work, "prepend.php")
        with open(prepend, "w", encoding="utf-8") as handle:
            handle.write("<?php function __cov($n) { static $hit = []; static $init = false; if (!$init) { $init = true; $dir = %r;"
                         " register_shutdown_function(function () use (&$hit, $dir) { register_shutdown_function(function () use (&$hit, $dir) {"
                         " @file_put_contents($dir . '/' . getmypid() . '.txt', implode(\"\\n\", array_keys($hit)) . \"\\n\", FILE_APPEND | LOCK_EX); }); }); }"
                         " $hit[$n] = 1; }\n" % hits.replace(os.sep, "/"))
        ini = os.path.join(work, "ini")
        os.makedirs(ini)
        with open(os.path.join(ini, "coverage.ini"), "w", encoding="utf-8") as handle:
            handle.write('auto_prepend_file="%s"\n' % prepend.replace(os.sep, "/"))
        environment = dict(os.environ, PHP_INI_SCAN_DIR=ini, PHP_BIN=a.php, PYTHONUTF8="1")
        command = [sys.executable, "-B", os.path.join(copy, "tools", "test", "verify_all.py"), "--php", a.php, "--ui"]
        if a.only:
            command += ["--only", a.only]
        subprocess.run(command, cwd=copy, env=environment)
        hit = set()
        for path in glob.glob(os.path.join(hits, "*.txt")):
            with open(path) as handle:
                hit |= set(handle.read().split())
        branch_map = json.load(open(map_path, encoding="utf-8"))
        allowed = collections.Counter()
        if os.path.exists(EXCEPTIONS):
            with open(EXCEPTIONS, encoding="utf-8", newline="") as handle:
                for row in csv.reader(handle, delimiter="\t", quoting=csv.QUOTE_NONE):
                    if row and not row[0].startswith("#"):
                        if len(row) < 3 or not row[2].strip():
                            print("NG  理由の無い例外: %s" % row)
                            return 1
                        allowed[(row[0], row[1])] += 1
        missed = [(v[0], v[1], v[2]) for k, v in branch_map.items() if k not in hit]
        unverified = []
        excepted = 0
        for path, line, text in sorted(missed):
            if allowed[(path, text[:150])] > 0:
                allowed[(path, text[:150])] -= 1
                excepted += 1
            else:
                unverified.append((path, line, text))
        out = os.path.join(HERE, "output", "未検証の分岐.txt")
        os.makedirs(os.path.dirname(out), exist_ok=True)
        with open(out, "w", encoding="utf-8") as handle:
            for path, line, text in unverified:
                handle.write("%s\t%d\t%s\n" % (path, line, text[:150]))
        print("\n分岐 %d / 通った %d / 理由付きの例外 %d / 未検証 %d" % (len(branch_map), len(branch_map) - len(missed), excepted, len(unverified)))
        for path, count in collections.Counter(p for p, _, _ in unverified).most_common():
            print("NG  未検証 %3d  %s" % (count, path))
        print("一覧: " + out)
        return 1 if unverified else 0
    finally:
        if not a.keep:
            shutil.rmtree(work, ignore_errors=True)
        else:
            print("写し: " + work)


if __name__ == "__main__":
    sys.exit(main())
