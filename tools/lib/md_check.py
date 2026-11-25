#!/usr/bin/env python3
"""MD の文書と実装の食い違いを、機械で分かる範囲で探す（読むだけ。何も変えない）。

    python3 tools/lib/md_check.py             # 食い違い（NG）と参考を出す。NG があれば終了コード 1
    python3 tools/lib/md_check.py --quiet     # NG だけを出す

NG にするもの: リンク切れ、文書にある無いファイル名、DB 編に無い表・列・暗号化の列、共通編に無い設定・ライブラリ、無い節への参照。
参考に出すもの: 文書に名前が出ない画面の処理・検証・道具、「未実装」「まだ作っていない」などの記述（実際の状態と合うかは人が見る）。
文の意味（権限・文言・手順が実装どおりか）は見ない。Git に入っているファイルだけを見る。Python 3.8 以上の標準ライブラリだけで動く。
"""
import argparse
import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
COMMON = "docs/detailed_design/00_common.md"
DATABASE = "docs/detailed_design/01_database.md"
# 過去の状態の記録・検討の記録（いまの実装と違ってよい）
RECORD_DOCUMENTS = ("docs/work_resume.md", "docs/verification_items.md", "docs/redesign_plan.md")
# 文書に書くが、リポジトリに入れないファイル
UNTRACKED_OK = ("config.local.php",)
SECTION_PATTERN = re.compile(r"(共通編|DB 編|基本設計書|G-\d\d) §(\d+(?:\.\d+)*)")
PENDING_PATTERN = re.compile(r"今後作成|まだ作っていない|まだない|まだありません|未実装|TODO|作成予定")


def read(path):
    with open(os.path.join(ROOT, path), encoding="utf-8") as handle:
        return handle.read()


def tracked_files():
    output = subprocess.run(["git", "-C", ROOT, "-c", "core.quotepath=false", "ls-files"], check=True, stdout=subprocess.PIPE).stdout
    return [path for path in output.decode("utf-8").split("\n") if path and os.path.isfile(os.path.join(ROOT, path))]


def check_links(documents, ng):
    for path, text in documents.items():
        for link in re.findall(r"\]\(([^)#\s]+)", text):
            if link.startswith(("http:", "https:", "mailto:")) or "…" in link:
                continue
            if not os.path.exists(os.path.join(ROOT, os.path.dirname(path), link)):
                ng.append("リンク切れ: %s → %s" % (path, link))


def check_file_names(current_documents, files, ng):
    base_names = {os.path.basename(path) for path in files}
    for path, text in current_documents.items():
        for name in sorted(set(re.findall(r"`((?:app|public|tools)/[\w/.-]+\.(?:php|py|js|css|tsv|txt))`", text))):
            if name not in files and os.path.basename(name) not in UNTRACKED_OK:
                ng.append("無いファイル: %s に %s" % (path, name))
        for name in sorted(set(re.findall(r"`([a-z_]+\.(?:php|py))`", text))):
            if name not in base_names and name not in UNTRACKED_OK:
                ng.append("無いファイル: %s に %s" % (path, name))


def check_database(documents, ng):
    schema = read("app/lib/schema.php") + read("app/lib/faq_chat_log.php")
    database_document = documents[DATABASE]
    for table in sorted(set(re.findall(r"CREATE TABLE IF NOT EXISTS (\w+)", schema))):
        if table not in database_document:
            ng.append("DB 編に無い表: " + table)
    for column in sorted(set(re.findall(r"^\s+'(\w+)'\s+=> [\"'](?:TEXT|INTEGER)", schema, re.M))):
        if column not in database_document:
            ng.append("DB 編に無い列: " + column)
    for column in sorted(set(re.findall(r"\b(\w+_enc)\b", schema))):
        if "`%s`" % column not in database_document:
            ng.append("DB 編 §4 に無い暗号化の列: " + column)


def check_common(documents, files, ng):
    common_document = documents[COMMON]
    for name in re.findall(r"^\s+'(\w+)'\s*=>", read("app/config/config.php"), re.M):
        if "`%s`" % name not in common_document:
            ng.append("共通編 §12 に無い設定: " + name)
    for path in files:
        if re.match(r"app/lib/[^/]+\.php\Z", path) and os.path.basename(path) not in common_document:
            ng.append("共通編のファイル一覧に無い: " + path)


def check_sections(documents, current_documents, files, ng):
    heading_numbers = {path: set(re.findall(r"^#{2,5} (\d+(?:\.\d+)*)", text, re.M)) for path, text in documents.items()}
    document_by_label = {"共通編": COMMON, "DB 編": DATABASE, "基本設計書": "docs/basic_design.md"}
    for path in documents:
        match = re.match(r"docs/detailed_design/G(\d\d)_", path)
        if match:
            document_by_label["G-" + match.group(1)] = path
    sources = dict(current_documents)
    for path in files:
        if re.match(r"(app|tools)/.*\.(php|py)\Z", path) and path != "tools/lib/md_check.py":
            sources[path] = read(path)
    for path, text in sorted(sources.items()):
        for label, number in sorted(set(SECTION_PATTERN.findall(text))):
            if label in document_by_label and number not in heading_numbers[document_by_label[label]]:
                ng.append("無い節への参照: %s に「%s §%s」" % (path, label, number))


def collect_notes(documents, current_documents, files):
    notes = []
    all_text = "\n".join(documents.values())
    for path in files:
        base_name = os.path.basename(path)
        is_target = re.match(r"app/(pages|bin)/[^/]+\.php\Z", path) or re.match(r"tools/.*\.py\Z", path) or re.match(r"public/.*\.php\Z", path)
        if is_target and base_name not in all_text and os.path.splitext(base_name)[0] not in all_text:
            notes.append("文書に名前が出ない: " + path)
    for path, text in sorted(current_documents.items()):
        for line_number, line in enumerate(text.split("\n"), 1):
            for match in PENDING_PATTERN.finditer(line):
                notes.append("「%s」: %s:%d …%s…" % (match.group(0), path, line_number, line[max(0, match.start() - 30):match.end() + 20]))
    return notes


def main():
    for output_stream in (sys.stdout, sys.stderr):
        if hasattr(output_stream, "reconfigure"):
            output_stream.reconfigure(encoding="utf-8", errors="replace")
    argument_parser = argparse.ArgumentParser(description="MD の文書と実装の食い違いを探す")
    argument_parser.add_argument("--quiet", action="store_true", help="NG だけを出す（参考を出さない）")
    arguments = argument_parser.parse_args()

    files = tracked_files()
    documents = {path: read(path) for path in files if path.endswith(".md")}
    current_documents = {path: text for path, text in documents.items() if path not in RECORD_DOCUMENTS}
    ng = []
    check_links(documents, ng)
    check_file_names(current_documents, set(files), ng)
    check_database(documents, ng)
    check_common(documents, files, ng)
    check_sections(documents, current_documents, files, ng)

    for line in ng:
        print("NG  " + line)
    if not arguments.quiet:
        for line in collect_notes(documents, current_documents, files):
            print("参考 " + line)
    print("文書 %d、NG %d 件" % (len(documents), len(ng)))
    return 1 if ng else 0


if __name__ == "__main__":
    sys.exit(main())
