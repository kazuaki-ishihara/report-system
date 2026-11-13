#!/usr/bin/env python3
"""案件台帳の道具（tools/lib/project_ledger.py）と、G-13 の案件の一覧の CSV 出力の確認（ローカル検証環境専用）。

ローカルのサーバーから管理者で CSV を出力し、一時フォルダ（リポジトリの外）の台帳に取り込んで、食い違いの検出を確かめる。

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_project_ledger.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import json
import os
import shutil
import sqlite3
import subprocess
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, csrf, login, rkey, summary  # noqa: E402

TEST_DIR = os.path.dirname(os.path.abspath(__file__))
REPO_ROOT = os.path.abspath(os.path.join(TEST_DIR, "..", ".."))
LEDGER_TOOL_PATH = os.path.join(REPO_ROOT, "tools", "lib", "project_ledger.py")


def main():
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--base", default="http://127.0.0.1:8000")
    argument_parser.add_argument("--db", required=True)
    arguments = argument_parser.parse_args()
    seed_info = json.load(open(os.path.join(TEST_DIR, "output_dev_users.json"), encoding="utf-8"))
    user_info_by_login_id = seed_info["users"]
    database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)
    work_directory = tempfile.mkdtemp(prefix="project_ledger_test_")
    ledger_path = os.path.join(work_directory, "project_ledger.sqlite")

    def run_ledger_tool(*tool_arguments):
        """台帳の道具を動かす。戻り値: (終了コード, 出力)"""
        completed_process = subprocess.run(
            [sys.executable, "-I", "-X", "utf8", LEDGER_TOOL_PATH, "--ledger", ledger_path] + list(tool_arguments),
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, env=dict(os.environ, PYTHONIOENCODING="utf-8"))
        return completed_process.returncode, completed_process.stdout.decode("utf-8", "replace")

    try:
        # ---- G-13 の CSV 出力 ----
        admin_client = login(arguments.base, "admin01", user_info_by_login_id["admin01"])
        # 呼び名に客先名らしい文を入れておく（台帳と突き合わせて見つけられるか確かめる）
        status_code, _location, projects_html = admin_client.get("/admin/projects.php")
        project_p0001_id = database.execute("SELECT id FROM projects WHERE code = 'P-0001'").fetchone()[0]
        admin_client.post("/admin/projects.php", {"csrf_token": csrf(projects_html), "request_key": rkey(projects_html), "action": "update",
                                                   "id": str(project_p0001_id), "alias": "サンプル商事の在庫", "department_id": str(seed_info["dept_a"])})
        status_code, _location, _csv_text = admin_client.get("/admin/projects.php?csv=1")
        system_csv_bytes = admin_client.last_raw
        system_csv_text = system_csv_bytes.decode("utf-8-sig", "replace")
        check("管理者は案件の一覧を CSV で出力できる（BOM 付き・社外秘の見出し）",
              status_code == 200 and system_csv_bytes.startswith(b"\xef\xbb\xbf") and system_csv_text.startswith('"社外秘"') and "P-0001" in system_csv_text, status_code)
        check("CSV に客先名の列はない", "客先名" not in system_csv_text)
        member_client = login(arguments.base, "ippan_a1", user_info_by_login_id["ippan_a1"])
        status_code, _location, _page_html = member_client.get("/admin/projects.php?csv=1")
        check("管理者でない人は出力できない（403）", status_code == 403, status_code)
        export_log_count = database.execute("SELECT COUNT(*) FROM access_logs WHERE target = 'export:projects'").fetchone()[0]
        check("出力が記録に残る", export_log_count == 1, export_log_count)
        system_csv_path = os.path.join(work_directory, "projects.csv")
        open(system_csv_path, "wb").write(system_csv_bytes)

        # ---- 台帳の道具 ----
        exit_code, tool_output = run_ledger_tool("check")
        check("台帳がないときは誤りにする（終了コード 2）", exit_code == 2 and "init" in tool_output, (exit_code, tool_output))
        inside_repo_exit_code, inside_repo_output = subprocess.run(
            [sys.executable, "-I", "-X", "utf8", LEDGER_TOOL_PATH, "--ledger", os.path.join(REPO_ROOT, "app", "data", "ledger.sqlite"), "init"],
            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, env=dict(os.environ, PYTHONIOENCODING="utf-8")).returncode, ""
        check("台帳をリポジトリの中には作らない", inside_repo_exit_code == 2 and not os.path.exists(os.path.join(REPO_ROOT, "app", "data", "ledger.sqlite")))
        exit_code, tool_output = run_ledger_tool("init")
        check("台帳を作れる", exit_code == 0 and os.path.exists(ledger_path), tool_output)
        exit_code, tool_output = run_ledger_tool("import-system", system_csv_path)
        check("システムの一覧を取り込むと、案件が台帳に足される", exit_code == 0 and "P-0001" in tool_output and "P-0004" in tool_output, tool_output)
        exit_code, tool_output = run_ledger_tool("check")
        check("取り込んだだけでは、客先名・案件名の入力漏れを知らせる", exit_code == 1 and "台帳の入力漏れ" in tool_output, tool_output)

        run_ledger_tool("set", "P-0001", "--customer", "サンプル商事", "--project-name", "在庫管理の改修", "--start", "2026-04-01")
        run_ledger_tool("set", "P-0002", "--customer", "テスト工業", "--project-name", "販売システム", "--start", "2026-04-01", "--end", "2026-05-31")
        run_ledger_tool("set", "P-0003", "--customer", "サンプル商事", "--project-name", "在庫管理の改修")
        run_ledger_tool("set", "P-0004", "--customer", "別の会社", "--project-name", "保守")
        exit_code, tool_output = run_ledger_tool("check")
        check("呼び名に客先名が入っていれば知らせる", "[呼び名に客先の情報] P-0001" in tool_output, tool_output)
        check("台帳では終了したのにシステムで有効なら知らせる", "[終了の食い違い] P-0002" in tool_output, tool_output)
        check("同じ客先名・案件名の重複を知らせる", "[重複] P-0001, P-0003" in tool_output, tool_output)
        exit_code, tool_output = run_ledger_tool("set", "P-0002", "--start", "2026-13-01")
        check("日付の形が違えば変えない（終了コード 2）", exit_code == 2, (exit_code, tool_output))
        exit_code, tool_output = run_ledger_tool("set", "P-0002", "--start", "2026-07-01")
        check("開始日が終了日より後なら変えない", exit_code == 2 and "開始日が終了日より後" in tool_output, tool_output)
        exit_code, tool_output = run_ledger_tool("set", "P-9999", "--customer", "x")
        check("台帳にない案件は、--create なしでは作らない", exit_code == 2, (exit_code, tool_output))
        exit_code, tool_output = run_ledger_tool("history", "P-0001")
        check("変更の履歴が残る", exit_code == 0 and "customer_name" in tool_output and "サンプル商事" in tool_output, tool_output)
        export_path = os.path.join(work_directory, "ledger_export.csv")
        exit_code, tool_output = run_ledger_tool("export-csv", export_path)
        exported_text = open(export_path, encoding="utf-8-sig").read() if os.path.exists(export_path) else ""
        check("台帳を CSV に書き出せる", exit_code == 0 and "サンプル商事" in exported_text and "P-0004" in exported_text, tool_output)
        exit_code, tool_output = run_ledger_tool("export-csv", os.path.join(REPO_ROOT, "ledger_export.csv"))
        check("書き出しもリポジトリの中にはしない", exit_code == 2 and not os.path.exists(os.path.join(REPO_ROOT, "ledger_export.csv")))
    finally:
        shutil.rmtree(work_directory, ignore_errors=True)
    return summary()


if __name__ == "__main__":
    sys.exit(main())
