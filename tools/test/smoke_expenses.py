#!/usr/bin/env python3
"""G-26 通勤定期代の精算の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

- 本人だけが見る・直す・消す・受け取る。ほかの人（確認者・課長・管理者・補佐）は 404
- 受け付けるのは通勤定期だけ（ほかの種別は保存しない）。入力の確かめ
- 様式の xlsx: 値の入るセル・2 枚目・式の値・保護・記入例のシートがそのまま
- 中身は DB で暗号化。記録（access_logs）に残さない。スマートフォンでは受け取れない

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_expenses.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import datetime
import io
import json
import os
import re
import sqlite3
import sys
import zipfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, csrf, login, rkey, section, summary  # noqa: E402

MOBILE_USER_AGENT = "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1"
SECRET_ROUTE_TEXT = "門真南～(谷町六丁目)～天王寺（暗号の確かめ）"


def sheet_form_values(page_html, row_list, **header_values):
    """精算書の保存の送信の中身（上の欄と明細の行）"""
    form_values = {
        "csrf_token": csrf(page_html), "request_key": rkey(page_html), "action": "save",
        "submitted_on": "2026-11-20", "payment_method": "transfer", "employee_number": "999", "office": "大阪",
        "department_name": "システム開発部", "full_name": "試験 太郎", "advance_amount": "",
    }
    form_values.update(header_values)
    for row_index, row_values in enumerate(row_list):
        for column_key, cell_value in row_values.items():
            form_values["rows[%d][%s]" % (row_index, column_key)] = cell_value
    return form_values


def pass_row(amount_text="11450", **override_values):
    row_values = {"start_date": "2026-11-01", "end_date": "2027-04-30", "purpose": "通勤（本社）", "transport": "私鉄・地下鉄",
                  "route": SECRET_ROUTE_TEXT, "trip": "往復", "amount": amount_text}
    row_values.update(override_values)
    return row_values


def cell_xml(sheet_xml, cell_reference):
    cell_match = re.search(r'<c r="%s"[^>]*?(?:/>|>.*?</c>)' % cell_reference, sheet_xml)
    return cell_match.group(0) if cell_match else ""


def cell_text(sheet_xml, cell_reference):
    cell_source = cell_xml(sheet_xml, cell_reference)
    inline_match = re.search(r"<is><t[^>]*>(.*?)</t></is>", cell_source)
    if inline_match:
        return inline_match.group(1)
    value_match = re.search(r"<v>(.*?)</v>", cell_source)
    return value_match.group(1) if value_match else ""


def main():
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--base", default="http://127.0.0.1:8000")
    argument_parser.add_argument("--db", required=True)
    arguments = argument_parser.parse_args()
    test_dir = os.path.dirname(os.path.abspath(__file__))
    repository_root = os.path.dirname(os.path.dirname(test_dir))
    user_info_by_login_id = json.load(open(os.path.join(test_dir, "output_dev_users.json"), encoding="utf-8"))["users"]
    database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)

    section("G-26", "通勤定期代の精算: 作る・保存する")
    owner = login(arguments.base, "ippan_a1", user_info_by_login_id["ippan_a1"])
    status_code, _, page_html = owner.get("/index.php")
    check("メニューに「通勤定期代の精算」が出る（全員）", "/expenses/index.php" in page_html and "通勤定期代の精算" in page_html)
    status_code, _, page_html = owner.get("/expenses/index.php")
    check("一覧を開ける", status_code == 200 and "精算書はまだありません" in page_html, status_code)
    check("一覧に「通勤定期だけ・ほかは別のシステム」の案内が出る", "通勤定期だけ" in page_html and "別のシステム" in page_html)
    status_code, _, page_html = owner.get("/expenses/edit.php")
    check("新しく作る画面に、氏名と課が入っている", "A一般1" in page_html and "A課" in page_html, status_code)
    check("種別は選べず「通勤定期」だけ", 'name="rows[0][kind]"' not in page_html and "expense-fixed-kind" in page_html)

    status_code, location, page_html = owner.post("/expenses/edit.php", sheet_form_values(page_html, [pass_row(kind="業務交通費")]))
    check("通勤定期のほかの種別を送っても保存しない（別のシステムの案内）", status_code == 200 and "別のシステムで申請してください" in page_html
          and database.execute("SELECT COUNT(*) FROM expense_sheets").fetchone()[0] == 0, status_code)
    status_code, location, page_html = owner.post("/expenses/edit.php", sheet_form_values(page_html, [pass_row(kind="通勤実費")]))
    check("通勤実費も保存しない", status_code == 200 and database.execute("SELECT COUNT(*) FROM expense_sheets").fetchone()[0] == 0)
    status_code, location, page_html = owner.post("/expenses/edit.php", sheet_form_values(page_html, []))
    check("明細が無いと保存しない", status_code == 200 and "明細を 1 行以上入れてください" in page_html)
    status_code, location, page_html = owner.post("/expenses/edit.php", sheet_form_values(
        page_html, [pass_row(amount_text="", route="", end_date="2026-10-01")], office="", advance_amount="-5"))
    check("入力の確かめ（金額・区間・終わりの日・所属・仮払い）", all(text in page_html for text in (
        "金額は 0 から", "発～(経由)～着を 80 文字以内で入れてください", "終わりの月／日は", "所属を選んでください", "仮払いは 0 から")), page_html[:0])
    check("入力の誤りのあとも、入れた値が残る", "通勤（本社）" in page_html)

    row_list = [pass_row(amount_text="１１，４５０円")] + [pass_row(amount_text="1000", start_date="2026-11-%02d" % (day_number + 2), end_date="")
                                                       for day_number in range(13)]
    status_code, location, page_html = owner.post("/expenses/edit.php", sheet_form_values(page_html, row_list, advance_amount="10000"))
    sheet_id_match = re.search(r"/expenses/edit\.php\?id=(\d+)", location)
    check("通勤定期の 14 行を保存できる（種別は送らなくても通勤定期になる。全角の金額もよい）", status_code in (302, 303) and sheet_id_match is not None, (status_code, location))
    sheet_id = int(sheet_id_match.group(1)) if sheet_id_match else 0
    status_code, _, page_html = owner.get("/expenses/edit.php?id=%d" % sheet_id)
    check("保存した精算書の小計・合計（小計 − 仮払い）", "24,450" in page_html and "14,450" in page_html, status_code)

    section("G-26", "通勤定期代の精算: 本人だけが見られる")
    stored_row = database.execute("SELECT user_id, data_enc FROM expense_sheets WHERE id = ?", (sheet_id,)).fetchone()
    check("DB の中身は暗号化されている（区間・氏名が平文で無い）", stored_row is not None and SECRET_ROUTE_TEXT not in stored_row[1] and "試験 太郎" not in stored_row[1])
    check("記録（access_logs）に精算書のことを残さない",
          database.execute("SELECT COUNT(*) FROM access_logs WHERE target LIKE '%expense%'").fetchone()[0] == 0)
    for other_login_id in ("leader_a", "kacho_a", "admin01", "hosa01", "ippan_a2"):
        other_client = login(arguments.base, other_login_id, user_info_by_login_id[other_login_id])
        edit_status, _, _ = other_client.get("/expenses/edit.php?id=%d" % sheet_id)
        copy_status, _, _ = other_client.get("/expenses/edit.php?copy=%d" % sheet_id)
        download_status, _, _ = other_client.get("/expenses/download.php?id=%d" % sheet_id)
        _, _, other_list_html = other_client.get("/expenses/index.php")
        _, _, other_new_html = other_client.get("/expenses/edit.php")
        delete_status, _, _ = other_client.post("/expenses/edit.php", {"csrf_token": csrf(other_new_html), "request_key": rkey(other_new_html),
                                                                        "action": "delete", "sheet_id": str(sheet_id)})
        check("%s は、ほかの人の精算書を開く・写す・受け取る・消すことができない（404）" % other_login_id,
              (edit_status, copy_status, download_status, delete_status) == (404, 404, 404, 404) and "精算書はまだありません" in other_list_html,
              (edit_status, copy_status, download_status, delete_status))
    check("ほかの人の操作のあとも、精算書は残っている", database.execute("SELECT COUNT(*) FROM expense_sheets WHERE id = ?", (sheet_id,)).fetchone()[0] == 1)

    section("G-26", "通勤定期代の精算: 様式の Excel（.xlsx）")
    status_code, _, _ = owner.get("/expenses/download.php?id=%d" % sheet_id)
    workbook_bytes = owner.last_raw
    content_disposition = owner.last_headers.get("Content-Disposition", "")
    check("PC からは xlsx を受け取れる（ファイル名は社員番号_交通費通勤定期代精算書_提出日）", status_code == 200 and workbook_bytes[:2] == b"PK"
          and "filename*=UTF-8''999_%E4%BA%A4%E9%80%9A%E8%B2%BB" in content_disposition and "2026-11-20.xlsx" in content_disposition, (status_code, content_disposition))
    workbook_zip = zipfile.ZipFile(io.BytesIO(workbook_bytes))
    check("xlsx の zip が壊れていない（CRC）", workbook_zip.testzip() is None)
    template_zip = zipfile.ZipFile(os.path.join(repository_root, "app", "templates", "expense_sheet.xlsx"))
    check("様式のファイルの中身の一覧は、元の様式と同じ", sorted(workbook_zip.namelist()) == sorted(template_zip.namelist()))
    check("記入例のシート・書式・入力規則のもとは、元の様式のまま",
          all(workbook_zip.read(part_name) == template_zip.read(part_name) for part_name in ("xl/worksheets/sheet2.xml", "xl/styles.xml", "xl/sharedStrings.xml")))
    sheet_xml = workbook_zip.read("xl/worksheets/sheet1.xml").decode("utf-8")
    check("上の欄（精算方法・提出日・社員番号・所属・部署・氏名）", (cell_text(sheet_xml, "C3"), cell_text(sheet_xml, "F3"), cell_text(sheet_xml, "H3"), cell_text(sheet_xml, "J3"),
                                                   cell_text(sheet_xml, "C5"), cell_text(sheet_xml, "F5"), cell_text(sheet_xml, "I5"), cell_text(sheet_xml, "O5"))
          == ("振込", "2026", "11", "20", "999", "大阪", "システム開発部", "試験 太郎"))
    expected_start_serial = str((datetime.date(2026, 11, 1) - datetime.date(1899, 12, 30)).days)
    check("明細の 1 行目（日付は Excel の日付・種別・区間・金額）", (cell_text(sheet_xml, "A8"), cell_text(sheet_xml, "C8"), cell_text(sheet_xml, "M8"), cell_text(sheet_xml, "V8"), cell_text(sheet_xml, "W8"))
          == (expected_start_serial, "通勤定期", SECRET_ROUTE_TEXT, "往復", "11450"))
    check("日付のセルの書式（m/d）はそのまま", 's="50"' in cell_xml(sheet_xml, "A8"))
    check("1 枚目の小計・仮払い・合計（式は残し、値も入れる）", "<f>SUM(W8:X20)</f>" in cell_xml(sheet_xml, "W21") and cell_text(sheet_xml, "W21") == "23450"
          and cell_text(sheet_xml, "W22") == "10000" and cell_text(sheet_xml, "W23") == "13450")
    check("14 行目は 2 枚目（No.2）の 1 行目に入り、2 枚目の小計が出る", cell_text(sheet_xml, "W39") == "1000" and cell_text(sheet_xml, "W52") == "1000"
          and cell_text(sheet_xml, "O36") == "試験 太郎")
    check("シートの保護は様式のまま", "<sheetProtection" in sheet_xml)
    check("開いたときに式を計算し直す設定", 'fullCalcOnLoad="1"' in workbook_zip.read("xl/workbook.xml").decode("utf-8"))
    owner.headers["User-Agent"] = MOBILE_USER_AGENT
    status_code, _, _ = owner.get("/expenses/download.php?id=%d" % sheet_id)
    check("スマートフォンでは受け取れない（PC から）", status_code == 403, status_code)
    status_code, _, _ = owner.get("/expenses/edit.php?id=%d" % sheet_id)
    check("スマートフォンでも入力はできる", status_code == 200, status_code)
    owner.headers["User-Agent"] = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) test"

    section("G-26", "通勤定期代の精算: 写す・消す")
    status_code, _, page_html = owner.get("/expenses/edit.php?copy=%d" % sheet_id)
    today_text = datetime.date.today().isoformat()
    check("写して新しく作ると、明細が入り、提出日は今日・仮払いは空", SECRET_ROUTE_TEXT in page_html and 'value="%s"' % today_text in page_html
          and 'id="advance_amount" name="advance_amount" type="text" inputmode="numeric" maxlength="9" class="expense-advance" value=""' in page_html, status_code)
    status_code, location, _ = owner.post("/expenses/edit.php?id=%d" % sheet_id, {"csrf_token": csrf(page_html), "request_key": rkey(page_html),
                                                                               "action": "delete", "sheet_id": str(sheet_id)})
    check("本人は消せる", status_code in (302, 303) and database.execute("SELECT COUNT(*) FROM expense_sheets WHERE id = ?", (sheet_id,)).fetchone()[0] == 0, status_code)
    return summary()


if __name__ == "__main__":
    sys.exit(main())
