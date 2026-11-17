#!/usr/bin/env python3
"""出力（G-10）の xlsx と CSV を、表計算のソフトで実際に開いて確かめる（ローカル検証環境専用。Windows の Excel を使う）。

アプリの出力の関数（export_xlsx・export_csv）で作ったファイルを、Excel（COM）で読み取り専用で開き、
見出し・ふつうの値・数式に見える値（=1+1 が 2 にならない）・記号（< & "）が、そのまま読めるかを確かめる。
Excel が無い端末では、確かめられなかったことを NG として出す（黙って通さない）。LibreOffice があれば、それでも開く。

    python3 tools/test/check_office.py --php <php.exe>
"""
import argparse
import os
import shutil
import subprocess
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, section, summary  # noqa: E402

ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))

# アプリの出力の関数でファイルを作る PHP（引数: 出力先のフォルダー）
MAKE_FILES_PHP = r"""
putenv('MR_VERIFY_DB');
require 'app/bootstrap.php';
require_once APP_DIR . '/lib/export.php';
$outputDir = $argv[1];
$rows = [['P-0001', '在庫の引当処理'], ['P-0002', '=1+1'], ['P-0003', '<script>&"記号"']];
file_put_contents($outputDir . '/office_check.xlsx', export_xlsx('報告', ['社外秘 出力者: 検証'], ['案件', '業務内容'], $rows));
file_put_contents($outputDir . '/office_check.csv', export_csv(['社外秘 出力者: 検証'], ['案件', '業務内容'], $rows));
echo "ok";
"""

# Excel で開いて、セルの文字を 1 行ずつ出す PowerShell（引数: ファイル）
READ_WITH_EXCEL_PS = r"""
param([string]$FilePath)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$excel = New-Object -ComObject Excel.Application
$excel.Visible = $false
$excel.DisplayAlerts = $false
try {
    $workbook = $excel.Workbooks.Open($FilePath, 0, $true)
    $sheet = $workbook.Worksheets.Item(1)
    $usedRange = $sheet.UsedRange
    for ($rowIndex = 1; $rowIndex -le $usedRange.Rows.Count; $rowIndex++) {
        $cellTextList = @()
        for ($columnIndex = 1; $columnIndex -le $usedRange.Columns.Count; $columnIndex++) {
            $cellTextList += [string]$sheet.Cells.Item($rowIndex, $columnIndex).Text
        }
        [Console]::Out.WriteLine(($cellTextList -join "`t"))
    }
    $workbook.Close($false)
} finally {
    $excel.Quit()
    [System.Runtime.InteropServices.Marshal]::ReleaseComObject($excel) | Out-Null
}
"""


def find_excel():
    for candidate_path in [r"C:\Program Files\Microsoft Office\root\Office16\EXCEL.EXE", r"C:\Program Files (x86)\Microsoft Office\root\Office16\EXCEL.EXE",
                           r"C:\Program Files\Microsoft Office\Office16\EXCEL.EXE"]:
        if os.path.isfile(candidate_path):
            return candidate_path
    return ""


def find_libreoffice():
    for candidate_path in [shutil.which("soffice") or "", r"C:\Program Files\LibreOffice\program\soffice.exe", "/usr/bin/soffice", "/usr/bin/libreoffice"]:
        if candidate_path and os.path.isfile(candidate_path):
            return candidate_path
    return ""


def check_cell_lines(software_name, file_label, cell_line_list):
    joined_text = "\n".join(cell_line_list)
    check("%s で %s が開ける" % (software_name, file_label), len(cell_line_list) >= 4, cell_line_list[:2])
    check("%s で %s の見出し（社外秘）と、ふつうの値が読める" % (software_name, file_label), "社外秘" in joined_text and "在庫の引当処理" in joined_text, cell_line_list[:3])
    check("%s で %s の「=1+1」は計算されず、文字のまま（2 にならない）" % (software_name, file_label),
          any(line.endswith("=1+1") or line.endswith("'=1+1") for line in cell_line_list) and not any(line.split("\t")[-1] == "2" for line in cell_line_list), cell_line_list)
    check("%s で %s の記号（< & \"）が文字のまま読める" % (software_name, file_label), '<script>&"記号"' in joined_text, cell_line_list)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--php", required=True)
    arguments = ap.parse_args()
    section("G-10", "表計算のソフトで開く（Excel・LibreOffice）")
    work_dir = tempfile.mkdtemp(prefix="mr_office_")
    try:
        make_result = subprocess.run([arguments.php, "-r", MAKE_FILES_PHP, work_dir], cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        check("出力の関数で xlsx と CSV を作れる（前提）", make_result.stdout.decode("utf-8", "replace").strip().endswith("ok"), make_result.stdout[-300:])
        excel_path = find_excel()
        check("Excel がある（無ければ、Excel で開けることを確かめられない）", excel_path != "")
        if excel_path:
            script_path = os.path.join(work_dir, "read_with_excel.ps1")
            with open(script_path, "w", encoding="utf-8-sig") as script_file:
                script_file.write(READ_WITH_EXCEL_PS)
            for file_name, file_label in [("office_check.xlsx", "xlsx"), ("office_check.csv", "CSV")]:
                read_result = subprocess.run(["powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", script_path,
                                              os.path.join(work_dir, file_name)], stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=120)
                output_text = read_result.stdout.decode("utf-8", "replace") if b"\x00" not in read_result.stdout else read_result.stdout.decode("utf-16", "replace")
                cell_line_list = [line.rstrip("\r") for line in output_text.splitlines() if line.strip()]
                check_cell_lines("Excel", file_label, cell_line_list)
        libreoffice_path = find_libreoffice()
        if libreoffice_path:
            convert_result = subprocess.run([libreoffice_path, "--headless", "--convert-to", "csv:Text - txt - csv (StarCalc):9,34,76", "--outdir", os.path.join(work_dir, "libreoffice"),
                                             os.path.join(work_dir, "office_check.xlsx")], stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=180)
            converted_path = os.path.join(work_dir, "libreoffice", "office_check.csv")
            converted_text = open(converted_path, encoding="utf-8", errors="replace").read() if os.path.isfile(converted_path) else ""
            check_cell_lines("LibreOffice Calc", "xlsx", [line.replace('","', "\t").strip('"') for line in converted_text.splitlines() if line.strip()])
        else:
            print("--  LibreOffice Calc は入っていないため、LibreOffice で開くことは確かめていない（入れれば自動で確かめる）")
    finally:
        shutil.rmtree(work_dir, ignore_errors=True)
    return summary()


if __name__ == "__main__":
    sys.exit(main())
