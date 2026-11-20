#!/usr/bin/env python3
"""操作マニュアルの画面を撮る道具（ローカル検証環境専用）。

操作マニュアル（docs/operation_manual.md）の手順ごとに、実際の画面を操作して撮り、
押す場所・入れる場所に赤い枠と番号（手順の番号と同じ）を付けた画像を docs/manual/images/ に書く。

    python tools/lib/manual_capture.py                 # すべての画面を撮り直す
    python tools/lib/manual_capture.py --only m04      # 名前が m04 で始まる画像だけ撮り直す（準備の操作は毎回すべて行う）
    python tools/lib/manual_capture.py --check         # マニュアルが使う画像がそろっているか・使っていない画像がないかだけ確かめる
    python tools/lib/manual_capture.py --show          # ブラウザーを表示して撮る（どう動くかを見るとき）

- 自分用の DB（app/data/manual.sqlite）と空いている番号のサーバーを使い、撮るたびにテストデータを作り直す。
  画面を見ている dev.sqlite・自動検証の verify.sqlite・config.local.php には触れない（環境変数 MR_VERIFY_DB。app/config/config.php）。
- 画像に写る名前・案件はテストデータのもの。2 段階認証のセットアップキー・予備のコード・アドレスは、見本の文字に置き換えて撮る。
- ブラウザーは Chrome / Edge / Chromium（tools/test/cdp.py）。Windows の Claude Code からは Bash で実行する。
Python 3.8 以上の標準ライブラリだけで動く。
"""
import argparse
import datetime
import json
import os
import re
import secrets
import sqlite3
import subprocess
import sys
import time

REPOSITORY_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
TEST_TOOL_DIR = os.path.join(REPOSITORY_ROOT, "tools", "test")
sys.path.insert(0, TEST_TOOL_DIR)
from verify_all import find_php, free_port, php_server_command, wait_server  # noqa: E402

MANUAL_PATH = os.path.join(REPOSITORY_ROOT, "docs", "operation_manual.md")
IMAGE_DIR = os.path.join(REPOSITORY_ROOT, "docs", "manual", "images")
MANUAL_DB_PATH = os.path.join(REPOSITORY_ROOT, "app", "data", "manual.sqlite")

PC_METRICS = {"width": 1280, "height": 800, "deviceScaleFactor": 1, "mobile": False}
PC_USER_AGENT = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36"
MOBILE_METRICS = {"width": 390, "height": 760, "deviceScaleFactor": 1, "mobile": True}
MOBILE_USER_AGENT = "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1"
MAX_SHOT_HEIGHT = 1800

SAMPLE_SECRET_TEXT = "ABCD EFGH IJKL MNOP QRST UVWX YZ23 4567"
SAMPLE_LOGIN_URL_TEXT = "https://（このシステムのアドレス）/login.php"
SAMPLE_BACKUP_CODE_TEXT = "xxxx-xxxxxx"
FIRST_LOGIN_ID = "shinnyu01"

# 印を付ける JavaScript。印の指定は {"sel": CSS, "text": 含む文字, "nth": 何番目, "up": 枠を広げる先の CSS, "label": 番号}
MARK_JS = r"""(function (markList, scrollMode) {
  function isVisible(element) { var rect = element.getBoundingClientRect(); return rect.width > 0 && rect.height > 0; }
  function findTarget(mark) {
    var candidateList = Array.prototype.filter.call(document.querySelectorAll(mark.sel), isVisible);
    if (mark.text) { candidateList = candidateList.filter(function (element) { return element.textContent.replace(/\s+/g, ' ').indexOf(mark.text) >= 0; }); }
    var target = candidateList[mark.nth || 0] || null;
    if (target && mark.up) { target = target.closest(mark.up) || target; }
    return target;
  }
  var targetList = markList.map(findTarget);
  var missingList = [];
  markList.forEach(function (mark, index) { if (!targetList[index]) { missingList.push(mark.sel + (mark.text ? ' / ' + mark.text : '')); } });
  if (missingList.length) { return {missing: missingList}; }
  if (targetList.length && scrollMode !== 'none' && scrollMode !== 'bottom') {
    // 印がすべて入る高さ（足りなければ、高さを広げてから撮り直す）
    var markTop = Math.min.apply(null, targetList.map(function (element) { return element.getBoundingClientRect().top + window.scrollY; }));
    var markBottom = Math.max.apply(null, targetList.map(function (element) { return element.getBoundingClientRect().bottom + window.scrollY; }));
    var neededHeight = Math.ceil(scrollMode === 'top' ? markBottom + 60 : markBottom - markTop + 200);
    if (neededHeight > window.innerHeight) { return {missing: [], need: neededHeight}; }
  }
  if (scrollMode === 'top') { window.scrollTo(0, 0); }
  else if (scrollMode === 'bottom') { window.scrollTo(0, document.documentElement.scrollHeight); }
  else if (targetList.length) {
    var firstTop = Math.min.apply(null, targetList.map(function (element) { return element.getBoundingClientRect().top + window.scrollY; }));
    var lastBottom = Math.max.apply(null, targetList.map(function (element) { return element.getBoundingClientRect().bottom + window.scrollY; }));
    var span = lastBottom - firstTop;
    var margin = span < window.innerHeight - 160 ? (window.innerHeight - span) / 2 : 80;
    window.scrollTo(0, Math.max(0, firstTop - margin));
  }
  var layer = document.createElement('div');
  layer.id = '__manual_marks';
  layer.style.cssText = 'position:absolute;left:0;top:0;width:0;height:0;z-index:2147483646;pointer-events:none;';
  document.body.appendChild(layer);
  targetList.forEach(function (element, index) {
    var rect = element.getBoundingClientRect();
    var box = document.createElement('div');
    box.style.cssText = 'position:absolute;border:3px solid #e53935;border-radius:6px;box-shadow:0 0 0 2px rgba(255,255,255,.85);'
      + 'left:' + (rect.left + window.scrollX - 5) + 'px;top:' + (rect.top + window.scrollY - 5) + 'px;'
      + 'width:' + (rect.width + 4) + 'px;height:' + (rect.height + 4) + 'px;';
    layer.appendChild(box);
    var label = markList[index].label;
    if (label) {
      var badge = document.createElement('div');
      badge.textContent = label;
      var badgeLeft = rect.left + window.scrollX - 18;
      var badgeTop = rect.top + window.scrollY - 18;
      badge.style.cssText = 'position:absolute;min-width:26px;height:26px;padding:0 4px;box-sizing:border-box;border-radius:13px;'
        + 'background:#e53935;color:#fff;font:bold 15px/26px sans-serif;text-align:center;border:2px solid #fff;'
        + 'left:' + Math.max(2, badgeLeft) + 'px;top:' + Math.max(2, badgeTop) + 'px;';
      layer.appendChild(badge);
    }
  });
  return {missing: []};
})"""

REMOVE_MARKS_JS = "(function () { var layer = document.getElementById('__manual_marks'); if (layer) { layer.remove(); } return true; })()"

# 撮る前の整え: 動くもの（花びらなど）と、入力欄の点滅するカーソルを止める
CALM_PAGE_JS = r"""(function () {
  if (!document.getElementById('__manual_calm')) {
    var style = document.createElement('style');
    style.id = '__manual_calm';
    style.textContent = '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}';
    document.head.appendChild(style);
  }
  if (document.activeElement && document.activeElement.blur) { document.activeElement.blur(); }
  return true;
})()"""


def mark(selector, text="", label="", nth=0, up=""):
    return {"sel": selector, "text": text, "label": str(label), "nth": nth, "up": up}


class ManualCamera:
    def __init__(self, browser, base_url, user_by_login_id, only_prefix):
        self.browser = browser
        self.base_url = base_url
        self.user_by_login_id = user_by_login_id
        self.only_prefix = only_prefix
        self.taken_names = []
        self.failed_list = []
        self.width_name = "pc"

    # ---- 画面の幅 ----
    def use_width(self, width_name):
        metrics, user_agent_text = (MOBILE_METRICS, MOBILE_USER_AGENT) if width_name == "mobile" else (PC_METRICS, PC_USER_AGENT)
        self.browser.call("Emulation.setDeviceMetricsOverride", metrics)
        self.browser.call("Emulation.setUserAgentOverride", {"userAgent": user_agent_text})
        # スマートフォンは指で触る端末として見せる（PC だけに出す案内（hover と pointer で分けている）を出さない）
        is_mobile = width_name == "mobile"
        self.browser.call("Emulation.setTouchEmulationEnabled", {"enabled": is_mobile, "maxTouchPoints": 5 if is_mobile else 1})
        media_feature_list = [{"name": "hover", "value": "none" if is_mobile else "hover"},
                              {"name": "pointer", "value": "coarse" if is_mobile else "fine"}]
        try:
            self.browser.call("Emulation.setEmulatedMedia", {"features": media_feature_list})
        except Exception:  # noqa: BLE001
            pass
        self.width_name = width_name

    # ---- 操作 ----
    def goto(self, url_path):
        self.browser.goto(self.base_url + url_path)

    def login(self, login_id, password=None):
        """パスワード（と 2 段階認証のコード）でログインする。ホームに着いたら True。"""
        from lib_http import next_login_code
        self.browser.clear_cookies()
        self.goto("/login.php")
        self.browser.fill("#login_id", login_id)
        self.browser.fill("#password", password or self.user_by_login_id[login_id]["password"])
        self.browser.click("form.form button[type=submit]", navigate=True)
        if self.browser.exists("#code") and login_id in self.user_by_login_id:
            self.browser.fill("#code", next_login_code(login_id, self.user_by_login_id[login_id]["totp"]))
            self.browser.click("form.form button[type=submit]", navigate=True)
        return "ホーム" in (self.browser.eval("document.title") or "")

    def click_text(self, selector, text, navigate=False):
        """selector のうち、文字に text を含む最初の見えている要素を押す"""
        js = ("(function () { var list = Array.prototype.filter.call(document.querySelectorAll(%s), function (element) {"
              " var rect = element.getBoundingClientRect(); return rect.width > 0 && rect.height > 0 && element.textContent.indexOf(%s) >= 0; });"
              " if (!list.length) { throw new Error('not found: ' + %s + ' / ' + %s); } list[0].scrollIntoView({block: 'center'}); list[0].click(); return true; })()") % (
            json.dumps(selector), json.dumps(text), json.dumps(selector), json.dumps(text))
        if navigate:
            self.browser.mark()
        self.browser.eval(js)
        if navigate:
            time.sleep(0.3)
            self.browser.wait_load()
        else:
            time.sleep(0.25)

    def set_text(self, selector, text):
        """見えている文字を置き換える（撮る画像に本物の鍵・コード・アドレスを残さない）"""
        self.browser.eval("(function () { Array.prototype.forEach.call(document.querySelectorAll(%s), function (element) { element.textContent = %s; }); return true; })()"
                          % (json.dumps(selector), json.dumps(text)))

    # ---- 撮る ----
    def wanted(self, image_name):
        return not self.only_prefix or image_name.startswith(self.only_prefix)

    def shoot(self, image_name, mark_list=(), scroll="marks"):
        """印を付けて、いま見えている範囲を撮る。scroll は marks（印が真ん中に来るように）・top・bottom・none"""
        if not self.wanted(image_name):
            return
        try:
            self.browser.eval(CALM_PAGE_JS)
            if scroll == "none":
                scroll_mode = "none"
            elif scroll == "marks" and not mark_list:
                scroll_mode = "top"
            else:
                scroll_mode = scroll
            mark_call_js = "%s(%s, %s)" % (MARK_JS, json.dumps(list(mark_list), ensure_ascii=False), json.dumps(scroll_mode))
            result = self.browser.eval(mark_call_js)
            if result and result.get("missing"):
                raise RuntimeError("印を付ける場所が見つかりません: " + "、".join(result["missing"]))
            is_stretched = False
            if result and result.get("need"):
                # 印が 1 画面に入らない: 画像の高さを広げて撮る（長くなりすぎないよう MAX_SHOT_HEIGHT まで）
                base_metrics = MOBILE_METRICS if self.width_name == "mobile" else PC_METRICS
                self.browser.call("Emulation.setDeviceMetricsOverride", dict(base_metrics, height=min(int(result["need"]), MAX_SHOT_HEIGHT)))
                is_stretched = True
                time.sleep(0.3)
                # 広げても入りきらないときは、入るところまでで撮る
                self.browser.eval(mark_call_js.replace("if (neededHeight > window.innerHeight)", "if (false)"))
            time.sleep(0.2)
            self.browser.screenshot(os.path.join(IMAGE_DIR, image_name + ".png"))
            self.browser.eval(REMOVE_MARKS_JS)
            if is_stretched:
                self.use_width(self.width_name)
            self.taken_names.append(image_name)
            print("  撮った  %s（%s）" % (image_name, "スマートフォン" if self.width_name == "mobile" else "PC"))
        except Exception as error:  # noqa: BLE001
            self.failed_list.append((image_name, str(error)))
            print("  撮れない %s: %s" % (image_name, error))


def run_scene(camera, scene_name, scene_function):
    """場面ごとに撮る。途中で失敗しても、次の場面に進む"""
    print("[%s]" % scene_name)
    try:
        scene_function()
    except Exception as error:  # noqa: BLE001
        camera.failed_list.append((scene_name, "場面の途中で止まった: %r" % error))
        print("  場面の途中で止まった: %r" % error)


def create_first_login_user(php_path, environment):
    """はじめてのログインを撮るための人を作り、仮のパスワードを返す"""
    created = subprocess.run([php_path, os.path.join("app", "bin", "create_user.php"), "--login=" + FIRST_LOGIN_ID, "--name=新人", "--dept=A課"],
                             cwd=REPOSITORY_ROOT, env=environment, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    password_match = re.search(r"仮のパスワード: (\S+)", created.stdout.decode("utf-8", "replace"))
    if not password_match:
        raise RuntimeError("はじめてのログインの人を作れません: " + created.stdout.decode("utf-8", "replace")[-300:])
    return password_match.group(1)


def capture_all(camera, php_path, environment, database_path):
    browser = camera.browser
    users = camera.user_by_login_id
    today = datetime.date.today()
    previous_month = (today.replace(day=1) - datetime.timedelta(days=1)).strftime("%Y-%m")
    current_month = today.strftime("%Y-%m")
    month_before_previous = (today.replace(day=1) - datetime.timedelta(days=1)).replace(day=1) - datetime.timedelta(days=1)
    older_month = month_before_previous.strftime("%Y-%m")
    database = sqlite3.connect("file:%s?mode=ro" % database_path, uri=True)
    sample_report_id = database.execute("SELECT id FROM report_records WHERE user_id = ? AND period = ?",
                                        (users["ippan_a1"]["id"], previous_month)).fetchone()[0]
    sample_request_row = database.execute("SELECT id FROM support_requests ORDER BY id LIMIT 1").fetchone()
    sample_survey_row = database.execute("SELECT id FROM adhoc_requests ORDER BY id LIMIT 1").fetchone()
    database.close()

    # ---- 準備: 標準のよくある質問を取り込む（チャットの答えを出すため。撮らない） ----
    def prepare_faq():
        camera.use_width("pc")
        camera.login("admin01")
        camera.goto("/faq.php")
        if browser.exists('button[value="import_standard"]'):
            browser.click('button[value="import_standard"]', navigate=True)
    run_scene(camera, "準備（よくある質問の取り込み）", prepare_faq)

    # ---- 2 章 ログイン ----
    def chapter_login():
        camera.use_width("pc")
        browser.clear_cookies()
        camera.goto("/login.php")
        browser.fill("#login_id", "ippan_a1")
        browser.fill("#password", users["ippan_a1"]["password"])
        camera.shoot("m02_01_login", [mark("#login_id", label=1), mark("#password", label=2), mark("form.form button[type=submit]", label=3)])
        browser.click("form.form button[type=submit]", navigate=True)
        from lib_http import next_login_code
        browser.fill("#code", next_login_code("ippan_a1", users["ippan_a1"]["totp"]))
        camera.shoot("m02_02_code", [mark("#code", label=1), mark('input[name="remember"]', label=2, up="label"), mark("form.form button[type=submit]", label=3)])
        browser.click("form.form button[type=submit]", navigate=True)
        camera.shoot("m02_03_home_after_login", [mark("h2", text="の報告", label="")], scroll="top")
        browser.clear_cookies()
        camera.goto("/login.php")
        camera.shoot("m02_09_forgot_link", [mark("a", text="パスワードを忘れた場合", label=1)])
        camera.goto("/password_reset.php")
        camera.shoot("m02_10_password_reset", [mark("input:not([type=hidden])", label=1, nth=0), mark("input:not([type=hidden])", label=2, nth=1),
                                                mark("input[type=password]", label=3, nth=0), mark("input[type=password]", label=4, nth=1),
                                                mark("form button.primary", label=5)])
    run_scene(camera, "2 章 ログイン", chapter_login)

    # ---- 2.2 はじめてのログイン（PC で開いたとき → スマートフォンで設定） ----
    def chapter_first_login():
        temporary_password = create_first_login_user(php_path, environment)
        camera.use_width("pc")
        camera.login(FIRST_LOGIN_ID, temporary_password)
        camera.set_text(".setup-pc-hint .secret", SAMPLE_LOGIN_URL_TEXT)
        camera.set_text(".steps .secret", SAMPLE_SECRET_TEXT)
        camera.shoot("m02_04_first_setup_pc", [mark(".setup-pc-hint", label=1)], scroll="top")
        camera.shoot("m02_05_first_setup_pc_key", [mark(".steps .secret", label=1), mark("#code", label=2), mark("form.form button.primary", label=3)])
        camera.use_width("mobile")
        camera.login(FIRST_LOGIN_ID, temporary_password)
        secret_text = (browser.eval("document.querySelector('.steps .secret').textContent") or "").replace(" ", "").strip()
        camera.set_text(".steps .secret", SAMPLE_SECRET_TEXT)
        camera.shoot("m02_06_first_setup_mobile", [mark("a.button", text="認証アプリに登録する", label=1)])
        from lib_http import totp
        browser.fill("#code", totp(secret_text))
        camera.shoot("m02_07_first_setup_mobile_code", [mark("#code", label=2), mark("form.form button.primary", label=3)])
        browser.click("form.form button.primary", navigate=True)
        camera.set_text("#backup-codes li", SAMPLE_BACKUP_CODE_TEXT)
        camera.shoot("m02_08_backup_codes", [mark("#backup-codes", label=1), mark(".copy-text", label=2), mark("a.button.primary", text="保管しました", label=3)])
        browser.click("a.button.primary", navigate=True)
        if browser.exists("#new_password"):
            # 新しいパスワードは撮るたびに作る（画像では「•」で隠れる）
            first_login_new_password = secrets.token_urlsafe(18)
            browser.fill("#new_password", first_login_new_password)
            browser.fill("#new_password_confirm", first_login_new_password)
            camera.shoot("m02_11_first_password", [mark("#new_password", label=1), mark("#new_password_confirm", label=2), mark("form.form button.primary", label=3)])
    run_scene(camera, "2.2 はじめてのログイン", chapter_first_login)

    # ---- 3 章 ホームとメニュー（部下・PC） ----
    def chapter_home():
        camera.use_width("pc")
        camera.login("ippan_a1")
        camera.shoot("m03_01_home_member", [mark("h2", text="の報告", label=1, up="section"), mark("h2", text="回答待ちのコメント", label=2, up="section"),
                                            mark(".app-menu summary", label=3)], scroll="top")
        browser.click(".app-menu summary")
        time.sleep(0.2)
        camera.shoot("m03_02_menu_open", [mark(".app-menu summary", label=1), mark(".app-menu > :not(summary)", label=2)], scroll="top")
        browser.click(".app-menu summary")
        camera.click_text("summary", "この画面の使い方")
        camera.shoot("m03_03_help_open", [mark("summary", text="この画面の使い方", label=1), mark("details[open] a", text="よくある質問", label=2)], scroll="top")
        camera.use_width("mobile")
        camera.goto("/index.php")
        camera.shoot("m03_04_home_mobile", [mark(".app-menu summary", label=1)], scroll="top")
    run_scene(camera, "3 章 ホームとメニュー", chapter_home)

    # ---- 4 章 報告を作る（部下） ----
    def chapter_report():
        camera.use_width("pc")
        camera.login("ippan_a1")
        camera.goto("/index.php")
        camera.shoot("m04_01_home_create", [mark("a", text="今月の報告を作る", label=1)] if browser.eval(
            "document.body.innerText.indexOf('今月の報告を作る') >= 0") else [mark("a", text="作る", label=1)])
        camera.goto("/reports/edit.php?period=" + current_month)
        camera.shoot("m04_02_edit_top", [mark('button[value="unchanged"]', label=1), mark("#copy_target", label=2), mark("button", text="写す", label=3)], scroll="top")
        camera.shoot("m04_03_edit_fields", [mark("#b0_project", label=1), mark("#b0_2", label=2), mark("#b0_3", label=3)])
        browser.click("#b0_4_1")
        time.sleep(0.25)
        camera.shoot("m04_04_conditional_field", [mark("#b0_4_1", label=1, up="label"), mark('.field[data-code="progress_note"]', label=2)])
        browser.click("#b0_4_0")
        original_duties = browser.eval("document.querySelector('#b0_2').value") or ""
        browser.fill("#b0_2", "株式会社サンプルの在庫管理の改修")
        time.sleep(0.3)
        camera.shoot("m04_05_customer_warning", [mark('.field[data-code="duties"] .field-warning', label=1)])
        browser.fill("#b0_2", original_duties or "在庫管理の改修")
        time.sleep(0.2)
        camera.shoot("m04_06_remove_project", [mark('input[name="b[0][remove]"]', label=1, up="label"), mark("button.duplicate-block", label=2)])
        camera.shoot("m04_07_add_project", [mark("#b1_project", label=1)])
        camera.shoot("m04_08_save_buttons", [mark('button[value="draft"]', label=1), mark('button[value="submit"]', label=2)])
        # スマートフォンの 1 問ずつの入力（提出する前の、今月の報告で撮る）
        camera.use_width("mobile")
        camera.goto("/reports/edit.php?period=" + current_month)
        browser.eval("localStorage.removeItem('reportEditMode')")
        camera.goto("/reports/edit.php?period=" + current_month)
        camera.shoot("m04_13_mobile_wizard", [mark(".wizard-progress", label=1), mark(".wizard-next", label=2), mark(".wizard-toggle", label=3)], scroll="bottom")
        browser.click(".wizard-toggle")
        time.sleep(0.25)
        camera.shoot("m04_14_mobile_list", [mark(".wizard-toggle", label=1)])
        browser.eval("localStorage.removeItem('reportEditMode')")
        camera.use_width("pc")
        camera.goto("/reports/edit.php?period=" + current_month)
        browser.click('button[value="draft"]', navigate=True)
        camera.shoot("m04_09_draft_saved", [], scroll="top")
        browser.click('button[value="submit"]', navigate=True)
        camera.shoot("m04_10_submitted", [], scroll="top")
        camera.goto("/reports/mine.php")
        camera.shoot("m04_11_mine", [mark("a", text=previous_month[:4] + "年", label=1, up="tr")], scroll="top")
        camera.goto("/reports/view.php?id=%d" % sample_report_id)
        camera.click_text("details.reply-form summary", "返信")
        browser.fill("details.reply-form textarea", "社内の案件コードに直しました")
        camera.shoot("m04_12_reply", [mark("details.reply-form summary", label=1), mark("details.reply-form textarea", label=2),
                                      mark('details.reply-form button[value="reply"]', label=3)])
        browser.click('details.reply-form button[value="reply"]', navigate=True)
        if sample_survey_row:
            camera.goto("/surveys/answer.php?id=%d" % sample_survey_row[0])
            camera.shoot("m04_15_survey_answer", [mark("form button.primary", label=1)])
    run_scene(camera, "4 章 報告を作る", chapter_report)

    # ---- 5 章 報告を確認する（確認者・課長） ----
    def chapter_review():
        camera.use_width("pc")
        camera.login("leader_a")
        camera.shoot("m05_01_home_reviewer", [mark("a", text="未チェックを順に見る", label=1), mark("a", text="提出状況", label=2, nth=0)], scroll="top")
        camera.goto("/admin/comment_templates.php")
        browser.fill("#new-template-body", "完了の見込みを教えてください")
        camera.shoot("m05_02_templates", [mark("#new-template-body", label=1), mark('button[value="add"]', label=2)])
        browser.click('button[value="add"]', navigate=True)
        camera.goto("/reports/view.php?id=%d" % sample_report_id)
        camera.shoot("m05_03_view_top", [mark("h1", label=1), mark("h2", text="チェック", label=2)], scroll="top")
        browser.eval("document.querySelector('details.comment-form summary').scrollIntoView({block: 'center'})")
        browser.click("details.comment-form summary")
        time.sleep(0.2)
        if browser.exists("details.comment-form select.comment-template-select"):
            browser.fill("details.comment-form select.comment-template-select", "完了の見込みを教えてください")
            time.sleep(0.2)
        camera.shoot("m05_04_comment_form", [mark("details.comment-form summary", label=1), mark('details.comment-form select[name="comment_type"]', label=2),
                                             mark("details.comment-form select.comment-template-select", label=3),
                                             mark('details.comment-form button[value="comment"]', label=4)])
        browser.click('details.comment-form button[value="comment"]', navigate=True)
        camera.click_text("summary", "返信・解決")
        camera.shoot("m05_05_resolve", [mark("summary", text="返信・解決", label=1), mark('button[value="resolve"]', label=2)])
        camera.shoot("m05_06_check_button", [mark('button[value="check"]', label=1)])
        browser.click('button[value="check"]', navigate=True)
        camera.shoot("m05_07_checked", [mark("h2", text="チェック", label=1)], scroll="top")
        camera.goto("/reports/comments.php")
        camera.shoot("m07_01_comment_list", [mark("a", text="自分が出したコメント", label=1), mark("a", text="自分の報告へのコメント", label=2),
                                             mark('select[name="status"]', label=3)], scroll="top")
        camera.login("kacho_a")
        camera.goto("/reports/view.php?id=%d" % sample_report_id)
        camera.shoot("m05_08_confirm_button", [mark('button[value="check"]', label=1), mark('button[value="confirm"]', label=2)])
    run_scene(camera, "5 章 報告を確認する", chapter_review)

    # ---- 6 章 提出状況・横並び・検索・出力・臨時の調査（課長） ----
    def chapter_status():
        camera.use_width("pc")
        camera.login("kacho_a")
        camera.goto("/status/index.php?period=" + previous_month)
        camera.shoot("m06_01_status", [mark('input[name="period"]', label=1), mark("a", text="未チェックを順に見る", label=2),
                                       mark("a", text="横並び・まとめ", label=3), mark('button[value="remind"]', label=4)], scroll="top")
        camera.goto("/status/compare.php?period=" + previous_month)
        camera.shoot("m06_02_compare", [mark('input[name="diff"]', label=1, up="label"), mark("button.open-cell", label=2)], scroll="top")
        camera.goto("/reports/search.php")
        browser.fill("#search-keyword", "在庫")
        camera.shoot("m06_03_search", [mark('input[name="from"]', label=1), mark('input[name="scope"]', label=2, up="fieldset, .field, p, div"),
                                       mark("#search-keyword", label=3), mark("button.primary", text="探す", label=4)], scroll="top")
        browser.click("button.primary", navigate=True)
        camera.shoot("m06_04_search_result", [], scroll="top")
        camera.goto("/export/index.php")
        camera.shoot("m06_05_export", [mark('input[name="type"][value="report"]', label=1, up="label"), mark("#from", label=2),
                                       mark('input[name="content"][value="table"]', label=3, up="fieldset, .field, div"),
                                       mark('input[name="format"][value="print"]', label=4, up="fieldset, .field, div"),
                                       mark("button.primary", text="出力する", label=5)], scroll="top")
        browser.click('input[name="format"][value="print"]')
        browser.eval("Array.prototype.forEach.call(document.querySelectorAll('form'), function (formElement) { formElement.removeAttribute('target'); }); true")
        camera.click_text("button.primary", "出力する", navigate=True)
        camera.shoot("m06_06_print_view", [], scroll="top")
        camera.goto("/surveys/manage.php")
        camera.shoot("m06_07_survey_manage", [mark("a", text="新しく作る", label=1), mark("a", text="結果", label=2), mark("a", text="設定", label=3)], scroll="top")
        camera.goto("/surveys/survey_edit.php")
        camera.shoot("m06_08_survey_new", [], scroll="top")
        if sample_survey_row:
            camera.goto("/surveys/result.php?id=%d" % sample_survey_row[0])
            camera.shoot("m06_09_survey_result", [], scroll="top")
    run_scene(camera, "6 章 提出状況・出力など", chapter_status)

    # ---- 7 章 要望・依頼・よくある質問 ----
    def chapter_requests():
        camera.use_width("pc")
        camera.login("kacho_a")
        camera.goto("/status/index.php?period=" + previous_month)
        camera.shoot("m07_02_request_link", [mark("a", text="この画面について要望・依頼を出す", label=1)])
        camera.click_text("a", "この画面について要望・依頼を出す", navigate=True)
        camera.shoot("m07_03_request_new", [mark("#new select", label=1, nth=0), mark("#new input[type=text]", label=2, nth=0), mark("#new textarea", label=3, nth=0),
                                            mark("#new button", text="出す", label=4)])
        camera.goto("/faq.php")
        camera.shoot("m07_04_faq_top", [mark("#faq_search_query", label=1), mark("#faq-chat a[data-category]", label=2)], scroll="top")
        browser.fill("#faq_search_query", "パスワードを忘れた")
        browser.click("#faq-chat form.faq-chat-input button[type=submit]")
        browser.wait_until("Array.prototype.some.call(document.querySelectorAll('#faq-chat .chat-choices button'), function (choiceButton) { return choiceButton.textContent === '解決した'; })")
        camera.shoot("m07_05_faq_answer", [mark("#faq-chat .chat-bot", text="Q.", label=1),
                                           mark("#faq-chat .chat-choices button", text="解決した", label=2),
                                           mark("#faq-chat .chat-choices button", text="解決しない", label=3)])
    run_scene(camera, "7 章 要望・依頼・よくある質問", chapter_requests)

    # ---- 8 章 課長の仕事 ----
    def chapter_manager():
        camera.use_width("pc")
        camera.login("kacho_a")
        camera.goto("/admin/department.php?dept=1")
        camera.shoot("m08_01_department", [mark(".section-tabs", label=1)], scroll="top")
        camera.goto("/admin/fields.php")
        camera.shoot("m08_02_fields", [mark("#dept", label=1), mark("a", text="項目を足す", label=2), mark("a", text="プレビュー", label=3),
                                       mark("a", text="この課の選択肢", label=4)], scroll="top")
        camera.goto("/admin/field_edit.php?dept=1")
        camera.shoot("m08_03_field_add", [mark("#label", label=1), mark('input[name="field_scope"]', label=2, up="fieldset, .field, div"),
                                          mark("#field_type", label=3)], scroll="top")
        camera.goto("/admin/field_edit.php?preview=1")
        camera.shoot("m08_04_preview", [], scroll="top")
        camera.goto("/admin/messages.php")
        camera.shoot("m08_05_messages", [mark("button", text="絞り込む", label=1), mark('button[value="save"]', label=2)], scroll="top")
    run_scene(camera, "8 章 課長の仕事", chapter_manager)

    # ---- 9 章 システム管理者 ----
    def chapter_admin():
        camera.use_width("pc")
        camera.login("admin01")
        camera.shoot("m09_01_admin_home", [], scroll="top")
        camera.goto("/admin/index.php")
        camera.shoot("m09_02_admin_top", [mark(".admin-nav", label=1), mark(".admin-card", label=2, nth=0)], scroll="top")
        camera.goto("/admin/org.php?tab=departments")
        camera.shoot("m09_03_org_departments", [mark('a[href$="tab=users"]', label=1, nth=0), mark("a.button", text="課を追加", label=2)], scroll="top")
        camera.goto("/admin/org.php?tab=users")
        camera.shoot("m09_04_org_users", [mark("a.button", text="ユーザーを追加", label=1)], scroll="top")
        camera.goto("/admin/user_edit.php?id=%d" % users["ippan_a1"]["id"])
        camera.shoot("m09_05_user_edit", [mark("button", text="仮のパスワードを発行", label=1), mark("button", text="2 段階認証を初期化", label=2),
                                           mark("button", text="予備のコードを初期化", label=3), mark("button", text="ロックを解除", label=4)])
        camera.goto("/admin/org.php?tab=bulk")
        camera.shoot("m09_06_org_bulk", [], scroll="top")
        camera.goto("/admin/projects.php")
        camera.shoot("m09_07_projects", [mark("button", text="案件を追加", label=1), mark("a.button", text="CSV に出力", label=2)], scroll="top")
        camera.goto("/admin/logs.php")
        camera.shoot("m09_08_logs", [], scroll="top")
        camera.goto("/admin/trash.php")
        camera.shoot("m09_09_trash", [], scroll="top")
        camera.goto("/admin/notices.php")
        camera.shoot("m09_10_notices", [], scroll="top")
        camera.goto("/requests/index.php?status=all")
        camera.shoot("m09_11_requests", [], scroll="top")
        if sample_request_row:
            camera.goto("/requests/view.php?id=%d" % sample_request_row[0])
            camera.shoot("m09_12_request_view", [mark("textarea", label=1, nth=0), mark("button", text="返信だけ送る", label=2)])
        camera.goto("/faq.php")
        camera.shoot("m09_13_faq_manage", [], scroll="bottom")
        camera.goto("/admin/faq_logs.php")
        camera.shoot("m09_14_faq_logs", [], scroll="top")
    run_scene(camera, "9 章 システム管理者", chapter_admin)

    # ---- 10 章 アカウント設定 ----
    def chapter_account():
        camera.use_width("pc")
        camera.login("ippan_a1")
        for tab_name, image_name in (("totp", "m10_01_totp"), ("codes", "m10_02_codes"), ("devices", "m10_03_devices"),
                                     ("password", "m10_04_password"), ("theme", "m10_05_theme")):
            camera.goto("/account.php?tab=" + tab_name)
            camera.shoot(image_name, [mark(".tabs a.active, a.active", label=1, nth=0)], scroll="top")
    run_scene(camera, "10 章 アカウント設定", chapter_account)


def referenced_image_names():
    text = open(MANUAL_PATH, encoding="utf-8").read()
    return re.findall(r"\]\(manual/images/([A-Za-z0-9_]+)\.png\)", text)


def check_images():
    """マニュアルが使う画像がそろっているか・使っていない画像がないか"""
    used_names = referenced_image_names()
    existing_names = sorted(file_name[:-4] for file_name in os.listdir(IMAGE_DIR) if file_name.endswith(".png")) if os.path.isdir(IMAGE_DIR) else []
    missing_names = sorted(set(used_names) - set(existing_names))
    unused_names = sorted(set(existing_names) - set(used_names))
    for image_name in missing_names:
        print("NG  マニュアルが使う画像が無い: %s.png" % image_name)
    for image_name in unused_names:
        print("NG  マニュアルが使っていない画像: %s.png" % image_name)
    if not missing_names:
        print("OK  操作マニュアルが使う画像がすべてある（%d 枚）" % len(set(used_names)))
    if not unused_names:
        print("OK  操作マニュアルが使っていない画像がない（画像 %d 枚）" % len(existing_names))
    return 1 if missing_names or unused_names else 0


def main():
    for output_stream in (sys.stdout, sys.stderr):
        if hasattr(output_stream, "reconfigure"):
            output_stream.reconfigure(errors="replace")
    parser = argparse.ArgumentParser(description="操作マニュアルの画面を撮る")
    parser.add_argument("--only", default="", help="名前がこの文字で始まる画像だけ撮る（例: m04）")
    parser.add_argument("--check", action="store_true", help="撮らずに、マニュアルが使う画像がそろっているかだけ確かめる")
    parser.add_argument("--show", action="store_true", help="ブラウザーを表示して撮る")
    parser.add_argument("--php", default="", help="php の場所（省くと自動で探す）")
    parser.add_argument("--browser-port", type=int, default=0, help="起動済みのブラウザーにつなぐ（--remote-debugging-port の番号）")
    arguments = parser.parse_args()
    if arguments.check:
        return check_images()

    php_path = find_php(arguments.php)
    if not php_path:
        print("PHP が見つかりません（--php で指定してください）")
        return 2
    os.makedirs(IMAGE_DIR, exist_ok=True)
    environment = dict(os.environ, MR_VERIFY_DB=os.path.abspath(MANUAL_DB_PATH))
    os.environ["MR_VERIFY_DB"] = environment["MR_VERIFY_DB"]
    port_number = free_port()
    base_url = "http://127.0.0.1:%d" % port_number
    print("操作マニュアルの画面を撮ります（サーバー: %s、DB: %s）" % (base_url, MANUAL_DB_PATH))
    server_process = subprocess.Popen(php_server_command(php_path, port_number),
                                      cwd=REPOSITORY_ROOT, env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    browser = None
    try:
        if not wait_server(base_url):
            print("ローカルのサーバーが起動しません")
            return 1
        seed_result = subprocess.run([php_path, os.path.join("tools", "test", "dev_seed.php"), "--yes"], cwd=REPOSITORY_ROOT, env=environment,
                                     stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        if seed_result.returncode != 0:
            print(seed_result.stdout.decode("utf-8", "replace"))
            print("テストデータを作れませんでした")
            return 1
        from cdp import Browser
        import screen_gallery
        users = json.load(open(os.path.join(TEST_TOOL_DIR, "output_dev_users.json"), encoding="utf-8"))["users"]
        browser = Browser(headless=not arguments.show, attach_port=arguments.browser_port or None)
        # 見本の報告・コメント・要望・依頼・臨時の調査を、画面の操作で作る（画面の目視確認の道具と同じ準備）
        gallery = screen_gallery.ScreenGallery(browser, base_url, users, IMAGE_DIR)
        today = datetime.date.today()
        previous_month = (today.replace(day=1) - datetime.timedelta(days=1)).strftime("%Y-%m")
        screen_gallery.prepare_sample_report(gallery, MANUAL_DB_PATH, previous_month)
        camera = ManualCamera(browser, base_url, users, arguments.only)
        capture_all(camera, php_path, environment, MANUAL_DB_PATH)
        print("撮った画像 %d 枚: %s" % (len(camera.taken_names), IMAGE_DIR))
        for failed_name, reason in camera.failed_list:
            print("NG  %s: %s" % (failed_name, reason))
        if not arguments.only:
            check_images()
        return 1 if camera.failed_list else 0
    finally:
        if browser is not None:
            browser.quit()
        server_process.terminate()
        try:
            server_process.wait(timeout=10)
        except Exception:  # noqa: BLE001
            server_process.kill()


if __name__ == "__main__":
    sys.exit(main())
