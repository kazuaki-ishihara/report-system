#!/usr/bin/env python3
"""設計書の「検証の観点」と確かめを 1 つずつ突き合わせて見つかった、残りの観点の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

G-03 削除した報告・コメントを数えない／G-04 隠れた必須の欄・無効にした選択肢の引き継ぎ・案件なし（社内待機など）／
G-05 下書き・未ログイン・「限定」のコメント・確認者の返信・変わった値の印／G-06 移行データ／G-07 ほかの人の値／
G-08 部下・「変更なし」の印／G-09 一覧の自由記述・まとめの件数・無効にした項目・下書き／G-10 部下の出力／
G-11 作れない人・課の外の対象者・ひな形／G-12 使っている選択肢の物理削除／G-13 管理者以外・コードの再利用・終了した案件／
G-14 仮のパスワード・2 段階認証の初期化・全端末ログアウトでセッションが切れる／
G-15 管理者以外・記録に値がない・30 日を過ぎた削除データ・作り直したあとの古い報告／
G-16 設定前の転送・仮の秘密・パスワードの変更と 2 段階認証の設定し直しでほかの端末が切れる。

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_viewpoints.py --base http://127.0.0.1:8000 --db app/data/verify.sqlite
"""
import argparse
import base64
import datetime
import hashlib
import hmac
import json
import os
import re
import sqlite3
import struct
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import Client, check, csrf, login, next_login_code, rkey, section, summary  # noqa: E402


def month_shift(period_text, month_count):
    year_number, month_number = int(period_text[:4]), int(period_text[5:7]) + month_count
    while month_number < 1:
        year_number, month_number = year_number - 1, month_number + 12
    while month_number > 12:
        year_number, month_number = year_number + 1, month_number - 12
    return "%04d-%02d" % (year_number, month_number)


def totp_now(secret_b32, step_offset=0):
    compact_secret = secret_b32.replace(" ", "").upper()
    key_bytes = base64.b32decode(compact_secret + "=" * ((8 - len(compact_secret) % 8) % 8))
    digest = hmac.new(key_bytes, struct.pack(">Q", int(time.time() // 30) + step_offset), hashlib.sha1).digest()
    offset = digest[19] & 0x0F
    return "%06d" % ((((digest[offset] & 0x7F) << 24) | (digest[offset + 1] << 16) | (digest[offset + 2] << 8) | digest[offset + 3]) % 1000000)


def redirected_to(response, path_part):
    return response[0] in (302, 303) and path_part in (response[1] or "")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8000")
    ap.add_argument("--db", required=True)
    arguments = ap.parse_args()
    here_dir = os.path.dirname(os.path.abspath(__file__))
    seed_info = json.load(open(os.path.join(here_dir, "output_dev_users.json"), encoding="utf-8"))
    user_info_by_login_id = seed_info["users"]
    department_a_id = seed_info["dept_a"]
    database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)
    field_id_by_code = {code: field_id for code, field_id in database.execute("SELECT code, id FROM report_fields")}
    project_id_by_code = {code: project_id for code, project_id in database.execute("SELECT code, id FROM projects")}
    current_period = datetime.date.today().strftime("%Y-%m")
    previous_period = month_shift(current_period, -1)
    now_text = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    clients = {}

    def client_of(login_id):
        if login_id not in clients:
            clients[login_id] = login(arguments.base, login_id, user_info_by_login_id[login_id])
        return clients[login_id]

    def user_id_of(login_id):
        return user_info_by_login_id[login_id]["id"]

    def report_row(login_id, period_text):
        return database.execute("SELECT id, status FROM report_records WHERE user_id = ? AND period = ? AND is_legacy = 0 AND deleted_at IS NULL",
                                (user_id_of(login_id), period_text)).fetchone()

    def save_report(login_id, period_text, action_name, block_list, report_values=None):
        client = client_of(login_id)
        _status_code, _location, edit_html = client.get("/reports/edit.php?period=" + period_text)
        form_values = {"csrf_token": csrf(edit_html), "request_key": rkey(edit_html), "period": period_text, "action": action_name}
        for block_index, (project_code, value_by_code) in enumerate(block_list):
            form_values["b[%d][project]" % block_index] = str(project_id_by_code[project_code])
            for field_code, field_value in value_by_code.items():
                form_values["b[%d][f][%d]" % (block_index, field_id_by_code[field_code])] = field_value
        for field_code, field_value in (report_values or {}).items():
            form_values["r[%d]" % field_id_by_code[field_code]] = field_value
        return client.post("/reports/save.php", form_values)

    def full_block(project_code, duties_text, **extra_values):
        block_values = {"duties": duties_text, "progress": "on_track", "continuation": "continuing", "phase": "build_unit", "staffing": "none", "system_type": "inventory"}
        block_values.update(extra_values)
        return (project_code, block_values)

    def submit(login_id, period_text, block_list, action_name="submit"):
        return save_report(login_id, period_text, action_name, block_list, report_values={"trouble_note": "特になし"})

    def act(login_id, report_id, values):
        client = client_of(login_id)
        _status_code, _location, view_html = client.get("/reports/view.php?id=%d" % report_id)
        form_values = {"csrf_token": csrf(view_html), "request_key": rkey(view_html), "report_id": report_id}
        form_values.update(values)
        return client.post("/reports/act.php", form_values)

    # 前提の報告: A一般1 は前月を提出（業務内容に「在庫の秘密の本文」、人員の動きの詳細は「限定」）
    submit("ippan_a1", previous_period, [full_block("P-0001", "在庫の秘密の本文", staffing="increase", staffing_note="限定の増員の話"), full_block("P-0002", "製造の作業")])
    a1_previous = report_row("ippan_a1", previous_period)
    leader_client = client_of("leader_a")

    def leader_home():
        _status_code, _location, home_html = leader_client.get("/index.php")
        count_match = re.search(r"A課 [^<]*（確認）</h2>\s*<p>未チェック <strong>(\d+)</strong>件", home_html)
        period_match = re.search(r"/status/index\.php\?period=(\d{4}-\d{2})&amp;dept=%d" % department_a_id, home_html)
        return (int(count_match.group(1)) if count_match else -1), (period_match.group(1) if period_match else "")

    # ================================================================ G-03
    section("G-03", "ホーム（削除した報告・コメントを数えない）")
    count_before, home_period = leader_home()
    submit("ippan_a2", home_period, [full_block("P-0001", "A一般2の作業")])
    count_with_member, _period = leader_home()
    check("提出された報告が、確認者の未チェックに数えられる（前提）", count_with_member == count_before + 1, (count_before, count_with_member))
    a2_home_report = report_row("ippan_a2", home_period)
    database.execute("UPDATE report_records SET deleted_at = ? WHERE id = ?", (now_text, a2_home_report[0]))
    count_after_delete, _period = leader_home()
    check("削除した報告は、未チェックに数えない", count_after_delete == count_before, (count_before, count_after_delete))
    database.execute("UPDATE report_records SET deleted_at = NULL WHERE id = ?", (a2_home_report[0],))
    act("leader_a", a1_previous[0], {"action": "comment", "field_id": field_id_by_code["trouble_note"], "report_project_id": 0, "comment_type": "request", "comment": "回答待ちの検証"})
    member_home_html = client_of("ippan_a1").get("/index.php")[2]
    check("依頼のコメントが、本人の回答待ちに数えられる（前提）", "回答待ちのコメント 1件" in member_home_html)
    request_comment_id = database.execute("SELECT id FROM report_field_comments WHERE report_id = ? ORDER BY id DESC LIMIT 1", (a1_previous[0],)).fetchone()[0]
    database.execute("UPDATE report_field_comments SET deleted_at = ? WHERE id = ?", (now_text, request_comment_id))
    check("削除したコメントは、回答待ちに数えない", "回答待ちのコメント 1件" not in client_of("ippan_a1").get("/index.php")[2])
    database.execute("UPDATE report_field_comments SET deleted_at = NULL WHERE id = ?", (request_comment_id,))

    # ================================================================ G-04
    section("G-04", "報告の作成・編集（隠れた必須の欄・無効にした選択肢の引き継ぎ）")
    database.execute("UPDATE report_fields SET is_required = 1 WHERE code = 'progress_note'")   # 「進捗の補足」を必須に（「遅延」「その他」のときだけ出る欄）
    submit_status, submit_location, _page = submit("dairi_a", current_period, [full_block("P-0001", "代理の作業", progress="on_track")])
    check("隠れた欄（表示の条件が合わない）は、必須でも空のまま提出できる", report_row("dairi_a", current_period) is not None and report_row("dairi_a", current_period)[1] == "submitted", (submit_status, submit_location))
    _status_code, _location, required_html = submit("kacho_a", current_period, [full_block("P-0001", "課長の作業", progress="delayed")])
    check("表示の条件が合って見えている必須の欄は、空なら提出できない（上の確かめが空振りでないこと）",
          (report_row("kacho_a", current_period) is None or report_row("kacho_a", current_period)[1] == "draft"), report_row("kacho_a", current_period))
    database.execute("UPDATE report_fields SET is_required = 0 WHERE code = 'progress_note'")
    _status_code, _location, carry_html = client_of("ippan_a1").get("/reports/edit.php?period=" + current_period)
    check("前月の選択肢の値（作業フェーズ「製造（単体）」）が引き継がれる（前提）", 'value="build_unit" selected' in carry_html)
    database.execute("UPDATE report_field_options SET is_active = 0 WHERE code = 'build_unit' AND field_id = ?", (field_id_by_code["phase"],))
    _status_code, _location, carry_disabled_html = client_of("ippan_a1").get("/reports/edit.php?period=" + current_period)
    check("無効にした選択肢の値は、前月から引き継がれない", 'value="build_unit" selected' not in carry_disabled_html)
    database.execute("UPDATE report_field_options SET is_active = 1 WHERE code = 'build_unit' AND field_id = ?", (field_id_by_code["phase"],))

    # 案件がない人（社内待機・研修など）: 「案件なし」を選ぶ。案件を選ばずに入れた値は黙って捨てず、知らせて入力を残す
    no_project_id = database.execute("SELECT id FROM projects WHERE code = '案件なし'").fetchone()
    check("予約の案件「案件なし」があり、課の指定がなく有効（前提）", no_project_id is not None
          and database.execute("SELECT department_id IS NULL AND is_active = 1 FROM projects WHERE code = '案件なし'").fetchone()[0] == 1)
    project_id_by_code["案件なし"] = no_project_id[0] if no_project_id else 0
    b1_edit_html = client_of("ippan_b1").get("/reports/edit.php?period=" + current_period)[2]
    first_project_option = re.search(r'<select[^>]*name="b\[0\]\[project\]"[^>]*>\s*<option value="">[^<]*</option>\s*<option value="(\d+)"', b1_edit_html)
    check("「案件なし」は、どの課（B課）でも案件の選択肢のいちばん上に出る", first_project_option is not None and int(first_project_option.group(1)) == project_id_by_code["案件なし"])
    client_a2 = client_of("ippan_a2")
    _status_code, _location, edit_html = client_a2.get("/reports/edit.php?period=" + month_shift(current_period, -2))
    refused_status, _location, refused_html = client_a2.post("/reports/save.php", {
        "csrf_token": csrf(edit_html), "request_key": rkey(edit_html), "period": month_shift(current_period, -2), "action": "submit",
        "b[0][project]": "", "b[0][f][%d]" % field_id_by_code["duties"]: "案件を選ばずに書いた社内待機の内容", "r[%d]" % field_id_by_code["trouble_note"]: "特になし"})
    check("案件を選ばずに案件の欄へ入れた値は、黙って捨てずに「案件なし」を選ぶよう知らせ、保存しない",
          refused_status == 200 and "「案件なし」を選んでください" in refused_html and report_row("ippan_a2", month_shift(current_period, -2)) is None, refused_status)
    check("知らせたとき、入れた値はその欄に残る", "案件を選ばずに書いた社内待機の内容" in refused_html)
    submit("ippan_a2", month_shift(current_period, -2), [full_block("案件なし", "社内待機の内容（研修）")])
    no_project_report = report_row("ippan_a2", month_shift(current_period, -2))
    check("「案件なし」を選べば、担当内容などを書いて提出できる", no_project_report is not None and no_project_report[1] == "submitted", no_project_report)
    check("「案件なし」で出した報告を開くと、入れた値が出る（初期のままにならない）",
          "社内待機の内容（研修）" in client_a2.get("/reports/edit.php?period=" + month_shift(current_period, -2))[2])

    # ================================================================ G-05
    section("G-05", "報告の詳細（下書き・未ログイン・「限定」のコメント・確認者の返信・変わった値の印）")
    save_report("ippan_a2", current_period, "draft", [("P-0001", {"duties": "A一般2の下書き"})])
    a2_previous_draft = report_row("ippan_a2", current_period)
    check("A一般2 の当月の下書きがある（前提）", a2_previous_draft is not None and a2_previous_draft[1] == "draft", a2_previous_draft)
    check("下書きの報告は、確認者にも開けない（404）", leader_client.get("/reports/view.php?id=%d" % a2_previous_draft[0])[0] == 404)
    check("未ログインで報告を開くと、ログインの画面へ", redirected_to(Client(arguments.base).get("/reports/view.php?id=%d" % a1_previous[0]), "login"))
    restricted_comment_text = "限定の項目へのコメント（検証）"
    staffing_note_project_id = database.execute("SELECT rp.id FROM report_projects rp JOIN projects p ON p.id = rp.project_id WHERE rp.report_id = ? AND p.code = 'P-0001'",
                                                (a1_previous[0],)).fetchone()[0]
    act("kacho_a", a1_previous[0], {"action": "comment", "field_id": field_id_by_code["staffing_note"], "report_project_id": staffing_note_project_id,
                                    "comment_type": "note", "comment": restricted_comment_text})
    check("課長は「限定」の項目にコメントでき、課長の画面には出る（前提）", restricted_comment_text in client_of("kacho_a").get("/reports/view.php?id=%d" % a1_previous[0])[2])
    check("確認者の画面（HTML）には、「限定」の項目へのコメントが含まれない", restricted_comment_text not in leader_client.get("/reports/view.php?id=%d" % a1_previous[0])[2])
    act("leader_a", a1_previous[0], {"action": "check"})
    submit("ippan_a1", previous_period, [full_block("P-0001", "在庫の秘密の本文（直した）", staffing="increase", staffing_note="限定の増員の話（直した）"), full_block("P-0002", "製造の作業")], action_name="save")
    leader_view_html = leader_client.get("/reports/view.php?id=%d" % a1_previous[0])[2]
    check("提出後に本人が直すと、確認者の画面で変わった値に「更新あり」の印が付く", 'class="tag tag-changed">更新あり' in leader_view_html)
    check("確認者の画面（HTML）には、「限定」の項目の変更履歴（前後の値）が含まれない", "限定の増員の話" not in leader_view_html)
    check("課長の画面には「限定」の項目の変更履歴が出る（上の確かめが空振りでないこと）", "限定の増員の話" in client_of("kacho_a").get("/reports/view.php?id=%d" % a1_previous[0])[2])
    act("leader_a", a1_previous[0], {"action": "comment", "field_id": field_id_by_code["trouble_note"], "report_project_id": 0, "comment_type": "request", "comment": "確認者の依頼"})
    reviewer_request_id = database.execute("SELECT id FROM report_field_comments WHERE report_id = ? AND parent_id IS NULL ORDER BY id DESC LIMIT 1", (a1_previous[0],)).fetchone()[0]
    act("leader_a", a1_previous[0], {"action": "reply", "parent_id": reviewer_request_id, "comment": "確認者の補足"})
    check("確認者の返信では、回答待ち（open）のまま", database.execute("SELECT thread_status FROM report_field_comments WHERE id = ?", (reviewer_request_id,)).fetchone()[0] == "open")

    # ================================================================ G-06
    section("G-06", "自分の報告一覧（移行データ）")
    legacy_id_list = []
    for legacy_number in (1, 2):
        database.execute("INSERT INTO report_records (user_id, department_id, period, status, is_legacy, legacy_report_id, submitted_at, created_at, updated_at) VALUES (?, ?, '2024-04', 'submitted', 1, ?, ?, ?, ?)",
                         (user_id_of("ippan_a1"), department_a_id, 900 + legacy_number, now_text, now_text, now_text))
        legacy_id_list.append(database.execute("SELECT last_insert_rowid()").fetchone()[0])
    mine_html = client_of("ippan_a1").get("/reports/mine.php")[2]
    check("移行データ（同じ月に 2 件）は、自分の報告一覧に両方出る", all(("view.php?id=%d" % legacy_id) in mine_html for legacy_id in legacy_id_list), legacy_id_list)

    # ================================================================ G-07
    section("G-07", "臨時の調査への回答（ほかの人の値は初期値に入らない）")
    manager_client = client_of("kacho_a")
    _status_code, _location, survey_html = manager_client.get("/surveys/survey_edit.php")
    _status_code, survey_location, _page = manager_client.post("/surveys/survey_edit.php", {
        "csrf_token": csrf(survey_html), "request_key": rkey(survey_html), "action": "save_meta", "department_id": str(department_a_id), "title": "作業フェーズの確認",
        "requester": "総務", "purpose": "検証", "due_at": (datetime.date.today() + datetime.timedelta(days=3)).isoformat(), "result_visibility": "detail", "target_type": "all"})
    survey_id = int(re.search(r"id=(\d+)", survey_location).group(1))
    _status_code, _location, survey_html = manager_client.get("/surveys/survey_edit.php?id=%d" % survey_id)
    manager_client.post("/surveys/survey_edit.php?id=%d" % survey_id, {"csrf_token": csrf(survey_html), "request_key": rkey(survey_html), "action": "add_source", "field_id": field_id_by_code["phase"]})
    _status_code, _location, survey_html = manager_client.get("/surveys/survey_edit.php?id=%d" % survey_id)
    manager_client.post("/surveys/survey_edit.php?id=%d" % survey_id, {"csrf_token": csrf(survey_html), "request_key": rkey(survey_html), "action": "publish"})
    check("A一般1 の回答の初期値に、自分の月次報告の値が入る（前提）", 'value="build_unit" selected' in client_of("ippan_a1").get("/surveys/answer.php?id=%d" % survey_id)[2])
    check("月次報告のない人（リーダー）の回答には、ほかの人の値が入らない", 'value="build_unit" selected' not in leader_client.get("/surveys/answer.php?id=%d" % survey_id)[2])

    # ================================================================ G-08
    section("G-08", "提出状況（部下・「変更なし」の印）")
    check("部下は提出状況を開けない（403）", client_of("ippan_a1").get("/status/index.php")[0] == 403)
    submit("ippan_a1", current_period, [], action_name="unchanged")
    status_html = leader_client.get("/status/index.php?period=%s&dept=%d" % (current_period, department_a_id))[2]
    check("「変更なし」で提出された報告に「変更なし」の印が出る", report_row("ippan_a1", current_period) is not None and '<span class="tag">変更なし</span>' in status_html)

    # ================================================================ G-09
    section("G-09", "横並び表示（一覧の自由記述・まとめの件数・無効にした項目・下書き）")
    compare_html = leader_client.get("/status/compare.php?period=" + previous_period)[2]
    check("横並びの一覧の HTML には、自由記述の本文が含まれない（セルを開くと出る）", "在庫の秘密の本文" not in compare_html and "open-cell" in compare_html)
    current_compare_html = leader_client.get("/status/compare.php?period=" + current_period)[2]
    check("下書きの報告は横並びに出ない", ("view.php?id=%d" % a2_previous_draft[0]) not in current_compare_html and ("view.php?id=%d" % report_row("dairi_a", current_period)[0]) in current_compare_html)
    summary_html = leader_client.get("/status/compare.php?period=%s&view=summary" % previous_period)[2]
    progress_label = database.execute("SELECT label FROM report_fields WHERE code = 'progress'").fetchone()[0]
    on_track_label = database.execute("SELECT label FROM report_field_options WHERE field_id = ? AND code = 'on_track'", (field_id_by_code["progress"],)).fetchone()[0]
    summary_match = re.search(r"<strong>%s</strong>: (.*?)</p>" % re.escape(progress_label), summary_html)
    on_track_count = database.execute(
        "SELECT COUNT(*) FROM report_field_values v JOIN report_records r ON r.id = v.report_id WHERE r.department_id = ? AND r.period = ? AND r.status IN ('submitted','confirmed') AND r.deleted_at IS NULL AND v.field_id = ? AND v.value_text = 'on_track'",
        (department_a_id, previous_period, field_id_by_code["progress"])).fetchone()[0]
    shown_count_match = re.search(re.escape(on_track_label) + r" (\d+)", summary_match.group(1)) if summary_match else None
    check("まとめの件数が、選択式の値の件数と一致する（「%s」）" % on_track_label, shown_count_match is not None and int(shown_count_match.group(1)) == on_track_count and on_track_count > 0,
          (summary_match.group(1)[:120] if summary_match else None, on_track_count))
    system_type_label = database.execute("SELECT label FROM report_fields WHERE code = 'system_type'").fetchone()[0]
    check("有効な項目は、横並びの列に出る（前提）", system_type_label in compare_html)
    database.execute("UPDATE report_fields SET is_active = 0 WHERE code = 'system_type'")
    check("無効にした項目は、過去の月の横並びにも列が出ない", system_type_label not in leader_client.get("/status/compare.php?period=" + previous_period)[2])
    database.execute("UPDATE report_fields SET is_active = 1 WHERE code = 'system_type'")

    # ================================================================ G-10
    section("G-10", "出力（部下）")
    member_client = client_of("ippan_a1")
    export_page_status = member_client.get("/export/index.php")[0]
    export_post_status = member_client.post("/export/index.php", {"csrf_token": csrf(member_client.get("/index.php")[2]), "type": "report", "dept": str(department_a_id),
                                                                   "from": previous_period, "to": previous_period, "content": "both", "free": "1", "format": "csv"})[0]
    check("部下は出力の画面を開けず、出力もできない（403）", export_page_status == 403 and export_post_status == 403, (export_page_status, export_post_status))

    # ================================================================ G-11
    section("G-11", "臨時の調査の管理（作れない人・課の外の対象者・ひな形）")
    for login_id in ("leader_a", "ippan_a1"):
        create_status = client_of(login_id).get("/surveys/survey_edit.php")[0]
        result_status = client_of(login_id).get("/surveys/result.php?id=%d" % survey_id)[0]
        check("%s（課長でない）は臨時の調査を作れず、結果も見られない" % login_id, create_status in (403, 404) and result_status in (403, 404), (create_status, result_status))
    other_manager_client = client_of("kacho_b")
    check("別の課の課長は、A課の調査の結果を見られず、編集も開けない",
          other_manager_client.get("/surveys/result.php?id=%d" % survey_id)[0] in (403, 404) and other_manager_client.get("/surveys/survey_edit.php?id=%d" % survey_id)[0] in (403, 404))
    _status_code, _location, other_survey_html = other_manager_client.get("/surveys/survey_edit.php")
    other_create_status, _location, _page = other_manager_client.post("/surveys/survey_edit.php", {
        "csrf_token": csrf(other_survey_html), "request_key": rkey(other_survey_html), "action": "save_meta", "department_id": str(department_a_id), "title": "B課長がA課に作る調査",
        "requester": "総務", "purpose": "検証", "due_at": (datetime.date.today() + datetime.timedelta(days=3)).isoformat(), "result_visibility": "summary", "target_type": "all"})
    check("別の課の課長は、A課の臨時の調査を作れない", database.execute("SELECT COUNT(*) FROM adhoc_requests WHERE title = 'B課長がA課に作る調査'").fetchone()[0] == 0, other_create_status)
    _status_code, _location, survey_html = manager_client.get("/surveys/survey_edit.php")
    outside_status, _location, _page = manager_client.post("/surveys/survey_edit.php", {
        "csrf_token": csrf(survey_html), "request_key": rkey(survey_html), "action": "save_meta", "department_id": str(department_a_id), "title": "課の外の対象者",
        "requester": "総務", "purpose": "検証", "due_at": (datetime.date.today() + datetime.timedelta(days=3)).isoformat(), "result_visibility": "summary",
        "target_type": "users", "targets[]": [str(user_id_of("ippan_b1"))]})
    check("課長は、対象者を自分の課の外の人にできない（400）", outside_status == 400 and database.execute("SELECT COUNT(*) FROM adhoc_requests WHERE title = '課の外の対象者'").fetchone()[0] == 0, outside_status)
    _status_code, _location, survey_html = manager_client.get("/surveys/survey_edit.php")
    _status_code, template_location, _page = manager_client.post("/surveys/survey_edit.php", {
        "csrf_token": csrf(survey_html), "request_key": rkey(survey_html), "action": "save_meta", "department_id": str(department_a_id), "title": "ひな形の調査",
        "requester": "総務", "purpose": "検証", "due_at": (datetime.date.today() + datetime.timedelta(days=3)).isoformat(), "result_visibility": "summary", "target_type": "all"})
    template_id = int(re.search(r"id=(\d+)", template_location).group(1))
    _status_code, _location, survey_html = manager_client.get("/surveys/survey_edit.php?id=%d" % template_id)
    manager_client.post("/surveys/survey_edit.php?id=%d" % template_id, {"csrf_token": csrf(survey_html), "request_key": rkey(survey_html), "action": "add_source", "field_id": field_id_by_code["phase"]})
    database.execute("UPDATE adhoc_requests SET is_template = 1 WHERE id = ?", (template_id,))
    _status_code, _location, survey_html = manager_client.get("/surveys/survey_edit.php?id=%d" % template_id)
    template_status, _location, template_html = manager_client.post("/surveys/survey_edit.php?id=%d" % template_id, {"csrf_token": csrf(survey_html), "request_key": rkey(survey_html), "action": "publish"})
    check("ひな形は公開できない（400）", template_status == 400 and database.execute("SELECT status FROM adhoc_requests WHERE id = ?", (template_id,)).fetchone()[0] == "draft", template_status)

    # ================================================================ G-12
    section("G-12", "項目マスタ（使っている選択肢の物理削除）")
    used_option_id = database.execute("SELECT id FROM report_field_options WHERE field_id = ? AND code = 'build_unit'", (field_id_by_code["phase"],)).fetchone()[0]
    try:
        database.execute("DELETE FROM report_field_options WHERE id = ?", (used_option_id,))
        option_deleted = True
    except sqlite3.DatabaseError:
        option_deleted = False
    check("報告で使っている選択肢は、DB でも物理削除できない（トリガーで止まる）", not option_deleted)

    # ================================================================ G-13
    section("G-13", "案件の管理（管理者以外・コードの再利用・終了した案件）")
    check("管理者以外（課長）は案件の画面を開けない（403）", manager_client.get("/admin/projects.php")[0] == 403)
    forbidden_status = manager_client.post("/admin/projects.php", {"csrf_token": csrf(manager_client.get("/index.php")[2]), "request_key": "0" * 31 + "7",
                                                                   "action": "add", "alias": "課長の案件", "department_id": str(department_a_id)})[0]
    check("管理者以外（課長）は案件を足せない（403）", forbidden_status == 403 and database.execute("SELECT COUNT(*) FROM projects WHERE alias = '課長の案件'").fetchone()[0] == 0, forbidden_status)
    admin_client = client_of("admin01")

    def add_project(alias_text):
        _status_code, _location, projects_html = admin_client.get("/admin/projects.php")
        admin_client.post("/admin/projects.php", {"csrf_token": csrf(projects_html), "request_key": rkey(projects_html), "action": "add", "alias": alias_text, "department_id": str(department_a_id)})
        row = database.execute("SELECT id, code FROM projects WHERE alias = ?", (alias_text,)).fetchone()
        return row if row else (0, "")

    database.execute("INSERT INTO projects (code, alias, department_id, created_at, updated_at) VALUES ('P-0010', '台帳から入れた案件', ?, ?, ?)", (department_a_id, now_text, now_text))
    first_added = add_project("足した案件その一")
    check("新しい案件のコードは、いちばん大きい番号の次（P-0010 があれば P-0011）", first_added[1] == "P-0011", first_added)
    _status_code, _location, projects_html = admin_client.get("/admin/projects.php")
    admin_client.post("/admin/projects.php", {"csrf_token": csrf(projects_html), "request_key": rkey(projects_html), "action": "end", "id": str(first_added[0])})
    second_added = add_project("足した案件その二")
    check("終了した案件のコードは使い回さない（次は P-0012）", second_added[1] == "P-0012", second_added)
    _status_code, _location, projects_html = admin_client.get("/admin/projects.php")
    admin_client.post("/admin/projects.php", {"csrf_token": csrf(projects_html), "request_key": rkey(projects_html), "action": "end", "id": str(project_id_by_code["P-0002"])})
    new_report_html = client_of("ippan_a2").get("/reports/edit.php?period=" + current_period)[2]
    project_option_code_set = set(re.findall(r'<option value="\d+"[^>]*>(P-\d{4})', new_report_html))
    check("終了した案件（P-0002）は、新しい報告の選択肢に出ない", "P-0002" not in project_option_code_set and "P-0001" in project_option_code_set, sorted(project_option_code_set))
    projects_list_html = admin_client.get("/admin/projects.php")[2]
    check("予約の「案件なし」は、案件の管理の一覧と案件の一覧の CSV に出ない",
          ("data-code=\"案件なし\"" not in projects_list_html) and ("社内待機・研修など" not in projects_list_html) and "案件なし" not in admin_client.get("/admin/projects.php?csv=1")[2])
    _status_code, _location, projects_html = admin_client.get("/admin/projects.php")
    end_reserved_status = admin_client.post("/admin/projects.php", {"csrf_token": csrf(projects_html), "request_key": rkey(projects_html), "action": "end", "id": str(project_id_by_code["案件なし"])})[0]
    check("予約の「案件なし」は、管理者も終了できない（404）", end_reserved_status == 404 and database.execute("SELECT is_active FROM projects WHERE code = '案件なし'").fetchone()[0] == 1, end_reserved_status)
    check("終了した案件も、過去の報告には「P-0002」と出る", "P-0002" in leader_client.get("/reports/view.php?id=%d" % a1_previous[0])[2])

    # ================================================================ G-15
    section("G-15", "記録の閲覧・削除データ（管理者以外・記録の中身・30 日・作り直したあと）")
    check("管理者以外（課長）は記録の画面を開けない（403）", manager_client.get("/admin/logs.php")[0] == 403 and manager_client.get("/admin/trash.php")[0] == 403)
    secret_word_list = ["在庫の秘密の本文", "限定の増員の話", restricted_comment_text, "回答待ちの検証"] + [user_info["password"] for user_info in user_info_by_login_id.values()]
    leaked_list = [secret_word for secret_word in secret_word_list
                   if database.execute("SELECT COUNT(*) FROM access_logs WHERE target LIKE ? OR action LIKE ?", ("%" + secret_word + "%", "%" + secret_word + "%")).fetchone()[0] > 0]
    check("操作の記録（access_logs）に、報告の値・コメント・パスワードが含まれない", leaked_list == [] and database.execute("SELECT COUNT(*) FROM access_logs").fetchone()[0] > 0, leaked_list)
    old_deleted_text = (datetime.datetime.now() - datetime.timedelta(days=31)).strftime("%Y-%m-%d %H:%M:%S")
    database.execute("UPDATE report_records SET deleted_at = ? WHERE id = ?", (old_deleted_text, a2_previous_draft[0]))
    trash_html = admin_client.get("/admin/trash.php")[2]
    check("30 日を過ぎた削除データは、削除データの一覧に出ない", ("value=\"%d\"" % a2_previous_draft[0]) not in trash_html and ("id=%d" % a2_previous_draft[0]) not in trash_html)
    database.execute("UPDATE report_records SET deleted_at = ? WHERE id = ?", (now_text, a2_previous_draft[0]))
    save_report("ippan_a2", current_period, "draft", [("P-0001", {"duties": "作り直した下書き"})])
    remade_report = report_row("ippan_a2", current_period)
    _status_code, _location, trash_html = admin_client.get("/admin/trash.php")
    restore_status, restore_location, _page = admin_client.post("/admin/trash.php", {"csrf_token": csrf(trash_html), "request_key": rkey(trash_html), "action": "restore_report", "id": str(a2_previous_draft[0])})
    restore_message_html = admin_client.get(restore_location or "/admin/trash.php")[2]
    check("同じ月の報告が作り直されたあとは、古いほうを戻せない", remade_report is not None and remade_report[0] != a2_previous_draft[0]
          and database.execute("SELECT deleted_at FROM report_records WHERE id = ?", (a2_previous_draft[0],)).fetchone()[0] is not None
          and "新しく作られているため、戻せません" in restore_message_html, restore_status)

    # ================================================================ G-16
    section("G-16", "アカウント設定（設定前の転送・仮の秘密・ほかの端末が切れる）")
    database.execute("UPDATE users SET totp_enabled_at = NULL, totp_secret_enc = NULL WHERE login_id = 'ippan_b1'")
    setup_client = Client(arguments.base)
    _status_code, _location, login_html = setup_client.get("/login.php")
    _status_code, setup_location, _page = setup_client.post("/login.php", {"csrf_token": csrf(login_html), "action": "password", "login_id": "ippan_b1",
                                                                          "password": user_info_by_login_id["ippan_b1"]["password"]})
    check("2 段階認証が未設定の人は、パスワードのあと設定の画面へ（前提）", "account.php" in (setup_location or ""), setup_location)
    check("設定が済むまでは、ほかの画面の GET も設定の画面へ転送される",
          all(redirected_to(setup_client.get(path), "account.php") for path in ("/index.php", "/reports/mine.php", "/faq.php", "/reports/search.php")))
    setup_html = setup_client.get("/account.php?tab=totp")[2]
    post_response = setup_client.post("/reports/save.php", {"csrf_token": csrf(setup_html), "request_key": "0" * 31 + "9", "period": current_period, "action": "draft"})
    check("設定が済むまでは、ほかの画面への POST も受け付けない（設定の画面へ・403）", redirected_to(post_response, "account.php") or post_response[0] == 403, post_response[:2])
    secret_match = re.search(r'<p class="secret">([A-Z2-7 ]+)</p>', setup_html)
    check("設定の画面に仮の秘密が出る（前提）", secret_match is not None)
    check("仮の秘密の値は、確かめの前には DB に入らない", database.execute("SELECT totp_secret_enc FROM users WHERE login_id = 'ippan_b1'").fetchone()[0] in (None, ""))

    other_device_client = login(arguments.base, "kacho_b", user_info_by_login_id["kacho_b"])
    changer_client = login(arguments.base, "kacho_b", user_info_by_login_id["kacho_b"])
    _status_code, _location, password_html = changer_client.get("/account.php?tab=password")
    changer_client.post("/account.php?tab=password", {"csrf_token": csrf(password_html), "request_key": rkey(password_html), "action": "password_change",
                                                      "current_password": user_info_by_login_id["kacho_b"]["password"],
                                                      "new_password": "kacho-b-new-pass-2026", "new_password_confirm": "kacho-b-new-pass-2026"})
    check("パスワードを変えた（前提）", database.execute("SELECT password_changed_at FROM users WHERE login_id = 'kacho_b'").fetchone()[0] is not None)
    check("パスワードを変えると、ほかの端末のセッションが切れる", redirected_to(other_device_client.get("/index.php"), "login"))
    check("パスワードを変えた端末は、そのまま使える", changer_client.get("/index.php")[0] == 200)

    second_device_client = login(arguments.base, "dairi_a", user_info_by_login_id["dairi_a"])
    redo_client = login(arguments.base, "dairi_a", user_info_by_login_id["dairi_a"])
    _status_code, _location, totp_html = redo_client.get("/account.php?tab=totp")
    redo_client.post("/account.php?tab=totp", {"csrf_token": csrf(totp_html), "request_key": rkey(totp_html), "action": "totp_redo",
                                               "current_password": user_info_by_login_id["dairi_a"]["password"]})
    _status_code, _location, redo_html = redo_client.get("/account.php?tab=totp")
    new_secret_match = re.search(r'<p class="secret">([A-Z2-7 ]+)</p>', redo_html)
    if new_secret_match:
        redo_client.post("/account.php?tab=totp", {"csrf_token": csrf(redo_html), "request_key": rkey(redo_html), "action": "totp_confirm",
                                                   "code": totp_now(new_secret_match.group(1)), "remember": "0"})
    check("2 段階認証を設定し直した（前提）", new_secret_match is not None and database.execute("SELECT totp_secret_enc FROM users WHERE login_id = 'dairi_a'").fetchone()[0] not in (None, ""))
    check("2 段階認証を設定し直すと、ほかの端末のセッションが切れる", redirected_to(second_device_client.get("/index.php"), "login"))

    # ================================================================ G-14
    section("G-14", "組織・ユーザーの管理（仮のパスワード・2 段階認証の初期化・全端末ログアウトで、その人のセッションが切れる）")
    for target_login_id, admin_action, action_label in [("ippan_a1", "temp_password", "仮のパスワードを発行"), ("ippan_a2", "reset_2fa", "2 段階認証を初期化"),
                                                        ("leader_a", "logout_all", "すべての端末からログアウト")]:
        target_session_client = login(arguments.base, target_login_id, user_info_by_login_id[target_login_id])
        check("%s の前に、その人がログインしている（前提）" % action_label, target_session_client.get("/index.php")[0] == 200)
        edit_path = "/admin/user_edit.php?id=%d" % user_id_of(target_login_id)
        _status_code, _location, edit_html = admin_client.get(edit_path)
        admin_client.post(edit_path, {"csrf_token": csrf(edit_html), "request_key": rkey(edit_html), "action": admin_action})
        check("管理者が%sすると、その人の今のセッションが切れる" % action_label, redirected_to(target_session_client.get("/index.php"), "login"))

    return summary()


if __name__ == "__main__":
    sys.exit(main())
