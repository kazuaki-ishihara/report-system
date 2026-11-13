#!/usr/bin/env python3
"""画面の目視確認の道具（ローカル検証環境専用）。

役割ごとにログインして、すべての画面を PC の幅とスマートフォンの幅（指定すればテーマごとにも）で
ページ全体の画像に撮り、一覧のページ（index.html）を作る。一覧のページでは、画面ごとに
「確認済み」の印とメモを付けられ、メモを課題管理に貼れる形で書き出せる。

    python tools/test/screen_gallery.py --db app/data/dev.sqlite
    python tools/test/screen_gallery.py --db app/data/dev.sqlite --themes light,dark,sakura --roles ippan_a1,kacho_a
    python tools/test/screen_gallery.py --db app/data/dev.sqlite --screens G-22 --roles kacho_a,admin01,hosa01   # 画面を絞る
    python tools/test/screen_gallery.py --db app/data/dev.sqlite --browser-port 29777   # 起動済みの Edge につなぐ

- 先にサーバーを起動しておく（php -S 127.0.0.1:8000 -t public）。dev_seed.php の直後でもよい。
  報告がないときは、見本の報告（部下の前月の提出）とコメントを画面の操作で作ってから撮る（--no-prepare で作らない）。
- テーマは画面の表示だけを切り替えて撮る（利用者の設定は変えない）。
- 画像に写った文字以外の気づき（横にはみ出す、JavaScript のエラー、403 など）は、画像の下に自動で印を付ける。
"""
import argparse
import datetime
import html
import json
import os
import sqlite3
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from cdp import Browser  # noqa: E402
from lib_http import totp  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))

PC_WIDTH_PIXELS = 1280
PC_USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36"
MOBILE_WIDTH_PIXELS = 375
MOBILE_USER_AGENT = "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1"
MAX_CAPTURE_HEIGHT_PIXELS = 16000

WIDTH_LABEL_BY_NAME = {"pc": "PC", "mobile": "スマートフォン"}
THEME_LABEL_BY_NAME = {"light": "既定", "dark": "ダーク", "sakura": "さくら", "wakaba": "若葉"}
ROLE_LABEL_BY_LOGIN_ID = {
    "ippan_a1": "部下（A課）", "leader_a": "確認者（A課 リーダー）", "dairi_a": "確認者（A課 課長代理）",
    "kacho_a": "課長（A課・管理担当）", "kacho_b": "課長（B課）", "ippan_b1": "部下（B課）", "admin01": "管理者",
    "hosa01": "システム管理者補佐",
}
DEFAULT_ROLE_LOGIN_IDS = ["ippan_a1", "leader_a", "kacho_a", "admin01", "hosa01"]


def build_screen_list_by_login_id(report_id_by_login_id, previous_month_text, current_month_text, first_user_id):
    """役割ごとに撮る画面: [(画面の番号, 画面の名前, URL のパス)]。報告の詳細は、その人が見られる報告があるときだけ。"""
    sample_report_id = report_id_by_login_id.get("ippan_a1")
    report_detail_screens = [("G-05", "報告の詳細", "/reports/view.php?id=%d" % sample_report_id)] if sample_report_id else []
    member_screens = [
        ("G-03", "ホーム", "/index.php"),
        ("G-04", "報告の作成（今月）", "/reports/edit.php?period=" + current_month_text),
        ("G-04", "報告の作成（前月）", "/reports/edit.php?period=" + previous_month_text),
        ("G-06", "自分の報告", "/reports/mine.php"),
        ("G-18", "コメント", "/reports/comments.php"),
        ("G-20", "検索", "/reports/search.php"),
        ("G-25", "よくある質問", "/faq.php"),
        ("G-16", "アカウント設定（テーマ）", "/account.php?tab=theme"),
        ("G-16", "アカウント設定（パスワード）", "/account.php?tab=password"),
    ]
    reviewer_screens = [
        ("G-03", "ホーム", "/index.php"),
        ("G-08", "提出状況", "/status/index.php?period=" + previous_month_text),
        ("G-09", "横並び", "/status/compare.php?period=" + previous_month_text),
        ("G-18", "コメント", "/reports/comments.php"),
        ("G-20", "検索", "/reports/search.php"),
        ("G-19", "コメントの定型文", "/admin/comment_templates.php"),
    ]
    survey_id = report_id_by_login_id.get("survey")
    survey_result_screens = [("G-11", "臨時の調査（結果）", "/surveys/result.php?id=%d" % survey_id),
                             ("G-11", "臨時の調査（編集）", "/surveys/survey_edit.php?id=%d" % survey_id)] if survey_id else []
    support_request_id = report_id_by_login_id.get("support_request")
    support_request_screens = [("G-22", "要望・依頼（一覧）", "/requests/index.php?status=all")] + (
        [("G-22", "要望・依頼（詳細・返信）", "/requests/view.php?id=%d" % support_request_id)] if support_request_id else [])
    support_request_new_screens = [("G-22", "要望・依頼（画面のリンクから出す）",
                                    "/requests/index.php?screen=G-08&from=" + "/status/index.php%3Fperiod%3D" + previous_month_text)]
    manager_screens = reviewer_screens + [
        ("G-11", "臨時の調査", "/surveys/manage.php"),
    ] + survey_result_screens + [
        ("G-10", "出力", "/export/index.php"),
        ("G-21", "管理のトップ", "/admin/index.php"),
        ("G-12", "項目マスタ", "/admin/fields.php"),
        ("G-12", "項目の追加", "/admin/field_edit.php?dept=1"),
        ("G-12", "様式のプレビュー", "/admin/field_edit.php?preview=1"),
        ("G-17", "メッセージ", "/admin/messages.php"),
    ] + support_request_new_screens + support_request_screens
    admin_screens = [
        ("G-03", "ホーム", "/index.php"),
        ("G-08", "提出状況", "/status/index.php?period=" + previous_month_text),
        ("G-21", "管理のトップ", "/admin/index.php"),
        ("G-14", "組織・ユーザー（課の一覧）", "/admin/org.php?tab=departments"),
        ("G-14", "組織・ユーザー（課の編集）", "/admin/org.php?tab=departments&edit=1"),
        ("G-14", "組織・ユーザー（ユーザー）", "/admin/org.php?tab=users"),
        ("G-14", "ユーザーの編集", "/admin/user_edit.php?id=%d" % first_user_id),
        ("G-14", "組織・ユーザー（注意の言葉）", "/admin/org.php?tab=words"),
        ("G-14", "組織・ユーザー（一括 CSV）", "/admin/org.php?tab=bulk"),
        ("G-13", "案件の管理", "/admin/projects.php"),
        ("G-15", "記録", "/admin/logs.php"),
        ("G-15", "削除データ", "/admin/trash.php"),
        ("G-24", "更新のお知らせ", "/admin/notices.php"),
        ("G-12", "項目マスタ", "/admin/fields.php"),
    ] + support_request_screens
    return {
        "ippan_a1": member_screens[:1] + report_detail_screens + member_screens[1:],
        "ippan_b1": member_screens,
        "leader_a": reviewer_screens[:2] + report_detail_screens + reviewer_screens[2:],
        "dairi_a": reviewer_screens[:2] + report_detail_screens + reviewer_screens[2:],
        "kacho_a": manager_screens[:2] + report_detail_screens + manager_screens[2:],
        "kacho_b": manager_screens,
        "admin01": admin_screens,
        "hosa01": [screen for screen in admin_screens if screen[0] != "G-14" or "ユーザーの編集" not in screen[1]] + [("G-17", "メッセージ", "/admin/messages.php")],
    }


class ScreenGallery:
    def __init__(self, browser, base_url, user_by_login_id, output_directory):
        self.browser = browser
        self.base_url = base_url.rstrip("/")
        self.user_by_login_id = user_by_login_id
        self.output_directory = output_directory
        self.used_totp_step_by_login_id = {}
        self.shot_records = []  # 1 枚ごとの dict（一覧のページの材料）

    # ---- 操作 ----
    def set_width(self, width_name):
        if width_name == "mobile":
            metrics = {"width": MOBILE_WIDTH_PIXELS, "height": 812, "deviceScaleFactor": 1, "mobile": True}
            user_agent_text = MOBILE_USER_AGENT
        else:
            metrics = {"width": PC_WIDTH_PIXELS, "height": 900, "deviceScaleFactor": 1, "mobile": False}
            user_agent_text = PC_USER_AGENT
        self.browser.call("Emulation.setDeviceMetricsOverride", metrics)
        self.browser.call("Emulation.setUserAgentOverride", {"userAgent": user_agent_text})
        self.current_width_metrics = metrics

    def login(self, login_id):
        """パスワード（と、求められたら 2 段階認証のコード）でログインする。ホームに着いたら True。"""
        self.browser.clear_cookies()
        self.browser.goto(self.base_url + "/login.php")
        self.browser.fill("#login_id", login_id)
        self.browser.fill("#password", self.user_by_login_id[login_id]["password"])
        self.browser.click("form.form button[type=submit]", navigate=True)
        if self.browser.exists("#code"):
            current_step = int(time.time() // 30)
            if self.used_totp_step_by_login_id.get(login_id) == current_step:
                time.sleep(31 - time.time() % 30)  # 同じ区間のコードは 2 回使えない
            self.browser.fill("#code", totp(self.user_by_login_id[login_id]["totp"]))
            self.used_totp_step_by_login_id[login_id] = int(time.time() // 30)
            self.browser.click("form.form button[type=submit]", navigate=True)
        return "ホーム" in (self.browser.eval("document.title") or "")

    def capture_full_page(self, image_path):
        """ページ全体を 1 枚に撮る（長すぎるページは MAX_CAPTURE_HEIGHT_PIXELS で切る）。"""
        page_height_pixels = int(self.browser.eval(
            "Math.max(document.documentElement.scrollHeight, document.body ? document.body.scrollHeight : 0)") or 900)
        capture_height_pixels = max(min(page_height_pixels, MAX_CAPTURE_HEIGHT_PIXELS), 400)
        full_metrics = dict(self.current_width_metrics, height=capture_height_pixels)
        self.browser.call("Emulation.setDeviceMetricsOverride", full_metrics)
        time.sleep(0.15)
        self.browser.screenshot(image_path)
        self.browser.call("Emulation.setDeviceMetricsOverride", self.current_width_metrics)
        return page_height_pixels > MAX_CAPTURE_HEIGHT_PIXELS

    def shoot_screen(self, login_id, screen_number, screen_name, url_path, width_name, theme_names):
        error_count_before = len(self.browser.errors)
        self.browser.goto(self.base_url + url_path)
        page_title_text = self.browser.eval("document.title") or ""
        body_text = self.browser.text()
        automatic_notes = []
        if self.browser.eval("document.documentElement.scrollWidth > window.innerWidth + 1"):
            automatic_notes.append("横にはみ出している")
        if any(marker in page_title_text + body_text[:200] for marker in ("403", "404", "見つかりません", "権限がありません")):
            automatic_notes.append("開けない画面（403 など）")
        if "Fatal error" in body_text or "Warning:" in body_text or "Notice:" in body_text:
            automatic_notes.append("PHP のエラー表示がある")
        if "/login.php" in (self.browser.eval("location.pathname") or ""):
            automatic_notes.append("ログイン画面に戻された")
        page_errors = self.browser.errors[error_count_before:]
        if page_errors:
            automatic_notes.append("ブラウザーのエラー: " + " / ".join(page_errors[:3]))
        for theme_name in theme_names:
            self.browser.eval("document.documentElement.setAttribute('data-theme', %s)" % json.dumps(theme_name))
            time.sleep(0.1)
            image_file_name = "%s_%03d_%s_%s.png" % (login_id, len(self.shot_records) + 1, width_name, theme_name)
            was_cut = self.capture_full_page(os.path.join(self.output_directory, image_file_name))
            self.shot_records.append({
                "login_id": login_id, "screen_number": screen_number, "screen_name": screen_name, "url_path": url_path,
                "width_name": width_name, "theme_name": theme_name, "image_file_name": image_file_name,
                "automatic_notes": automatic_notes + (["長いため途中まで"] if was_cut else []),
            })
        print("  %-8s %-6s %s %s%s" % (width_name, screen_number, screen_name, url_path,
                                       ("  ※ " + "、".join(automatic_notes)) if automatic_notes else ""))


def prepare_sample_report(gallery, database_path, previous_month_text):
    """部下（ippan_a1）の前月の報告がなければ、画面の操作で提出し、確認者がコメントする（見本のデータ）。"""
    database = sqlite3.connect("file:%s?mode=ro" % database_path, uri=True)
    member_user_id = gallery.user_by_login_id["ippan_a1"]["id"]
    existing_row = database.execute("SELECT id FROM report_records WHERE user_id = ? AND status <> 'draft' ORDER BY period DESC LIMIT 1",
                                    (member_user_id,)).fetchone()
    if existing_row:
        return
    field_id_by_code = {field_code: field_id for field_code, field_id in database.execute("SELECT code, id FROM report_fields")}
    project_id_by_code = {project_code: project_id for project_code, project_id in database.execute("SELECT code, id FROM projects")}
    database.close()
    print("見本の報告を作ります（ippan_a1 の %s の提出と、leader_a のコメント）" % previous_month_text)
    browser = gallery.browser
    gallery.set_width("pc")
    gallery.login("ippan_a1")
    browser.goto(gallery.base_url + "/reports/edit.php?period=" + previous_month_text)
    browser.fill("#b0_project", str(project_id_by_code["P-0001"]))
    browser.fill("#b0_%d" % field_id_by_code["phase"], "build_unit")
    browser.click('input[name="b[0][f][%d]"][value="on_track"]' % field_id_by_code["progress"])
    browser.click('input[name="b[0][f][%d]"][value="continuing"]' % field_id_by_code["continuation"])
    browser.fill("#b0_%d" % field_id_by_code["duties"], "在庫管理の改修（画面確認の見本）")
    browser.click('button[value="submit"]', navigate=True)
    if "提出しました" not in browser.text():
        print("  見本の報告を提出できませんでした（必須の項目が増えた可能性があります）。報告なしで撮ります")
        return
    database = sqlite3.connect("file:%s?mode=ro" % database_path, uri=True)
    report_id = database.execute("SELECT id FROM report_records WHERE user_id = ? AND period = ?",
                                 (member_user_id, previous_month_text)).fetchone()[0]
    database.close()
    gallery.login("leader_a")
    browser.goto(gallery.base_url + "/reports/view.php?id=%d" % report_id)
    browser.click("details.comment-form summary")
    browser.fill("details.comment-form textarea", "完了の見込みを教えてください（画面確認の見本）")
    browser.click('details.comment-form button[value="comment"]', navigate=True)
    prepare_sample_support_request(gallery)
    prepare_sample_survey(gallery)


def prepare_sample_survey(gallery):
    """課長が臨時の調査を作って公開し、部下が 1 件答える（見本のデータ。G-11 をデータありで撮る。APP-027）。"""
    print("見本の臨時の調査を作ります（kacho_a が作って公開し、ippan_a1 が答える）")
    browser = gallery.browser
    gallery.login("kacho_a")
    browser.goto(gallery.base_url + "/surveys/survey_edit.php")
    browser.fill("#title", "AI の利用状況（画面確認の見本）")
    browser.fill("#requester", "経営企画")
    browser.fill("#purpose", "研修の計画のため")
    browser.fill("#due_at", (datetime.date.today() + datetime.timedelta(days=7)).isoformat())
    browser.click('form.form input[name="action"][value="save_meta"] ~ button[type=submit]', navigate=True)
    survey_id_text = browser.eval("(new URLSearchParams(location.search)).get('id') || ''")
    if not survey_id_text:
        print("  臨時の調査を作れませんでした（見本なしで撮ります）")
        return
    browser.fill("#q_label", "AI を業務で使っていますか")
    browser.fill("#q_type", "radio")
    browser.fill("#q_options", "毎日使う\nときどき使う\n使わない")
    browser.click('button[name="action"][value="add_custom"]', navigate=True)
    browser.click('button[name="action"][value="publish"]', navigate=True)
    gallery.login("ippan_a1")
    browser.goto(gallery.base_url + "/surveys/answer.php?id=" + survey_id_text)
    browser.eval("Array.prototype.forEach.call(document.querySelectorAll('form.form .choices'), function (choiceGroup) {"
                 " var firstChoice = choiceGroup.querySelector('input[type=radio]'); if (firstChoice) { firstChoice.checked = true; } });"
                 " Array.prototype.forEach.call(document.querySelectorAll('form.form select'), function (selectBox) {"
                 " if (selectBox.options.length > 1 && selectBox.value === '') { selectBox.selectedIndex = 1; } });")
    browser.click('form.form .sticky-actions button[type=submit]', navigate=True)


def prepare_sample_support_request(gallery):
    """課長の要望・依頼を 1 件出し、管理者が対応中にして返信する（見本のデータ。G-22）。"""
    print("見本の要望・依頼を作ります（kacho_a が出し、admin01 が返信）")
    browser = gallery.browser
    gallery.login("kacho_a")
    browser.goto(gallery.base_url + "/requests/index.php")
    browser.click("details.support-request-new summary")
    browser.fill("#request_kind", "improvement")
    browser.fill("#request_title", "提出状況の色分け（画面確認の見本）")
    browser.fill("#request_body", "提出済みと未作成が同じ色で、ぱっと見で分かりません。\n色を分けてほしいです。")
    browser.click('input[name="screen_code"][value="G-08"]')
    browser.click("details.support-request-new button.primary", navigate=True)
    gallery.login("admin01")
    browser.goto(gallery.base_url + "/requests/index.php")
    browser.click(".support-request-list a", navigate=True)
    browser.fill("#staff_reply_body", "ご要望ありがとうございます。色の分け方を検討し、次の更新で反映します（画面確認の見本）。")
    browser.click('.support-status-buttons button[value="in_progress"]', navigate=True)


def find_report_ids(database_path, user_by_login_id):
    """役割ごとの「報告の詳細」に使う報告: 部下（ippan_a1）の提出済みの最新の報告。"""
    database = sqlite3.connect("file:%s?mode=ro" % database_path, uri=True)
    report_id_by_login_id = {}
    found_row = database.execute("SELECT id FROM report_records WHERE user_id = ? AND status <> 'draft' ORDER BY period DESC LIMIT 1",
                                 (user_by_login_id["ippan_a1"]["id"],)).fetchone()
    if found_row:
        report_id_by_login_id["ippan_a1"] = found_row[0]
    has_support_table = database.execute("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'support_requests'").fetchone()
    if has_support_table:
        support_row = database.execute("SELECT id FROM support_requests ORDER BY id LIMIT 1").fetchone()
        if support_row:
            report_id_by_login_id["support_request"] = support_row[0]
    survey_row = database.execute("SELECT id FROM adhoc_requests WHERE deleted_at IS NULL ORDER BY id LIMIT 1").fetchone()
    if survey_row:
        report_id_by_login_id["survey"] = survey_row[0]
    database.close()
    return report_id_by_login_id


def write_index_page(output_directory, shot_records, role_login_ids, width_names, theme_names, created_at_text):
    """一覧のページ（index.html）。確認済みの印とメモは、そのブラウザーの中（localStorage）に残す。"""
    sections_html = []
    for login_id in role_login_ids:
        role_records = [record for record in shot_records if record["login_id"] == login_id]
        if not role_records:
            continue
        screen_keys_in_order = []
        for record in role_records:
            screen_key = (record["screen_number"], record["screen_name"], record["url_path"])
            if screen_key not in screen_keys_in_order:
                screen_keys_in_order.append(screen_key)
        cards_html = []
        for screen_number, screen_name, url_path in screen_keys_in_order:
            note_key = "%s|%s|%s" % (login_id, screen_number, url_path)
            image_cells_html = []
            for record in role_records:
                if (record["screen_number"], record["screen_name"], record["url_path"]) != (screen_number, screen_name, url_path):
                    continue
                automatic_notes_html = "".join('<li>%s</li>' % html.escape(note) for note in record["automatic_notes"])
                image_cells_html.append(
                    '<figure class="shot shot-%s" data-width="%s" data-theme="%s"><figcaption>%s・%s</figcaption>'
                    '<a href="%s" target="_blank"><img loading="lazy" src="%s" alt=""></a>%s</figure>' % (
                        record["width_name"], record["width_name"], record["theme_name"],
                        WIDTH_LABEL_BY_NAME[record["width_name"]], THEME_LABEL_BY_NAME.get(record["theme_name"], record["theme_name"]),
                        html.escape(record["image_file_name"]), html.escape(record["image_file_name"]),
                        ('<ul class="auto-notes">%s</ul>' % automatic_notes_html) if automatic_notes_html else ""))
            has_automatic_notes = any(record["automatic_notes"] for record in role_records
                                      if (record["screen_number"], record["url_path"]) == (screen_number, url_path))
            cards_html.append(
                '<article class="card%s" data-key="%s"><header><span class="screen-number">%s</span> <b>%s</b> <code>%s</code>'
                '<label class="done"><input type="checkbox" class="done-check"> 確認済み</label></header>'
                '<div class="shots">%s</div><textarea class="memo" rows="2" placeholder="気づいたこと（課題管理に書き出せます）"></textarea></article>' % (
                    " has-auto-notes" if has_automatic_notes else "", html.escape(note_key), html.escape(screen_number),
                    html.escape(screen_name), html.escape(url_path), "".join(image_cells_html)))
        sections_html.append('<section class="role"><h2>%s <small>%s</small></h2>%s</section>' % (
            html.escape(ROLE_LABEL_BY_LOGIN_ID.get(login_id, login_id)), html.escape(login_id), "".join(cards_html)))
    width_buttons_html = "".join('<label><input type="checkbox" class="filter-width" value="%s" checked> %s</label>' % (
        width_name, WIDTH_LABEL_BY_NAME[width_name]) for width_name in width_names)
    theme_buttons_html = "".join('<label><input type="checkbox" class="filter-theme" value="%s" checked> %s</label>' % (
        theme_name, THEME_LABEL_BY_NAME.get(theme_name, theme_name)) for theme_name in theme_names)
    page_html = INDEX_TEMPLATE.replace("{{created_at}}", html.escape(created_at_text)) \
        .replace("{{storage_key}}", json.dumps("screen_gallery:" + created_at_text)) \
        .replace("{{width_buttons}}", width_buttons_html).replace("{{theme_buttons}}", theme_buttons_html) \
        .replace("{{sections}}", "\n".join(sections_html))
    with open(os.path.join(output_directory, "index.html"), "w", encoding="utf-8", newline="\n") as index_file:
        index_file.write(page_html)


INDEX_TEMPLATE = """<!doctype html>
<html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>画面の目視確認</title>
<style>
body { font-family: "Yu Gothic UI", Meiryo, sans-serif; margin: 0; background: #f4f5f7; color: #222; }
.bar { position: sticky; top: 0; z-index: 2; background: #fff; border-bottom: 1px solid #ccc; padding: 8px 16px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
.bar h1 { font-size: 16px; margin: 0; } .bar .progress { font-weight: bold; }
main { padding: 8px 16px 40px; }
h2 { font-size: 18px; margin: 24px 0 8px; border-left: 6px solid #3367d6; padding-left: 8px; } h2 small { color: #777; font-weight: normal; }
.card { background: #fff; border: 1px solid #ddd; border-radius: 6px; margin: 10px 0; padding: 8px 10px; }
.card.is-done { border-color: #3a9a4a; background: #f3fbf4; } .card.has-auto-notes header { color: #b3261e; }
.card header { display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap; } .card header code { color: #666; font-size: 12px; }
.screen-number { background: #eef; padding: 0 6px; border-radius: 4px; font-size: 12px; }
.done { margin-left: auto; }
.shots { display: flex; gap: 12px; overflow-x: auto; align-items: flex-start; padding: 6px 0; }
.shot { margin: 0; flex: none; } .shot figcaption { font-size: 12px; color: #555; }
.shot img { display: block; border: 1px solid #ccc; max-height: 520px; object-fit: cover; object-position: top; }
.shot-pc img { width: 480px; } .shot-mobile img { width: 188px; }
.shots.large .shot-pc img { width: 1000px; max-height: none; } .shots.large .shot-mobile img { width: 375px; max-height: none; }
.auto-notes { color: #b3261e; font-size: 12px; margin: 4px 0 0; padding-left: 18px; max-width: 480px; }
.memo { width: 100%; box-sizing: border-box; font: inherit; }
.hidden { display: none !important; }
#export { width: 100%; box-sizing: border-box; height: 200px; }
#export-panel { background: #fffbe6; border: 2px solid #e0b000; border-radius: 6px; padding: 8px 10px; scroll-margin-top: 70px; }
#export-status { margin: 0 0 6px; font-weight: bold; }
</style></head><body>
<div class="bar"><h1>画面の目視確認</h1><span>{{created_at}}</span><span class="progress"></span>
<span>幅: {{width_buttons}}</span><span>テーマ: {{theme_buttons}}</span>
<label><input type="checkbox" id="large"> 大きく表示</label>
<label><input type="checkbox" id="only-open"> 未確認だけ</label>
<button type="button" id="export-button">メモを書き出す</button></div>
<main>
<p>画像を押すと原寸で開きます。印とメモはこのブラウザーの中に残ります（画像を撮り直すと別の一覧になります）。「メモを書き出す」で、課題管理に貼れる形にします。自動で見つけた気づきは赤字です。</p>
<div id="export-panel" class="hidden"><p id="export-status"></p><textarea id="export" readonly></textarea></div>
{{sections}}
</main>
<script>
(function () {
  var storageKey = {{storage_key}};
  var savedState = {};
  try { savedState = JSON.parse(localStorage.getItem(storageKey) || '{}'); } catch (error) { savedState = {}; }
  var cards = Array.prototype.slice.call(document.querySelectorAll('.card'));
  function save() { try { localStorage.setItem(storageKey, JSON.stringify(savedState)); } catch (error) {} }
  function refresh() {
    var doneCount = 0;
    var onlyOpen = document.getElementById('only-open').checked;
    cards.forEach(function (card) {
      var isDone = card.querySelector('.done-check').checked;
      card.classList.toggle('is-done', isDone);
      card.classList.toggle('hidden', onlyOpen && isDone);
      if (isDone) { doneCount++; }
    });
    document.querySelector('.progress').textContent = '確認済み ' + doneCount + ' / ' + cards.length;
  }
  cards.forEach(function (card) {
    var cardState = savedState[card.dataset.key] || {};
    var doneCheck = card.querySelector('.done-check');
    var memoArea = card.querySelector('.memo');
    doneCheck.checked = !!cardState.done;
    memoArea.value = cardState.memo || '';
    doneCheck.addEventListener('change', function () { savedState[card.dataset.key] = {done: doneCheck.checked, memo: memoArea.value}; save(); refresh(); });
    memoArea.addEventListener('input', function () { savedState[card.dataset.key] = {done: doneCheck.checked, memo: memoArea.value}; save(); });
  });
  function applyFilters() {
    var shownWidths = Array.prototype.filter.call(document.querySelectorAll('.filter-width'), function (box) { return box.checked; }).map(function (box) { return box.value; });
    var shownThemes = Array.prototype.filter.call(document.querySelectorAll('.filter-theme'), function (box) { return box.checked; }).map(function (box) { return box.value; });
    Array.prototype.forEach.call(document.querySelectorAll('.shot'), function (shot) {
      shot.classList.toggle('hidden', shownWidths.indexOf(shot.dataset.width) < 0 || shownThemes.indexOf(shot.dataset.theme) < 0);
    });
  }
  Array.prototype.forEach.call(document.querySelectorAll('.filter-width, .filter-theme'), function (box) { box.addEventListener('change', applyFilters); });
  document.getElementById('large').addEventListener('change', function (event) {
    Array.prototype.forEach.call(document.querySelectorAll('.shots'), function (shots) { shots.classList.toggle('large', event.target.checked); });
  });
  document.getElementById('only-open').addEventListener('change', refresh);
  document.getElementById('export-button').addEventListener('click', function () {
    var lines = [];
    cards.forEach(function (card) {
      var memoText = card.querySelector('.memo').value.trim();
      if (!memoText) { return; }
      var headerText = card.querySelector('.screen-number').textContent + ' ' + card.querySelector('b').textContent;
      var roleText = card.closest('.role').querySelector('h2').textContent;
      lines.push('- ' + headerText + '（' + roleText + '、' + card.querySelector('code').textContent + '）: ' + memoText.replace(/\\s*\\n\\s*/g, ' / '));
    });
    var exportArea = document.getElementById('export');
    exportArea.value = lines.length ? lines.join('\\n') : '（メモはありません）';
    var exportPanel = document.getElementById('export-panel');
    var exportStatus = document.getElementById('export-status');
    exportPanel.classList.remove('hidden');
    exportPanel.scrollIntoView({block: 'start'});
    exportArea.focus();
    exportArea.select();
    var copied = false;
    try { copied = document.execCommand('copy'); } catch (error) { copied = false; }
    exportStatus.textContent = (lines.length ? lines.length + ' 件のメモを書き出しました。' : '') +
      (copied ? 'クリップボードにコピーしました。メールなどに貼り付けて返送してください。' : '下の欄の文をコピーして、メールなどに貼り付けて返送してください。');
  });
  refresh();
})();
</script>
</body></html>
"""


def main():
    argument_parser = argparse.ArgumentParser(description="画面の目視確認の道具（画面の画像と一覧のページを作る）")
    argument_parser.add_argument("--base", default="http://127.0.0.1:8000")
    argument_parser.add_argument("--db", required=True, help="ローカルの DB（app/data/dev.sqlite）。報告の番号を調べるのに読む")
    argument_parser.add_argument("--out", default="", help="出力先（既定: tools/test/output/画面一覧/<日時>）")
    argument_parser.add_argument("--roles", default=",".join(DEFAULT_ROLE_LOGIN_IDS),
                                 help="撮る役割のログイン ID（カンマ区切り）。使える ID: " + ",".join(ROLE_LABEL_BY_LOGIN_ID))
    argument_parser.add_argument("--widths", default="pc,mobile", help="pc,mobile")
    argument_parser.add_argument("--themes", default="light", help="light,dark,sakura,wakaba")
    argument_parser.add_argument("--screens", default="", help="撮る画面の番号（カンマ区切り。例: G-22）。空ならすべて")
    argument_parser.add_argument("--no-prepare", action="store_true", help="見本の報告を作らない")
    argument_parser.add_argument("--show", action="store_true", help="ブラウザーを表示して実行する")
    argument_parser.add_argument("--browser-port", type=int, default=0, help="起動済みのブラウザー（--remote-debugging-port）につなぐ")
    arguments = argument_parser.parse_args()
    if "127.0.0.1" not in arguments.base and "localhost" not in arguments.base:
        print("ローカル検証環境（127.0.0.1）以外には実行しません")
        return 2

    role_login_ids = [login_id.strip() for login_id in arguments.roles.split(",") if login_id.strip()]
    width_names = [width_name.strip() for width_name in arguments.widths.split(",") if width_name.strip() in WIDTH_LABEL_BY_NAME]
    theme_names = [theme_name.strip() for theme_name in arguments.themes.split(",") if theme_name.strip() in THEME_LABEL_BY_NAME]
    unknown_login_ids = [login_id for login_id in role_login_ids if login_id not in ROLE_LABEL_BY_LOGIN_ID]
    screen_number_filter_set = set(screen_number.strip() for screen_number in arguments.screens.split(",") if screen_number.strip())
    if unknown_login_ids or not width_names or not theme_names:
        print("指定が正しくありません: 役割 %s / 幅 %s / テーマ %s" % (unknown_login_ids or "OK", width_names or "なし", theme_names or "なし"))
        return 2

    now = datetime.datetime.now()
    created_at_text = now.strftime("%Y-%m-%d %H:%M")
    output_directory = arguments.out or os.path.join(HERE, "output", "画面一覧", now.strftime("%Y%m%d_%H%M%S"))
    os.makedirs(output_directory, exist_ok=True)
    user_by_login_id = json.load(open(os.path.join(HERE, "output_dev_users.json"), encoding="utf-8"))["users"]
    today = datetime.date.today()
    previous_month_text = (today.replace(day=1) - datetime.timedelta(days=1)).strftime("%Y-%m")
    current_month_text = today.strftime("%Y-%m")

    browser = Browser(headless=not arguments.show, attach_port=arguments.browser_port or None)
    gallery = ScreenGallery(browser, arguments.base, user_by_login_id, output_directory)
    try:
        if not arguments.no_prepare:
            try:
                prepare_sample_report(gallery, arguments.db, previous_month_text)
            except Exception as error:  # noqa: BLE001
                print("  見本の報告を作れませんでした: %r（報告なしで撮ります）" % error)
        report_id_by_login_id = find_report_ids(arguments.db, user_by_login_id)
        screen_list_by_login_id = build_screen_list_by_login_id(
            report_id_by_login_id, previous_month_text, current_month_text, user_by_login_id["ippan_a1"]["id"])
        for login_id in role_login_ids:
            print("%s（%s）" % (ROLE_LABEL_BY_LOGIN_ID[login_id], login_id))
            for width_name in width_names:
                gallery.set_width(width_name)
                if not gallery.login(login_id):
                    print("  ログインできませんでした（2 段階認証の設定やパスワードを確かめてください）")
                    break
                for screen_number, screen_name, url_path in screen_list_by_login_id[login_id]:
                    if screen_number_filter_set and screen_number not in screen_number_filter_set:
                        continue
                    try:
                        gallery.shoot_screen(login_id, screen_number, screen_name, url_path, width_name, theme_names)
                    except Exception as error:  # noqa: BLE001
                        print("  撮れませんでした: %s %s: %r" % (screen_number, url_path, error))
    finally:
        browser.quit()
        write_index_page(output_directory, gallery.shot_records, role_login_ids, width_names, theme_names, created_at_text)
    print("\n%d 枚。一覧: %s" % (len(gallery.shot_records), os.path.join(output_directory, "index.html")))
    return 0


if __name__ == "__main__":
    sys.exit(main())
