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

        b.goto(a.base + "/reports/edit.php?period=" + prev)
        shot("edit_empty")
        check("入力画面がスマートフォンの幅で横にはみ出さない", no_hscroll())
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

        b.click('button[value="submit"]', navigate=True)
        shot("submit_error")
        check("必須が空のまま提出すると、欄の下に「入力してください」", "入力してください" in b.text())
        check("入力した値は残っている", b.eval("document.querySelector('#b0_%d').value" % F["duties"]) == "在庫管理の改修")
        b.click('input[name="b[0][f][%d]"][value="continuing"]' % F["continuation"])
        b.fill("#b0_%d" % F["duties"], "株式会社サンプルの在庫管理の改修")
        b.dialogs.clear()
        b.click('button[value="submit"]', navigate=True)
        check("注意が残っているときは提出の前に確認が出る", any("注意" in d for d in b.dialogs), b.dialogs)
        shot("submitted")
        check("提出できる", "提出しました" in b.text())
        rid = db.execute("SELECT id FROM report_records WHERE user_id = ? AND period = ?", (U["ippan_a1"]["id"], prev)).fetchone()
        rid = rid[0] if rid else 0

        # ---- 確認者がコメントし、チェックする（スマートフォン） ----
        check("確認者がログインできる", login("leader_a"))
        b.goto(a.base + "/reports/view.php?id=%d" % rid)
        shot("view_reviewer")
        check("確認者に「限定」の値が見えない", "限定のため表示しません" in b.text())
        check("確認者に注意の印が見える", "客先名の可能性" in b.text())
        b.click("details.comment-form summary")
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
        b.goto(a.base + "/admin/field_edit.php?preview=1")
        shot("preview")
        check("様式のプレビューが出る", "案件 1" in b.text() and "作業フェーズ" in b.text())
        b.goto(a.base + "/export/index.php")
        check("PC からは出力の画面が使える", "出力する" in b.text())
        b.mobile(True)
        b.goto(a.base + "/export/index.php")
        check("スマートフォンでは出力の画面に案内だけが出る", "PC から行ってください" in b.text())
        b.mobile(False)

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
