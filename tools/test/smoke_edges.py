#!/usr/bin/env python3
"""設計書で「自動の検証でまだ試していないもの」とされていた項目の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

時間に頼るもの（5 分を過ぎた手順 2・15 分後のロック解除）は、セッションのファイル・DB の時刻を進めて確かめる。
同時の新規作成は、同じ DB を使うサーバーをもう 1 本立て、1 つ目の画面を「報告を探したあと」で待たせ（検証の印のときだけ効く X-Verify-Pause）、
そのあいだに 2 つ目の画面で同じ月の報告を作る。

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_edges.py --base http://127.0.0.1:8000 --db app/data/verify.sqlite --php <php.exe>
"""
import argparse
import datetime
import glob
import json
import os
import re
import socket
import sqlite3
import subprocess
import sys
import tempfile
import threading
import time
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import Client, check, csrf, login, next_login_code, rkey, section, summary  # noqa: E402
from verify_all import PHP_SESSION_DIR, php_server_command  # noqa: E402

LOCK_MESSAGE_PART = "しばらく"


def cookie_value(client, cookie_name):
    for cookie in client.jar:
        if cookie.name == cookie_name:
            return cookie.value
    return ""


def password_step(client, login_id, password_text):
    _status_code, _location, login_html = client.get("/login.php")
    return client.post("/login.php", {"csrf_token": csrf(login_html), "action": "password", "login_id": login_id, "password": password_text})


def code_step(client, code_text):
    _status_code, _location, step_html = client.get("/login.php?step=2")
    return client.post("/login.php", {"csrf_token": csrf(step_html), "action": "code", "code": code_text, "remember": "0"})


def session_file_path(session_id):
    """PHP の組み込みサーバーのセッションのファイル（sess_<ID>）。検証のサーバーは PHP_SESSION_DIR に置く。
    手で立てたサーバー（session.save_path が空）なら一時フォルダーにある"""
    candidate_dir_list = [PHP_SESSION_DIR, tempfile.gettempdir(), os.environ.get("TEMP", ""), os.environ.get("TMP", "")]
    for candidate_dir in candidate_dir_list:
        if candidate_dir:
            candidate_path = os.path.join(candidate_dir, "sess_" + session_id)
            if os.path.isfile(candidate_path):
                return candidate_path
    found_list = glob.glob(os.path.join(os.path.expanduser("~"), "AppData", "Local", "Temp", "sess_" + session_id))
    return found_list[0] if found_list else ""


def month_shift(period_text, month_count):
    year_number, month_number = int(period_text[:4]), int(period_text[5:7]) + month_count
    while month_number < 1:
        year_number, month_number = year_number - 1, month_number + 12
    while month_number > 12:
        year_number, month_number = year_number + 1, month_number - 12
    return "%04d-%02d" % (year_number, month_number)


def free_port():
    probe_socket = socket.socket()
    probe_socket.bind(("127.0.0.1", 0))
    port_number = probe_socket.getsockname()[1]
    probe_socket.close()
    return port_number


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8000")
    ap.add_argument("--db", required=True)
    ap.add_argument("--php", required=True, help="同時の新規作成で、もう 1 本サーバーを立てるための PHP")
    arguments = ap.parse_args()
    here_dir = os.path.dirname(os.path.abspath(__file__))
    root_dir = os.path.abspath(os.path.join(here_dir, "..", ".."))
    seed_info = json.load(open(os.path.join(here_dir, "output_dev_users.json"), encoding="utf-8"))
    user_info_by_login_id = seed_info["users"]
    department_a_id = seed_info["dept_a"]
    database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)
    field_id_by_code = {code: field_id for code, field_id in database.execute("SELECT code, id FROM report_fields")}
    project_id_by_code = {code: project_id for code, project_id in database.execute("SELECT code, id FROM projects")}
    current_period = datetime.date.today().strftime("%Y-%m")
    previous_period = month_shift(current_period, -1)

    def clear_throttle():
        database.execute("DELETE FROM login_throttle")

    def report_row(login_id, period_text):
        return database.execute("SELECT id, status FROM report_records WHERE user_id = ? AND period = ? AND is_legacy = 0 AND deleted_at IS NULL",
                                (user_info_by_login_id[login_id]["id"], period_text)).fetchone()

    def save_report(client, period_text, action_name, block_list, report_values=None, extra_values=None):
        """報告を保存する。block_list は [(案件のコード, {項目のコード: 値})]"""
        _status_code, _location, edit_html = client.get("/reports/edit.php?period=" + period_text)
        form_values = {"csrf_token": csrf(edit_html), "request_key": rkey(edit_html), "period": period_text, "action": action_name}
        for block_index, (project_code, value_by_code) in enumerate(block_list):
            form_values["b[%d][project]" % block_index] = str(project_id_by_code[project_code])
            for field_code, field_value in value_by_code.items():
                form_values["b[%d][f][%d]" % (block_index, field_id_by_code[field_code])] = field_value
        for field_code, field_value in (report_values or {}).items():
            form_values["r[%d]" % field_id_by_code[field_code]] = field_value
        form_values.update(extra_values or {})
        return client.post("/reports/save.php", form_values)

    # ================================================================ G-01 時間に頼るもの
    section("G-01", "ログイン（時間に頼るもの: 5 分を過ぎた手順 2・15 分後のロック解除）")
    expired_client = Client(arguments.base)
    password_step(expired_client, "ippan_a1", user_info_by_login_id["ippan_a1"]["password"])
    session_path = session_file_path(cookie_value(expired_client, "mr_session"))
    check("手順 2 の状態はセッションのファイルにある（前提）", session_path != "", cookie_value(expired_client, "mr_session"))
    if session_path:
        session_text = open(session_path, encoding="utf-8", errors="replace").read()
        pw_at_match = re.search(r"pw_at\|i:(\d+);", session_text)
        check("手順 2 を始めた時刻がセッションにある（前提）", pw_at_match is not None)
        if pw_at_match:
            old_time_text = str(int(pw_at_match.group(1)) - 6 * 60)   # 6 分前に始めたことにする
            open(session_path, "w", encoding="utf-8").write(session_text.replace(pw_at_match.group(0), "pw_at|i:%s;" % old_time_text))
    _status_code, expired_location, _page = code_step(expired_client, next_login_code("ippan_a1", user_info_by_login_id["ippan_a1"]["totp"]))
    check("5 分を過ぎた手順 2 は断られる（正しいコードでも入れない）", "/index.php" not in expired_location, expired_location)

    lock_client = Client(arguments.base)
    for attempt_number in range(10):
        password_step(lock_client, "leader_a", "wrong-password-%d" % attempt_number)
    clear_throttle()
    _status_code, locked_location, locked_html = password_step(lock_client, "leader_a", user_info_by_login_id["leader_a"]["password"])
    check("10 回の失敗でロックされる（前提）", "step=2" not in locked_location and LOCK_MESSAGE_PART in locked_html)
    passed_time_text = (datetime.datetime.now() - datetime.timedelta(seconds=1)).strftime("%Y-%m-%d %H:%M:%S")
    database.execute("UPDATE users SET locked_until = ? WHERE login_id = 'leader_a'", (passed_time_text,))   # 15 分が過ぎたことにする
    _status_code, unlocked_location, _page = password_step(Client(arguments.base), "leader_a", user_info_by_login_id["leader_a"]["password"])
    check("ロックの時間（15 分）が過ぎると、ふたたびログインできる", "step=2" in unlocked_location, unlocked_location)
    clear_throttle()

    # ================================================================ G-04 報告の作成・編集
    section("G-04", "報告の作成・編集（ほかの人の対象月・案件 10 件・文字数の境目・同時の新規作成）")
    member_a1_client = login(arguments.base, "ippan_a1", user_info_by_login_id["ippan_a1"])
    member_a2_client = login(arguments.base, "ippan_a2", user_info_by_login_id["ippan_a2"])
    save_report(member_a2_client, previous_period, "draft", [("P-0001", {"duties": "A一般2だけの作業の記録"})])
    other_report = report_row("ippan_a2", previous_period)
    check("ほかの人（A一般2）の前月の下書きがある（前提）", other_report is not None)
    _status_code, _location, a1_edit_html = member_a1_client.get("/reports/edit.php?period=" + previous_period)
    check("同じ対象月を開いても、自分の報告しか開けない（ほかの人の入力が出ない）", "A一般2だけの作業の記録" not in a1_edit_html)
    save_report(member_a1_client, previous_period, "draft", [("P-0002", {"duties": "A一般1の作業"})], extra_values={"report_id": str(other_report[0]), "id": str(other_report[0])})
    a2_duties_count = database.execute("SELECT COUNT(*) FROM report_projects WHERE report_id = ?", (other_report[0],)).fetchone()[0]
    a1_report = report_row("ippan_a1", previous_period)
    check("ほかの人の報告の ID を送っても、ほかの人の報告は変わらず、自分の報告に保存される",
          a2_duties_count == 1 and a1_report is not None and a1_report[0] != other_report[0], (a2_duties_count, a1_report))

    now_text = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    for extra_number in range(5, 12):
        database.execute("INSERT INTO projects (code, alias, department_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?)",
                         ("P-%04d" % extra_number, "検証用の案件 %d" % extra_number, department_a_id, now_text, now_text))
    project_id_by_code.update({code: project_id for code, project_id in database.execute("SELECT code, id FROM projects")})
    project_code_list = ["P-0001", "P-0002", "P-0004"] + ["P-%04d" % extra_number for extra_number in range(5, 12)]
    project_code_list = [code for code in project_code_list if code in project_id_by_code]
    ten_blocks = [(code, {"duties": "案件 %s" % code}) for code in project_code_list[:10]]
    save_report(member_a1_client, current_period, "draft", ten_blocks)
    current_report = report_row("ippan_a1", current_period)
    ten_count = database.execute("SELECT COUNT(*) FROM report_projects WHERE report_id = ?", (current_report[0],)).fetchone()[0] if current_report else 0
    check("案件 10 件の報告を保存できる", len(ten_blocks) == 10 and ten_count == 10, (len(ten_blocks), ten_count))
    eleven_blocks = ten_blocks + [("P-0003" if "P-0003" in project_id_by_code else project_code_list[0], {"duties": "11 件目"})]
    database.execute("UPDATE projects SET department_id = ? WHERE code = 'P-0003'", (department_a_id,))
    _status_code, _location, eleven_html = save_report(member_a1_client, current_period, "draft", eleven_blocks)
    eleven_count = database.execute("SELECT COUNT(*) FROM report_projects WHERE report_id = ?", (current_report[0],)).fetchone()[0] if current_report else 0
    check("案件 11 件は断られ（「案件は 10 件までにしてください」）、保存されない", "案件は 10 件までにしてください" in eleven_html and eleven_count == 10, eleven_count)

    duties_max_length = json.loads(database.execute("SELECT settings_json FROM report_fields WHERE code = 'duties'").fetchone()[0])["max_length"]
    exact_text = "あ" * duties_max_length
    save_report(member_a2_client, current_period, "draft", [("P-0001", {"duties": exact_text})])
    a2_current = report_row("ippan_a2", current_period)
    saved_duties = database.execute("SELECT value_text, is_encrypted FROM report_field_values WHERE report_id = ? AND field_id = ?",
                                    (a2_current[0], field_id_by_code["duties"])).fetchone() if a2_current else None
    check("自由記述の上限ちょうど（%d 文字）は保存できる" % duties_max_length, saved_duties is not None and saved_duties[1] == 1, saved_duties and saved_duties[1])
    _status_code, _location, over_html = save_report(member_a2_client, current_period, "draft", [("P-0001", {"duties": exact_text + "い"})])
    still_exact = database.execute("SELECT value_text FROM report_field_values WHERE report_id = ? AND field_id = ?",
                                   (a2_current[0], field_id_by_code["duties"])).fetchone() if a2_current else None
    check("自由記述の上限を 1 文字超えると断られ、前の値のまま", "文字" in over_html and still_exact is not None and saved_duties is not None and still_exact[0] == saved_duties[0])

    # 同時の新規作成: 同じ DB のサーバーをもう 1 本立て、2 つの画面（別々のセッション）から同じ月を同時に作る
    second_port = free_port()
    second_base = "http://127.0.0.1:%d" % second_port
    second_server = subprocess.Popen(php_server_command(arguments.php, second_port),
                                     cwd=root_dir, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        server_ready = False
        for _attempt in range(75):
            try:
                if urllib.request.urlopen(second_base + "/health.php", timeout=2).read().strip() == b"ok":
                    server_ready = True
                    break
            except Exception:  # noqa: BLE001
                time.sleep(0.2)
        check("同じ DB を使う 2 本目のサーバーが動く（前提）", server_ready)
        race_results = []
        conflict_shown = False
        for race_login_id in ["dairi_a", "kacho_a", "leader_a"]:
            first_window = login(arguments.base, race_login_id, user_info_by_login_id[race_login_id])
            second_window = login(arguments.base, race_login_id, user_info_by_login_id[race_login_id])
            second_window.base = second_base
            window_form_list = []
            for window_client in (first_window, second_window):
                _status_code, _location, edit_html = window_client.get("/reports/edit.php?period=" + current_period)
                window_form_list.append({"csrf_token": csrf(edit_html), "request_key": rkey(edit_html), "period": current_period, "action": "draft",
                                         "b[0][project]": str(project_id_by_code["P-0001"]), "b[0][f][%d]" % field_id_by_code["duties"]: "同時に作った報告"})
            response_list = [None, None]

            def send(window_index):
                response_list[window_index] = (first_window, second_window)[window_index].post("/reports/save.php", window_form_list[window_index])

            # 1 つ目の画面は、報告を探したあとに待たせる（検証の印のあるサーバーだけが X-Verify-Pause を見る）。
            # そのあいだに 2 つ目の画面（2 本目のサーバー）が同じ月の報告を作る。1 つ目は、書き込みの中でそれに気づく
            first_window.headers["X-Verify-Pause"] = "report_save"
            first_sender = threading.Thread(target=send, args=(0,))
            first_sender.start()
            time.sleep(0.5)
            send(1)
            first_sender.join(30)
            first_window.headers.pop("X-Verify-Pause", None)
            report_count = database.execute("SELECT COUNT(*) FROM report_records WHERE user_id = ? AND period = ? AND is_legacy = 0 AND deleted_at IS NULL",
                                            (user_info_by_login_id[race_login_id]["id"], current_period)).fetchone()[0]
            status_list = [response[0] if response else None for response in response_list]
            race_results.append((race_login_id, report_count, status_list))
            conflict_shown = conflict_shown or any(response and "同じ月の報告が別の画面で作られていました" in response[2] for response in response_list)
        check("2 つの画面から同時に新規作成しても、報告は 1 件で、エラー画面にならない（3 人）",
              all(report_count == 1 and all(status_code in (200, 303) for status_code in status_list) for _login_id, report_count, status_list in race_results), race_results)
        check("同時に作ったとき、あとの画面には「同じ月の報告が別の画面で作られていました」と出て、入力が残る", conflict_shown, race_results)
    finally:
        second_server.terminate()
        try:
            second_server.wait(timeout=10)
        except Exception:  # noqa: BLE001
            second_server.kill()

    # ================================================================ G-05 報告の詳細
    section("G-05", "報告の詳細（コメントの二重送信・自分のチェック欄・回答待ちのままの確認完了・他人のコメントの削除）")
    save_report(member_a1_client, previous_period, "submit", [("P-0002", {"duties": "A一般1の作業", "progress": "on_track", "continuation": "continue"})],
                report_values={"trouble_note": "特になし"})
    submitted_report = report_row("ippan_a1", previous_period)
    if submitted_report is None or submitted_report[1] != "submitted":
        progress_value_list = [row[0] for row in database.execute("SELECT o.code FROM report_field_options o JOIN report_fields f ON f.id = o.field_id WHERE f.code = 'progress' ORDER BY o.sort_order")]
        continuation_value_list = [row[0] for row in database.execute("SELECT o.code FROM report_field_options o JOIN report_fields f ON f.id = o.field_id WHERE f.code = 'continuation' ORDER BY o.sort_order")]
        save_report(member_a1_client, previous_period, "submit", [("P-0002", {"duties": "A一般1の作業", "progress": progress_value_list[0], "continuation": continuation_value_list[0]})],
                    report_values={"trouble_note": "特になし"})
        submitted_report = report_row("ippan_a1", previous_period)
    check("A一般1 の前月の報告を提出（前提）", submitted_report is not None and submitted_report[1] == "submitted", submitted_report)
    report_id = submitted_report[0] if submitted_report else 0
    leader_client = login(arguments.base, "leader_a", user_info_by_login_id["leader_a"])
    _status_code, _location, view_html = leader_client.get("/reports/view.php?id=%d" % report_id)
    comment_form = {"csrf_token": csrf(view_html), "request_key": rkey(view_html), "action": "comment", "report_id": report_id,
                    "field_id": field_id_by_code["trouble_note"], "report_project_id": 0, "comment_type": "request", "comment": "見込みを教えてください"}
    leader_client.post("/reports/act.php", comment_form)
    leader_client.post("/reports/act.php", comment_form)
    comment_count = database.execute("SELECT COUNT(*) FROM report_field_comments WHERE report_id = ? AND deleted_at IS NULL", (report_id,)).fetchone()[0]
    check("同じ送信の番号でコメントを 2 回送っても 1 件", comment_count == 1, comment_count)
    leader_comment_id = database.execute("SELECT id FROM report_field_comments WHERE report_id = ? ORDER BY id LIMIT 1", (report_id,)).fetchone()[0]
    _status_code, _location, member_view_html = member_a1_client.get("/reports/view.php?id=%d" % report_id)
    delete_status, _location, _page = member_a1_client.post("/reports/act.php", {"csrf_token": csrf(member_view_html), "request_key": rkey(member_view_html),
                                                                                 "action": "comment_delete", "report_id": report_id, "comment_id": leader_comment_id})
    check("ほかの人のコメントは消せない（403）", delete_status == 403 and database.execute("SELECT deleted_at FROM report_field_comments WHERE id = ?", (leader_comment_id,)).fetchone()[0] is None, delete_status)
    check("回答待ちのコメントがある（前提）", database.execute("SELECT thread_status FROM report_field_comments WHERE id = ?", (leader_comment_id,)).fetchone()[0] == "open")
    manager_client = login(arguments.base, "kacho_a", user_info_by_login_id["kacho_a"])
    _status_code, _location, manager_view_html = manager_client.get("/reports/view.php?id=%d" % report_id)
    manager_client.post("/reports/act.php", {"csrf_token": csrf(manager_view_html), "request_key": rkey(manager_view_html), "action": "confirm", "report_id": report_id})
    check("回答待ちがあっても、課長は確認完了にできる", report_row("ippan_a1", previous_period)[1] == "confirmed")

    save_report(leader_client, previous_period, "submit", [], report_values={"trouble_note": "特になし"})
    leader_report = report_row("leader_a", previous_period)
    check("リーダー（確認者）の自分の報告を提出（前提）", leader_report is not None and leader_report[1] == "submitted", leader_report)
    _status_code, _location, own_view_html = leader_client.get("/reports/view.php?id=%d" % (leader_report[0] if leader_report else 0))
    check("自分の報告には、自分のチェックのボタンが出ない", 'value="check"' not in own_view_html and "チェックする" not in own_view_html)

    # ================================================================ G-07 臨時の調査への回答
    section("G-07", "臨時の調査への回答（下書きの依頼・締め切り後・二重送信・暗号化）")

    def create_survey(title_text, publish):
        _status_code, _location, survey_html = manager_client.get("/surveys/survey_edit.php")
        due_text = (datetime.date.today() + datetime.timedelta(days=3)).isoformat()
        _status_code, survey_location, _page = manager_client.post("/surveys/survey_edit.php", {
            "csrf_token": csrf(survey_html), "request_key": rkey(survey_html), "action": "save_meta", "department_id": str(department_a_id),
            "title": title_text, "requester": "総務", "purpose": "検証", "due_at": due_text, "result_visibility": "detail", "target_type": "all"})
        survey_match = re.search(r"id=(\d+)", survey_location or "")
        survey_id = int(survey_match.group(1)) if survey_match else 0
        _status_code, _location, survey_html = manager_client.get("/surveys/survey_edit.php?id=%d" % survey_id)
        manager_client.post("/surveys/survey_edit.php?id=%d" % survey_id, {"csrf_token": csrf(survey_html), "request_key": rkey(survey_html), "action": "add_custom",
                                                                            "q_label": "感想", "q_type": "multi_text", "q_conf": "internal", "q_required": "1"})
        if publish:
            _status_code, _location, survey_html = manager_client.get("/surveys/survey_edit.php?id=%d" % survey_id)
            manager_client.post("/surveys/survey_edit.php?id=%d" % survey_id, {"csrf_token": csrf(survey_html), "request_key": rkey(survey_html), "action": "publish"})
        return survey_id

    draft_survey_id = create_survey("下書きの調査", False)
    check("下書きの依頼の回答画面は開けない（404）", draft_survey_id > 0 and member_a1_client.get("/surveys/answer.php?id=%d" % draft_survey_id)[0] == 404)
    open_survey_id = create_survey("公開した調査", True)
    _status_code, _location, answer_html = member_a1_client.get("/surveys/answer.php?id=%d" % open_survey_id)
    question_id_list = re.findall(r'name="q\[(\d+)\]"', answer_html)
    answer_form = {"csrf_token": csrf(answer_html), "request_key": rkey(answer_html), "id": open_survey_id,
                   "q[%s]" % (question_id_list[0] if question_id_list else "0"): "とても役に立った（検証の感想）"}
    member_a1_client.post("/surveys/answer.php?id=%d" % open_survey_id, answer_form)
    member_a1_client.post("/surveys/answer.php?id=%d" % open_survey_id, answer_form)
    response_count = database.execute("SELECT COUNT(*) FROM adhoc_responses WHERE request_id = ? AND user_id = ?",
                                      (open_survey_id, user_info_by_login_id["ippan_a1"]["id"])).fetchone()[0]
    check("同じ送信の番号で 2 回送っても、回答は 1 件", response_count == 1, response_count)
    encrypted_row = database.execute("SELECT v.value_text, v.is_encrypted FROM adhoc_response_values v JOIN adhoc_responses r ON r.id = v.response_id WHERE r.request_id = ?",
                                     (open_survey_id,)).fetchone()
    check("回答の自由記述は暗号化されている", encrypted_row is not None and encrypted_row[1] == 1 and "検証の感想" not in encrypted_row[0], encrypted_row and encrypted_row[1])
    closed_survey_id = create_survey("締め切る調査", True)
    _status_code, _location, closed_answer_html = member_a2_client.get("/surveys/answer.php?id=%d" % closed_survey_id)
    yesterday_text = (datetime.date.today() - datetime.timedelta(days=1)).isoformat()
    database.execute("UPDATE adhoc_requests SET due_at = ? WHERE id = ?", (yesterday_text, closed_survey_id))   # 期限が過ぎたことにする
    closed_question_list = re.findall(r'name="q\[(\d+)\]"', closed_answer_html)
    closed_status, _location, closed_post_html = member_a2_client.post("/surveys/answer.php?id=%d" % closed_survey_id, {
        "csrf_token": csrf(closed_answer_html), "request_key": rkey(closed_answer_html), "id": closed_survey_id,
        "q[%s]" % (closed_question_list[0] if closed_question_list else "0"): "締め切り後の回答"})   # 回答の画面を開く・送ると、期限を過ぎた調査が締め切られる
    check("期限を過ぎると自動で締め切られる", database.execute("SELECT status FROM adhoc_requests WHERE id = ?", (closed_survey_id,)).fetchone()[0] == "closed")
    check("締め切り後は回答できない（400「この調査は締め切られました。」）", closed_status == 400 and "締め切られました" in closed_post_html
          and database.execute("SELECT COUNT(*) FROM adhoc_responses WHERE request_id = ?", (closed_survey_id,)).fetchone()[0] == 0, closed_status)

    # ================================================================ G-08 提出状況
    section("G-08", "提出状況（ホームとの件数の一致・下書きへのリンク・課を移った人）")
    _status_code, _location, leader_home_html = leader_client.get("/index.php")
    home_period = re.search(r'/status/index\.php\?period=(\d{4}-\d{2})', leader_home_html)
    home_unchecked = re.search(r"未チェック <strong>(\d+)</strong>件", leader_home_html)
    status_period = home_period.group(1) if home_period else previous_period
    _status_code, _location, status_html = leader_client.get("/status/index.php?period=%s&dept=%d" % (status_period, department_a_id))
    status_unchecked = re.search(r"自分が未チェック <strong>(\d+)</strong>", status_html)
    check("ホームの未チェックの件数と、提出状況の件数が一致する",
          home_unchecked is not None and status_unchecked is not None and home_unchecked.group(1) == status_unchecked.group(1),
          (home_unchecked and home_unchecked.group(1), status_unchecked and status_unchecked.group(1)))
    a2_draft = report_row("ippan_a2", current_period)
    _status_code, _location, current_status_html = leader_client.get("/status/index.php?period=%s&dept=%d" % (current_period, department_a_id))
    check("下書きの報告（A一般2）の中身へのリンクが出ない", a2_draft is not None and a2_draft[1] == "draft" and ("view.php?id=%d" % a2_draft[0]) not in current_status_html, a2_draft)
    database.execute("UPDATE users SET department_id = ? WHERE login_id = 'ippan_a1'", (seed_info["dept_b"],))   # A一般1 が B課に移る
    _status_code, _location, moved_status_html = manager_client.get("/status/index.php?period=%s&dept=%d" % (previous_period, department_a_id))
    check("課を移った人の、移る前の月の報告が、元の課の提出状況に出る", "A一般1" in moved_status_html and ("view.php?id=%d" % report_id) in moved_status_html)
    database.execute("UPDATE users SET department_id = ? WHERE login_id = 'ippan_a1'", (department_a_id,))

    # ================================================================ G-03 ホームの未チェックの数え方
    section("G-03", "ホームの未チェックの数え方（自分の報告・チェック・更新あり）")
    option_code_by_field = {}
    for option_field_code in ("progress", "continuation"):
        option_code_by_field[option_field_code] = database.execute(
            "SELECT o.code FROM report_field_options o JOIN report_fields f ON f.id = o.field_id WHERE f.code = ? AND o.is_active = 1 ORDER BY o.sort_order, o.id LIMIT 1",
            (option_field_code,)).fetchone()[0]

    def submit_full(client, period_text, duties_text):
        return save_report(client, period_text, "submit", [("P-0001", {"duties": duties_text, "progress": option_code_by_field["progress"],
                                                                       "continuation": option_code_by_field["continuation"]})], report_values={"trouble_note": "特になし"})

    def home_unchecked_count():
        _status_code, _location, home_html = leader_client.get("/index.php")
        count_match = re.search(r"A課 [^<]*（確認）</h2>\s*<p>未チェック <strong>(\d+)</strong>件", home_html)
        period_match = re.search(r"/status/index\.php\?period=(\d{4}-\d{2})&amp;dept=%d" % department_a_id, home_html)
        return (int(count_match.group(1)) if count_match else -1), (period_match.group(1) if period_match else "")

    count_before, home_target_period = home_unchecked_count()
    check("ホームに A課の未チェックの件数が出る（前提）", count_before >= 0 and home_target_period != "", (count_before, home_target_period))
    submit_full(leader_client, home_target_period, "リーダー自身の作業")
    count_after_own, _period = home_unchecked_count()
    check("自分の報告を提出しても、自分の未チェックには数えない", report_row("leader_a", home_target_period)[1] == "submitted" and count_after_own == count_before, (count_before, count_after_own))
    member_b_client = login(arguments.base, "dairi_a", user_info_by_login_id["dairi_a"])
    submit_full(member_b_client, home_target_period, "代理の作業")
    count_after_member, _period = home_unchecked_count()
    check("ほかの人が提出すると、未チェックが 1 件増える", count_after_member == count_after_own + 1, (count_after_own, count_after_member))
    dairi_report = report_row("dairi_a", home_target_period)
    _status_code, _location, dairi_view_html = leader_client.get("/reports/view.php?id=%d" % dairi_report[0])
    leader_client.post("/reports/act.php", {"csrf_token": csrf(dairi_view_html), "request_key": rkey(dairi_view_html), "action": "check", "report_id": dairi_report[0]})
    count_after_check, _period = home_unchecked_count()
    check("チェックすると、未チェックが 1 件減る", count_after_check == count_after_member - 1, (count_after_member, count_after_check))
    save_report(member_b_client, home_target_period, "save", [("P-0001", {"duties": "代理の作業（直した）", "progress": option_code_by_field["progress"],
                                                                          "continuation": option_code_by_field["continuation"]})], report_values={"trouble_note": "特になし"})   # 提出済みの報告は「保存」で直す
    check("提出後に直すと、更新の回数が上がる（前提）", database.execute("SELECT revision FROM report_records WHERE id = ?", (dairi_report[0],)).fetchone()[0] >= 1)
    count_after_update, _period = home_unchecked_count()
    check("チェックしたあとに本人が直す（更新あり）と、ふたたび未チェックに数える", count_after_update == count_after_check + 1, (count_after_check, count_after_update))

    # ================================================================ G-01 期限切れの記憶の Cookie
    section("G-01", "ログイン（期限切れの記憶の Cookie）")
    remember_client = Client(arguments.base)
    password_step(remember_client, "ippan_b1", user_info_by_login_id["ippan_b1"]["password"])
    _status_code, _location, step_html = remember_client.get("/login.php?step=2")
    remember_client.post("/login.php", {"csrf_token": csrf(step_html), "action": "code", "code": next_login_code("ippan_b1", user_info_by_login_id["ippan_b1"]["totp"]), "remember": "1"})
    remembered_cookie_list = [cookie for cookie in remember_client.jar if cookie.name == "mr_device"]
    check("記憶の Cookie が付く（前提）", len(remembered_cookie_list) == 1)

    def password_with_cookie():
        cookie_client = Client(arguments.base)
        for remembered_cookie in remembered_cookie_list:
            cookie_client.jar.set_cookie(remembered_cookie)
        return password_step(cookie_client, "ippan_b1", user_info_by_login_id["ippan_b1"]["password"])[1]

    check("期限内の記憶の Cookie では、コードを聞かれない（前提）", "step=2" not in password_with_cookie())
    database.execute("UPDATE remembered_devices SET expires_at = datetime('now', '-1 day') WHERE user_id = ?", (user_info_by_login_id["ippan_b1"]["id"],))
    check("期限切れの記憶の Cookie では、2 段階認証を飛ばせない", "step=2" in password_with_cookie())

    # ================================================================ G-20 検索
    section("G-20", "検索（キーワードで探す報告が上限を超えたときの案内）")
    decrypt_limit = 2000
    filler_user_count = 100
    filler_period_list = [month_shift(current_period, -month_offset) for month_offset in range(2, 23)]   # 21 か月 × 100 人 = 2,100 件
    for filler_number in range(filler_user_count):
        filler_login_id = "filler%03d" % filler_number
        database.execute("INSERT INTO users (username, password_hash, role_title, is_admin, created_at, login_id, display_name, department_id, updated_at) VALUES (?, 'x', '一般', 0, ?, ?, ?, ?, ?)",
                         (filler_login_id, now_text, filler_login_id, "件数用%03d" % filler_number, department_a_id, now_text))
    filler_user_id_list = [row[0] for row in database.execute("SELECT id FROM users WHERE login_id LIKE 'filler%'")]
    database.executemany("INSERT INTO report_records (user_id, department_id, period, status, submitted_at, created_at, updated_at) VALUES (?, ?, ?, 'submitted', ?, ?, ?)",
                         [(filler_user_id, department_a_id, filler_period, now_text, now_text, now_text) for filler_user_id in filler_user_id_list for filler_period in filler_period_list])
    _status_code, _location, search_html = manager_client.get("/reports/search.php?search=1&dept=%d&from=%s&to=%s&q=%s" % (
        department_a_id, filler_period_list[-1], current_period, urllib.request.quote("作業")))
    check("キーワードで探す報告が上限（%d 件）を超えると、絞り込むよう案内し、探さない" % decrypt_limit, "上限（%d 件）を超えています" % decrypt_limit in search_html, re.findall(r"flash-error[^>]*>([^<]{0,80})", search_html)[:1])

    return summary()


if __name__ == "__main__":
    sys.exit(main())
