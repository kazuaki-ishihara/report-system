#!/usr/bin/env python3
"""メッセージ（画面に出す文言）の一覧を作る・確かめる道具（詳細設計書 G17_messages.md）。

コードの msg('…')（PHP）、M('…')（public/js/app.js）、JS_MESSAGES（app/lib/message.php）から
既定の文言を集め、app/lib/messages_catalog.php に書き出す。課長が文言を変える画面（G-17）はこの一覧を使う。

    python3 tools/lib/msg_catalog.py          # 一覧を作り直す
    python3 tools/lib/msg_catalog.py --check  # 一覧が最新か、msg() に文字列以外を渡していないかを確かめる（自動検証で使う）

Python 3.8 以上の標準ライブラリだけで動く。
"""
import argparse
import os
import re
import sys

REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
CATALOG_PATH = os.path.join(REPO_ROOT, "app", "lib", "messages_catalog.php")

# 文言を集めないファイル（G-01・G-02 の文言は固定。message.php は msg() の定義そのもの）
FIXED_FILE_NAMES = {"login.php", "password_reset.php", "auth.php", "messages_catalog.php"}

# ファイル名 → 画面の名前（課長の画面で、どこに出る文言かを示す）
SCREEN_NAME_BY_FILE_NAME = {
    "home.php": "G-03 ホーム",
    "report_edit.php": "G-04 報告の作成・編集", "report_save.php": "G-04 報告の作成・編集",
    "report_view.php": "G-05 報告の詳細", "report_act.php": "G-05 報告の詳細",
    "report_mine.php": "G-06 自分の報告",
    "survey_answer.php": "G-07 臨時の調査の回答",
    "status_index.php": "G-08 提出状況",
    "status_compare.php": "G-09 横並び・まとめ",
    "export.php": "G-10 出力",
    "survey_edit.php": "G-11 臨時の調査の管理", "survey_manage.php": "G-11 臨時の調査の管理", "survey_result.php": "G-11 臨時の調査の管理",
    "admin_fields.php": "G-12 項目マスタ", "admin_field_options.php": "G-12 項目マスタ", "admin_field_edit.php": "G-12 項目マスタ", "admin_field_preview.php": "G-12 項目マスタ",
    "admin_projects.php": "G-13 案件の管理",
    "admin_org.php": "G-14 組織・ユーザー", "admin_user_edit.php": "G-14 組織・ユーザー",
    "admin_logs.php": "G-15 記録・削除データ", "admin_trash.php": "G-15 記録・削除データ",
    "account.php": "G-16 アカウント設定",
    "admin_messages.php": "G-17 メッセージ",
    "comment_list.php": "G-18 コメントの一覧",
    "comment_templates.php": "G-19 コメントの定型文",
    "search.php": "G-20 検索",
    "admin_home.php": "G-21 管理のトップ",
    "department_manage.php": "G-23 課の管理",
    "admin_notices.php": "G-24 更新のお知らせ", "faq.php": "G-25 よくある質問",
    "notice.php": "共通（画面ごとの使い方）", "maintenance.php": "共通（メンテナンス）", "backup.php": "G-21 管理のトップ",
    "support_request_list.php": "G-22 要望・依頼", "support_request_view.php": "G-22 要望・依頼", "support_request.php": "G-22 要望・依頼",
    "form.php": "共通（入力の確認）",
    "app.js": "共通（画面の補助）",
}
COMMON_SCREEN_NAME = "共通"

PHP_MSG_CALL = re.compile(r"\bmsg\(\s*'((?:[^'\\]|\\.)*)'")
PHP_MSG_CALL_ANY = re.compile(r"\bmsg\(\s*([^\s)])")
JS_M_CALL = re.compile(r"\bM\(\s*'((?:[^'\\]|\\.)*)'")
PHP_QUOTED = re.compile(r"'((?:[^'\\]|\\.)*)'")


def unescape_php_single_quoted(literal_body):
    """PHP の '…' の中身を文字列に戻す（\\' と \\\\ だけが特別）"""
    return re.sub(r"\\(['\\])", r"\1", literal_body)


def escape_php_single_quoted(text):
    return text.replace("\\", "\\\\").replace("'", "\\'")


def list_source_paths():
    """文言を集めるファイル（app/ の PHP と public/js/app.js）"""
    source_paths = []
    for directory_path, _sub_directory_names, file_names in os.walk(os.path.join(REPO_ROOT, "app")):
        for file_name in sorted(file_names):
            if file_name.endswith(".php") and file_name not in FIXED_FILE_NAMES:
                source_paths.append(os.path.join(directory_path, file_name))
    source_paths.append(os.path.join(REPO_ROOT, "public", "js", "app.js"))
    return sorted(source_paths)


def screen_name_for(path):
    file_name = os.path.basename(path)
    if os.path.basename(os.path.dirname(path)) == "lib" and file_name != "form.php":
        return COMMON_SCREEN_NAME
    return SCREEN_NAME_BY_FILE_NAME.get(file_name, COMMON_SCREEN_NAME)


def collect_messages():
    """戻り値: (screen_names_by_default_text: dict[str, set[str]], problem_lines: list[str])"""
    screen_names_by_default_text = {}
    problem_lines = []
    for path in list_source_paths():
        relative_path = os.path.relpath(path, REPO_ROOT).replace("\\", "/")
        source_text = open(path, encoding="utf-8").read()
        is_message_lib = relative_path == "app/lib/message.php"
        is_javascript = path.endswith(".js")
        call_pattern = JS_M_CALL if is_javascript else PHP_MSG_CALL
        for match in call_pattern.finditer(source_text):
            default_text = unescape_php_single_quoted(match.group(1))
            screen_names_by_default_text.setdefault(default_text, set()).add(screen_name_for(path))
        if not is_javascript and not is_message_lib:
            # msg() の最初の引数は、必ず文字列そのもの（一覧に載せるため）。message.php は JS_MESSAGES を回すので除く
            for match in PHP_MSG_CALL_ANY.finditer(source_text):
                if match.group(1) != "'":
                    line_number = source_text.count("\n", 0, match.start()) + 1
                    problem_lines.append("%s:%d: msg() の最初の引数が文字列そのものではない" % (relative_path, line_number))
        if is_message_lib:
            js_block = re.search(r"const JS_MESSAGES = \[(.*?)\];", source_text, re.S)
            for match in PHP_QUOTED.finditer(js_block.group(1) if js_block else ""):
                default_text = unescape_php_single_quoted(match.group(1))
                screen_names_by_default_text.setdefault(default_text, set()).add(SCREEN_NAME_BY_FILE_NAME["app.js"])
    return screen_names_by_default_text, problem_lines


def render_catalog(screen_names_by_default_text):
    lines = [
        "<?php",
        "",
        "declare(strict_types=1);",
        "",
        "// 変えられる文言の一覧（tools/lib/msg_catalog.py が作る。手で直さない）",
        "// 既定の文言 => ['screens' => 使っている画面]",
        "",
        "return [",
    ]
    for default_text in sorted(screen_names_by_default_text, key=lambda text: (sorted(screen_names_by_default_text[text])[0], text)):
        screen_list_text = ", ".join("'%s'" % escape_php_single_quoted(name) for name in sorted(screen_names_by_default_text[default_text]))
        lines.append("    '%s' => ['screens' => [%s]]," % (escape_php_single_quoted(default_text), screen_list_text))
    lines.append("];")
    return "\n".join(lines) + "\n"


def main():
    parser = argparse.ArgumentParser(description="メッセージの一覧を作る・確かめる")
    parser.add_argument("--check", action="store_true", help="一覧が最新かを確かめるだけ（書き換えない）")
    arguments = parser.parse_args()

    screen_names_by_default_text, problem_lines = collect_messages()
    catalog_text = render_catalog(screen_names_by_default_text)
    current_catalog_text = open(CATALOG_PATH, encoding="utf-8").read() if os.path.exists(CATALOG_PATH) else ""

    if arguments.check:
        is_up_to_date = current_catalog_text == catalog_text
        print(("OK  " if is_up_to_date else "NG  ") + "メッセージの一覧が最新（%d 件）" % len(screen_names_by_default_text)
              + ("" if is_up_to_date else "  python3 tools/lib/msg_catalog.py で作り直してください"))
        print(("OK  " if not problem_lines else "NG  ") + "msg() の最初の引数がすべて文字列そのもの")
        for problem_line in problem_lines:
            print("    " + problem_line)
        return 0 if is_up_to_date and not problem_lines else 1

    with open(CATALOG_PATH, "w", encoding="utf-8", newline="\n") as catalog_file:
        catalog_file.write(catalog_text)
    print("メッセージの一覧を書きました: %s（%d 件）" % (os.path.relpath(CATALOG_PATH, REPO_ROOT), len(screen_names_by_default_text)))
    for problem_line in problem_lines:
        print("注意: " + problem_line)
    return 0


if __name__ == "__main__":
    sys.exit(main())
