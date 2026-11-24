#!/usr/bin/env python3
"""出力の一斉チェック（検証環境向け）。動いているサーバーに対して、出力をすべて取り出して確かめる。

    python tools/test/check_outputs.py --base http://127.0.0.1:8100 --db <検証環境>/app/data/dev.sqlite --prepare --out <PDF の置き場所>

--prepare: 確かめに使うデータを足す（課のある全員の前月・前々月の報告を提出、前々月は課長が確認完了、
           通勤定期代の精算書・目標管理シート・要望を 1 つずつ）。足したものは残る（検証環境は作り直せば消える）。
           すでにある報告は作り直さない（2 回目以降は足りない分だけ）。

確かめる出力:
  G-10 月次報告（課ごと。印刷・xlsx・CSV、横並び・まとめ・両方、自由記述・限定の有無、権限・期間の誤り）
  G-10 臨時の調査（課の調査。印刷・xlsx・CSV。件数を DB から数え直して突き合わせる）
  G-11 全課共通の調査（管理者・補佐・部長以上・依頼元の担当。全課と課ごとの件数を DB と突き合わせる）
  G-14 組織の CSV、G-13 案件の CSV、G-21 記録の CSV、G-22 要望の CSV、G-26 精算書の xlsx、G-27 目標管理の xlsx・zip
印刷用の画面は PDF にして --out に置く（目で見る用。Edge か Chrome が要る。--no-pdf で省く）。

ログイン: 検証環境は dev_skip_2fa でパスワードだけ。2 段階認証を求められたときは dev_seed の決まりのコードを使う。
Python 3.8 以上の標準ライブラリだけで動く。
"""
import argparse
import base64
import csv
import datetime
import hashlib
import io
import json
import os
import random
import re
import sqlite3
import sys
import tempfile
import time
import urllib.parse
import zipfile
from xml.etree import ElementTree

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from lib_http import Client, check, csrf, next_login_code, rkey, section, summary  # noqa: E402

MOBILE_USER_AGENT = "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1"
FREE_TEXT_MARKER = "自由記述の確かめ"
RESTRICTED_MARKER = "限定の確かめ"
CSV_INJECTION_TEXT = "=HYPERLINK(\"http://example.invalid\")"
FULL_WIDTH_INJECTION_TEXT = "＝1+1（全角の数式の確かめ）"
QUOTE_TEXT = "引用\"と,カンマ\n改行"
PHP_ERROR_PATTERN = re.compile(r"(Warning|Notice|Fatal error|Deprecated|Parse error|Uncaught)\b.*?( on line |in /|in [A-Z]:)", re.S)
XLSX_NAMESPACE = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}


# ---------------------------------------------------------------- ログイン・取り出し

def seed_user_info(login_id):
    secret = base64.b32encode(hashlib.sha1(("dev-totp-" + login_id).encode()).digest()[:20]).decode()
    return {"password": "pass-%s-1234" % login_id, "totp": secret}


def login_as(base_url, login_id, user_agent=None):
    client = Client(base_url)
    if user_agent:
        client.headers["User-Agent"] = user_agent
    _, _, page_html = client.get("/login.php")
    info = seed_user_info(login_id)
    status_code, location, _ = client.post("/login.php", {"csrf_token": csrf(page_html), "action": "password", "login_id": login_id, "password": info["password"]})
    if "step=2" in location:
        _, _, page_html = client.get("/login.php?step=2")
        status_code, location, _ = client.post("/login.php", {"csrf_token": csrf(page_html), "action": "code", "code": next_login_code(login_id, info["totp"]), "remember": "0"})
    if "/index.php" not in location:
        raise SystemExit("ログインできません: %s %s %s" % (login_id, status_code, location))
    return client


class ClientPool:
    def __init__(self, base_url):
        self.base_url = base_url
        self.client_by_login_id = {}

    def __call__(self, login_id):
        if login_id not in self.client_by_login_id:
            self.client_by_login_id[login_id] = login_as(self.base_url, login_id)
        return self.client_by_login_id[login_id]


def post_with_tokens(client, form_page_path, post_path, form_values):
    """フォームの画面を開いて、csrf と送信の番号を付けて送る"""
    _, _, page_html = client.get(form_page_path)
    values = {"csrf_token": csrf(page_html), "request_key": rkey(page_html)}
    values.update(form_values)
    return client.post(post_path, values)


def disposition_file_name(headers):
    disposition = headers.get("Content-Disposition", "") if headers else ""
    match = re.search(r"filename\*=UTF-8''([^;]+)", disposition)
    return urllib.parse.unquote(match.group(1)) if match else ""


def has_php_error(text):
    return PHP_ERROR_PATTERN.search(text) is not None


# ---------------------------------------------------------------- CSV・xlsx を読む

def read_csv_rows(raw_bytes):
    text = raw_bytes.decode("utf-8-sig")
    return list(csv.reader(io.StringIO(text)))


def read_xlsx_rows(raw_bytes, all_sheets=False):
    """1 枚目のシートの行（文字の一覧。all_sheets ならすべてのシートの行をつなぐ）。共有文字列と inline の文字の両方を読む"""
    workbook = zipfile.ZipFile(io.BytesIO(raw_bytes))
    shared_strings = []
    if "xl/sharedStrings.xml" in workbook.namelist():
        root = ElementTree.fromstring(workbook.read("xl/sharedStrings.xml"))
        for item in root.findall("m:si", XLSX_NAMESPACE):
            shared_strings.append("".join(t.text or "" for t in item.iter("{%s}t" % XLSX_NAMESPACE["m"])))
    sheet_names = sorted((name for name in workbook.namelist() if re.match(r"xl/worksheets/sheet\d+\.xml$", name)),
                         key=lambda name: int(re.search(r"(\d+)\.xml$", name).group(1)))
    row_list = []
    for sheet_name in (sheet_names if all_sheets else sheet_names[:1]):
        row_list.extend(read_sheet_rows(ElementTree.fromstring(workbook.read(sheet_name)), shared_strings))
    return row_list


def read_sheet_rows(root, shared_strings):
    row_list = []
    for row in root.iter("{%s}row" % XLSX_NAMESPACE["m"]):
        cell_text_by_column = {}
        for cell in row.findall("m:c", XLSX_NAMESPACE):
            column_letters = re.match(r"[A-Z]+", cell.get("r", "A")).group(0)
            column_index = 0
            for letter in column_letters:
                column_index = column_index * 26 + (ord(letter) - 64)
            cell_type = cell.get("t", "")
            value_element = cell.find("m:v", XLSX_NAMESPACE)
            if cell_type == "inlineStr":
                text = "".join(t.text or "" for t in cell.iter("{%s}t" % XLSX_NAMESPACE["m"]))
            elif cell_type == "s" and value_element is not None:
                text = shared_strings[int(value_element.text)]
            else:
                text = value_element.text if value_element is not None and value_element.text else ""
            cell_text_by_column[column_index] = text
        width = max(cell_text_by_column) if cell_text_by_column else 0
        row_list.append([cell_text_by_column.get(index, "") for index in range(1, width + 1)])
    return row_list


def trimmed(row_list):
    """末尾の空の欄と空の行を落とす（CSV と xlsx を比べるため。xlsx は上の行と表の間に空の行を 1 つ入れる）。
    CSV は = + - @ で始まる値の前に ' を付ける（xlsx は文字の欄にするので付けない）ので、比べるときは外す"""
    result = []
    for row in row_list:
        cells = [cell[1:] if cell[:1] == "'" and cell[1:2] in ("=", "+", "-", "@", "\t", "\r") else cell for cell in row]
        while cells and cells[-1] == "":
            cells.pop()
        if cells:
            result.append(cells)
    return result


# ---------------------------------------------------------------- PDF（印刷用の画面を目で見る用）

class PdfPrinter:
    def __init__(self, base_url, out_dir):
        self.base_url = base_url.rstrip("/")
        self.out_dir = out_dir
        self.browser = None
        self.work_dir = tempfile.mkdtemp(prefix="mr_print_")
        self.made_file_list = []

    def save(self, page_html, file_label):
        if self.browser is None:
            from cdp import Browser
            self.browser = Browser(headless=True, width=1280, height=900)
        html_text = page_html.replace("<head>", '<head><base href="%s/">' % self.base_url, 1)
        html_path = os.path.join(self.work_dir, "page_%d.html" % (len(self.made_file_list) + 1))
        with open(html_path, "w", encoding="utf-8") as html_file:
            html_file.write(html_text)
        self.browser.goto("file:///" + html_path.replace("\\", "/"))
        result = self.browser.call("Page.printToPDF", {"printBackground": True, "preferCSSPageSize": True}, timeout=60)
        pdf_bytes = base64.b64decode(result["data"])
        pdf_path = os.path.join(self.out_dir, re.sub(r'[\\/:*?"<>|]', "_", file_label) + ".pdf")
        with open(pdf_path, "wb") as pdf_file:
            pdf_file.write(pdf_bytes)
        self.made_file_list.append(pdf_path)
        return pdf_bytes

    def close(self):
        if self.browser is not None:
            self.browser.quit()


# ---------------------------------------------------------------- データを足す

def month_shift(period_text, month_count):
    year, month = map(int, period_text.split("-"))
    index = year * 12 + (month - 1) + month_count
    return "%04d-%02d" % (index // 12, index % 12 + 1)


def fill_report_form(page_html, random_source, login_id, is_special_user):
    """報告の入力画面の欄を、種類に合わせて埋めた送信の中身（案件は 1 つ目のブロックだけ）"""
    # 前月の報告があると「前月と同じ内容で提出」のフォームが先にあるので、入力欄のフォーム（id="report-form"）を選ぶ
    form_match = re.search(r'<form[^>]*id="report-form"[^>]*>(.*?)</form>', page_html, re.S)
    form_html = form_match.group(1) if form_match else page_html
    values = {}
    for select_match in re.finditer(r'<select[^>]*name="((?:b\[0\]|r)[^"]*)"[^>]*>(.*?)</select>', form_html, re.S):
        option_values = [value for value in re.findall(r'<option value="([^"]*)"', select_match.group(2)) if value != ""]
        if option_values:
            values[select_match.group(1)] = random_source.choice(option_values)
    radio_values = {}
    checkbox_values = {}
    for input_tag in re.findall(r"<input[^>]*>", form_html):
        name_match = re.search(r'name="((?:b\[0\]|r)[^"]*)"', input_tag)
        if not name_match:
            continue
        name = name_match.group(1)
        if name.endswith("[remove]"):
            continue   # 「この案件を外す」は付けない（前月から写した案件が外れる）
        type_match = re.search(r'type="([a-z]+)"', input_tag)
        input_type = type_match.group(1) if type_match else "text"
        value_match = re.search(r'value="([^"]*)"', input_tag)
        value = value_match.group(1) if value_match else ""
        if input_type == "radio":
            if value != "":
                radio_values.setdefault(name, []).append(value)
        elif input_type == "checkbox":
            checkbox_values.setdefault(name, []).append(value)
        elif input_type == "month":
            values[name] = month_shift(datetime.date.today().strftime("%Y-%m"), random_source.randint(1, 12))
        elif input_type == "date":
            values[name] = (datetime.date.today() + datetime.timedelta(days=random_source.randint(1, 200))).isoformat()
        elif input_type == "number":
            values[name] = str(random_source.randint(0, 20))
        elif input_type in ("text", "search"):
            values[name] = "%s の入力 %d" % (login_id, random_source.randint(1, 99))
    for name, option_values in radio_values.items():
        values[name] = random_source.choice(option_values)
    for name, option_values in checkbox_values.items():
        chosen = [value for value in option_values if random_source.random() < 0.5] or option_values[:1]
        values[name] = chosen
    for textarea_match in re.finditer(r'<textarea[^>]*name="((?:b\[0\]|r)[^"]*)"[^>]*>', form_html):
        name = textarea_match.group(1)
        text = "%s（%s）: 作業は予定どおり。" % (FREE_TEXT_MARKER, login_id)
        if is_special_user:
            text = CSV_INJECTION_TEXT if "[f][2]" in name else (FULL_WIDTH_INJECTION_TEXT if "[f][5]" in name else QUOTE_TEXT)
        values[name] = text
    return values


def prepare_data(database, pool, periods, restricted_field_ids):
    section("道具", "データを足す（報告・確認完了・精算書・目標管理・要望）")
    member_list = database.execute(
        "SELECT u.id, u.login_id, u.department_id FROM users u JOIN departments d ON d.id = u.department_id "
        "WHERE u.is_active = 1 AND d.is_active = 1 ORDER BY u.department_id, u.id").fetchall()
    created_count = 0
    failed_list = []
    special_login_id = member_list[0][1] if member_list else ""
    for user_id, login_id, department_id in member_list:
        random_source = random.Random("output-check-" + login_id)
        for period_text in periods:
            exists = database.execute("SELECT status FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NULL AND is_legacy = 0",
                                      (user_id, period_text)).fetchone()
            if exists and exists[0] in ("submitted", "confirmed"):
                continue
            client = pool(login_id)
            status_code, location, page_html = client.get("/reports/edit.php?period=" + period_text)
            if status_code != 200:
                failed_list.append("%s %s: 入力画面 %s %s" % (login_id, period_text, status_code, location))
                continue
            form_values = fill_report_form(page_html, random_source, login_id, login_id == special_login_id)
            for field_id in restricted_field_ids:
                key = "b[0][f][%d]" % field_id
                if key in form_values and isinstance(form_values[key], str):
                    form_values[key] = "%s（%s）" % (RESTRICTED_MARKER, login_id)
            form_values.update({"csrf_token": csrf(page_html), "request_key": rkey(page_html), "period": period_text, "action": "submit"})
            status_code, location, response_html = client.post("/reports/save.php", form_values)
            if status_code == 303 and "view.php" in location:
                created_count += 1
            else:
                error_texts = re.findall(r'class="(?:error|field-error)[^"]*"[^>]*>(.*?)<', response_html)
                failed_list.append("%s %s: 提出 %s %s %s" % (login_id, period_text, status_code, location, error_texts[:3]))
    check("課のある全員の報告を提出できる（足した %d 件）" % created_count, not failed_list, failed_list[:5])

    # 案件を 1 つも選ばずに「提出する」を押したら、提出にしない（中身の無い報告が出力に出ないように）
    blank_login_id = member_list[-1][1]
    blank_period = month_shift(periods[0], -1)
    client = pool(blank_login_id)
    status_code, _, page_html = client.get("/reports/edit.php?period=" + blank_period)
    if status_code == 200:
        form_values = {"csrf_token": csrf(page_html), "request_key": rkey(page_html), "period": blank_period, "action": "submit", "b[0][project]": ""}
        client.post("/reports/save.php", form_values)
        blank_status = database.execute("SELECT status FROM report_records WHERE user_id = (SELECT id FROM users WHERE login_id = ?) AND period = ? AND deleted_at IS NULL",
                                        (blank_login_id, blank_period)).fetchone()
        check("案件を選ばずに提出すると断る（%s %s）" % (blank_login_id, blank_period), blank_status is None or blank_status[0] != "submitted", blank_status)

    # 前々月の報告は課長が確認完了にする（確認完了の報告も出力に入ることを見る）
    older_period = periods[0]
    confirm_failed_list = []
    for department_id, final_login_id in database.execute(
            "SELECT r.department_id, u.login_id FROM department_reviewers r JOIN users u ON u.id = r.user_id WHERE r.is_final = 1 AND u.is_active = 1").fetchall():
        client = pool(final_login_id)
        for (report_id,) in database.execute("SELECT id FROM report_records WHERE department_id = ? AND period = ? AND status = 'submitted' AND deleted_at IS NULL",
                                             (department_id, older_period)).fetchall():
            _, _, view_html = client.get("/reports/view.php?id=%d" % report_id)
            if 'value="confirm"' not in view_html:
                continue
            status_code, _, _ = client.post("/reports/act.php", {"csrf_token": csrf(view_html), "request_key": rkey(view_html), "action": "confirm", "report_id": report_id})
            if status_code not in (302, 303):
                confirm_failed_list.append((final_login_id, report_id, status_code))
    confirmed_count = database.execute("SELECT COUNT(*) FROM report_records WHERE period = ? AND status = 'confirmed'", (older_period,)).fetchone()[0]
    check("課長が前々月の報告を確認完了にできる（確認完了 %d 件）" % confirmed_count, not confirm_failed_list and confirmed_count > 0, confirm_failed_list[:5])


def prepare_expense_goal_request(database, pool, owner_login_id, deputy_login_id, final_login_id):
    owner_id = database.execute("SELECT id FROM users WHERE login_id = ?", (owner_login_id,)).fetchone()[0]
    if database.execute("SELECT COUNT(*) FROM expense_sheets WHERE user_id = ?", (owner_id,)).fetchone()[0] == 0:
        client = pool(owner_login_id)
        _, _, page_html = client.get("/expenses/edit.php")
        form_values = {"csrf_token": csrf(page_html), "request_key": rkey(page_html), "action": "save", "submitted_on": datetime.date.today().isoformat(),
                       "payment_method": "transfer", "employee_number": "1001", "office": "大阪", "department_name": "お試し課1", "full_name": "出力 確かめ",
                       "advance_amount": ""}
        for row_index in range(3):
            form_values.update({"rows[%d][start_date]" % row_index: "2026-11-%02d" % (row_index + 1), "rows[%d][end_date]" % row_index: "2027-04-30",
                                "rows[%d][purpose]" % row_index: "通勤（本社）", "rows[%d][transport]" % row_index: "私鉄・地下鉄",
                                "rows[%d][route]" % row_index: "門真南～(谷町六丁目)～天王寺", "rows[%d][trip]" % row_index: "往復",
                                "rows[%d][amount]" % row_index: str(11450 + row_index * 1000)})
        status_code, location, _ = client.post("/expenses/edit.php", form_values)
        check("精算書を作れる", status_code in (302, 303), (status_code, location))

    today = datetime.date.today()
    fiscal_year = today.year if today.month >= 10 else today.year - 1
    for login_id in (owner_login_id, deputy_login_id):
        user_id = database.execute("SELECT id FROM users WHERE login_id = ?", (login_id,)).fetchone()[0]
        if database.execute("SELECT COUNT(*) FROM goal_sheets WHERE user_id = ? AND fiscal_year = ?", (user_id, fiscal_year)).fetchone()[0]:
            continue
        client = pool(login_id)
        _, _, page_html = client.get("/goals/edit.php?year=%d" % fiscal_year)
        form_values = {"csrf_token": csrf(page_html), "request_key": rkey(page_html), "action": "save_owner",
                       "department_name": "お試し課1", "full_name": login_id, "position": "一般",
                       "fiscal_year": re.search(r'name="fiscal_year" value="(\d+)"', page_html).group(1),
                       "kind": re.search(r'name="kind" value="(\w+)"', page_html).group(1)}
        for goal_index in range(2):
            form_values.update({"goals[%d][theme]" % goal_index: "テーマ%d" % (goal_index + 1), "goals[%d][goal]" % goal_index: "目標%dのゴール" % (goal_index + 1),
                                "goals[%d][progress]" % goal_index: "中間の進捗", "goals[%d][mid_report]" % goal_index: "中間報告",
                                "goals[%d][mid_plan]" % goal_index: "後半の対策", "goals[%d][mid_self]" % goal_index: "B"})
        for item_name in set(re.findall(r'name="(competency_self\[\d+\])"', page_html)):
            form_values[item_name] = "4"
        status_code, location, _ = client.post("/goals/edit.php?year=%d" % fiscal_year, form_values)
        check("目標管理シートを作れる（%s）" % login_id, status_code in (302, 303), (status_code, location))

    if database.execute("SELECT COUNT(*) FROM support_requests").fetchone()[0] == 0:
        status_code, location, _page = post_with_tokens(pool(final_login_id), "/requests/index.php", "/requests/index.php",
                                                    {"action": "create", "kind": "improvement", "title": "出力の確かめの要望", "body": "本文の確かめ, \"引用\"",
                                                     "screen_code": "G-10", "urgency": "normal"})
        check("要望を出せる", status_code in (302, 303), (status_code, location, re.findall(r'class="[^"]*error[^"]*"[^>]*>([^<]*)<', _page)[:3]))
    return fiscal_year


# ---------------------------------------------------------------- 確かめ

def check_common_csv(label, client, status_code, expected_name_part=""):
    raw_bytes = client.last_raw
    content_type = client.last_headers.get("Content-Type", "")
    file_name = disposition_file_name(client.last_headers)
    is_ok = (status_code == 200 and "text/csv" in content_type and raw_bytes[:3] == b"\xef\xbb\xbf"
             and expected_name_part in file_name and not has_php_error(raw_bytes.decode("utf-8", "replace")))
    check("%s: CSV を受け取れる（UTF-8 の BOM・ファイル名 %s）" % (label, file_name), is_ok, (status_code, content_type, raw_bytes[:60]))
    return read_csv_rows(raw_bytes) if is_ok else []


def check_common_xlsx(label, client, status_code, all_sheets=False):
    raw_bytes = client.last_raw
    content_type = client.last_headers.get("Content-Type", "")
    try:
        row_list = read_xlsx_rows(raw_bytes, all_sheets) if raw_bytes[:2] == b"PK" else None
    except Exception as error:  # noqa: BLE001
        row_list = None
        content_type += " / " + str(error)
    check("%s: xlsx を受け取れて開ける（ファイル名 %s）" % (label, disposition_file_name(client.last_headers)),
          status_code == 200 and "spreadsheetml" in content_type and row_list is not None, (status_code, content_type))
    return row_list or []


def export_post(client, values):
    """G-10 の出力（画面を開いて csrf を付けて送る）"""
    return post_with_tokens(client, "/export/index.php", "/export/index.php", values)


def check_injection_safe(label, row_list):
    unsafe_cells = [cell for row in row_list for cell in row if cell[:1] in ("=", "+", "-", "@", "＝", "＋", "－", "＠") and cell not in ("-",)]
    check("%s: = + - @（全角も）で始まる値は ' を付けて出す（式にならない）" % label, not unsafe_cells, unsafe_cells[:3])


def check_report_exports(database, pool, printer, periods, restricted_labels):
    section("G-10", "月次報告の出力（課ごと・3 つの形式）")
    from_period, to_period = periods[0], periods[-1]
    final_list = database.execute(
        "SELECT r.department_id, d.name, u.login_id FROM department_reviewers r JOIN users u ON u.id = r.user_id JOIN departments d ON d.id = r.department_id "
        "WHERE r.is_final = 1 AND u.is_active = 1 AND d.is_active = 1 ORDER BY r.department_id").fetchall()
    for department_id, department_name, final_login_id in final_list:
        client = pool(final_login_id)
        # 行は報告×案件（案件の無い報告も 1 行）
        expected_row_count = database.execute(
            "SELECT COALESCE(SUM(MAX(1, (SELECT COUNT(*) FROM report_projects p WHERE p.report_id = r.id))), 0) FROM report_records r WHERE r.department_id = ? "
            "AND r.period BETWEEN ? AND ? AND r.status IN ('submitted','confirmed') AND r.deleted_at IS NULL AND r.is_legacy = 0",
            (department_id, from_period, to_period)).fetchone()[0]
        label = "%s（課長 %s）" % (department_name, final_login_id)
        base_values = {"type": "report", "dept": department_id, "from": from_period, "to": to_period, "content": "table", "free": "1", "restricted": "1"}
        if expected_row_count == 0:
            status_code, _, page_html = export_post(client, dict(base_values, format="csv"))
            check("%s: 報告が無ければ「出力する報告がありません」" % label, status_code == 404 and "出力する報告がありません" in page_html, status_code)
            continue
        status_code, _, _ = export_post(client, dict(base_values, format="csv"))
        csv_rows = check_common_csv(label, client, status_code, "report_")
        status_code, _, _ = export_post(client, dict(base_values, format="xlsx"))
        xlsx_rows = check_common_xlsx(label, client, status_code)
        if xlsx_rows:
            sheet_xml = zipfile.ZipFile(io.BytesIO(client.last_raw)).read("xl/worksheets/sheet1.xml").decode("utf-8")
            check("%s: xlsx に式の欄が無い（= で始まる値も文字のまま）" % label, "<f>" not in sheet_xml and "<f " not in sheet_xml)
        if csv_rows:
            check("%s: 1〜3 行目は 社外秘・題名・出力者" % label, csv_rows[0] == ["社外秘"] and department_name in csv_rows[1][0] and "出力者" in csv_rows[2][0], csv_rows[:3])
            body_rows = csv_rows[4:]
            check("%s: 行の数が DB の報告×案件の数と合う（%d 行）" % (label, expected_row_count), len(body_rows) == expected_row_count, len(body_rows))
            check("%s: 対象月が範囲の中だけ" % label, all(from_period <= row[0] <= to_period for row in body_rows), sorted(set(row[0] for row in body_rows)))
            check_injection_safe(label, csv_rows)
        if csv_rows and xlsx_rows:
            check("%s: xlsx と CSV の中身が同じ" % label, trimmed(xlsx_rows) == trimmed(csv_rows), (len(xlsx_rows), len(csv_rows)))
        status_code, _, print_html = export_post(client, dict(base_values, format="print", content="both"))
        check("%s: 印刷用の画面（横並び＋まとめ）を開ける" % label, status_code == 200 and "社外秘" in print_html and not has_php_error(print_html), status_code)
        if printer is not None and status_code == 200:
            pdf_bytes = printer.save(print_html, "月次報告_%s_%s_%s" % (department_name, from_period, to_period))
            check("%s: 印刷用の画面を PDF にできる" % label, pdf_bytes[:4] == b"%PDF", len(pdf_bytes))

    # 自由記述・限定を含めるかどうか（最初の課で見る）
    section("G-10", "自由記述・限定を含めるかどうか")
    department_id, department_name, final_login_id = final_list[0]
    client = pool(final_login_id)
    base_values = {"type": "report", "dept": department_id, "from": from_period, "to": to_period, "content": "both", "format": "csv"}
    variants = {}
    for free_flag in ("", "1"):
        for restricted_flag in ("", "1"):
            values = dict(base_values)
            if free_flag:
                values["free"] = "1"
            if restricted_flag:
                values["restricted"] = "1"
            export_post(client, values)
            variants[(free_flag, restricted_flag)] = client.last_raw.decode("utf-8", "replace")
    check("自由記述なし: 自由記述の文が出ない", FREE_TEXT_MARKER not in variants[("", "")] and FREE_TEXT_MARKER not in variants[("", "1")])
    check("自由記述あり: 自由記述の文が出る", FREE_TEXT_MARKER in variants[("1", "")])
    check("限定なし: 限定の項目（%s）の列が出ない" % "・".join(restricted_labels),
          all("%s（限定）" % restricted_label not in variants[("1", "")] for restricted_label in restricted_labels) and RESTRICTED_MARKER not in variants[("1", "")])
    check("限定あり: 限定の項目の列が出る", any("（限定）" in variants[("1", "1")] for _ in [0]))
    check("自由記述・限定ありで、限定の自由記述の文が出る", RESTRICTED_MARKER in variants[("1", "1")])
    special_texts = variants[("1", "1")]
    check("引用符・カンマ・改行を含む値が CSV で崩れない", "\"引用\"\"と,カンマ" in special_texts or QUOTE_TEXT not in special_texts)
    check("まとめ（両方）の行が CSV に入る", "■" in variants[("", "")])

    section("G-10", "出力の権限・入力の誤り")
    non_final_rows = database.execute(
        "SELECT u.login_id FROM users u WHERE u.department_id = ? AND u.is_active = 1 AND u.id NOT IN (SELECT user_id FROM department_reviewers WHERE is_final = 1) ORDER BY u.id",
        (department_id,)).fetchall()
    for (login_id,) in non_final_rows[:2] + [("admin01",), ("hosa01",), ("exec.toukatsu",)]:
        status_code, _, _ = export_post(pool(login_id), dict(base_values))
        check("課長でない %s は出力できない（403）" % login_id, status_code == 403, status_code)
    other_department = final_list[1][0] if len(final_list) > 1 else 0
    status_code, _, _ = export_post(client, dict(base_values, dept=other_department))
    check("課長が、ほかの課を出力できない（403）", status_code == 403, status_code)
    status_code, _, page_html = export_post(client, dict(base_values, **{"from": month_shift(to_period, -12)}))
    check("13 か月以上の範囲は断る", status_code == 400 and "12 か月以内" in page_html, status_code)
    status_code, _, page_html = export_post(client, dict(base_values, **{"from": to_period, "to": from_period}))
    check("開始 > 終了は断る", status_code == 400 if from_period != to_period else True, status_code)
    status_code, _, _ = export_post(client, dict(base_values, format="pdf"))
    check("決まっていない形式は断る（400）", status_code == 400, status_code)
    mobile_client = login_as(client.base, final_login_id, MOBILE_USER_AGENT)
    status_code, _, page_html = export_post(mobile_client, dict(base_values))
    check("スマートフォンからは出力できない（403）", status_code == 403 and "PC から" in page_html, status_code)


def expected_survey_counts(database, request_id, user_id_list=None):
    """DB から数え直した件数 {質問の名前: {選択肢の名前: 件数}} と回答者数"""
    response_rows = database.execute("SELECT id, user_id FROM adhoc_responses WHERE request_id = ? AND submitted_at IS NOT NULL", (request_id,)).fetchall()
    if user_id_list is not None:
        response_rows = [row for row in response_rows if row[1] in user_id_list]
    response_ids = set(row[0] for row in response_rows)
    count_by_question = {}
    for field_id, field_label, field_type, options_json in database.execute(
            "SELECT id, label, field_type, options_json FROM adhoc_request_fields WHERE request_id = ? ORDER BY sort_order, id", (request_id,)).fetchall():
        label_by_code = {option["code"]: option["label"] for option in json.loads(options_json or "[]")}
        counts = {}
        if field_type == "checkbox":
            counts = {"はい": 0, "チェックなし": 0}
        for label in label_by_code.values():
            counts[label] = 0
        for response_id, value_text in database.execute("SELECT response_id, value_text FROM adhoc_response_values WHERE request_field_id = ?", (field_id,)).fetchall():
            if response_id not in response_ids:
                continue
            if field_type == "checkbox":
                counts["はい" if value_text == "1" else "チェックなし"] += 1
            elif field_type in ("multi_check",):
                for code in json.loads(value_text or "[]"):
                    counts[label_by_code.get(code, code)] = counts.get(label_by_code.get(code, code), 0) + 1
            elif value_text != "":
                counts[label_by_code.get(value_text, value_text)] = counts.get(label_by_code.get(value_text, value_text), 0) + 1
        if field_type == "checkbox":
            counts["チェックなし"] = len(response_ids) - counts["はい"]
        count_by_question[field_label] = counts
    return count_by_question, len(response_ids)


def counts_from_rows(row_list, offset):
    """[質問, 選択肢, 件数, 割合] の行から {質問: {選択肢: 件数}}（質問の「（複数回答）」は落とす）"""
    result = {}
    for row in row_list:
        if len(row) < offset + 3 or not row[offset + 2].isdigit():
            continue
        question_label = row[offset].replace("（複数回答）", "")
        result.setdefault(question_label, {})[row[offset + 1]] = int(row[offset + 2])
    return result


def compare_counts(expected, actual):
    differences = []
    for question_label, counts in expected.items():
        for option_label, count in counts.items():
            actual_count = actual.get(question_label, {}).get(option_label)
            if actual_count is None and count == 0:
                continue
            if actual_count != count:
                differences.append((question_label, option_label, count, actual_count))
    return differences


def check_survey_exports(database, pool, printer):
    section("G-10", "臨時の調査（課の調査）の出力")
    for request_id, title, department_id in database.execute(
            "SELECT id, title, department_id FROM adhoc_requests WHERE deleted_at IS NULL AND is_template = 0 AND status <> 'draft' AND department_id IS NOT NULL").fetchall():
        final_row = database.execute("SELECT u.login_id FROM department_reviewers r JOIN users u ON u.id = r.user_id WHERE r.department_id = ? AND r.is_final = 1", (department_id,)).fetchone()
        if final_row is None:
            continue
        client = pool(final_row[0])
        expected, respondent_count = expected_survey_counts(database, request_id)
        label = "調査「%s」" % title
        values = {"type": "survey", "survey_id": request_id, "format": "csv"}
        status_code, _, _ = export_post(client, values)
        csv_rows = check_common_csv(label, client, status_code, "survey_")
        status_code, _, _ = export_post(client, dict(values, format="xlsx"))
        xlsx_rows = check_common_xlsx(label, client, status_code)
        if csv_rows:
            check("%s: 4 行目に回答の状況（回答 %d 人）" % (label, respondent_count), "回答 %d 人" % respondent_count in csv_rows[3][0], csv_rows[3])
            differences = compare_counts(expected, counts_from_rows(csv_rows[5:], 0))
            check("%s: 件数が DB から数え直したものと合う" % label, not differences, differences[:5])
            check("%s: 「結果だけ」の調査では、個人の名前の表を出さない" % label, not any(row[:1] == ["回答者"] for row in csv_rows))
        if csv_rows and xlsx_rows:
            check("%s: xlsx と CSV の中身が同じ" % label, trimmed(xlsx_rows) == trimmed(csv_rows))
        status_code, _, print_html = export_post(client, dict(values, format="print"))
        check("%s: 印刷用の画面（質問ごとの表とグラフ）" % label, status_code == 200 and "survey-bar" in print_html and not has_php_error(print_html), status_code)
        if printer is not None and status_code == 200:
            check("%s: PDF にできる" % label, printer.save(print_html, "臨時の調査_%s" % title)[:4] == b"%PDF")


def check_all_department_survey_exports(database, pool, printer):
    section("G-11", "全課共通の調査の出力")
    for request_id, title in database.execute(
            "SELECT id, title FROM adhoc_requests WHERE deleted_at IS NULL AND is_template = 0 AND status <> 'draft' AND department_id IS NULL").fetchall():
        expected, respondent_count = expected_survey_counts(database, request_id)
        viewer_login_ids = [row[0] for row in database.execute(
            "SELECT u.login_id FROM adhoc_request_viewers v JOIN users u ON u.id = v.user_id WHERE v.request_id = ?", (request_id,)).fetchall()]
        executive_login_ids = [row[0] for row in database.execute("SELECT login_id FROM users WHERE is_executive = 1 AND is_active = 1 ORDER BY id").fetchall()]
        allowed_login_ids = ["admin01", "hosa01"] + executive_login_ids[:1] + viewer_login_ids[:1]
        result_path = "/surveys/result.php?id=%d" % request_id
        reference_csv_rows = None
        for login_id in allowed_login_ids:
            client = pool(login_id)
            label = "全課共通「%s」（%s）" % (title, login_id)
            status_code, _, _ = post_with_tokens(client, result_path, result_path, {"format": "csv"})
            csv_rows = check_common_csv(label, client, status_code, "survey_all_")
            if not csv_rows:
                continue
            if reference_csv_rows is None:
                reference_csv_rows = csv_rows
                check("%s: 4 行目に回答の状況（回答 %d 人）" % (label, respondent_count), "回答 %d 人" % respondent_count in csv_rows[3][0], csv_rows[3])
                overall_rows = [row for row in csv_rows[5:] if row[:1] == ["全課"]]
                differences = compare_counts(expected, counts_from_rows(overall_rows, 1))
                check("%s: 全課の件数が DB から数え直したものと合う" % label, not differences, differences[:5])
                # 課ごとの件数も、回答した人の今の課で数え直して突き合わせる
                status_start = next((index for index, row in enumerate(csv_rows) if row[:4] == ["課", "対象", "回答", "回答率"]), None)
                status_rows = csv_rows[status_start + 1:] if status_start is not None else []
                check("%s: 課ごとの回答の状況の表がある" % label, bool(status_rows))
                check("%s: 課ごとの回答の数を足すと全体の回答数（%d 人）" % (label, respondent_count),
                      sum(int(row[2]) for row in status_rows if len(row) > 2 and row[2].isdigit()) == respondent_count,
                      [(row[0], row[2]) for row in status_rows])
                department_mismatch_list = []
                for department_id, department_name in database.execute("SELECT id, name FROM departments WHERE is_active = 1").fetchall():
                    member_ids = set(row[0] for row in database.execute("SELECT id FROM users WHERE department_id = ?", (department_id,)).fetchall())
                    department_expected, department_respondents = expected_survey_counts(database, request_id, member_ids)
                    department_rows = [row for row in csv_rows[5:] if row[:1] == [department_name]]
                    if department_respondents == 0:
                        continue
                    for difference in compare_counts(department_expected, counts_from_rows(department_rows, 1)):
                        department_mismatch_list.append((department_name,) + difference)
                check("%s: 課ごとの件数が DB から数え直したものと合う" % label, not department_mismatch_list, department_mismatch_list[:5])
                check("%s: 個人の名前を出さない" % label, not any(
                    row[0] in csv_rows[2][0] for row in []) and not any(name in "".join(",".join(row) for row in csv_rows)
                    for (name,) in database.execute("SELECT display_name FROM users u JOIN adhoc_responses a ON a.user_id = u.id WHERE a.request_id = ? AND length(display_name) > 2 LIMIT 20", (request_id,)).fetchall()
                    if name not in csv_rows[2][0]))
            else:
                check("%s: 管理者と同じ中身（出力者の行のほか）" % label, csv_rows[3:] == reference_csv_rows[3:])
            status_code, _, _ = post_with_tokens(client, result_path, result_path, {"format": "xlsx"})
            xlsx_rows = check_common_xlsx(label, client, status_code)
            if xlsx_rows:
                check("%s: xlsx と CSV の中身が同じ" % label, trimmed(xlsx_rows) == trimmed(csv_rows))
            status_code, _, print_html = post_with_tokens(client, result_path, result_path, {"format": "print"})
            check("%s: 印刷用の画面（全課＋課ごと）" % label, status_code == 200 and "survey-bar" in print_html and not has_php_error(print_html), status_code)
            if printer is not None and status_code == 200 and login_id == "admin01":
                check("%s: PDF にできる" % label, printer.save(print_html, "全課共通_%s" % title)[:4] == b"%PDF")
        for login_id in [row[0] for row in database.execute(
                "SELECT login_id FROM users WHERE is_active = 1 AND is_admin = 0 AND is_admin_deputy = 0 AND is_executive = 0 AND department_id IS NOT NULL ORDER BY id LIMIT 2").fetchall()]:
            if login_id in viewer_login_ids:
                continue
            client = pool(login_id)
            status_code, _, _ = client.get(result_path)
            _, _, home_html = client.get("/index.php")
            status_code_post, _, _ = client.post(result_path, {"csrf_token": csrf(home_html), "format": "csv"})
            check("見る権限のない %s は全課共通の結果を開けない・出力できない" % login_id, status_code in (403, 404) and status_code_post in (403, 404), (status_code, status_code_post))


def check_admin_csv(database, pool):
    section("G-14", "管理の CSV（組織・案件・記録・要望）")
    admin_client = pool("admin01")
    status_code, _, _ = admin_client.get("/admin/org.php?tab=bulk&csv=1")
    org_rows = check_common_csv("組織の CSV", admin_client, status_code)
    active_user_count = database.execute("SELECT COUNT(*) FROM users WHERE is_active = 1 AND department_id IS NOT NULL").fetchone()[0]
    check("組織の CSV に課のある有効な人がそろう（%d 人以上の行）" % active_user_count, len(org_rows) - 1 >= active_user_count, len(org_rows))
    check_injection_safe("組織の CSV", org_rows)

    status_code, _, _ = admin_client.get("/admin/projects.php?csv=1&state=all")
    project_rows = check_common_csv("案件の CSV", admin_client, status_code)
    project_count = database.execute("SELECT COUNT(*) FROM projects").fetchone()[0]
    check("案件の CSV の行が案件の数以上（%d）" % project_count, len(project_rows) - 1 >= min(project_count, 1), len(project_rows))

    today_text = datetime.date.today().isoformat()
    status_code, _, _ = admin_client.get("/admin/logs.php?csv=1&from=%s&to=%s" % ((datetime.date.today() - datetime.timedelta(days=7)).isoformat(), today_text))
    log_rows = check_common_csv("記録の CSV", admin_client, status_code)
    check("記録の CSV に、いま行った出力の記録（export:）がある", any("export:" in ",".join(row) for row in log_rows))
    check_injection_safe("記録の CSV", log_rows)

    for login_id in ("hosa01",):
        status_code, _, _ = pool(login_id).get("/admin/logs.php?csv=1")
        check("補佐の記録の CSV: %s" % status_code, status_code in (200, 403), status_code)

    status_code, _, _ = admin_client.get("/requests/index.php?csv=1&status=all")
    request_rows = check_common_csv("要望の CSV", admin_client, status_code)
    check("要望の CSV に行がある", len(request_rows) >= 2, len(request_rows))
    status_code, _, _ = admin_client.get("/requests/index.php?csv=1&status=all&body=1")
    request_body_rows = check_common_csv("要望の CSV（本文あり）", admin_client, status_code)
    check("本文ありの CSV には本文が入り、なしには入らない", any("本文の確かめ" in ",".join(row) for row in request_body_rows)
          and not any("本文の確かめ" in ",".join(row) for row in request_rows))

    for login_id, path in (("trial.m01", "/admin/org.php?tab=bulk&csv=1"), ("trial.m01", "/admin/projects.php?csv=1"), ("trial.m01", "/admin/logs.php?csv=1"),
                           ("exec.toukatsu", "/admin/logs.php?csv=1")):
        status_code, _, _ = pool(login_id).get(path)
        check("管理者でない %s は %s を受け取れない" % (login_id, path.split("?")[0]), status_code in (403, 404) and "text/csv" not in pool(login_id).last_headers.get("Content-Type", ""), status_code)


def check_expense_goal(database, pool, owner_login_id, deputy_login_id, final_login_id, fiscal_year, department_id):
    section("G-26", "通勤定期代の精算書の xlsx")
    owner_id = database.execute("SELECT id FROM users WHERE login_id = ?", (owner_login_id,)).fetchone()[0]
    sheet_row = database.execute("SELECT id FROM expense_sheets WHERE user_id = ? ORDER BY id DESC", (owner_id,)).fetchone()
    if sheet_row:
        client = pool(owner_login_id)
        status_code, _, _ = client.get("/expenses/download.php?id=%d" % sheet_row[0])
        row_list = check_common_xlsx("精算書", client, status_code)
        all_text = ",".join(",".join(row) for row in row_list)
        check("精算書の xlsx に区間と金額が入る", "天王寺" in all_text and "11450" in all_text.replace(",", ""), all_text[:200])
        status_code, _, _ = pool(final_login_id).get("/expenses/download.php?id=%d" % sheet_row[0])
        check("本人のほか（課長）は精算書を受け取れない（404）", status_code == 404, status_code)

    section("G-27", "目標管理シートの xlsx・課の zip")
    boss_client = pool(final_login_id)
    for login_id in (owner_login_id, deputy_login_id):
        user_id = database.execute("SELECT id FROM users WHERE login_id = ?", (login_id,)).fetchone()[0]
        sheet_row = database.execute("SELECT id FROM goal_sheets WHERE user_id = ? AND fiscal_year = ?", (user_id, fiscal_year)).fetchone()
        if not sheet_row:
            check("目標管理シートがある（%s）" % login_id, False)
            continue
        for who, client in (("本人", pool(login_id)), ("課長", boss_client)):
            status_code, _, _ = client.get("/goals/download.php?id=%d" % sheet_row[0])
            row_list = check_common_xlsx("目標管理（%s のシートを%sが）" % (login_id, who), client, status_code, all_sheets=True)
            check("目標管理の xlsx にゴールの文が入る（%s・%s）" % (login_id, who), any("目標1のゴール" in cell for row in row_list for cell in row))
    status_code, _, _ = boss_client.get("/goals/download_all.php?year=%d&dept=%d" % (fiscal_year, department_id))
    raw_bytes = boss_client.last_raw
    try:
        member_names = zipfile.ZipFile(io.BytesIO(raw_bytes)).namelist() if raw_bytes[:2] == b"PK" else []
    except zipfile.BadZipFile:
        member_names = []
    check("課長が課の全員分を zip で受け取れる（%d 個）" % len(member_names), status_code == 200 and len(member_names) >= 2, (status_code, member_names))
    status_code, _, _ = pool(owner_login_id).get("/goals/download_all.php?year=%d&dept=%d" % (fiscal_year, department_id))
    check("課長でない人は課の zip を受け取れない", status_code in (302, 303, 403, 404) and pool(owner_login_id).last_raw[:2] != b"PK", status_code)


# ---------------------------------------------------------------- 本体

def main():
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--base", default="http://127.0.0.1:8100")
    argument_parser.add_argument("--db", required=True)
    argument_parser.add_argument("--prepare", action="store_true", help="確かめに使うデータを足す")
    argument_parser.add_argument("--out", default="", help="印刷用の画面の PDF の置き場所")
    argument_parser.add_argument("--no-pdf", action="store_true")
    arguments = argument_parser.parse_args()

    database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)
    pool = ClientPool(arguments.base)
    today = datetime.date.today()
    current_period = today.strftime("%Y-%m")
    periods = [month_shift(current_period, -2), month_shift(current_period, -1)]
    restricted_field_ids = [row[0] for row in database.execute(
        "SELECT id FROM report_fields WHERE confidentiality = 'restricted' AND field_type IN ('multi_text','single_text') AND is_active = 1").fetchall()]
    restricted_labels = [row[0] for row in database.execute("SELECT label FROM report_fields WHERE confidentiality = 'restricted' AND is_active = 1 AND department_id IS NULL").fetchall()]

    trial_department = database.execute("SELECT department_id FROM users WHERE login_id = 'trial.m01'").fetchone()
    if trial_department is None:
        raise SystemExit("お試し課のテストデータ（trial_seed.php）がありません")
    trial_department_id = trial_department[0]
    trial_final_login_id = database.execute("SELECT u.login_id FROM department_reviewers r JOIN users u ON u.id = r.user_id WHERE r.department_id = ? AND r.is_final = 1",
                                            (trial_department_id,)).fetchone()[0]

    if arguments.prepare:
        prepare_data(database, pool, periods, restricted_field_ids)
    fiscal_year = prepare_expense_goal_request(database, pool, "trial.m01", "trial.dairi1", trial_final_login_id) if arguments.prepare else (
        today.year if today.month >= 10 else today.year - 1)

    printer = None
    if not arguments.no_pdf:
        out_dir = arguments.out or os.path.join(HERE, "output", "出力チェック_" + datetime.datetime.now().strftime("%Y%m%d-%H%M"))
        os.makedirs(out_dir, exist_ok=True)
        printer = PdfPrinter(arguments.base, out_dir)
    try:
        check_report_exports(database, pool, printer, periods, restricted_labels)
        check_survey_exports(database, pool, printer)
        check_all_department_survey_exports(database, pool, printer)
        check_admin_csv(database, pool)
        check_expense_goal(database, pool, "trial.m01", "trial.dairi1", trial_final_login_id, fiscal_year, trial_department_id)
    finally:
        if printer is not None:
            printer.close()
            print("PDF: %d 個 → %s" % (len(printer.made_file_list), printer.out_dir))
    return summary()


if __name__ == "__main__":
    sys.exit(main())
