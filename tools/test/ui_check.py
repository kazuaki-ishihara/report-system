#!/usr/bin/env python3
"""画面の自動操作検証（ローカル検証環境専用。dev_seed.php の直後に実行する）。

実際のブラウザー（Chrome / Edge / Chromium）を操作して、画面の動きを確かめる。
画面の画像を --out のフォルダに残す。

    python3 tools/test/ui_check.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite --out tools/test/output/ui [--show]
"""
import argparse
import datetime
import json
import os
import sqlite3
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from cdp import Browser  # noqa: E402
from lib_http import check, summary, totp  # noqa: E402


# 画面を離れるときの確認（beforeunload）が出るかを、イベントを起こして確かめる
LEAVE_PROBE = "(function(){var e=new Event('beforeunload',{cancelable:true});window.dispatchEvent(e);return e.defaultPrevented;})()"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8000")
    ap.add_argument("--db", required=True)
    ap.add_argument("--out", default=os.path.join(os.path.dirname(os.path.abspath(__file__)), "output", "ui"))
    ap.add_argument("--show", action="store_true", help="ブラウザーを表示して実行する（目視で確認するとき）")
    ap.add_argument("--browser-port", type=int, default=0, help="起動済みのブラウザー（--remote-debugging-port）につなぐ")
    a = ap.parse_args()
    if "127.0.0.1" not in a.base and "localhost" not in a.base:
        print("ローカル検証環境（127.0.0.1）以外には実行しません")
        return 2
    os.makedirs(a.out, exist_ok=True)
    here = os.path.dirname(os.path.abspath(__file__))
    U = json.load(open(os.path.join(here, "output_dev_users.json"), encoding="utf-8"))["users"]
    db = sqlite3.connect("file:%s?mode=ro" % a.db, uri=True)
    F = {c: i for c, i in db.execute("SELECT code, id FROM report_fields")}
    P = {c: i for c, i in db.execute("SELECT code, id FROM projects")}
    today = datetime.date.today()
    prev = (today.replace(day=1) - datetime.timedelta(days=1)).strftime("%Y-%m")
    shot_no = [0]
    used = {}

    b = Browser(headless=not a.show, attach_port=a.browser_port or None)

    def shot(name):
        shot_no[0] += 1
        b.screenshot(os.path.join(a.out, "%02d_%s.png" % (shot_no[0], name)))

    def login(login_id):
        b.clear_cookies()
        b.goto(a.base + "/login.php")
        b.fill("#login_id", login_id)
        b.fill("#password", U[login_id]["password"])
        b.click("form.form button[type=submit]", navigate=True)
        if b.exists("#code"):
            # 同じ人の同じ 30 秒区間のコードは 2 回使えない（アプリの仕様）ので、次の区間まで待つ
            step = int(time.time() // 30)
            if used.get(login_id) == step:
                time.sleep(31 - time.time() % 30)
            b.fill("#code", totp(U[login_id]["totp"]))
            used[login_id] = int(time.time() // 30)
            b.click("form.form button[type=submit]", navigate=True)
        return "ホーム" in (b.eval("document.title") or "")

    def no_hscroll():
        return bool(b.eval("document.documentElement.scrollWidth <= window.innerWidth + 1"))

    try:
        # ---- スマートフォンの幅で部下が報告を作る ----
        b.mobile(True)
        check("部下がログインできる（2 段階認証を含む）", login("ippan_a1"))
        shot("home_mobile")
        check("ホームに「今月の報告を作る」", "今月の報告を作る" in b.text() or "続きを書く" in b.text())
        check("ホームがスマートフォンの幅で横にはみ出さない", no_hscroll())

        # ---- スマートフォンの 1 問ずつの入力（APP-019・K-040）。既定は 1 問ずつ ----
        b.goto(a.base + "/reports/edit.php?period=" + prev)
        b.eval("localStorage.removeItem('reportEditMode')")
        b.goto(a.base + "/reports/edit.php?period=" + prev)
        check("スマートフォンの幅では、既定で 1 問ずつ出す", b.visible(".wizard-nav") and "/ " in (b.eval("document.querySelector('.wizard-progress').textContent") or ""))
        check("1 問ずつのときは、出ている設問が 1 つだけ",
              b.eval("Array.prototype.filter.call(document.querySelectorAll('#report-form .field'), function (fieldElement) { return fieldElement.offsetParent !== null; }).length") <= 1)
        check("1 問ずつのときは、答えを確かめる前は「提出する」を出さない", not b.visible('button[value="submit"]'))
        first_progress_text = b.eval("document.querySelector('.wizard-progress').textContent")
        b.click(".wizard-next")
        time.sleep(0.2)
        check("「次へ」で次の設問に進む", b.eval("document.querySelector('.wizard-progress').textContent") != first_progress_text,
              (first_progress_text, b.eval("document.querySelector('.wizard-progress').textContent")))
        shot("edit_wizard")
        b.click(".wizard-toggle")
        time.sleep(0.2)
        check("「一覧で表示する」で全部の設問を出す", not b.visible(".wizard-nav") and b.visible('button[value="submit"]'))
        check("JavaScript のエラーがない（1 問ずつ）", not [e for e in b.errors if e.startswith("JS")], [e for e in b.errors if e.startswith("JS")][:3])
        b.eval("localStorage.setItem('reportEditMode', 'list')")   # ここから先の検証は一覧の表示で行う
        b.goto(a.base + "/reports/edit.php?period=" + prev)
        shot("edit_empty")
        check("入力画面がスマートフォンの幅で横にはみ出さない", no_hscroll())
        check("入力する前は、離れるときの確認を出さない", not b.eval(LEAVE_PROBE))
        b.fill("#b0_project", str(P["P-0001"]))
        b.fill("#b0_%d" % F["phase"], "build_unit")
        check("「進捗の補足」は最初は隠れている", not b.visible('.field[data-code="progress_note"]'))
        b.click('input[name="b[0][f][%d]"][value="delayed"]' % F["progress"])
        time.sleep(0.2)
        check("進捗に「遅延あり」を選ぶと「進捗の補足」が出る", b.visible('.field[data-code="progress_note"]'))
        b.click('input[name="b[0][f][%d]"][value="on_track"]' % F["progress"])
        time.sleep(0.2)
        check("「問題なく進行」に戻すと隠れる", not b.visible('.field[data-code="progress_note"]'))
        b.fill("#b0_%d" % F["duties"], "株式会社サンプルの在庫管理の改修")
        time.sleep(0.2)
        check("客先名らしい言葉で注意が出る", b.visible('.field[data-code="duties"] .field-warning'))
        shot("edit_warning")
        b.fill("#b0_%d" % F["duties"], "在庫管理の改修")
        time.sleep(0.2)
        check("言葉を消すと注意が消える", not b.visible('.field[data-code="duties"] .field-warning'))
        check("保存していない入力があると、離れるときの確認を出す", b.eval(LEAVE_PROBE) is True)

        b.click('button[value="submit"]', navigate=True)
        shot("submit_error")
        check("必須が空のまま提出すると、欄の下に「入力してください」", "入力してください" in b.text())
        check("入力した値は残っている", b.eval("document.querySelector('#b0_%d').value" % F["duties"]) == "在庫管理の改修")
        check("入力エラーで戻った画面でも、離れるときの確認を出す", b.eval(LEAVE_PROBE) is True)
        b.click('input[name="b[0][f][%d]"][value="continuing"]' % F["continuation"])
        b.fill("#b0_%d" % F["duties"], "株式会社サンプルの在庫管理の改修")
        b.dialogs.clear()
        b.click('button[value="submit"]', navigate=True)
        check("注意が残っているときは提出の前に確認が出る", any("注意" in d for d in b.dialogs), b.dialogs)
        shot("submitted")
        check("提出できる", "提出しました" in b.text())
        rid = db.execute("SELECT id FROM report_records WHERE user_id = ? AND period = ?", (U["ippan_a1"]["id"], prev)).fetchone()
        rid = rid[0] if rid else 0

        # ---- 案件の内容を「案件を追加するとき」の欄に写す（G-04 §4.5） ----
        current_month = today.strftime("%Y-%m")
        b.goto(a.base + "/reports/edit.php?period=" + current_month)
        new_block_phase_selector = "#b1_%d" % F["phase"]
        check("前月から引き継いだ案件に「この内容で案件を追加」が出る", b.visible('button.duplicate-block[data-source-block-index="0"]'))
        b.click('button.duplicate-block[data-source-block-index="0"]')
        time.sleep(0.2)
        check("案件の内容が新しい案件の欄に写る", b.eval("document.querySelector('%s').value" % new_block_phase_selector) == "build_unit",
              b.eval("document.querySelector('%s').value" % new_block_phase_selector))
        check("写した先の案件は選び直す（空）", b.eval("document.querySelector('#b1_project').value") == "")
        check("写したことを知らせる", "内容を写しました。追加する案件を選んでください" in b.text())
        check("写したあとは、離れるときの確認を出す", b.eval(LEAVE_PROBE) is True)

        # ---- 確認者がコメントし、チェックする（スマートフォン） ----
        # 前提: 課のコメントの定型文（G-19）を 1 つ入れておく（画面の確認用。この準備だけ DB に書く）
        setup_database = sqlite3.connect(a.db, timeout=10, isolation_level=None)
        department_a_id = setup_database.execute("SELECT department_id FROM users WHERE id = ?", (U["ippan_a1"]["id"],)).fetchone()[0]
        setup_database.execute(
            "INSERT INTO comment_templates (department_id, body, sort_order, created_at, updated_at) VALUES (?, '完了の見込みを教えてください', 10, datetime('now'), datetime('now'))",
            (department_a_id,))
        setup_database.close()
        check("確認者がログインできる", login("leader_a"))
        b.goto(a.base + "/reports/view.php?id=%d" % rid)
        shot("view_reviewer")
        check("確認者に「限定」の値が見えない", "限定のため表示しません" in b.text())
        check("確認者に注意の印が見える", "客先名の可能性" in b.text())
        b.click("details.comment-form summary")
        b.fill("details.comment-form select.comment-template-select", "完了の見込みを教えてください")
        time.sleep(0.2)
        check("定型文を選ぶと本文に入る", b.eval("document.querySelector('details.comment-form textarea').value") == "完了の見込みを教えてください",
              b.eval("document.querySelector('details.comment-form textarea').value"))
        b.fill("details.comment-form textarea", "サンプル社とは書かないでください。社内コードでお願いします")
        b.click('details.comment-form button[value="comment"]', navigate=True)
        check("コメントできる", "コメントしました" in b.text())
        b.click('button[value="check"]', navigate=True)
        check("チェック済みにできる", "チェック済みにしました" in b.text())
        shot("view_checked")

        # ---- 部下がコメントに返信する ----
        login("ippan_a1")
        check("部下のホームに回答待ち", "回答待ちのコメント 1件" in b.text())
        b.goto(a.base + "/reports/view.php?id=%d" % rid)
        b.click("details.reply-form summary")
        b.fill("details.reply-form textarea", "直しました")
        b.click('details.reply-form button[value="reply"]', navigate=True)
        check("返信できる", "返信しました" in b.text() and "回答あり" in b.text())

        # ---- PC の幅で課長が横並び・項目マスタ・出力を使う ----
        b.mobile(False)
        check("課長がログインできる", login("kacho_a"))
        b.goto(a.base + "/status/compare.php?period=" + prev)
        shot("compare")
        check("横並びに案件と選択肢の表示名", "P-0001" in b.text() and "製造・単体" in b.text())
        b.click("button.open-cell")
        time.sleep(0.6)
        check("自由記述のセルを開ける", "在庫管理の改修" in b.text())
        b.goto(a.base + "/admin/fields.php")
        check("課長が項目マスタを開ける", "業務システムの種類" in b.text())
        # 課長が画面から課別項目を足す → プレビューと部下の入力画面に出る
        b.goto(a.base + "/admin/field_edit.php?dept=1")
        b.fill("#label", "画面で足した項目")
        b.fill("#code", "ui_added")
        b.click('input[name="field_scope"][value="project"]')
        # 入力部品に合う設定だけが出る
        check("1 行テキストでは最大文字数が出て、数値の設定と選択肢は出ない",
              b.visible("#max_length") and not b.visible("#min") and not b.visible('fieldset[data-field-types~="radio"]'))
        b.fill("#field_type", "number")
        time.sleep(0.2)
        check("数値に変えると、最小・最大が出て最大文字数は隠れる", b.visible("#min") and not b.visible("#max_length"))
        b.fill("#field_type", "radio")
        time.sleep(0.2)
        check("ラジオに変えると選択肢が出て、入力の制限はない旨が出る",
              b.visible('fieldset[data-field-types~="radio"]') and b.visible(".no-setting-note"))
        option_row_count_before = b.eval("document.querySelectorAll('fieldset[data-field-types~=\"radio\"] tbody tr').length")
        b.click("button.add-option-row")
        time.sleep(0.2)
        check("「選択肢の行を足す」で行が増える",
              b.eval("document.querySelectorAll('fieldset[data-field-types~=\"radio\"] tbody tr').length") == option_row_count_before + 1)
        # 表示の条件: 同じ区分の項目だけ選べ、選んだ項目の値だけ出る
        check("表示の条件に、案件ブロックの選択式の項目が出る",
              b.eval("!document.querySelector('#show_when_field option[value=\"progress\"]').disabled"))
        b.fill("#show_when_field", "progress")
        time.sleep(0.2)
        check("表示の条件で項目を選ぶと、その項目の値だけが出る",
              b.visible('.show-when-values[data-field-code="progress"]') and not b.visible('.show-when-values[data-field-code="phase"]'))
        b.fill("#show_when_field", "")
        time.sleep(0.2)
        b.fill('input[name="opt_code[0]"]', "yes")
        b.fill('input[name="opt_label[0]"]', "はい")
        b.fill('input[name="opt_code[1]"]', "no")
        b.fill('input[name="opt_label[1]"]', "いいえ")
        shot("field_edit")
        b.click("form.form button.primary", navigate=True)
        check("課長が画面から項目を足せる", "保存しました" in b.text() and "画面で足した項目" in b.text())
        b.goto(a.base + "/admin/field_edit.php?preview=1")
        shot("preview")
        check("様式のプレビューが出る", "案件 1" in b.text() and "作業フェーズ" in b.text())
        check("足した項目がプレビューに出る", "画面で足した項目" in b.text())
        b.goto(a.base + "/export/index.php")
        check("PC からは出力の画面が使える", "出力する" in b.text())
        b.mobile(True)
        b.goto(a.base + "/export/index.php")
        check("スマートフォンでは出力の画面に案内だけが出る", "PC から行ってください" in b.text())
        b.mobile(False)

        # ---- 管理画面（管理者） ----
        check("管理者がログインできる", login("admin01"))
        check("課のない管理者のホームには、報告の欄の代わりに管理画面への入口が出る",
              "今月の報告を作る" not in b.text() and "所属の課が設定されていません" not in b.text() and b.exists(".shortcut-list"))
        b.click(".app-menu summary")
        time.sleep(0.2)
        shot("menu_admin")
        check("メニューが見出し（報告・確認・管理）で分かれる",
              b.eval("Array.prototype.map.call(document.querySelectorAll('.app-menu .menu-group'), function (groupLabel) { return groupLabel.textContent; }).join(',')") == "報告,確認,管理,アカウント")
        b.goto(a.base + "/admin/org.php?tab=departments&edit=1")
        shot("org_department_edit")
        check("課の編集の欄が、課の一覧より上に出る",
              b.eval("document.querySelector('.edit-panel').compareDocumentPosition(document.querySelector('.edit-panel ~ table.table')) & 4") == 4)
        check("課の編集は節（基本・課長・引き継ぎ）のタブに分かれ、最初は「基本」だけを出す（K-042）",
              b.eval("document.querySelectorAll('.section-tabs button').length") == 3 and b.visible("#dept-basic") and not b.visible("#dept-final"))
        b.eval("Array.prototype.filter.call(document.querySelectorAll('.section-tabs button'), function (tabButton) { return tabButton.textContent === '課長'; })[0].click()")
        time.sleep(0.2)
        check("「課長」のタブを押すと、課長の節が出る", b.visible("#dept-final") and not b.visible("#dept-basic"))
        check("管理画面の上に道しるべ（管理から組織・ユーザーへ）が出る", b.exists(".admin-nav .breadcrumb") and "組織・ユーザー" in b.eval("document.querySelector('.admin-nav .breadcrumb').textContent"))

        # ---- 課長の「課の管理」（G-23。K-042・K-039） ----
        check("課長がログインできる（課の管理）", login("kacho_a"))
        b.goto(a.base + "/admin/department.php?dept=1")
        shot("department_manage")
        check("課の管理は節（基本・確認者と課員・班）のタブに分かれる",
              b.eval("document.querySelectorAll('.section-tabs button').length") == 3 and b.visible("#dept-basic"))
        b.eval("Array.prototype.filter.call(document.querySelectorAll('.section-tabs button'), function (tabButton) { return tabButton.textContent === '確認者と課員'; })[0].click()")
        time.sleep(0.2)
        b.fill("#dept-members .table-filter", "ippan_a1")
        time.sleep(0.2)
        check("確認者と課員の表を名前・ID で絞り込める",
              b.eval("Array.prototype.filter.call(document.querySelectorAll('#department-member-table tbody tr'), function (tableRow) { return !tableRow.hidden; }).length") == 1)
        check("管理者に戻る", login("admin01"))
        b.goto(a.base + "/admin/fields.php?dept=1")
        shot("fields_list")
        b.goto(a.base + "/admin/index.php")
        shot("admin_home")
        check("管理のトップに、管理の仕事ごとのカードが出る", b.eval("document.querySelectorAll('.admin-card').length") >= 4)

        # ---- 画面のテーマ（G-16）: ダークを選ぶと背景が暗くなる ----
        b.goto(a.base + "/account.php?tab=theme")
        b.click('input[name="ui_theme"][value="dark"]')
        b.click("form.theme-form button.primary", navigate=True)
        check("ダークを選ぶと背景が暗くなる",
              b.eval("getComputedStyle(document.body).backgroundColor") == "rgb(13, 17, 23)", b.eval("getComputedStyle(document.body).backgroundColor"))
        b.goto(a.base + "/admin/org.php?tab=departments&edit=1")
        shot("theme_dark")
        b.goto(a.base + "/account.php?tab=theme")
        b.click('input[name="ui_theme"][value="sakura"]')
        b.click("form.theme-form button.primary", navigate=True)
        shot("theme_sakura")
        check("さくらを選ぶと見出しに花が付く", b.eval("getComputedStyle(document.querySelector('.app-title'), '::before').content").find("🌸") != -1)

        # ---- 画面の作りの確認 ----
        csp = [e for e in b.errors if "Content Security Policy" in e or "Refused" in e]
        check("CSP の違反がない", not csp, csp[:3])
        js = [e for e in b.errors if e.startswith("JS")]
        check("JavaScript のエラーがない", not js, js[:3])
    except Exception as e:  # noqa: BLE001
        check("自動操作が最後まで動く", False, repr(e))
        try:
            shot("error")
        except Exception:  # noqa: BLE001
            pass
    finally:
        b.quit()
    print("画面の画像: " + a.out)
    return summary()


if __name__ == "__main__":
    sys.exit(main())
