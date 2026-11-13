#!/usr/bin/env python3
"""案件台帳: 案件コードと、システムに入れない案件の情報（客先名・案件名・期間）を、手元で管理する道具。

システム（月次報告）は客先名を持たず、案件コード（P-0001 …）と社内向けの呼び名だけを持つ。
コードと客先・案件の対応を人の手（表計算など）で管理すると食い違いが出るため、この道具で台帳を管理し、
システムから出力した案件の一覧（G-13 の「案件の一覧を CSV に出力」）と突き合わせて食い違いを見つける。

台帳は SQLite のファイルで、リポジトリの外に置く（客先名が入るため。リポジトリの中は断る）。
台帳の変更はこの道具のコマンドからだけ行い、変更の履歴を残す。

    python3 tools/lib/project_ledger.py --ledger ~/report-ledger/project_ledger.sqlite init
    python3 tools/lib/project_ledger.py --ledger … import-system projects_20261113-1000.csv
    python3 tools/lib/project_ledger.py --ledger … set P-0001 --customer "（客先名）" --project-name "在庫管理の改修" --start 2026-04-01
    python3 tools/lib/project_ledger.py --ledger … end P-0001 --end 2026-12-31
    python3 tools/lib/project_ledger.py --ledger … list
    python3 tools/lib/project_ledger.py --ledger … check          # 食い違いの一覧（あれば終了コード 1）
    python3 tools/lib/project_ledger.py --ledger … history P-0001
    python3 tools/lib/project_ledger.py --ledger … export-csv ledger.csv

台帳の場所は --ledger か、環境変数 PROJECT_LEDGER_PATH で指定する。
Python 3.8 以上の標準ライブラリだけで動く。
"""
import argparse
import csv
import datetime
import getpass
import io
import os
import re
import sqlite3
import sys
import unicodedata

REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
PROJECT_CODE_PATTERN = re.compile(r"^P-\d{4,}$")
DATE_PATTERN = re.compile(r"^\d{4}-\d{2}-\d{2}$")
STALE_IMPORT_DAYS = 31        # システムの一覧の取り込みがこれより古ければ知らせる
SYSTEM_CSV_COLUMNS = ["案件コード", "社内向けの呼び名", "課", "状態", "終了日", "作成日", "最後に報告で使われた月", "その月の人数"]

SCHEMA_STATEMENTS = [
    """CREATE TABLE IF NOT EXISTS ledger_projects (
        code TEXT PRIMARY KEY,                 -- 案件コード（システムと同じ）
        customer_name TEXT NOT NULL DEFAULT '',-- 客先名（システムには入れない）
        project_name TEXT NOT NULL DEFAULT '', -- 案件名（システムには入れない）
        start_date TEXT NOT NULL DEFAULT '',   -- 開始日 YYYY-MM-DD
        end_date TEXT NOT NULL DEFAULT '',     -- 終了日 YYYY-MM-DD（空なら継続中）
        note TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )""",
    """CREATE TABLE IF NOT EXISTS system_projects (
        code TEXT PRIMARY KEY,                 -- 最後に取り込んだシステムの一覧（そのまま写す）
        alias TEXT NOT NULL,
        department_name TEXT NOT NULL,
        is_active INTEGER NOT NULL,
        ended_on TEXT NOT NULL,
        created_on TEXT NOT NULL,
        last_used_period TEXT NOT NULL,
        last_used_user_count INTEGER NOT NULL
    )""",
    """CREATE TABLE IF NOT EXISTS ledger_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL,
        changed_field TEXT NOT NULL,
        old_value TEXT NOT NULL,
        new_value TEXT NOT NULL,
        changed_by TEXT NOT NULL,
        changed_at TEXT NOT NULL
    )""",
    """CREATE TABLE IF NOT EXISTS ledger_state (
        name TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )""",
]


class LedgerError(Exception):
    """利用者に知らせて止める誤り（終了コード 2）"""


def now_text():
    return datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def normalize_for_compare(text):
    """全角・半角、大文字・小文字、空白の違いをならす（重複や客先名の混入を探すため）"""
    return re.sub(r"\s+", "", unicodedata.normalize("NFKC", text)).lower()


def resolve_ledger_path(ledger_path_argument):
    ledger_path = ledger_path_argument or os.environ.get("PROJECT_LEDGER_PATH", "")
    if not ledger_path:
        raise LedgerError("台帳の場所を --ledger か環境変数 PROJECT_LEDGER_PATH で指定してください")
    ledger_path = os.path.abspath(os.path.expanduser(ledger_path))
    repo_root_with_separator = REPO_ROOT.rstrip(os.sep) + os.sep
    if os.path.normcase(ledger_path).startswith(os.path.normcase(repo_root_with_separator)):
        raise LedgerError("台帳はリポジトリの外に置いてください（客先名が入るため）: " + ledger_path)
    return ledger_path


def open_ledger(ledger_path, must_exist=True):
    if must_exist and not os.path.exists(ledger_path):
        raise LedgerError("台帳がありません。先に init で作ってください: " + ledger_path)
    ledger_directory = os.path.dirname(ledger_path)
    if ledger_directory and not os.path.isdir(ledger_directory):
        os.makedirs(ledger_directory, exist_ok=True)
    database = sqlite3.connect(ledger_path)
    database.row_factory = sqlite3.Row
    for statement in SCHEMA_STATEMENTS:
        database.execute(statement)
    database.commit()
    if os.name == "posix":
        os.chmod(ledger_path, 0o600)   # 本人だけが読める
    return database


def record_history(database, code, changed_field, old_value, new_value):
    database.execute(
        "INSERT INTO ledger_history (code, changed_field, old_value, new_value, changed_by, changed_at) VALUES (?, ?, ?, ?, ?, ?)",
        (code, changed_field, old_value, new_value, getpass.getuser(), now_text()))


def validate_code(code):
    if PROJECT_CODE_PATTERN.match(code) is None:
        raise LedgerError("案件コードの形が違います（例: P-0001）: " + code)


def validate_date(date_text, label):
    if date_text == "":
        return
    if DATE_PATTERN.match(date_text) is None:
        raise LedgerError("%sは YYYY-MM-DD の形で入れてください: %s" % (label, date_text))
    try:
        datetime.date.fromisoformat(date_text)
    except ValueError:
        raise LedgerError("%sが日付として正しくありません: %s" % (label, date_text))


# ---------------------------------------------------------------- コマンド

def command_init(arguments):
    ledger_path = resolve_ledger_path(arguments.ledger)
    if os.path.exists(ledger_path):
        raise LedgerError("台帳はもうあります: " + ledger_path)
    open_ledger(ledger_path, must_exist=False).close()
    print("台帳を作りました: " + ledger_path)
    return 0


def read_system_csv(csv_path):
    """システムの案件の一覧（G-13 の CSV）を読む。戻り値: [{列名: 値}, ...]"""
    raw_bytes = open(csv_path, "rb").read()
    csv_text = raw_bytes.decode("utf-8-sig")
    csv_rows = list(csv.reader(io.StringIO(csv_text)))
    header_index = None
    for row_index, csv_row in enumerate(csv_rows):
        if csv_row[:1] == ["案件コード"]:
            header_index = row_index
            break
    if header_index is None or csv_rows[header_index] != SYSTEM_CSV_COLUMNS:
        raise LedgerError("システムの案件の一覧（G-13 の「案件の一覧を CSV に出力」）ではないようです: " + csv_path)
    system_project_list = []
    for csv_row in csv_rows[header_index + 1:]:
        if not csv_row or all(cell == "" for cell in csv_row):
            continue
        if len(csv_row) != len(SYSTEM_CSV_COLUMNS):
            raise LedgerError("列の数が違う行があります: " + ",".join(csv_row))
        system_project = dict(zip(SYSTEM_CSV_COLUMNS, csv_row))
        validate_code(system_project["案件コード"])
        system_project_list.append(system_project)
    return system_project_list


def command_import_system(arguments):
    ledger_path = resolve_ledger_path(arguments.ledger)
    database = open_ledger(ledger_path)
    system_project_list = read_system_csv(arguments.csv_path)
    added_code_list = []
    with database:
        database.execute("DELETE FROM system_projects")
        for system_project in system_project_list:
            code = system_project["案件コード"]
            database.execute(
                "INSERT INTO system_projects (code, alias, department_name, is_active, ended_on, created_on, last_used_period, last_used_user_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                (code, system_project["社内向けの呼び名"], system_project["課"], 1 if system_project["状態"] == "有効" else 0,
                 system_project["終了日"], system_project["作成日"], system_project["最後に報告で使われた月"],
                 int(system_project["その月の人数"] or 0)))
            if database.execute("SELECT 1 FROM ledger_projects WHERE code = ?", (code,)).fetchone() is None:
                # システムで新しく作られた案件: 台帳に行を作る（客先名などは set で入れてもらう）
                database.execute("INSERT INTO ledger_projects (code, created_at, updated_at) VALUES (?, ?, ?)", (code, now_text(), now_text()))
                record_history(database, code, "（作成）", "", "システムの一覧から取り込み")
                added_code_list.append(code)
        database.execute("INSERT INTO ledger_state (name, value) VALUES ('last_import_at', ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value", (now_text(),))
        database.execute("INSERT INTO ledger_state (name, value) VALUES ('last_import_file', ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value",
                         (os.path.basename(arguments.csv_path),))
    print("システムの一覧を取り込みました: %d 件" % len(system_project_list))
    if added_code_list:
        print("台帳に足した案件（客先名・案件名を set で入れてください）: " + ", ".join(added_code_list))
    return 0


def command_set(arguments):
    ledger_path = resolve_ledger_path(arguments.ledger)
    database = open_ledger(ledger_path)
    validate_code(arguments.code)
    new_value_by_column = {}
    for column_name, argument_value, label in [
        ("customer_name", arguments.customer, "客先名"), ("project_name", arguments.project_name, "案件名"),
        ("start_date", arguments.start, "開始日"), ("end_date", arguments.end, "終了日"), ("note", arguments.note, "備考"),
    ]:
        if argument_value is None:
            continue
        argument_value = argument_value.strip()
        if column_name in ("start_date", "end_date"):
            validate_date(argument_value, label)
        if len(argument_value) > 200:
            raise LedgerError("%sは 200 文字以内にしてください" % label)
        new_value_by_column[column_name] = argument_value
    if not new_value_by_column:
        raise LedgerError("変える項目を指定してください（--customer --project-name --start --end --note）")
    ledger_row = database.execute("SELECT * FROM ledger_projects WHERE code = ?", (arguments.code,)).fetchone()
    if ledger_row is None and not arguments.create:
        raise LedgerError("台帳にない案件です。システムの一覧を import-system で取り込むか、--create を付けてください: " + arguments.code)
    start_date = new_value_by_column.get("start_date", ledger_row["start_date"] if ledger_row else "")
    end_date = new_value_by_column.get("end_date", ledger_row["end_date"] if ledger_row else "")
    if start_date and end_date and start_date > end_date:
        raise LedgerError("開始日が終了日より後になっています")
    with database:
        if ledger_row is None:
            database.execute("INSERT INTO ledger_projects (code, created_at, updated_at) VALUES (?, ?, ?)", (arguments.code, now_text(), now_text()))
            record_history(database, arguments.code, "（作成）", "", "手で作成")
            ledger_row = database.execute("SELECT * FROM ledger_projects WHERE code = ?", (arguments.code,)).fetchone()
        for column_name, new_value in new_value_by_column.items():
            if ledger_row[column_name] == new_value:
                continue
            database.execute("UPDATE ledger_projects SET %s = ?, updated_at = ? WHERE code = ?" % column_name, (new_value, now_text(), arguments.code))
            record_history(database, arguments.code, column_name, ledger_row[column_name], new_value)
    print("台帳を更新しました: " + arguments.code)
    return 0


def command_end(arguments):
    arguments.customer = arguments.project_name = arguments.start = arguments.note = None
    arguments.create = False
    return command_set(arguments)


def collect_problems(database):
    """食い違いの一覧。戻り値: [(種類, 案件コード, 内容), ...]"""
    problem_list = []
    today_text = datetime.date.today().isoformat()
    ledger_row_by_code = {row["code"]: row for row in database.execute("SELECT * FROM ledger_projects ORDER BY code")}
    system_row_by_code = {row["code"]: row for row in database.execute("SELECT * FROM system_projects ORDER BY code")}

    last_import_row = database.execute("SELECT value FROM ledger_state WHERE name = 'last_import_at'").fetchone()
    if last_import_row is None:
        problem_list.append(("取り込み", "", "システムの一覧をまだ取り込んでいません（import-system）"))
    else:
        last_import_date = datetime.datetime.strptime(last_import_row["value"], "%Y-%m-%d %H:%M:%S").date()
        if (datetime.date.today() - last_import_date).days > STALE_IMPORT_DAYS:
            problem_list.append(("取り込み", "", "システムの一覧の取り込みが %d 日より前です（%s）。新しい一覧を取り込んでください" % (STALE_IMPORT_DAYS, last_import_row["value"])))

    for code, ledger_row in ledger_row_by_code.items():
        system_row = system_row_by_code.get(code)
        if last_import_row is not None and system_row is None:
            problem_list.append(("システムにない", code, "台帳にはあるが、システムの一覧にない案件コード"))
        if ledger_row["customer_name"] == "" or ledger_row["project_name"] == "":
            problem_list.append(("台帳の入力漏れ", code, "客先名か案件名が空（set で入れてください）"))
        if system_row is None:
            continue
        has_ledger_ended = ledger_row["end_date"] != "" and ledger_row["end_date"] < today_text
        if has_ledger_ended and system_row["is_active"] == 1:
            problem_list.append(("終了の食い違い", code, "台帳では %s に終了したが、システムでは有効のまま（G-13 で終了にしてください）" % ledger_row["end_date"]))
        if system_row["is_active"] == 0 and ledger_row["end_date"] == "":
            problem_list.append(("終了の食い違い", code, "システムでは終了（%s）だが、台帳に終了日がない（end で入れてください）" % (system_row["ended_on"] or "日付なし")))
        if system_row["is_active"] == 1 and system_row["last_used_period"] == "" and ledger_row["start_date"] != "" and ledger_row["start_date"][:7] < today_text[:7]:
            problem_list.append(("使われていない", code, "開始日を過ぎているが、まだ報告で使われていない"))
        alias_for_compare = normalize_for_compare(system_row["alias"])
        for secret_text in (ledger_row["customer_name"], ledger_row["project_name"]):
            if len(normalize_for_compare(secret_text)) >= 2 and normalize_for_compare(secret_text) in alias_for_compare:
                problem_list.append(("呼び名に客先の情報", code, "システムの呼び名に、台帳の客先名か案件名が入っています（G-13 で呼び名を直してください）"))
                break
    for code in system_row_by_code:
        if code not in ledger_row_by_code:
            problem_list.append(("台帳にない", code, "システムにあるが、台帳にない（import-system で足せます）"))

    code_list_by_identity = {}
    for code, ledger_row in ledger_row_by_code.items():
        if ledger_row["customer_name"] and ledger_row["project_name"]:
            identity = (normalize_for_compare(ledger_row["customer_name"]), normalize_for_compare(ledger_row["project_name"]))
            code_list_by_identity.setdefault(identity, []).append(code)
    for duplicated_code_list in code_list_by_identity.values():
        if len(duplicated_code_list) > 1:
            problem_list.append(("重複", ", ".join(duplicated_code_list), "同じ客先名・案件名に、複数の案件コードがあります"))
    return problem_list


def command_check(arguments):
    ledger_path = resolve_ledger_path(arguments.ledger)
    database = open_ledger(ledger_path)
    problem_list = collect_problems(database)
    if not problem_list:
        print("食い違いはありません")
        return 0
    print("食い違い: %d 件" % len(problem_list))
    for problem_kind, code, problem_text in problem_list:
        print("[%s] %s %s" % (problem_kind, code, problem_text))
    return 1


def command_list(arguments):
    ledger_path = resolve_ledger_path(arguments.ledger)
    database = open_ledger(ledger_path)
    system_row_by_code = {row["code"]: row for row in database.execute("SELECT * FROM system_projects")}
    for ledger_row in database.execute("SELECT * FROM ledger_projects ORDER BY code"):
        system_row = system_row_by_code.get(ledger_row["code"])
        system_state_text = "システムにない" if system_row is None else ("有効" if system_row["is_active"] == 1 else "終了")
        print("%s\t%s\t%s\t%s〜%s\tシステム: %s\t呼び名: %s" % (
            ledger_row["code"], ledger_row["customer_name"] or "（未入力）", ledger_row["project_name"] or "（未入力）",
            ledger_row["start_date"], ledger_row["end_date"], system_state_text, system_row["alias"] if system_row else ""))
    return 0


def command_history(arguments):
    ledger_path = resolve_ledger_path(arguments.ledger)
    database = open_ledger(ledger_path)
    validate_code(arguments.code)
    for history_row in database.execute("SELECT * FROM ledger_history WHERE code = ? ORDER BY id", (arguments.code,)):
        print("%s\t%s\t%s\t%s → %s" % (history_row["changed_at"], history_row["changed_by"], history_row["changed_field"],
                                       history_row["old_value"], history_row["new_value"]))
    return 0


def command_export_csv(arguments):
    ledger_path = resolve_ledger_path(arguments.ledger)
    database = open_ledger(ledger_path)
    output_path = os.path.abspath(arguments.output_path)
    if os.path.normcase(output_path).startswith(os.path.normcase(REPO_ROOT.rstrip(os.sep) + os.sep)):
        raise LedgerError("出力はリポジトリの外にしてください（客先名が入るため）: " + output_path)
    with open(output_path, "w", encoding="utf-8-sig", newline="") as output_file:
        csv_writer = csv.writer(output_file)
        csv_writer.writerow(["社外秘（客先名を含む。システムや共有の場所に置かない）"])
        csv_writer.writerow(["案件コード", "客先名", "案件名", "開始日", "終了日", "備考", "システムの状態", "システムの呼び名", "最後に報告で使われた月"])
        system_row_by_code = {row["code"]: row for row in database.execute("SELECT * FROM system_projects")}
        for ledger_row in database.execute("SELECT * FROM ledger_projects ORDER BY code"):
            system_row = system_row_by_code.get(ledger_row["code"])
            csv_writer.writerow([
                ledger_row["code"], ledger_row["customer_name"], ledger_row["project_name"], ledger_row["start_date"], ledger_row["end_date"],
                ledger_row["note"], "システムにない" if system_row is None else ("有効" if system_row["is_active"] == 1 else "終了"),
                system_row["alias"] if system_row else "", system_row["last_used_period"] if system_row else ""])
    if os.name == "posix":
        os.chmod(output_path, 0o600)
    print("台帳を書き出しました（見るためだけ。直すときは set を使ってください）: " + output_path)
    return 0


def build_argument_parser():
    argument_parser = argparse.ArgumentParser(description="案件台帳（客先名・案件名と案件コードの対応）を管理し、システムと突き合わせる")
    argument_parser.add_argument("--ledger", help="台帳のファイル（リポジトリの外。環境変数 PROJECT_LEDGER_PATH でも指定できる）")
    sub_parsers = argument_parser.add_subparsers(dest="command_name", required=True)

    sub_parsers.add_parser("init", help="台帳を作る").set_defaults(handler=command_init)

    import_parser = sub_parsers.add_parser("import-system", help="システムの案件の一覧（G-13 の CSV）を取り込む")
    import_parser.add_argument("csv_path")
    import_parser.set_defaults(handler=command_import_system)

    set_parser = sub_parsers.add_parser("set", help="台帳の案件の情報を変える")
    set_parser.add_argument("code")
    set_parser.add_argument("--customer", help="客先名")
    set_parser.add_argument("--project-name", help="案件名")
    set_parser.add_argument("--start", help="開始日 YYYY-MM-DD")
    set_parser.add_argument("--end", help="終了日 YYYY-MM-DD（空にするときは \"\"）")
    set_parser.add_argument("--note", help="備考")
    set_parser.add_argument("--create", action="store_true", help="台帳にない案件を作る（ふつうは import-system で足す）")
    set_parser.set_defaults(handler=command_set)

    end_parser = sub_parsers.add_parser("end", help="案件の終了日を入れる")
    end_parser.add_argument("code")
    end_parser.add_argument("--end", required=True, help="終了日 YYYY-MM-DD")
    end_parser.set_defaults(handler=command_end)

    sub_parsers.add_parser("list", help="台帳の一覧").set_defaults(handler=command_list)
    sub_parsers.add_parser("check", help="食い違いの一覧（あれば終了コード 1）").set_defaults(handler=command_check)

    history_parser = sub_parsers.add_parser("history", help="案件の変更の履歴")
    history_parser.add_argument("code")
    history_parser.set_defaults(handler=command_history)

    export_parser = sub_parsers.add_parser("export-csv", help="台帳を CSV に書き出す（見るため。リポジトリの外に）")
    export_parser.add_argument("output_path")
    export_parser.set_defaults(handler=command_export_csv)
    return argument_parser


def main(argument_list=None):
    arguments = build_argument_parser().parse_args(argument_list)
    try:
        return arguments.handler(arguments)
    except LedgerError as ledger_error:
        print("誤り: " + str(ledger_error), file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
