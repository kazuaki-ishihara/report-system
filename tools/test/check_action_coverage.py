#!/usr/bin/env python3
"""画面の操作が、自動検証に入っているかを確かめる（検証の入れ忘れを NG にする）。

1. サーバーの操作: app/views の name="action" の値、app/pages の $action・post_str('action') で分けている値、app.js が送る action を集め、
   検証のスクリプト（smoke_*.py・ui_*.py）に、その値を文字列として書いた所があるか。
2. JavaScript の補助: public/js/app.js の「// ---- 」の見出しごとに、UI_COVERAGE の印（検証のスクリプトの中の文字列）があるか。
   見出しを足したら、画面で動かす検証を足して UI_COVERAGE に書く。書かなければ NG。
3. JavaScript の書き方: フォームの .action・.method などを使わない（name="action" の欄があると、その欄を指す。APP-118）。

    python3 tools/test/check_action_coverage.py [--list]

文字列があることの確かめで、中身の十分さは見ない。Python 3.8 以上の標準ライブラリだけで動く。
"""
import glob
import os
import re
import sys

REPOSITORY_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
TEST_DIR = os.path.join(REPOSITORY_ROOT, "tools", "test")

# app.js の見出しの頭の言葉 → (検証のスクリプト, その中にあるはずの文字列)
UI_COVERAGE = {
    "文言": ("smoke_messages.py", "JavaScript の文言にも変えた文言が渡る"),
    "パスワードの表示": ("ui_session.py", ".toggle-password"),
    "コンピテンシー評価基準": ("ui_session.py", "評価基準: つまみで表が横に動く"),
    "2 段階認証のコードの欄": ("ui_check.py", ".use-backup-code"),
    "自分の色": ("ui_session.py", "自分の色: 読みにくい"),
    "メンテナンス中": ("ui_session.py", "メンテナンス中: 保存・提出のボタンが押せない"),
    "要望・依頼の返信の定型文": ("ui_session.py", "返信の定型文を選ぶと"),
    "パスワードを自動で作る": ("ui_check.py", ".generate-password"),
    "客先名らしい言葉の注意": ("ui_check.py", "客先名らしい言葉で注意が出る"),
    "保存していない入力": ("ui_check.py", "LEAVE_PROBE"),
    "表示の条件": ("ui_check.py", "「進捗の補足」は最初は隠れている"),
    "案件ブロックの内容": ("ui_check.py", "duplicate-block"),
    "項目の編集": ("ui_check.py", "add-option-row"),
    "ページの中のタブ": ("ui_check.py", "section-tabs"),
    "表の絞り込み": ("ui_session.py", "一覧の絞り込みで"),
    "選ぶ欄を札と候補の一覧にする": ("ui_session.py", "課長の候補を探すと"),
    "コメントの定型文": ("ui_check.py", "comment-template-select"),
    "ログインの状態の確認": ("ui_session.py", "ログインが切れたまま送っても"),
    "確認の表示": ("ui_session.py", "二重に押しても、送るのは 1 回"),
    "印刷用画面": ("ui_session.py", "印刷のボタンで印刷が呼ばれる"),
    "横並び表示": ("ui_check.py", "button.open-cell"),
    "スマートフォンの幅では": ("ui_session.py", "1 問ずつ: 最後まで進む"),
    "必須の未入力の数": ("ui_session.py", "必須の未入力の数が減る"),
    "下書きの自動保存": ("ui_session.py", "自動保存の送り先は"),
    "よくある質問のチャット": ("ui_session.py", "反応が DB に残る"),
    "交通費の精算": ("ui_session.py", "精算: 小計と合計"),
}


def read(path):
    with open(path, encoding="utf-8") as file_handle:
        return file_handle.read()


def collect_actions():
    found = {}

    def add(value, path):
        found.setdefault(value, set()).add(os.path.relpath(path, REPOSITORY_ROOT).replace(os.sep, "/"))

    for path in sorted(glob.glob(os.path.join(REPOSITORY_ROOT, "app", "views", "*.php"))):
        text = read(path)
        for pattern in (r'name="action"\s+value="([a-z_0-9]+)"', r'value="([a-z_0-9]+)"\s+name="action"'):
            for value in re.findall(pattern, text):
                add(value, path)
    for path in sorted(glob.glob(os.path.join(REPOSITORY_ROOT, "app", "pages", "*.php"))):
        text = read(path)
        for value in re.findall(r"\$action\s*===\s*'([a-z_0-9]+)'", text) + re.findall(r"post_str\('action'\)\s*===\s*'([a-z_0-9]+)'", text):
            add(value, path)
        for block in re.findall(r"in_array\(\$action,\s*\[([^\]]+)\]", text):
            for value in re.findall(r"'([a-z_0-9]+)'", block):
                add(value, path)
        if re.search(r"switch \(\$action\)|match \(\$action\)", text):
            for value in re.findall(r"case '([a-z_0-9]+)':", text):
                add(value, path)
    for value in re.findall(r"(?:append|set)\('action', '([a-z_0-9]+)'\)", read(os.path.join(REPOSITORY_ROOT, "public", "js", "app.js"))):
        add(value, os.path.join(REPOSITORY_ROOT, "public", "js", "app.js"))
    return found


def page_url_map():
    """app/pages/<名前>.php => その画面の URL の一覧（public/ がどのページを呼ぶかから作る）。"""
    url_by_page = {}
    public_dir = os.path.join(REPOSITORY_ROOT, "public")
    for public_path in glob.glob(os.path.join(public_dir, "**", "*.php"), recursive=True):
        page_match = re.search(r"pages/([a-z_0-9]+\.php)", read(public_path))
        if page_match is None:
            continue
        url = "/" + os.path.relpath(public_path, public_dir).replace(os.sep, "/")
        url_by_page.setdefault(page_match.group(1), set()).add(url)
    return url_by_page


def main():
    for output_stream in (sys.stdout, sys.stderr):
        if hasattr(output_stream, "reconfigure"):
            output_stream.reconfigure(errors="replace")
    show_list = "--list" in sys.argv
    test_text = {}
    for path in glob.glob(os.path.join(TEST_DIR, "smoke_*.py")) + glob.glob(os.path.join(TEST_DIR, "ui_*.py")):
        test_text[os.path.basename(path)] = read(path)
    failed = passed = 0

    url_by_page = page_url_map()
    actions = collect_actions()
    for value in sorted(actions):
        action_pattern = re.compile(r"""['"=]""" + re.escape(value) + r"""['"&]""")
        files_with_action = [name for name, text in test_text.items() if action_pattern.search(text)]
        required_urls = set()
        for source in actions[value]:
            if source.startswith("app/pages/"):
                for url in url_by_page.get(os.path.basename(source), ()):
                    required_urls.add(url)
        if required_urls:
            missing_urls = [url for url in sorted(required_urls)
                            if not any(url in text and action_pattern.search(text) for text in test_text.values())]
            if missing_urls:
                failed += 1
                print("NG  操作 %s が、画面ごとの検証にない（%s）" % (value, ", ".join(missing_urls)))
                continue
            passed += 1
            if show_list:
                print("OK  操作 %s（%s）: %s" % (value, ", ".join(sorted(required_urls)), ", ".join(sorted(files_with_action))))
            else:
                print("OK  操作 %s（%s）" % (value, ", ".join(sorted(required_urls))))
        elif files_with_action:
            passed += 1
            if show_list:
                print("OK  操作 %s（%s）: %s" % (value, ", ".join(sorted(actions[value])), ", ".join(sorted(files_with_action))))
            else:
                print("OK  操作 %s（%s）" % (value, ", ".join(sorted(actions[value]))))
        else:
            failed += 1
            print("NG  操作 %s が検証にない（%s）" % (value, ", ".join(sorted(actions[value]))))

    script_text = read(os.path.join(REPOSITORY_ROOT, "public", "js", "app.js"))
    headings = re.findall(r"^  // ---- (.+?)(?: ----.*)?$", script_text, re.M)
    for heading in headings:
        key = next((k for k in UI_COVERAGE if heading.startswith(k)), None)
        if key is None:
            failed += 1
            print("NG  app.js の補助が検証の表（UI_COVERAGE）にない: " + heading[:60])
            continue
        script_name, marker = UI_COVERAGE[key]
        if marker in test_text.get(script_name, ""):
            passed += 1
        else:
            failed += 1
            print("NG  app.js の補助の検証がない: %s（%s に %s が無い）" % (heading[:50], script_name, marker))

    risky = []
    for line_number, line_text in enumerate(script_text.splitlines(), 1):
        if line_text.lstrip().startswith("//"):
            continue
        if re.search(r"\b(?:\w*[fF]orm|f)\.(?:action|method|target|enctype|id|name|title|length|submit|reset)\b", line_text):
            risky.append("%d: %s" % (line_number, line_text.strip()[:90]))
    for risky_line in risky:
        failed += 1
        print("NG  フォームの属性は getAttribute で読む（同じ名前の欄を指すおそれ） app.js:" + risky_line)
    if not risky:
        passed += 1
    print("\nOK %d / NG %d（操作 %d・app.js の補助 %d）" % (passed, failed, len(actions), len(headings)))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
