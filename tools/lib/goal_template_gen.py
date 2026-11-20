#!/usr/bin/env python3
"""目標管理評価シートの様式（app/templates/goal_*.xlsx）から、G-27 目標管理が使う定数を作る道具。

様式のシートごとに、コンピテンシー評価の項目（大分類・中分類・行動指針）と、行の位置を読み、
app/lib/goal_templates.php に書き出す。様式を差し替えたら流し直す。

    python3 tools/lib/goal_template_gen.py           # 書き出す
    python3 tools/lib/goal_template_gen.py --check   # 書き出したものが様式と合っているかだけ確かめる（自動検証で使う）

Python 3.8 以上の標準ライブラリだけで動く（xlsx は zip と XML として読む）。
"""
import argparse
import os
import re
import sys
import zipfile
import xml.etree.ElementTree as ElementTree

REPOSITORY_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
OUTPUT_PATH = os.path.join(REPOSITORY_ROOT, "app", "lib", "goal_templates.php")
MAIN_NAMESPACE = "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}"
RELATIONSHIP_NAMESPACE = "{http://schemas.openxmlformats.org/officeDocument/2006/relationships}"

# 種類 => (表示名, 様式のファイル, シート名)
KIND_TABLE = [
    ("general", "一般", "goal_general.xlsx", "目標管理シート（一般）"),
    ("leader", "リーダー・課長代理", "goal_leader.xlsx", "目標管理シート（リーダー・課代）"),
    ("manager", "課長以上", "goal_manager.xlsx", "目標管理シート（課長以上）"),
    ("sales", "営業", "goal_manager.xlsx", "目標管理シート（営業）"),
    ("indirect", "間接部門", "goal_manager.xlsx", "目標管理シート（間接）"),
]


def read_sheet_cells(template_path, sheet_name):
    """シートの {セルの番地: 文字}（共有文字列・inlineStr・数）と、シートの中の場所"""
    with zipfile.ZipFile(template_path) as workbook_zip:
        shared_text_list = []
        if "xl/sharedStrings.xml" in workbook_zip.namelist():
            for string_item in ElementTree.fromstring(workbook_zip.read("xl/sharedStrings.xml")).iter(MAIN_NAMESPACE + "si"):
                # 文字は si の直下の t と、r（書式つきの文字の並び）の中の t だけ。rPh（ふりがな）の t は入れない
                text_node_list = string_item.findall(MAIN_NAMESPACE + "t") + string_item.findall(MAIN_NAMESPACE + "r/" + MAIN_NAMESPACE + "t")
                shared_text_list.append("".join(text_node.text or "" for text_node in text_node_list))
        workbook_root = ElementTree.fromstring(workbook_zip.read("xl/workbook.xml"))
        relationship_root = ElementTree.fromstring(workbook_zip.read("xl/_rels/workbook.xml.rels"))
        target_by_id = {relation.get("Id"): relation.get("Target") for relation in relationship_root}
        sheet_path = None
        for sheet_element in workbook_root.iter(MAIN_NAMESPACE + "sheet"):
            if sheet_element.get("name") == sheet_name:
                target_text = target_by_id[sheet_element.get(RELATIONSHIP_NAMESPACE + "id")]
                sheet_path = target_text.lstrip("/") if target_text.startswith("/") else "xl/" + target_text
        if sheet_path is None:
            raise SystemExit("シートが見つかりません: %s / %s" % (template_path, sheet_name))
        sheet_root = ElementTree.fromstring(workbook_zip.read(sheet_path))
    text_by_reference = {}
    for cell_element in sheet_root.iter(MAIN_NAMESPACE + "c"):
        cell_type = cell_element.get("t")
        value_element = cell_element.find(MAIN_NAMESPACE + "v")
        if cell_type == "s" and value_element is not None:
            text_by_reference[cell_element.get("r")] = shared_text_list[int(value_element.text)]
        elif cell_type == "inlineStr":
            text_by_reference[cell_element.get("r")] = "".join(node.text or "" for node in cell_element.iter(MAIN_NAMESPACE + "t"))
        elif value_element is not None and cell_element.find(MAIN_NAMESPACE + "f") is None:
            text_by_reference[cell_element.get("r")] = value_element.text or ""
    return text_by_reference, sheet_path


def find_row(text_by_reference, column_letter, prefix, after_row=0):
    rows = sorted(int(reference[len(column_letter):]) for reference, text in text_by_reference.items()
                  if re.fullmatch(column_letter + r"\d+", reference) and text.startswith(prefix) and int(reference[len(column_letter):]) > after_row)
    if not rows:
        raise SystemExit("見出しが見つかりません: %s%s…" % (column_letter, prefix))
    return rows[0]


def build_definition(template_dir):
    definition_list = []
    for kind_code, kind_label, file_name, sheet_name in KIND_TABLE:
        text_by_reference, sheet_path = read_sheet_cells(os.path.join(template_dir, file_name), sheet_name)
        first_goal_row = find_row(text_by_reference, "A", "目標１")
        second_goal_row = find_row(text_by_reference, "A", "目標２")
        competency_heading_row = find_row(text_by_reference, "A", "大分類", after_row=second_goal_row)
        score_row = find_row(text_by_reference, "A", "評点", after_row=competency_heading_row)
        item_list = []
        current_major_text = ""
        for row_number in range(competency_heading_row + 1, score_row):
            major_text = text_by_reference.get("A%d" % row_number, "").strip()
            if major_text:
                current_major_text = major_text.replace("\n", "")
            minor_text = text_by_reference.get("B%d" % row_number, "").replace("\n", "").strip()
            guide_text = text_by_reference.get("D%d" % row_number, "").replace("\n", "").strip()
            if minor_text:
                item_list.append({"row": row_number, "major": current_major_text, "minor": minor_text, "guide": guide_text})
        definition_list.append({
            "code": kind_code, "label": kind_label, "file": file_name, "sheet": sheet_name, "sheet_path": sheet_path,
            "goal_rows": [first_goal_row, second_goal_row], "score_row": score_row, "items": item_list,
        })
    return definition_list


def php_string(text):
    return "'" + text.replace("\\", "\\\\").replace("'", "\\'") + "'"


def render_php(definition_list):
    line_list = [
        "<?php", "", "declare(strict_types=1);", "",
        "// ====================================================================",
        "// G-27 目標管理: 様式（app/templates/goal_*.xlsx）から作った定数。手で直さない。",
        "// 様式を差し替えたら python3 tools/lib/goal_template_gen.py で作り直す。",
        "// ====================================================================", "",
        "/** 種類 => 様式のファイル・シート・目標 1・2 の見出しの行・コンピテンシーの評点の行・項目（行・大分類・中分類・行動指針） */",
        "const GOAL_TEMPLATE_KINDS = [",
    ]
    for definition in definition_list:
        line_list.append("    %s => [" % php_string(definition["code"]))
        line_list.append("        'label' => %s, 'file' => %s, 'sheet' => %s, 'sheet_path' => %s," % (
            php_string(definition["label"]), php_string(definition["file"]), php_string(definition["sheet"]), php_string(definition["sheet_path"])))
        line_list.append("        'goal_rows' => [%d, %d], 'score_row' => %d," % (definition["goal_rows"][0], definition["goal_rows"][1], definition["score_row"]))
        line_list.append("        'items' => [")
        for item in definition["items"]:
            line_list.append("            ['row' => %d, 'major' => %s, 'minor' => %s, 'guide' => %s]," % (
                item["row"], php_string(item["major"]), php_string(item["minor"]), php_string(item["guide"])))
        line_list.append("        ],")
        line_list.append("    ],")
    line_list.append("];")
    return "\n".join(line_list) + "\n"


def main():
    for output_stream in (sys.stdout, sys.stderr):
        if hasattr(output_stream, "reconfigure"):
            output_stream.reconfigure(errors="replace")
    parser = argparse.ArgumentParser(description="目標管理の様式から定数を作る")
    parser.add_argument("--check", action="store_true", help="書き出したものが様式と合っているかだけ確かめる")
    arguments = parser.parse_args()
    php_text = render_php(build_definition(os.path.join(REPOSITORY_ROOT, "app", "templates")))
    if arguments.check:
        current_text = open(OUTPUT_PATH, encoding="utf-8").read().replace("\r\n", "\n") if os.path.isfile(OUTPUT_PATH) else ""
        if current_text == php_text:
            print("OK  目標管理の様式の定数が様式と合っている（app/lib/goal_templates.php）")
            return 0
        print("NG  目標管理の様式の定数が様式と合っていない（python3 tools/lib/goal_template_gen.py で作り直す）")
        return 1
    with open(OUTPUT_PATH, "w", encoding="utf-8", newline="\n") as output_file:
        output_file.write(php_text)
    print("書きました: %s" % OUTPUT_PATH)
    return 0


if __name__ == "__main__":
    sys.exit(main())
