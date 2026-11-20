#!/usr/bin/env python3
"""コンピテンシー評価基準（app/templates/competency_criteria.xlsx）を、画面に出す表にして app/lib/goal_criteria.php に書き出す道具。

シートごとに、文字の入ったセルと結合（rowspan・colspan）を読む。何も入っていない行・列と、Excel で隠した行・列は出さない。
評価基準のファイルを差し替えたら流し直す。

    python3 tools/lib/goal_criteria_gen.py           # 書き出す
    python3 tools/lib/goal_criteria_gen.py --check   # 書き出したものが評価基準のファイルと合っているかだけ確かめる（自動検証で使う）

Python 3.8 以上の標準ライブラリだけで動く（xlsx は zip と XML として読む）。
"""
import argparse
import os
import re
import sys
import zipfile
import xml.etree.ElementTree as ElementTree

REPOSITORY_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SOURCE_PATH = os.path.join(REPOSITORY_ROOT, "app", "templates", "competency_criteria.xlsx")
OUTPUT_PATH = os.path.join(REPOSITORY_ROOT, "app", "lib", "goal_criteria.php")
MAIN = "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}"
RELATIONSHIP = "{http://schemas.openxmlformats.org/officeDocument/2006/relationships}"


def column_number(column_text):
    number = 0
    for character in column_text:
        number = number * 26 + (ord(character) - 64)
    return number


def split_reference(reference_text):
    match = re.fullmatch(r"([A-Z]+)(\d+)", reference_text)
    return int(match.group(2)), column_number(match.group(1))


def read_sheets(source_path):
    """[(シート名, {(行, 列): 文字}, [(行1, 列1, 行2, 列2)], 隠した行の集合, 隠した列の集合)]"""
    with zipfile.ZipFile(source_path) as workbook_zip:
        shared_text_list = []
        if "xl/sharedStrings.xml" in workbook_zip.namelist():
            for string_item in ElementTree.fromstring(workbook_zip.read("xl/sharedStrings.xml")).iter(MAIN + "si"):
                text_nodes = string_item.findall(MAIN + "t") + string_item.findall(MAIN + "r/" + MAIN + "t")   # rPh（ふりがな）は入れない
                shared_text_list.append("".join(node.text or "" for node in text_nodes))
        workbook_root = ElementTree.fromstring(workbook_zip.read("xl/workbook.xml"))
        relationship_root = ElementTree.fromstring(workbook_zip.read("xl/_rels/workbook.xml.rels"))
        target_by_id = {relation.get("Id"): relation.get("Target") for relation in relationship_root}
        sheet_list = []
        for sheet_element in workbook_root.iter(MAIN + "sheet"):
            if sheet_element.get("state") in ("hidden", "veryHidden"):
                continue
            target_text = target_by_id[sheet_element.get(RELATIONSHIP + "id")]
            sheet_path = target_text.lstrip("/") if target_text.startswith("/") else "xl/" + target_text
            sheet_root = ElementTree.fromstring(workbook_zip.read(sheet_path))
            text_by_position = {}
            hidden_rows = set()
            for row_element in sheet_root.iter(MAIN + "row"):
                if row_element.get("hidden") in ("1", "true"):
                    hidden_rows.add(int(row_element.get("r")))
                for cell_element in row_element.iter(MAIN + "c"):
                    cell_type = cell_element.get("t")
                    value_element = cell_element.find(MAIN + "v")
                    if cell_type == "s" and value_element is not None:
                        cell_text = shared_text_list[int(value_element.text)]
                    elif cell_type == "inlineStr":
                        cell_text = "".join(node.text or "" for node in cell_element.iter(MAIN + "t"))
                    elif value_element is not None and value_element.text is not None:
                        cell_text = value_element.text
                        if re.fullmatch(r"-?\d+\.0+", cell_text):
                            cell_text = cell_text.split(".")[0]
                    else:
                        continue
                    cell_text = cell_text.replace("​", "").strip()
                    if cell_text:
                        text_by_position[split_reference(cell_element.get("r"))] = cell_text
            hidden_columns = set()
            for column_element in sheet_root.iter(MAIN + "col"):
                if column_element.get("hidden") in ("1", "true"):
                    hidden_columns.update(range(int(column_element.get("min")), int(column_element.get("max")) + 1))
            merge_list = []
            for merge_element in sheet_root.iter(MAIN + "mergeCell"):
                first_text, last_text = merge_element.get("ref").split(":")
                first_row, first_column = split_reference(first_text)
                last_row, last_column = split_reference(last_text)
                merge_list.append((first_row, first_column, last_row, last_column))
            sheet_list.append((sheet_element.get("name"), text_by_position, merge_list, hidden_rows, hidden_columns))
    return sheet_list


def build_table(text_by_position, merge_list, hidden_rows, hidden_columns):
    """出す行・列を決め、行ごとのセル（文字・rowspan・colspan）にする"""
    visible_text_by_position = {position: text for position, text in text_by_position.items()
                                if position[0] not in hidden_rows and position[1] not in hidden_columns}
    if not visible_text_by_position:
        return []
    merge_by_anchor = {(merge[0], merge[1]): merge for merge in merge_list}
    kept_rows = sorted({row for row, _ in visible_text_by_position})
    kept_columns = sorted({column for _, column in visible_text_by_position})
    covered_positions = set()
    for first_row, first_column, last_row, last_column in merge_list:
        if (first_row, first_column) not in visible_text_by_position:
            continue
        for row in range(first_row, last_row + 1):
            for column in range(first_column, last_column + 1):
                if (row, column) != (first_row, first_column):
                    covered_positions.add((row, column))
    table_row_list = []
    for row in kept_rows:
        cell_list = []
        for column in kept_columns:
            if (row, column) in covered_positions:
                continue
            text = visible_text_by_position.get((row, column), "")
            rowspan = colspan = 1
            merge = merge_by_anchor.get((row, column))
            if merge is not None and text:
                rowspan = max(1, len([kept_row for kept_row in kept_rows if merge[0] <= kept_row <= merge[2]]))
                colspan = max(1, len([kept_column for kept_column in kept_columns if merge[1] <= kept_column <= merge[3]]))
            cell_list.append({"text": text, "rowspan": rowspan, "colspan": colspan})
        # 見出し・説明の行（文が 2 つ以下）は、Excel で右の空いたセルにはみ出して見える文なので、右の空いたセルまで広げる
        if sum(1 for cell in cell_list if cell["text"]) <= 2:
            widened_cell_list = []
            for cell in cell_list:
                if not cell["text"] and cell["rowspan"] == 1 and widened_cell_list and widened_cell_list[-1]["text"] and widened_cell_list[-1]["rowspan"] == 1:
                    widened_cell_list[-1]["colspan"] += cell["colspan"]
                    continue
                widened_cell_list.append(cell)
            cell_list = widened_cell_list
        table_row_list.append(cell_list)
    return table_row_list


def php_string(text):
    return "'" + text.replace("\\", "\\\\").replace("'", "\\'") + "'"


def render_php(sheet_tables):
    line_list = [
        "<?php", "", "declare(strict_types=1);", "",
        "// ====================================================================",
        "// G-27 目標管理: コンピテンシー評価基準（app/templates/competency_criteria.xlsx）を画面に出す表。手で直さない。",
        "// 評価基準のファイルを差し替えたら python3 tools/lib/goal_criteria_gen.py で作り直す。",
        "// ====================================================================", "",
        "/** シート => 行の並び（行は セル の並び。セルは text・rowspan・colspan） */",
        "const GOAL_CRITERIA_SHEETS = [",
    ]
    for sheet_name, table_row_list in sheet_tables:
        line_list.append("    %s => [" % php_string(sheet_name))
        for cell_list in table_row_list:
            cell_text_list = ["[%s, %d, %d]" % (php_string(cell["text"]), cell["rowspan"], cell["colspan"]) for cell in cell_list]
            line_list.append("        [" + ", ".join(cell_text_list) + "],")
        line_list.append("    ],")
    line_list.append("];")
    return "\n".join(line_list) + "\n"


def main():
    for output_stream in (sys.stdout, sys.stderr):
        if hasattr(output_stream, "reconfigure"):
            output_stream.reconfigure(errors="replace")
    parser = argparse.ArgumentParser(description="コンピテンシー評価基準を画面に出す表にする")
    parser.add_argument("--check", action="store_true", help="書き出したものが評価基準のファイルと合っているかだけ確かめる")
    arguments = parser.parse_args()
    sheet_tables = [(sheet_name, build_table(text_by_position, merge_list, hidden_rows, hidden_columns))
                    for sheet_name, text_by_position, merge_list, hidden_rows, hidden_columns in read_sheets(SOURCE_PATH)]
    php_text = render_php(sheet_tables)
    if arguments.check:
        current_text = open(OUTPUT_PATH, encoding="utf-8").read().replace("\r\n", "\n") if os.path.isfile(OUTPUT_PATH) else ""
        if current_text == php_text:
            print("OK  コンピテンシー評価基準の表が評価基準のファイルと合っている（app/lib/goal_criteria.php）")
            return 0
        print("NG  コンピテンシー評価基準の表が評価基準のファイルと合っていない（python3 tools/lib/goal_criteria_gen.py で作り直す）")
        return 1
    with open(OUTPUT_PATH, "w", encoding="utf-8", newline="\n") as output_file:
        output_file.write(php_text)
    for sheet_name, table_row_list in sheet_tables:
        print("%s: %d 行" % (sheet_name, len(table_row_list)))
    print("書きました: %s" % OUTPUT_PATH)
    return 0


if __name__ == "__main__":
    sys.exit(main())
