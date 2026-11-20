#!/usr/bin/env python3
"""G-27 目標管理の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

- 見られるのは本人・その課の課長・課長代理（課長代理は一般・主任の分だけ）。ほかの人（同じ課の人・主任・ほかの課の課長・管理者・補佐）は 404
- 本人は目標・報告・本人評価、課長は配点・上長評価・総合評価だけを入れられる。課長代理はコメント・面談の記録だけ
- コメント・返信・面談の記録（直す・消す操作はない）、更新の履歴、暗号化
- 評点の計算、様式の xlsx（値の入るセル・式の値・開くシート・様式のほかの部分はそのまま）

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_goals.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
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
SECRET_GOAL_TEXT = "受注管理の改修を、品質を保って期限内に終える（暗号の確かめ）"
GENERAL_ITEM_COUNT = 16


def fiscal_year_today():
    today = datetime.date.today()
    return today.year if today.month >= 10 else today.year - 1


def owner_form(page_html, **extra_values):
    form_values = {"csrf_token": csrf(page_html), "request_key": rkey(page_html), "action": "save_owner",
                   "department_name": "A課", "full_name": "A一般1", "position": "一般"}
    sheet_id_match = re.search(r'name="sheet_id" value="(\d+)"', page_html)
    if sheet_id_match:
        form_values["sheet_id"] = sheet_id_match.group(1)
    else:
        form_values["fiscal_year"] = re.search(r'name="fiscal_year" value="(\d+)"', page_html).group(1)
        form_values["kind"] = re.search(r'name="kind" value="(\w+)"', page_html).group(1)
    for goal_index in range(2):
        form_values.update({
            "goals[%d][theme]" % goal_index: "テーマ%d" % (goal_index + 1),
            "goals[%d][goal]" % goal_index: SECRET_GOAL_TEXT if goal_index == 0 else "目標2のゴール",
            "goals[%d][progress]" % goal_index: "中間の進捗",
            "goals[%d][mid_report]" % goal_index: "中間報告の本文\n2 行目",
            "goals[%d][mid_plan]" % goal_index: "後半の対策",
            "goals[%d][mid_self]" % goal_index: "B",
            "goals[%d][final_report]" % goal_index: "", "goals[%d][final_review]" % goal_index: "", "goals[%d][final_self]" % goal_index: "",
        })
    for item_index in range(GENERAL_ITEM_COUNT):
        form_values["competency_self[%d]" % item_index] = "4"
    form_values.update(extra_values)
    return form_values


def cell_value(sheet_xml, cell_reference):
    cell_match = re.search(r'<c r="%s"[^>]*?(?:/>|>(.*?)</c>)' % cell_reference, sheet_xml)
    if not cell_match or cell_match.group(1) is None:
        return ""
    inline_match = re.search(r"<is><t[^>]*>(.*?)</t></is>", cell_match.group(1))
    if inline_match:
        return inline_match.group(1)
    value_match = re.search(r"<v>(.*?)</v>", cell_match.group(1))
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
    fiscal_year = fiscal_year_today()
    client_by_login_id = {}

    def client_of(login_id):
        if login_id not in client_by_login_id:
            client_by_login_id[login_id] = login(arguments.base, login_id, user_info_by_login_id[login_id])
        return client_by_login_id[login_id]

    section("G-27", "目標管理: 本人がシートを作る")
    owner = client_of("ippan_a1")
    _, _, page_html = owner.get("/index.php")
    check("メニューに「目標管理シート」が出る", "/goals/index.php" in page_html and "目標管理シート" in page_html)
    status_code, _, page_html = owner.get("/goals/index.php")
    check("一覧に、今の年度のシートを作るボタン", status_code == 200 and "/goals/edit.php?year=%d" % fiscal_year in page_html, status_code)
    status_code, _, page_html = owner.get("/goals/edit.php?year=%d" % fiscal_year)
    check("一般の人には、一般の様式を選んでいる（コンピテンシー 16 項目）", 'name="kind" value="general"' in page_html
          and len(re.findall(r'name="competency_self\[\d+\]"', page_html)) == GENERAL_ITEM_COUNT, status_code)
    check("本人の画面では、配点・上長評価は入れられない", 'name="goals[0][difficulty]"' not in page_html and 'name="overall_boss"' not in page_html)
    check("一般の人には様式を選ぶ欄を出さない（様式は役職で決まる）", 'id="goal-kind"' not in page_html and "様式は役職で決まります" in page_html)
    _, _, other_kind_html = owner.get("/goals/edit.php?year=%d&kind=manager" % fiscal_year)
    check("一般の人が URL で別の様式を指定しても、一般の様式のまま", 'name="kind" value="general"' in other_kind_html)
    forged_status, _, _ = owner.post("/goals/edit.php?year=%d" % fiscal_year, owner_form(other_kind_html, kind="manager"))
    check("一般の人が別の様式を送っても受け付けない（403）", forged_status == 403, forged_status)
    _, _, page_html = owner.get("/goals/edit.php?year=%d" % fiscal_year)
    status_code, _, error_html = owner.post("/goals/edit.php?year=%d" % fiscal_year, owner_form(page_html, **{"goals[0][goal]": "あ" * 300}))
    check("様式の欄に収まらない長さは保存しない（行の数を出す）", status_code == 200 and "長すぎます" in error_html and "7 行" in error_html, status_code)
    status_code, location, _ = owner.post("/goals/edit.php?year=%d" % fiscal_year, owner_form(
        error_html, **{"goals[0][difficulty]": "ストレッチ", "goals[0][mid_boss]": "A", "overall_boss": "A"}))
    sheet_id_match = re.search(r"id=(\d+)", location)
    check("本人が保存できる", status_code in (302, 303) and sheet_id_match is not None, (status_code, location))
    sheet_id = int(sheet_id_match.group(1)) if sheet_id_match else 0
    status_code, _, page_html = owner.get("/goals/edit.php?id=%d" % sheet_id)
    check("本人が送った配点・上長評価・総合評価は入らない", 'class="goal-choice-value">－' in page_html and "ストレッチ" not in re.sub(r"<option[^>]*>.*?</option>", "", page_html.split("goal-scores")[0].split("目標1")[1] if "目標1" in page_html else ""), status_code)
    status_code, location, _ = owner.get("/goals/edit.php?year=%d" % fiscal_year)
    check("同じ年度のシートは 1 つ（作る画面は、あるシートを開く）", status_code in (302, 303) and "id=%d" % sheet_id in location, (status_code, location))

    section("G-27", "目標管理: 見られる人")
    for login_id, expected_status in (("ippan_a2", 404), ("leader_a", 404), ("kacho_b", 404), ("admin01", 404), ("hosa01", 404), ("dairi_a", 200), ("kacho_a", 200)):
        view_status, _, _ = client_of(login_id).get("/goals/edit.php?id=%d" % sheet_id)
        download_status, _, _ = client_of(login_id).get("/goals/download.php?id=%d" % sheet_id)
        check("%s がほかの人のシートを開く・受け取る: %d" % (login_id, expected_status), (view_status, download_status) == (expected_status, expected_status), (view_status, download_status))
    _, _, list_html = client_of("kacho_a").get("/goals/index.php")
    check("課長の一覧に、課の人のシートと未作成の人が出る", "/goals/edit.php?id=%d" % sheet_id in list_html and "未作成" in list_html and "A代理" in list_html)
    _, _, list_html = client_of("dairi_a").get("/goals/index.php")
    check("課長代理の一覧に、一般・主任の人だけが出る（課長は出ない）", "/goals/edit.php?id=%d" % sheet_id in list_html and "Aリーダー" in list_html and "A課長" not in list_html)
    _, _, list_html = client_of("ippan_a2").get("/goals/index.php")
    check("一般の人の一覧には、ほかの人のシートが出ない", "課の人のシート" not in list_html)

    section("G-27", "目標管理: 課長の評価・課長代理")
    boss = client_of("kacho_a")
    _, _, page_html = boss.get("/goals/edit.php?id=%d" % sheet_id)
    check("課長の画面では、本人の欄は入れられず、配点・上長評価は入れられる", 'name="goals[0][goal]"' not in page_html and 'name="goals[0][difficulty]"' in page_html and 'name="overall_boss"' in page_html)
    boss_values = {"csrf_token": csrf(page_html), "request_key": rkey(page_html), "action": "save_boss", "sheet_id": str(sheet_id), "overall_boss": "B",
                   "goals[0][difficulty]": "ストレッチ", "goals[0][mid_boss]": "A", "goals[1][difficulty]": "適正", "goals[1][mid_boss]": "C1",
                   "goals[0][goal]": "課長が書き換えようとした文"}
    for item_index in range(GENERAL_ITEM_COUNT):
        boss_values["competency_boss[%d]" % item_index] = "3"
    status_code, _, _ = boss.post("/goals/edit.php?id=%d" % sheet_id, boss_values)
    _, _, page_html = boss.get("/goals/edit.php?id=%d" % sheet_id)
    check("課長が評価を保存できる（本人の文は変わらない）", status_code in (302, 303) and SECRET_GOAL_TEXT in page_html and "課長が書き換えようとした文" not in page_html, status_code)
    # 中間: 本人 B×120=120・B×100=100 → 平均 110。上長 A×120=144・C1×100=70 → 平均 107。コンピテンシー 本人 4×16/80=80・上長 3×16/80=60
    check("評点の計算（中間の本人 110・上長 107、コンピテンシー 80・60）",
          all(text in page_html for text in ('<td class="number">110</td>', '<td class="number">107</td>', '<td class="number">80</td>', '<td class="number">60</td>')))
    deputy = client_of("dairi_a")
    _, _, page_html = deputy.get("/goals/edit.php?id=%d" % sheet_id)
    status_code, _, _ = deputy.post("/goals/edit.php?id=%d" % sheet_id, dict(boss_values, csrf_token=csrf(page_html), request_key=rkey(page_html)))
    check("課長代理は評価を保存できない（403）", status_code == 403, status_code)
    status_code, _, _ = owner.post("/goals/edit.php?id=%d" % sheet_id, dict(boss_values, csrf_token=csrf(page_html), request_key=rkey(page_html)))
    check("本人は課長の保存を使えない（403）", status_code in (400, 403), status_code)
    _, _, page_html = deputy.get("/goals/edit.php?id=%d" % sheet_id)
    status_code, _, _ = deputy.post("/goals/edit.php?id=%d" % sheet_id, {"csrf_token": csrf(page_html), "request_key": rkey(page_html), "action": "comment",
                                                                        "sheet_id": str(sheet_id), "body": "目標2の指標を数字にしてください"})
    check("課長代理がコメントできる", status_code in (302, 303), status_code)
    _, _, page_html = deputy.get("/goals/edit.php?id=%d" % sheet_id)
    status_code, _, _ = deputy.post("/goals/edit.php?id=%d" % sheet_id, {"csrf_token": csrf(page_html), "request_key": rkey(page_html), "action": "meeting",
                                                                        "sheet_id": str(sheet_id), "held_on": datetime.date.today().isoformat(), "note": "目標2の指標を件数に直すと決めた"})
    check("課長代理が面談の記録を残せる", status_code in (302, 303), status_code)
    _, _, page_html = deputy.get("/goals/edit.php?id=%d" % sheet_id)
    status_code, _, _ = deputy.post("/goals/edit.php?id=%d" % sheet_id, {"csrf_token": csrf(page_html), "request_key": rkey(page_html), "action": "meeting",
                                                                        "sheet_id": str(sheet_id), "held_on": "2999-01-01", "note": "先の日"})
    check("面談の日に今日より先の日は入れられない", status_code == 200, status_code)
    _, _, page_html = owner.get("/goals/edit.php?id=%d" % sheet_id)
    comment_id = re.search(r'name="parent_id" value="(\d+)"', page_html).group(1)
    check("本人にコメントと面談の記録が見える", "目標2の指標を数字にしてください" in page_html and "目標2の指標を件数に直すと決めた" in page_html)
    check("本人の画面に面談の記録を残す欄は出ない", 'value="meeting"' not in page_html)
    status_code, _, _ = owner.post("/goals/edit.php?id=%d" % sheet_id, {"csrf_token": csrf(page_html), "request_key": rkey(page_html), "action": "reply",
                                                                       "sheet_id": str(sheet_id), "parent_id": comment_id, "body": "件数に直しました"})
    check("本人が返信できる", status_code in (302, 303), status_code)
    _, _, page_html = owner.get("/goals/edit.php?id=%d" % sheet_id)
    status_code, _, _ = owner.post("/goals/edit.php?id=%d" % sheet_id, {"csrf_token": csrf(page_html), "request_key": rkey(page_html), "action": "comment_delete",
                                                                       "sheet_id": str(sheet_id), "comment_id": comment_id})
    check("コメントを消す操作はない（403）", status_code == 403, status_code)
    _, _, page_html = owner.get("/goals/edit.php?id=%d" % sheet_id)
    check("更新の履歴に、作成・課長の評価・コメント・返信・面談が残る", all(text in page_html for text in ("作成", "課長が評価を保存", "コメント", "返信", "面談の結果")))

    section("G-27", "目標管理: 課長代理・課長のシート")
    _, _, page_html = client_of("dairi_a").get("/goals/edit.php?year=%d" % fiscal_year)
    check("課長代理には、リーダー・課長代理の様式を選んでいる", 'name="kind" value="leader"' in page_html)
    status_code, location, _ = client_of("dairi_a").post("/goals/edit.php?year=%d" % fiscal_year, dict(owner_form(page_html), full_name="A代理", position="課長代理"))
    deputy_sheet_id = int(re.search(r"id=(\d+)", location).group(1)) if "id=" in location else 0
    view_status, _, _ = client_of("kacho_a").get("/goals/edit.php?id=%d" % deputy_sheet_id)
    other_status, _, _ = client_of("leader_a").get("/goals/edit.php?id=%d" % deputy_sheet_id)
    check("課長代理のシートは課長が見られる（主任は見られない）", deputy_sheet_id > 0 and view_status == 200 and other_status == 404, (deputy_sheet_id, view_status, other_status))
    _, _, page_html = client_of("kacho_a").get("/goals/edit.php?year=%d&kind=manager" % fiscal_year)
    check("課長は、課長以上・営業・間接部門から様式を選べる（一般・リーダーは選べない）", 'id="goal-kind"' in page_html
          and all('value="%s"' % kind_code in page_html for kind_code in ("manager", "sales", "indirect")) and '<option value="general"' not in page_html)
    status_code, location, _ = client_of("kacho_a").post("/goals/edit.php?year=%d&kind=manager" % fiscal_year, dict(owner_form(page_html), full_name="A課長", position="課長"))
    manager_sheet_id = int(re.search(r"id=(\d+)", location).group(1)) if "id=" in location else 0
    deputy_view_status, _, _ = client_of("dairi_a").get("/goals/edit.php?id=%d" % manager_sheet_id)
    check("課長のシートは本人だけ（課長代理は見られない）", manager_sheet_id > 0 and deputy_view_status == 404, (manager_sheet_id, deputy_view_status))

    section("G-27", "目標管理: 暗号化・様式の xlsx")
    stored_text = database.execute("SELECT data_enc FROM goal_sheets WHERE id = ?", (sheet_id,)).fetchone()[0]
    comment_texts = " ".join(row[0] for row in database.execute("SELECT body_enc FROM goal_sheet_comments WHERE sheet_id = ?", (sheet_id,)))
    meeting_texts = " ".join(row[0] for row in database.execute("SELECT note_enc FROM goal_sheet_meetings WHERE sheet_id = ?", (sheet_id,)))
    check("シート・コメント・面談の記録は暗号化されている", SECRET_GOAL_TEXT not in stored_text and "指標" not in comment_texts and "件数" not in meeting_texts)
    status_code, _, _ = boss.get("/goals/download.php?id=%d" % sheet_id)
    workbook_bytes = boss.last_raw
    check("課長が xlsx を受け取れる（ファイル名に年度と氏名）", status_code == 200 and workbook_bytes[:2] == b"PK"
          and "%d%%E5%%B9%%B4%%E5%%BA%%A6" % fiscal_year in boss.last_headers.get("Content-Disposition", ""), status_code)
    workbook_zip = zipfile.ZipFile(io.BytesIO(workbook_bytes))
    template_zip = zipfile.ZipFile(os.path.join(repository_root, "app", "templates", "goal_general.xlsx"))
    check("xlsx の zip が壊れていない・中のファイルの一覧は様式と同じ", workbook_zip.testzip() is None and sorted(workbook_zip.namelist()) == sorted(template_zip.namelist()))
    check("ほかのシート・書式・共有文字列は様式のまま", all(workbook_zip.read(part_name) == template_zip.read(part_name)
                                                for part_name in ("xl/worksheets/sheet2.xml", "xl/worksheets/sheet5.xml", "xl/styles.xml", "xl/sharedStrings.xml")))
    sheet_xml = workbook_zip.read("xl/worksheets/sheet4.xml").decode("utf-8")
    check("上の欄（所属・氏名・年度・職位・総合評価）", (cell_value(sheet_xml, "B5"), cell_value(sheet_xml, "F5"), cell_value(sheet_xml, "B6"), cell_value(sheet_xml, "G6"), cell_value(sheet_xml, "K5"))
          == ("A課", "A一般1", str(fiscal_year), "一般", "B"))
    check("目標 1（テーマ・ゴールの 1 行目・配点・配点の点・中間の本人と上長）", (cell_value(sheet_xml, "B8"), cell_value(sheet_xml, "A10"), cell_value(sheet_xml, "L10"),
                                                             cell_value(sheet_xml, "L13"), cell_value(sheet_xml, "L18"), cell_value(sheet_xml, "L23"))
          == ("テーマ1", SECRET_GOAL_TEXT, "ストレッチ", "120", "B", "A"))
    check("中間報告は行に分けて入る（2 行目は次の行）", cell_value(sheet_xml, "A18") == "中間報告の本文" and cell_value(sheet_xml, "A19") == "2 行目")
    check("目標 2 は 30 行下（配点・中間の上長）", cell_value(sheet_xml, "L40") == "適正" and cell_value(sheet_xml, "L53") == "C1")
    check("評点の式の値（中間の本人 110・上長 107）", "<f>" in re.search(r'<c r="E71".*?</c>', sheet_xml).group(0) and cell_value(sheet_xml, "E71") == "110" and cell_value(sheet_xml, "F71") == "107")
    check("コンピテンシー（本人 4・上長 3・100 点換算 80・60）", (cell_value(sheet_xml, "K77"), cell_value(sheet_xml, "L92"), cell_value(sheet_xml, "K93"), cell_value(sheet_xml, "L93")) == ("4", "3", "80", "60"))
    workbook_xml = workbook_zip.read("xl/workbook.xml").decode("utf-8")
    check("開いたときに、一般のシートが出て、式を計算し直す", 'activeTab="3"' in workbook_xml and 'fullCalcOnLoad="1"' in workbook_xml and 'tabSelected="1"' in sheet_xml
          and 'tabSelected="1"' not in workbook_zip.read("xl/worksheets/sheet1.xml").decode("utf-8"))
    status_code, _, _ = client_of("kacho_a").get("/goals/download.php?id=%d" % manager_sheet_id)
    manager_zip = zipfile.ZipFile(io.BytesIO(client_of("kacho_a").last_raw))
    manager_sheet_xml = manager_zip.read("xl/worksheets/sheet4.xml").decode("utf-8")
    check("課長以上の様式は 1 行下にずれた位置に入る（テーマ B9・ゴール A11）", status_code == 200 and cell_value(manager_sheet_xml, "B9") == "テーマ1" and cell_value(manager_sheet_xml, "A11") == SECRET_GOAL_TEXT)
    check("業績目標を入れていなければ、様式の見本の値（100・125）は残さない", (cell_value(manager_sheet_xml, "B8"), cell_value(manager_sheet_xml, "C8"), cell_value(manager_sheet_xml, "E8")) == ("", "", ""))
    _, _, page_html = client_of("kacho_a").get("/goals/edit.php?id=%d" % manager_sheet_id)
    status_code, _, _ = client_of("kacho_a").post("/goals/edit.php?id=%d" % manager_sheet_id, dict(owner_form(page_html), full_name="A課長", position="課長", business_plan="1,000", business_actual="１１００"))
    client_of("kacho_a").get("/goals/download.php?id=%d" % manager_sheet_id)
    manager_sheet_xml = zipfile.ZipFile(io.BytesIO(client_of("kacho_a").last_raw)).read("xl/worksheets/sheet4.xml").decode("utf-8")
    check("業績目標（計画 1,000・実績 1,100 → 達成率 1.1・評点 4）", (cell_value(manager_sheet_xml, "B8"), cell_value(manager_sheet_xml, "C8"), cell_value(manager_sheet_xml, "D8"), cell_value(manager_sheet_xml, "E8")) == ("1000", "1100", "1.1", "4"))
    section("G-27", "目標管理: 課長が一覧から受け取る（1 人ずつ・まとめて）")
    department_a_id = json.load(open(os.path.join(test_dir, "output_dev_users.json"), encoding="utf-8"))["dept_a"]
    _, _, boss_list_html = client_of("kacho_a").get("/goals/index.php?year=%d" % fiscal_year)
    check("課長の一覧に、1 人ずつの Excel（シートのある 2 人）と、まとめて受け取るボタンが出る",
          boss_list_html.count("goal-download-one") == 2 and "/goals/download.php?id=%d" % sheet_id in boss_list_html
          and "/goals/download_all.php?year=%d&amp;dept=%d" % (fiscal_year, department_a_id) in boss_list_html)
    _, _, deputy_list_html = client_of("dairi_a").get("/goals/index.php?year=%d" % fiscal_year)
    check("課長代理の一覧には、Excel もまとめて受け取るボタンも出ない", "goal-download-one" not in deputy_list_html and "goal-download-all" not in deputy_list_html)
    status_code, _, _ = client_of("kacho_a").get("/goals/download_all.php?year=%d&dept=%d" % (fiscal_year, department_a_id))
    bulk_zip = zipfile.ZipFile(io.BytesIO(client_of("kacho_a").last_raw)) if status_code == 200 else None
    entry_names = sorted(bulk_zip.namelist()) if bulk_zip else []
    check("課長がまとめて受け取ると、課の人のシートが 1 人 1 つの xlsx で zip に入る（課長自身のシートは入らない）",
          status_code == 200 and len(entry_names) == 2 and any("A一般1" in name for name in entry_names) and any("A代理" in name for name in entry_names)
          and not any("A課長" in name for name in entry_names), (status_code, entry_names))
    check("zip の中の xlsx は、それぞれ開ける（中の zip が壊れていない）",
          bool(entry_names) and all(zipfile.ZipFile(io.BytesIO(bulk_zip.read(name))).testzip() is None for name in entry_names))
    check("zip のファイル名に年度と課の名前", "%d%%E5%%B9%%B4%%E5%%BA%%A6_A%%E8%%AA%%B2.zip" % fiscal_year in client_of("kacho_a").last_headers.get("Content-Disposition", ""))
    for login_id in ("dairi_a", "kacho_b", "admin01", "ippan_a1"):
        bulk_status, _, _ = client_of(login_id).get("/goals/download_all.php?year=%d&dept=%d" % (fiscal_year, department_a_id))
        check("%s は、A課のシートをまとめて受け取れない（403）" % login_id, bulk_status == 403, bulk_status)
    _, _, history_html = owner.get("/goals/edit.php?id=%d" % sheet_id)
    check("まとめて受け取ったことも、シートの更新の履歴に残る", history_html.count("Excel を受け取った") >= 2)
    section("G-27", "目標管理: 面談の結果は、課長・課長代理の一人ずつの欄に書く")
    meeting_block_pattern = r'<div class="goal-meeting-block" data-reviewer-id="(\d+)">(.*?)(?=<div class="goal-meeting-block"|</section>)'

    def meeting_blocks(page_html):
        return {int(reviewer_id): block_html for reviewer_id, block_html in re.findall(meeting_block_pattern, page_html, re.S)}

    boss_user_id = user_info_by_login_id["kacho_a"]["id"]
    deputy_user_id = user_info_by_login_id["dairi_a"]["id"]
    second_deputy_user_id = user_info_by_login_id["leader_a"]["id"]
    _, _, page_html = owner.get("/goals/edit.php?id=%d" % sheet_id)
    block_by_reviewer_id = meeting_blocks(page_html)
    check("本人の画面に、課長と課長代理の欄が分かれて出る（課長が先）", list(block_by_reviewer_id) == [boss_user_id, deputy_user_id], list(block_by_reviewer_id))
    check("課長代理の面談の結果は、課長代理の欄に出る（課長の欄には出ない）",
          "目標2の指標を件数に直すと決めた" in block_by_reviewer_id.get(deputy_user_id, "") and "目標2の指標を件数に直すと決めた" not in block_by_reviewer_id.get(boss_user_id, ""))
    _, _, page_html = boss.get("/goals/edit.php?id=%d" % sheet_id)
    block_by_reviewer_id = meeting_blocks(page_html)
    check("課長の画面では、書く欄は課長の欄にだけ出る", 'value="meeting"' in block_by_reviewer_id.get(boss_user_id, "") and 'value="meeting"' not in block_by_reviewer_id.get(deputy_user_id, ""))
    status_code, _, _ = boss.post("/goals/edit.php?id=%d" % sheet_id, {"csrf_token": csrf(page_html), "request_key": rkey(page_html), "action": "meeting",
                                                                      "sheet_id": str(sheet_id), "held_on": datetime.date.today().isoformat(), "note": "課長の面談: 下期は設計の指摘の件数で見る"})
    _, _, page_html = owner.get("/goals/edit.php?id=%d" % sheet_id)
    block_by_reviewer_id = meeting_blocks(page_html)
    check("課長の面談の結果は、課長の欄に出る", status_code in (302, 303) and "課長の面談: 下期は設計の指摘の件数で見る" in block_by_reviewer_id.get(boss_user_id, "")
          and "課長の面談" not in block_by_reviewer_id.get(deputy_user_id, ""), status_code)
    # 課長代理が 2 人いるとき（主任を課長代理にして確かめ、あとで戻す）
    database.execute("UPDATE users SET role_title = '課長代理' WHERE id = ?", (second_deputy_user_id,))
    try:
        _, _, page_html = owner.get("/goals/edit.php?id=%d" % sheet_id)
        block_by_reviewer_id = meeting_blocks(page_html)
        check("課長代理が 2 人いると、課長と課長代理 2 人の 3 つの欄が出る", list(block_by_reviewer_id) == [boss_user_id, deputy_user_id, second_deputy_user_id], list(block_by_reviewer_id))
        second_deputy = login(arguments.base, "leader_a", user_info_by_login_id["leader_a"])
        _, _, page_html = second_deputy.get("/goals/edit.php?id=%d" % sheet_id)
        status_code, _, _ = second_deputy.post("/goals/edit.php?id=%d" % sheet_id, {"csrf_token": csrf(page_html), "request_key": rkey(page_html), "action": "meeting",
                                                                                     "sheet_id": str(sheet_id), "held_on": datetime.date.today().isoformat(), "note": "2 人目の課長代理の面談"})
        _, _, page_html = owner.get("/goals/edit.php?id=%d" % sheet_id)
        block_by_reviewer_id = meeting_blocks(page_html)
        check("2 人目の課長代理の面談の結果は、その人の欄に出る（ほかの 2 人の欄には出ない）", status_code in (302, 303)
              and "2 人目の課長代理の面談" in block_by_reviewer_id.get(second_deputy_user_id, "")
              and all("2 人目の課長代理の面談" not in block_by_reviewer_id.get(reviewer_id, "") for reviewer_id in (boss_user_id, deputy_user_id)), status_code)
    finally:
        database.execute("UPDATE users SET role_title = '主任' WHERE id = ?", (second_deputy_user_id,))
    _, _, page_html = owner.get("/goals/edit.php?id=%d" % sheet_id)
    check("主任に戻すと、その人の欄は消え、書いた面談の結果は「以前の担当」の欄に残る",
          second_deputy_user_id not in meeting_blocks(page_html) and "以前の担当の面談の結果" in page_html and "2 人目の課長代理の面談" in page_html)
    section("G-27", "目標管理: コンピテンシー評価基準（別のタブで開く）")
    _, _, list_html = owner.get("/goals/index.php")
    check("一覧に、評価基準を別のタブで開くリンク", 'href="/goals/criteria.php" target="_blank" rel="noopener"' in list_html)
    _, _, page_html = owner.get("/goals/edit.php?id=%d" % sheet_id)
    check("シートのコンピテンシー評価の欄にも、評価基準を別のタブで開くリンク", 'href="/goals/criteria.php" target="_blank"' in page_html)
    sheet_titles = ("システム", "ヘルプデスク", "管理職登用基準", "職能レベル定義")
    status_code, _, criteria_html = owner.get("/goals/criteria.php")
    check("評価基準の画面に、4 つのシートのタブと、システムの基準（受益者志向・4 点:エキスパート）が出る",
          status_code == 200 and all(title in criteria_html for title in sheet_titles) and "受益者志向" in criteria_html and "エキスパート" in criteria_html, status_code)
    status_code, _, criteria_html = owner.get("/goals/criteria.php?sheet=3")
    check("タブで管理職登用基準に切り替えられる", status_code == 200 and "管理職登用維持基準" in criteria_html, status_code)
    status_code, _, criteria_html = owner.get("/goals/criteria.php?sheet=4")
    check("職能レベル定義は、横に並んだ部門の表を部門ごとに分けて縦に並べ、表ごとに横スライドを付ける",
          status_code == 200 and criteria_html.count('class="goal-criteria-table"') == 5 and criteria_html.count('class="goal-criteria-slider"') == 5
          and all(text in criteria_html for text in ("◆システム部門", "◆ヘルプデスク部門", "◆営業部門", "◆管理部門")), status_code)
    status_code, _, _ = owner.get("/goals/criteria.php?sheet=9")
    check("無いシートは 404", status_code == 404, status_code)
    criteria_statuses = [client_of(login_id).get("/goals/criteria.php")[0] for login_id in ("kacho_a", "dairi_a", "admin01")]
    check("評価基準は、ログインしていればだれでも見られる（参考の資料）", criteria_statuses == [200, 200, 200], criteria_statuses)
    owner.headers["User-Agent"] = MOBILE_USER_AGENT
    status_code, _, _ = owner.get("/goals/download.php?id=%d" % sheet_id)
    check("スマートフォンでは受け取れない（PC から）", status_code == 403, status_code)
    return summary()


if __name__ == "__main__":
    sys.exit(main())
