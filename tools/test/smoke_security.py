#!/usr/bin/env python3
"""予備のコードの確認・復旧コード・凍結と再初期化・全員の総入れ替え（K-075。ローカル検証環境専用。dev_seed.php の直後に実行する）。

- 使えるのはシステム管理者と補佐（開発担当）だけ。操作者のパスワードと理由が要り、相手のホームに操作者の名前と役割を 30 日出す
- 復旧コードは 30 分・1 回限り。使うと 2 段階認証の設定からやり直す
- 本人が「心当たりがない」を押すと凍結し、管理者と補佐（開発担当）に知らせる。操作した人以外が再初期化する
- 凍結前のパスワードは使えない。総入れ替えは 2 人の承認がそろわないと実施しない

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_security.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import datetime
import json
import os
import re
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import Client, check, csrf, login, next_login_code, rkey, section, summary, totp  # noqa: E402


def main():
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--base", default="http://127.0.0.1:8000")
    argument_parser.add_argument("--db", required=True)
    arguments = argument_parser.parse_args()
    test_dir = os.path.dirname(os.path.abspath(__file__))
    users = json.load(open(os.path.join(test_dir, "output_dev_users.json"), encoding="utf-8"))["users"]
    database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)
    clients = {}

    def uid(login_id):
        return users[login_id]["id"]

    def password_of(login_id):
        return users[login_id]["password"]

    def client_of(login_id):
        if login_id not in clients:
            clients[login_id] = login(arguments.base, login_id, users[login_id])
        return clients[login_id]

    def one(sql, *values):
        row = database.execute(sql, values).fetchone()
        return row[0] if row else None

    def edit_path(login_id):
        return "/admin/user_edit.php?id=%d" % uid(login_id)

    def post(client, path, values, get_path=None):
        _, _, page_html = client.get(get_path or path)
        if not csrf(page_html):
            _, _, page_html = client.get("/index.php")
        return client.post(path, dict(values, csrf_token=csrf(page_html), request_key=os.urandom(16).hex()))

    def security_post(operator, action, target, password=None, reason="本人から紛失の連絡"):
        return post(client_of(operator), edit_path(target), {
            "action": action, "reason": reason, "operator_password": password_of(operator) if password is None else password})

    def shown(operator, target):
        """操作のあとの画面に出たコード: (予備のコード, 復旧コード)。"""
        _, _, page_html = client_of(operator).get(edit_path(target))
        codes = re.search(r'<ul class="codes">(.*?)</ul>', page_html, re.S)
        recovery = re.search(r'復旧コード（<strong>.*?<p class="secret">([a-z0-9]+)</p>', page_html, re.S)
        return re.findall(r"<li>([^<]+)</li>", codes.group(1)) if codes else [], recovery.group(1) if recovery else None

    def notice_count(login_id, kind=None):
        return one("SELECT COUNT(*) FROM security_notices WHERE user_id = ?" + (" AND kind = '%s'" % kind if kind else ""), uid(login_id))

    def password_step(login_id, password):
        """パスワードまで入れる。(client, 行き先, 画面)"""
        client = Client(arguments.base)
        _, _, page_html = client.get("/login.php")
        _, location, page_html = client.post("/login.php", {"csrf_token": csrf(page_html), "action": "password", "login_id": login_id, "password": password})
        return client, location or "", page_html

    def code_step(client, code):
        _, _, page_html = client.get("/login.php?step=2")
        _, location, page_html = client.post("/login.php", {"csrf_token": csrf(page_html), "action": "code", "code": code, "remember": "0"})
        return location or "", page_html

    def set_up_second_factor(client):
        """2 段階認証の設定をして、予備のコードを返す。"""
        _, _, page_html = client.get("/account.php?tab=totp")
        secret = re.search(r'<p class="secret">([A-Z2-7 ]+)</p>', page_html).group(1).replace(" ", "")
        _, location, _ = client.post("/account.php?tab=totp", {"csrf_token": csrf(page_html), "action": "totp_confirm", "code": totp(secret)})
        _, _, codes_html = client.get("/account.php?tab=codes")
        return secret, location or "", re.findall(r"<li>([a-z0-9]{4}-[a-z0-9]{6})</li>", codes_html)

    database.execute("UPDATE users SET is_admin_deputy_lead = 1 WHERE id = ?", (uid("hosa01"),))
    database.execute("UPDATE users SET is_admin_deputy = 1 WHERE id = ?", (uid("ippan_b1"),))
    roles = {"admin01": "システム管理者", "hosa01": "システム管理者補佐（開発担当）"}
    names = {login_id: one("SELECT display_name FROM users WHERE id = ?", uid(login_id)) for login_id in users}

    section("G-14", "古い形の予備のコードと作り直し")
    _, _, home_html = client_of("ippan_a1").get("/index.php")
    check("古い形の予備のコードの人には、作り直すよう知らせる", "予備のコードを作り直してください（なくしたときに" in home_html)
    security_post("admin01", "view_backup_codes", "ippan_a1")
    check("古い形の予備のコードは確認できず、本人への知らせも残らない", shown("admin01", "ippan_a1")[0] == [] and notice_count("ippan_a1") == 0)
    _, _, codes_html = client_of("ippan_a1").get("/account.php?tab=codes")
    client_of("ippan_a1").post("/account.php?tab=codes", {"csrf_token": csrf(codes_html), "action": "codes_regen", "current_password": password_of("ippan_a1")})
    _, _, codes_html = client_of("ippan_a1").get("/account.php?tab=codes")
    own_codes = re.findall(r"<li>([a-z0-9]{4}-[a-z0-9]{6})</li>", codes_html)
    check("作り直すと 8 個でき、知らせが消える", len(own_codes) == 8 and "予備のコードを作り直してください（なくしたときに" not in client_of("ippan_a1").get("/index.php")[2], len(own_codes))
    raw_rows = database.execute("SELECT code_hash, code_enc FROM user_backup_codes WHERE user_id = ?", (uid("ippan_a1"),)).fetchall()
    check("DB に予備のコードの平文がない（ハッシュと暗号文だけ）",
          all(row[1] and not any(code in text or code.replace("-", "") in text for code in own_codes for text in row) for row in raw_rows) and len(raw_rows) == 8)
    # 予備のコードは何度でも使える（2026-11-26 指示）。使ったコードも暗号文を残し、管理者が確認できるまま
    for attempt_number in (1, 2):
        backup_login_client = Client(arguments.base)
        _, _, login_html = backup_login_client.get("/login.php")
        backup_login_client.post("/login.php", {"csrf_token": csrf(login_html), "action": "password", "login_id": "ippan_a1", "password": password_of("ippan_a1")})
        _, _, step2_html = backup_login_client.get("/login.php?step=2")
        status_code, location, _ = backup_login_client.post("/login.php", {"csrf_token": csrf(step2_html), "action": "code", "code": own_codes[0], "remember": "0"})
        check("同じ予備のコードで入れる（%d 回目）" % attempt_number, status_code == 303 and "/index.php" in (location or ""), (status_code, location))
    used_rows = database.execute("SELECT used_at, code_enc FROM user_backup_codes WHERE user_id = ?", (uid("ippan_a1"),)).fetchall()
    check("使った予備のコードも残り、暗号文も消さない（使った日時だけ入る）",
          len(used_rows) == 8 and all(row[1] for row in used_rows) and sum(1 for row in used_rows if row[0]) == 1, used_rows)
    _, _, codes_html = client_of("ippan_a1").get("/account.php?tab=codes")
    check("アカウント設定の予備のコードは、使ったあとも 8 個と出る", "8 個（どのコードも何度でも使えます）" in codes_html)

    section("G-14", "予備のコードの確認: 使える人")
    for operator in ("ippan_b1", "kacho_a", "leader_a", "ippan_a2"):
        before = notice_count("ippan_a1")
        status_code, _, page_html = security_post(operator, "view_backup_codes", "ippan_a1")
        recovery_status, _, _ = security_post(operator, "recovery_issue", "ippan_a1")
        check("%s は予備のコードの確認・復旧コードの発行ができない（403・知らせも残らない）" % operator,
              status_code == 403 and recovery_status == 403 and notice_count("ippan_a1") == before and one("SELECT COUNT(*) FROM user_recovery_codes") == 0, (status_code, recovery_status))
    _, _, deputy_html = client_of("ippan_b1").get(edit_path("ippan_a1"))
    check("ふつうの補佐のユーザーの編集には、確認・発行の欄がない", 'value="view_backup_codes"' not in deputy_html and 'value="recovery_issue"' not in deputy_html and 'value="unlock"' in deputy_html)
    for login_id in ("admin01", "hosa01"):
        check("%s は自分の分を確認・発行できない" % login_id, security_post(login_id, "view_backup_codes", login_id)[0] == (403 if login_id == "hosa01" else 400) and security_post(login_id, "recovery_issue", login_id)[0] == (403 if login_id == "hosa01" else 400))
    before = notice_count("ippan_a1")
    status_code, _, page_html = security_post("admin01", "view_backup_codes", "ippan_a1", password="wrong-password")
    check("操作者のパスワードが違うと見られず、知らせも残らず、失敗を数える", "あなたのパスワードが違います" in page_html and shown("admin01", "ippan_a1")[0] == [] and notice_count("ippan_a1") == before
          and one("SELECT failed_count FROM users WHERE id = ?", uid("admin01")) == 1, status_code)
    status_code, _, page_html = security_post("admin01", "view_backup_codes", "ippan_a1", reason="")
    check("理由がないと見られない", "理由を入れてください" in page_html and notice_count("ippan_a1") == before)
    status_code, _, page_html = security_post("admin01", "view_backup_codes", "ippan_a1", reason="あ" * 101)
    check("理由は 100 文字まで（101 文字は断る）", "100 文字以内" in page_html and notice_count("ippan_a1") == before)
    for wrong_count in range(10):
        if one("SELECT locked_until FROM users WHERE id = ?", uid("hosa01")) is not None:
            break
        security_post("hosa01", "recovery_issue", "ippan_a1", password="wrong-password")
    _, _, page_html = security_post("hosa01", "recovery_issue", "ippan_a1")
    check("操作者のパスワードを続けて間違えるとロックし、正しいパスワードでも発行できない", "続けて間違えたため" in page_html and one("SELECT COUNT(*) FROM user_recovery_codes") == 0, page_html[:80])
    database.execute("UPDATE users SET locked_until = NULL, failed_count = 0 WHERE id IN (?, ?)", (uid("hosa01"), uid("admin01")))
    for operator in ("admin01", "hosa01"):
        status_code, _, _ = security_post(operator, "view_backup_codes", "ippan_a1", reason="紛失 " + operator)
        codes, _ = shown(operator, "ippan_a1")
        check("%s は予備のコードを確認でき、本人のコードと同じ" % operator, status_code in (302, 303) and sorted(codes) == sorted(own_codes), codes)
        check("%s の確認は 1 度だけ出る（開き直すと出ない）" % operator, shown(operator, "ippan_a1")[0] == [])
        _, _, home_html = client_of("ippan_a1").get("/index.php")
        expected = "%s さん（%s）が、あなたの予備のコードを確認しました。理由: 紛失 %s。" % (names[operator], roles[operator], operator)
        check("本人のホームに、操作者の名前と役割・理由が出る（%s）" % operator, expected in home_html, re.findall(r'security-notice".*?</p>', home_html, re.S)[:2])
        check("記録に残る（%s）" % operator, one("SELECT COUNT(*) FROM access_logs WHERE user_id = ? AND target = ?", uid(operator), "user:%d:view_backup_codes" % uid("ippan_a1")) == 1)
    check("ほかの人のホームには出ない", all("予備のコードを確認しました" not in client_of(login_id).get("/index.php")[2] for login_id in ("ippan_a2", "kacho_a", "ippan_b1")))
    client, _, _ = password_step("ippan_a1", password_of("ippan_a1"))
    location, _ = code_step(client, own_codes[0])
    check("予備のコードでログインでき、使ったコードの暗号文も残る（何度でも使える）", "/index.php" in location and one("SELECT COUNT(*) FROM user_backup_codes WHERE user_id = ? AND code_enc IS NOT NULL", uid("ippan_a1")) == 8, location)
    security_post("admin01", "view_backup_codes", "ippan_a1")
    check("使った予備のコードも確認に出る（8 個すべて）", sorted(shown("admin01", "ippan_a1")[0]) == sorted(own_codes))
    database.execute("UPDATE security_notices SET created_at = ?", ((datetime.datetime.now() - datetime.timedelta(days=29)).strftime("%Y-%m-%d %H:%M:%S"),))
    check("知らせは 29 日後も出る", "予備のコードを確認しました" in client_of("ippan_a1").get("/index.php")[2])
    database.execute("UPDATE security_notices SET created_at = ?", ((datetime.datetime.now() - datetime.timedelta(days=31)).strftime("%Y-%m-%d %H:%M:%S"),))
    check("知らせは 31 日後には出ない", "予備のコードを確認しました" not in client_of("ippan_a1").get("/index.php")[2])

    section("G-01", "復旧コード（30 分・1 回限り）")
    security_post("hosa01", "recovery_issue", "ippan_a2", reason="スマートフォンの紛失")
    first_code = shown("hosa01", "ippan_a2")[1]
    check("開発担当が復旧コードを発行でき、12 文字で 1 度だけ出る", first_code is not None and len(first_code) == 12 and shown("hosa01", "ippan_a2")[1] is None, first_code)
    check("DB に復旧コードの平文がない", first_code not in (one("SELECT code_hash FROM user_recovery_codes WHERE user_id = ?", uid("ippan_a2")) or first_code))
    check("本人のホームに発行した人の名前と役割が出る", "%s さん（%s）が、あなたの復旧コードを発行しました" % (names["hosa01"], roles["hosa01"]) in client_of("ippan_a2").get("/index.php")[2])
    security_post("admin01", "recovery_issue", "ippan_a2", reason="スマートフォンの紛失")
    second_code = shown("admin01", "ippan_a2")[1]
    client, location, _ = password_step("ippan_a2", password_of("ippan_a2"))
    location, _ = code_step(client, first_code)
    check("新しく発行すると、前の復旧コードは使えない", "/index.php" not in location and "/account.php" not in location, location)
    client, _, _ = password_step("ippan_a1", password_of("ippan_a1"))
    check("ほかの人の復旧コードは使えない", "/account.php" not in code_step(client, second_code)[0])
    reset_client = Client(arguments.base)
    _, _, reset_html = reset_client.get("/password_reset.php")
    _, _, reset_result = reset_client.post("/password_reset.php", {"csrf_token": csrf(reset_html), "login_id": "ippan_a2", "code": second_code, "new_password": "new-long-password-1", "new_password_confirm": "new-long-password-1"})
    check("復旧コードだけではパスワードを再設定できない", "コードが違います" in reset_result and one("SELECT used_at FROM user_recovery_codes WHERE user_id = ?", uid("ippan_a2")) is None)
    database.execute("UPDATE users SET failed_count = 0, locked_until = NULL")

    def shift_expiry(minutes_left):
        database.execute("UPDATE user_recovery_codes SET expires_at = ? WHERE user_id = ?", ((datetime.datetime.now() + datetime.timedelta(minutes=minutes_left)).strftime("%Y-%m-%d %H:%M:%S"), uid("ippan_a2")))

    expires = datetime.datetime.strptime(one("SELECT expires_at FROM user_recovery_codes WHERE user_id = ?", uid("ippan_a2")), "%Y-%m-%d %H:%M:%S")
    created = datetime.datetime.strptime(one("SELECT created_at FROM user_recovery_codes WHERE user_id = ?", uid("ippan_a2")), "%Y-%m-%d %H:%M:%S")
    check("復旧コードの期限は 30 分", 29.9 <= (expires - created).total_seconds() / 60 <= 30.1, expires - created)
    shift_expiry(-1)
    client, _, _ = password_step("ippan_a2", password_of("ippan_a2"))
    check("期限を 1 分過ぎた復旧コード（発行から 31 分）は使えない", "/account.php" not in code_step(client, second_code)[0])
    shift_expiry(1)
    old_session = client_of("ippan_a2")
    client, _, _ = password_step("ippan_a2", password_of("ippan_a2"))
    location, _ = code_step(client, second_code[:4] + "-" + second_code[4:].upper())
    check("期限まで 1 分（発行から 29 分）の復旧コードは使え、2 段階認証の設定に進む（区切り・大文字でもよい）", "/account.php?tab=totp" in location, location)
    check("2 段階認証・予備のコード・端末が消え、ほかのログインも切れる",
          one("SELECT totp_secret_enc FROM users WHERE id = ?", uid("ippan_a2")) is None and one("SELECT COUNT(*) FROM user_backup_codes WHERE user_id = ?", uid("ippan_a2")) == 0
          and "/login.php" in (old_session.get("/index.php")[1] or ""))
    check("設定が済むまで、ほかの画面は使えない", "/account.php?tab=totp" in (client.get("/index.php")[1] or ""))
    secret, location, _ = set_up_second_factor(client)
    users["ippan_a2"]["totp"] = secret
    clients["ippan_a2"] = client
    check("設定し直すと使え、ホームに復旧コードが使われた知らせ（発行した人）が出る",
          "復旧コードが使われました。発行した人: %s さん（%s）" % (names["admin01"], roles["admin01"]) in client.get("/index.php")[2])
    check("使った復旧コードは、もう使えない", one("SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL", uid("ippan_a2")) == 0)
    client2, _, _ = password_step("ippan_a2", password_of("ippan_a2"))
    check("使った復旧コードを入れても断られる", "/account.php" not in code_step(client2, second_code)[0])
    database.execute("UPDATE users SET failed_count = 0, locked_until = NULL")

    section("G-16", "心当たりがない（知らせて凍結する）")
    database.execute("DELETE FROM security_notices WHERE id NOT IN (SELECT notice_id FROM user_recovery_codes)")
    security_post("hosa01", "view_backup_codes", "ippan_a1", reason="確かめ")
    own_notice_id = one("SELECT MAX(id) FROM security_notices WHERE user_id = ?", uid("ippan_a1"))
    _, _, home_html = client_of("ippan_a1").get("/index.php")
    check("一般の人の知らせに「心当たりがない」が出る", 'value="security_report"' in home_html and "心当たりがない（知らせて凍結する）" in home_html)
    status_code, _, _ = post(client_of("kacho_a"), "/account.php", {"action": "security_report", "notice_id": str(own_notice_id)}, "/index.php")
    check("ほかの人の知らせでは凍結できない（404）", status_code == 404 and one("SELECT COUNT(*) FROM account_freezes") == 0, status_code)
    client, _, _ = password_step("ippan_a1", password_of("ippan_a1"))
    _, _, step2_html = client.get("/login.php?step=2")
    post(client_of("ippan_a1"), "/account.php", {"action": "security_report", "notice_id": str(own_notice_id)}, "/index.php")
    check("押すと本人が凍結する", one("SELECT frozen_at FROM users WHERE id = ?", uid("ippan_a1")) is not None and one("SELECT COUNT(*) FROM account_freezes WHERE user_id = ? AND resolved_at IS NULL", uid("ippan_a1")) == 1)
    check("操作した人は凍結しない", one("SELECT frozen_at FROM users WHERE id = ?", uid("hosa01")) is None)
    check("予備のコード・復旧コードがなくなり、ログイン中の端末が切れる",
          one("SELECT COUNT(*) FROM user_backup_codes WHERE user_id = ?", uid("ippan_a1")) == 0 and "/login.php" in (client_of("ippan_a1").get("/index.php")[1] or ""))
    _, location, page_html = client.post("/login.php", {"csrf_token": csrf(step2_html), "action": "code", "code": totp(users["ippan_a1"]["totp"], 1), "remember": "0"})
    check("凍結の前にパスワードを通していても、コードで入れない", "/index.php" not in (location or ""), location)
    _, location, page_html = password_step("ippan_a1", password_of("ippan_a1"))
    check("凍結中はログインできず、凍結と出る", "step=2" not in location and "一時凍結しています" in page_html, location)
    _, _, page_html = password_step("ippan_a1", "wrong-password-x")
    check("パスワードが違う人には、凍結を知らせない", "一時凍結" not in page_html)
    reset_client = Client(arguments.base)
    _, _, reset_html = reset_client.get("/password_reset.php")
    _, _, reset_result = reset_client.post("/password_reset.php", {"csrf_token": csrf(reset_html), "login_id": "ippan_a1", "code": totp(users["ippan_a1"]["totp"], 1), "new_password": "another-long-pass-1", "new_password_confirm": "another-long-pass-1"})
    check("凍結中はパスワードを再設定できない", "ID かコードが違います" in reset_result)
    for login_id, is_shown in (("admin01", True), ("hosa01", True), ("ippan_b1", False), ("kacho_a", False)):
        home_html = client_of(login_id).get("/index.php")[2]
        check("%s のホームに凍結の知らせが%s" % (login_id, "出る" if is_shown else "出ない"), ("心当たりがないと知らせて凍結したアカウントがあります（1 件）" in home_html) == is_shown)
        check("%s は凍結の画面を%s" % (login_id, "開ける" if is_shown else "開けない（403）"), client_of(login_id).get("/admin/security.php")[0] == (200 if is_shown else 403))
    security_html = client_of("admin01").get("/admin/security.php")[2]
    check("凍結の画面に、だれが・操作した人の名前と役割・理由が出る", all(text in security_html for text in (names["ippan_a1"], names["hosa01"] + "（" + roles["hosa01"] + "）", "確かめ", "予備のコードの確認")))
    for action in ("temp_password", "reset_2fa", "unlock", "logout_all", "reset_backup_codes", "recovery_issue", "view_backup_codes"):
        status_code, _, _ = post(client_of("admin01"), edit_path("ippan_a1"), {"action": action, "reason": "x", "operator_password": password_of("admin01")})
        check("凍結中は、ユーザーの編集の %s では解けない（400）" % action, status_code == 400 and one("SELECT frozen_at FROM users WHERE id = ?", uid("ippan_a1")) is not None, status_code)

    section("G-14", "再初期化して凍結を解く")

    def reinit(operator, target, password=None):
        return post(client_of(operator), "/admin/security.php", {"action": "freeze_reinit", "user_id": str(uid(target)), "reason": "本人に電話で確認", "operator_password": password_of(operator) if password is None else password})

    status_code, _, _ = reinit("hosa01", "ippan_a1")
    check("操作した人は、自分が関わった凍結を再初期化できない（403）", status_code == 403 and one("SELECT frozen_at FROM users WHERE id = ?", uid("ippan_a1")) is not None, status_code)
    check("操作した人の画面には、再初期化の欄がない", 'value="freeze_reinit"' not in client_of("hosa01").get("/admin/security.php")[2] and 'value="freeze_reinit"' in security_html)
    check("ふつうの補佐・課長は再初期化できない（403）", reinit("ippan_b1", "ippan_a1")[0] == 403 and reinit("kacho_a", "ippan_a1")[0] == 403)
    _, _, page_html = reinit("admin01", "ippan_a1", password="wrong-password")
    check("操作者のパスワードが違うと再初期化できない", "あなたのパスワードが違います" in page_html and one("SELECT frozen_at FROM users WHERE id = ?", uid("ippan_a1")) is not None)
    check("凍結していない人は再初期化できない（404）", reinit("admin01", "ippan_a2")[0] == 404)
    department_before = database.execute("SELECT department_id, role_title, is_active FROM users WHERE id = ?", (uid("ippan_a1"),)).fetchone()
    reinit("admin01", "ippan_a1")
    security_html = client_of("admin01").get("/admin/security.php")[2]
    temp_password = re.search(r'<p class="secret">([^<]+)</p>', security_html)
    temp_password = temp_password.group(1) if temp_password else ""
    check("ほかの管理者が再初期化でき、仮のパスワードが 1 度だけ出る", temp_password != "" and 'class="secret"' not in client_of("admin01").get("/admin/security.php")[2])
    check("凍結が解け、課・役職は変わらない", one("SELECT frozen_at FROM users WHERE id = ?", uid("ippan_a1")) is None and one("SELECT COUNT(*) FROM account_freezes WHERE resolved_at IS NULL") == 0
          and database.execute("SELECT department_id, role_title, is_active FROM users WHERE id = ?", (uid("ippan_a1"),)).fetchone() == department_before)
    check("前のパスワードではログインできない", "/account.php" not in password_step("ippan_a1", password_of("ippan_a1"))[1])
    database.execute("UPDATE users SET failed_count = 0, locked_until = NULL")
    client, location, _ = password_step("ippan_a1", temp_password)
    check("仮のパスワードで、2 段階認証の初期設定からやり直す（前の認証アプリでは進めない）", "/account.php?tab=totp" in location, location)
    secret, _, new_codes = set_up_second_factor(client)
    check("2 段階認証の秘密の値が新しくなり、予備のコードが新しくできる", secret != users["ippan_a1"]["totp"] and len(new_codes) == 8 and not set(new_codes) & set(own_codes))
    users["ippan_a1"]["totp"] = secret

    def change_password(client, new_password, current=None):
        _, _, page_html = client.get("/account.php?tab=password")
        values = {"csrf_token": csrf(page_html), "action": "password_change", "new_password": new_password, "new_password_confirm": new_password}
        if current is not None:
            values["current_password"] = current
        return client.post("/account.php?tab=password", values)

    first_old = password_of("ippan_a1")
    hash_before = one("SELECT password_hash FROM users WHERE id = ?", uid("ippan_a1"))
    _, _, page_html = change_password(client, first_old)
    check("凍結前のパスワードは設定できない（DB も変わらない）", "前に使えなくなったパスワード" in page_html and one("SELECT password_hash FROM users WHERE id = ?", uid("ippan_a1")) == hash_before)
    _, location, _ = change_password(client, "second-pass-ippan-a1")
    check("別のパスワードなら設定できる", "/index.php" in (location or ""), location)
    users["ippan_a1"]["password"] = "second-pass-ippan-a1"
    clients["ippan_a1"] = client
    check("ホームに、再初期化した人の名前と役割の知らせ", "%s さん（%s）が、あなたのアカウントを再初期化しました" % (names["admin01"], roles["admin01"]) in client.get("/index.php")[2])
    _, _, page_html = change_password(client, first_old, "second-pass-ippan-a1")
    check("あとからアカウント設定でも、凍結前のパスワードに戻せない", "前に使えなくなったパスワード" in page_html)
    database.execute("UPDATE user_blocked_passwords SET created_at = '2020-01-01 00:00:00'")
    _, _, page_html = change_password(client, first_old, "second-pass-ippan-a1")
    check("期間がたっても戻せない", "前に使えなくなったパスワード" in page_html)
    reset_client = Client(arguments.base)
    _, _, reset_html = reset_client.get("/password_reset.php")
    _, _, reset_result = reset_client.post("/password_reset.php", {"csrf_token": csrf(reset_html), "login_id": "ippan_a1", "code": new_codes[0], "new_password": first_old, "new_password_confirm": first_old})
    check("パスワードの再設定でも、凍結前のパスワードは使えない", "前に使えなくなったパスワード" in reset_result)
    _, _, reset_html = reset_client.get("/password_reset.php")
    _, _, reset_result = reset_client.post("/password_reset.php", {"csrf_token": csrf(reset_html), "login_id": "ippan_a1", "code": "aaaa-aaaaaa", "new_password": first_old, "new_password_confirm": first_old})
    check("コードが違う人には、使えないパスワードかを知らせない", "前に使えなくなった" not in reset_result and "ID かコードが違います" in reset_result)
    _, location, _ = change_password(client_of("ippan_a2"), first_old, password_of("ippan_a2"))
    check("ほかの人は、同じ文字列のパスワードを使える", "/index.php" in (location or ""), location)
    users["ippan_a2"]["password"] = first_old
    database.execute("UPDATE users SET failed_count = 0, locked_until = NULL")

    section("G-14", "関わった人しかいないときの再初期化・最後の 1 人の管理者")
    check("1 回目の凍結では、総入れ替えの欄は出ない", 'id="mass-reset"' not in client_of("admin01").get("/admin/security.php")[2])
    reissue_id = one("SELECT MAX(id) FROM security_notices WHERE user_id = ? AND kind = 'reinit'", uid("ippan_a1"))
    database.execute("UPDATE users SET is_active = 0 WHERE id = ?", (uid("hosa01"),))
    post(client_of("ippan_a1"), "/account.php", {"action": "security_report", "notice_id": str(reissue_id)}, "/index.php")
    check("再初期化の知らせからも凍結できる（2 回目）", one("SELECT COUNT(*) FROM account_freezes WHERE user_id = ?", uid("ippan_a1")) == 2)
    status_code, _, _ = reinit("admin01", "ippan_a1")
    check("ほかに管理者も開発担当もいないときは、操作した人でも再初期化できる", status_code in (302, 303) and one("SELECT frozen_at FROM users WHERE id = ?", uid("ippan_a1")) is None, status_code)
    security_html = client_of("admin01").get("/admin/security.php")[2]
    temp_password = re.search(r'<p class="secret">([^<]+)</p>', security_html).group(1)
    client, _, _ = password_step("ippan_a1", temp_password)
    secret, _, _ = set_up_second_factor(client)
    for old_password, label in ((first_old, "1 回目"), ("second-pass-ippan-a1", "2 回目")):
        _, _, page_html = change_password(client, old_password)
        check("%sの凍結のときのパスワードも使えない" % label, "前に使えなくなったパスワード" in page_html)
    change_password(client, "third-pass-ippan-a1")
    users["ippan_a1"].update(totp=secret, password="third-pass-ippan-a1")
    clients["ippan_a1"] = client
    database.execute("INSERT INTO security_notices (user_id, kind, actor_id, actor_name, actor_role, reason, created_at) VALUES (?, 'codes_view', ?, 'x', 'システム管理者補佐（開発担当）', 'x', datetime('now', 'localtime'))", (uid("admin01"), uid("hosa01")))
    admin_notice = one("SELECT MAX(id) FROM security_notices WHERE user_id = ?", uid("admin01"))
    post(client_of("admin01"), "/account.php", {"action": "security_report", "notice_id": str(admin_notice)}, "/index.php")
    check("最後の 1 人の管理者は、凍結せずコードと端末だけ無効にする",
          one("SELECT frozen_at FROM users WHERE id = ?", uid("admin01")) is None and one("SELECT is_login_stopped FROM account_freezes WHERE user_id = ?", uid("admin01")) == 0
          and "/login.php" in (client_of("admin01").get("/index.php")[1] or ""))
    database.execute("DELETE FROM account_freezes WHERE user_id = ?", (uid("admin01"),))
    database.execute("DELETE FROM user_blocked_passwords WHERE user_id = ?", (uid("admin01"),))
    database.execute("UPDATE users SET is_active = 1 WHERE id = ?", (uid("hosa01"),))
    clients.pop("admin01")
    clients.pop("hosa01")

    section("G-14", "全員の総入れ替え（2 人の承認）")

    def mass(operator, action, **extra):
        values = {"action": action, "reason": "同じ人の 2 回目の凍結", "operator_password": password_of(operator)}
        pending = one("SELECT MAX(id) FROM mass_reset_requests WHERE status = 'pending'")
        if pending:
            values["request_id"] = str(pending)
        values.update(extra)
        return post(client_of(operator), "/admin/security.php", values)

    def reset_count():
        return one("SELECT COUNT(*) FROM mass_credential_resets")

    for login_id, is_shown in (("admin01", True), ("hosa01", True), ("ippan_b1", False), ("kacho_a", False)):
        check("同じ人の 2 回目の凍結のあと、%s のホームに総入れ替えの知らせが%s" % (login_id, "出る" if is_shown else "出ない"), ("凍結が続いています" in client_of(login_id).get("/index.php")[2]) == is_shown)
    check("補佐・課長は申請できない（403）", mass("ippan_b1", "mass_request")[0] == 403 and mass("kacho_a", "mass_request")[0] == 403)
    _, _, page_html = mass("hosa01", "mass_request", operator_password="wrong-password")
    check("操作者のパスワードが違うと申請できない", "あなたのパスワードが違います" in page_html and one("SELECT COUNT(*) FROM mass_reset_requests") == 0)
    mass("hosa01", "mass_request")
    check("開発担当が申請できるが、まだ実施しない", one("SELECT COUNT(*) FROM mass_reset_requests WHERE status = 'pending'") == 1 and reset_count() == 0 and client_of("kacho_a").get("/index.php")[0] == 200)
    for login_id in ("admin01", "hosa01"):
        check("申請の知らせが %s のホームに出る" % login_id, "総入れ替えの申請があります" in client_of(login_id).get("/index.php")[2])
    check("二重には申請できない（403）", mass("admin01", "mass_request")[0] == 403)
    check("申請した人は、自分で承認できない（403）", mass("hosa01", "mass_approve")[0] == 403 and reset_count() == 0)
    check("補佐・課長は承認できない（403）", mass("ippan_b1", "mass_approve")[0] == 403 and mass("kacho_a", "mass_approve")[0] == 403 and reset_count() == 0)
    database.execute("UPDATE users SET is_admin = 1, is_admin_deputy = 0 WHERE id = ?", (uid("ippan_b1"),))
    mass("admin01", "mass_cancel")
    check("申請を取り消せる", one("SELECT COUNT(*) FROM mass_reset_requests WHERE status = 'pending'") == 0 and reset_count() == 0)
    check("取り消した申請は承認できない（404）", mass("admin01", "mass_approve", request_id="1")[0] == 404)
    mass("admin01", "mass_request")
    check("開発担当がいるときは、システム管理者 2 人では承認できない（403）", mass("ippan_b1", "mass_approve")[0] == 403 and reset_count() == 0)
    database.execute("UPDATE users SET is_admin = 0, is_admin_deputy = 1 WHERE id = ?", (uid("ippan_b1"),))
    pending_id = one("SELECT MAX(id) FROM mass_reset_requests WHERE status = 'pending'")

    def age_request(hours):
        database.execute("UPDATE mass_reset_requests SET created_at = ? WHERE id = ?", ((datetime.datetime.now() - datetime.timedelta(hours=hours)).strftime("%Y-%m-%d %H:%M:%S"), pending_id))

    age_request(24.1)
    check("24 時間を過ぎた申請は承認できない（404）", mass("hosa01", "mass_approve")[0] == 404 and reset_count() == 0)
    age_request(23.9)
    _, _, page_html = mass("hosa01", "mass_approve", operator_password="wrong-password")
    check("承認する人のパスワードが違うと実施しない", "あなたのパスワードが違います" in page_html and reset_count() == 0)
    database.execute("UPDATE users SET failed_count = 0, locked_until = NULL")
    active_count = one("SELECT COUNT(*) FROM users WHERE is_active = 1")
    kacho_client = client_of("kacho_a")
    mass("hosa01", "mass_approve")
    check("もう 1 人（開発担当）が承認すると実施する（有効な全員）", reset_count() == 1 and one("SELECT user_count FROM mass_credential_resets") == active_count
          and one("SELECT COUNT(*) FROM users WHERE is_active = 1 AND must_change_password = 1") == active_count and one("SELECT COUNT(*) FROM security_notices WHERE kind = 'mass_reset'") == active_count)
    check("全員の端末・予備のコード・復旧コードがなくなり、ログインが切れる",
          one("SELECT (SELECT COUNT(*) FROM user_backup_codes) + (SELECT COUNT(*) FROM user_recovery_codes) + (SELECT COUNT(*) FROM remembered_devices)") == 0 and "/login.php" in (kacho_client.get("/index.php")[1] or ""))
    client, location, _ = password_step("kacho_a", password_of("kacho_a"))
    check("次のログインは、今のパスワードと今の認証アプリで確かめる", "step=2" in location, location)
    location, _ = code_step(client, next_login_code("kacho_a", users["kacho_a"]["totp"]))
    check("ログインしても、パスワードを変えるまでほかの画面は使えない", "/account.php?tab=password" in (client.get("/reports/mine.php")[1] or ""))
    _, _, page_html = client.get("/account.php?tab=password")
    check("今のパスワードを聞く", 'name="current_password"' in page_html and "総入れ替え" in page_html)
    _, _, page_html = change_password(client, "brand-new-kacho-a")
    check("今のパスワードなしでは変えられない", "今のパスワードが違います" in page_html)
    _, _, page_html = change_password(client, password_of("kacho_a"), password_of("kacho_a"))
    check("替える前のパスワードは使えない", "今と同じパスワードは使えません" in page_html or "前に使えなくなった" in page_html)
    _, location, _ = change_password(client, "brand-new-kacho-a", password_of("kacho_a"))
    check("パスワードを変えると、2 段階認証の設定し直しに進む", "/account.php?tab=totp" in (location or "") and "/account.php?tab=totp" in (client.get("/reports/mine.php")[1] or ""), location)
    secret, _, codes = set_up_second_factor(client)
    _, _, home_html = client.get("/index.php")
    clients["kacho_a"] = client
    check("設定し直すと使え、新しい予備のコードができる", len(codes) == 8 and secret != users["kacho_a"]["totp"] and "あなたの役割と権限" in home_html)
    check("ホームに、2 人の名前と役割の知らせ（「心当たりがない」は出ない）",
          "%s さん（%s）と %s さん（%s）が、全員のログインの情報を総入れ替えしました" % (names["admin01"], roles["admin01"], names["hosa01"], roles["hosa01"]) in home_html and 'value="security_report"' not in home_html)
    _, _, page_html = change_password(client, password_of("kacho_a"), "brand-new-kacho-a")
    check("総入れ替えの前のパスワードには、あとからも戻せない", "前に使えなくなったパスワード" in page_html)
    check("総入れ替えのあとは、欄が消える（承認も申請もできない）", one("SELECT COUNT(*) FROM mass_reset_requests WHERE status = 'pending'") == 0)
    # 総入れ替えの途中に、復旧コードは管理者も入れ直してから
    reset_client = Client(arguments.base)
    _, _, reset_html = reset_client.get("/password_reset.php")
    _, _, reset_result = reset_client.post("/password_reset.php", {"csrf_token": csrf(reset_html), "login_id": "leader_a", "code": next_login_code("leader_a", users["leader_a"]["totp"]), "new_password": "leader-new-pass-1", "new_password_confirm": "leader-new-pass-1"})
    check("総入れ替えの途中は、パスワードの再設定で 2 段階認証の設定し直しを飛ばせない", "ログインしてから" in reset_result and one("SELECT is_credential_reset FROM users WHERE id = ?", uid("leader_a")) == 1)

    # 管理者が入れ直す → 総入れ替えの途中の人に復旧コード → 2 回設定させない
    admin_client, _, _ = password_step("admin01", password_of("admin01"))
    code_step(admin_client, next_login_code("admin01", users["admin01"]["totp"]))
    change_password(admin_client, "brand-new-admin-01", password_of("admin01"))
    secret, _, _ = set_up_second_factor(admin_client)
    users["admin01"].update(password="brand-new-admin-01", totp=secret)
    clients["admin01"] = admin_client
    check("総入れ替えのあと、管理者の画面に総入れ替えの欄が出ない", 'id="mass-reset"' not in admin_client.get("/admin/security.php")[2])
    security_post("admin01", "recovery_issue", "dairi_a")
    recovery = shown("admin01", "dairi_a")[1]
    client, _, _ = password_step("dairi_a", password_of("dairi_a"))
    code_step(client, recovery)
    secret, _, _ = set_up_second_factor(client)
    _, location, _ = change_password(client, "brand-new-dairi-a", password_of("dairi_a"))
    check("復旧コードで設定し直した人には、2 段階認証を 2 回設定させない", "/index.php" in (location or "") and client.get("/reports/mine.php")[0] == 200, location)

    # 30 日に 2 人以上
    database.execute("DELETE FROM account_freezes")
    database.execute("DELETE FROM mass_credential_resets")
    any_notice = one("SELECT MIN(id) FROM security_notices")
    for login_id, days in (("ippan_a1", 1), ("ippan_a2", 31)):
        database.execute("INSERT INTO account_freezes (user_id, notice_id, operator_id, created_at, resolved_at) VALUES (?, ?, ?, ?, 'x')",
                         (uid(login_id), any_notice, uid("hosa01"), (datetime.datetime.now() - datetime.timedelta(days=days)).strftime("%Y-%m-%d %H:%M:%S")))
    check("別々の人でも、30 日を超えて離れていれば欄は出ない", 'id="mass-reset"' not in admin_client.get("/admin/security.php")[2])
    database.execute("UPDATE account_freezes SET created_at = ? WHERE user_id = ?", ((datetime.datetime.now() - datetime.timedelta(days=29)).strftime("%Y-%m-%d %H:%M:%S"), uid("ippan_a2")))
    check("30 日に 2 人なら欄が出る", 'id="mass-reset"' in admin_client.get("/admin/security.php")[2])
    # 開発担当がいない・凍結中のときは、別の補佐が代わりになる
    database.execute("UPDATE users SET must_change_password = 0, is_credential_reset = 0 WHERE id IN (?, ?)", (uid("ippan_b1"), uid("hosa01")))
    clients.pop("ippan_b1", None)
    check("開発担当がいる間は、別の補佐は総入れ替えの画面を開けない（403）・知らせも出ない",
          client_of("ippan_b1").get("/admin/security.php")[0] == 403 and "凍結が続いています" not in client_of("ippan_b1").get("/index.php")[2])
    for label, sql in (("凍結中", "UPDATE users SET frozen_at = '2026-01-01 00:00:00' WHERE id = ?"), ("無効", "UPDATE users SET frozen_at = NULL, is_active = 0 WHERE id = ?")):
        database.execute(sql, (uid("hosa01"),))
        page_html = client_of("ippan_b1").get("/admin/security.php")[2]
        check("開発担当が%sなら、別の補佐のホームに知らせが出て、総入れ替えの欄を開ける" % label,
              "凍結が続いています" in client_of("ippan_b1").get("/index.php")[2] and 'id="mass-reset"' in page_html and 'value="mass_request"' in page_html)
    database.execute("INSERT INTO account_freezes (user_id, notice_id, operator_id, created_at) VALUES (?, ?, ?, ?)",
                     (uid("ippan_a2"), any_notice, uid("hosa01"), datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")))
    database.execute("UPDATE users SET frozen_at = '2026-01-01 00:00:00' WHERE id = ?", (uid("ippan_a2"),))
    check("代わりの補佐には、凍結したアカウントの一覧・再初期化を出さず、送っても断る（403）",
          names["ippan_a2"] not in client_of("ippan_b1").get("/admin/security.php")[2] and reinit("ippan_b1", "ippan_a2")[0] == 403
          and one("SELECT frozen_at FROM users WHERE id = ?", uid("ippan_a2")) is not None)
    check("代わりの補佐でも、予備のコードの確認・復旧コードの発行はできない（403）",
          security_post("ippan_b1", "view_backup_codes", "dairi_a")[0] == 403 and security_post("ippan_b1", "recovery_issue", "dairi_a")[0] == 403)
    mass("admin01", "mass_request")
    check("開発担当がいないときも、1 人では実施できない（403）", mass("admin01", "mass_approve")[0] == 403 and reset_count() == 0)
    check("課長は承認できない（403）", mass("kacho_a", "mass_approve")[0] == 403 and reset_count() == 0)
    # 別のシステム管理者では代わりにならない
    database.execute("UPDATE users SET is_admin = 1, must_change_password = 0, is_credential_reset = 0 WHERE id = ?", (uid("leader_a"),))
    clients.pop("leader_a", None)
    check("別のシステム管理者では承認できない（403）", mass("leader_a", "mass_approve")[0] == 403 and reset_count() == 0)
    database.execute("UPDATE users SET is_admin = 0 WHERE id = ?", (uid("leader_a"),))
    mass("ippan_b1", "mass_approve")
    check("開発担当がいないときは、別の補佐が承認すると実施する", reset_count() == 1)

    section("G-14", "管理者と補佐を 1 名ずつ以上置く")
    database.execute("UPDATE users SET must_change_password = 0, is_credential_reset = 0 WHERE id IN (?, ?)", (uid("admin01"), uid("ippan_b1")))
    users["admin01"]["password"] = "brand-new-admin-01"
    clients["admin01"] = login(arguments.base, "admin01", users["admin01"])

    def save_deputy(login_id, **values):
        row = database.execute("SELECT display_name, role_title, department_id FROM users WHERE id = ?", (uid(login_id),)).fetchone()
        form = {"action": "save", "display_name": row[0], "role_title": row[1], "department_id": str(row[2] or ""), "is_active": "1", "is_admin_deputy": "1"}
        form.update(values)
        return post(client_of("admin01"), edit_path(login_id), form)

    for label, values in (("補佐から外す", {"is_admin_deputy": ""}), ("無効にする", {"is_active": ""})):
        _, _, page_html = save_deputy("ippan_b1", **values)
        check("開発担当のほかの最後の 1 人の補佐は、%sことができない" % label, "開発担当のほかに補佐が 1 人もいなくなる" in page_html
              and database.execute("SELECT is_admin_deputy, is_active FROM users WHERE id = ?", (uid("ippan_b1"),)).fetchone() == (1, 1))
    # 有効な開発担当がいても、ほかの補佐の数には入れない
    database.execute("UPDATE users SET is_active = 1, frozen_at = NULL WHERE id = ?", (uid("hosa01"),))
    for label, values in (("補佐から外す", {"is_admin_deputy": ""}), ("無効にする", {"is_active": ""})):
        _, _, page_html = save_deputy("ippan_b1", **values)
        check("有効な開発担当がいても、ほかの最後の 1 人の補佐は%sことができない" % label, "開発担当のほかに補佐が 1 人もいなくなる" in page_html
              and database.execute("SELECT is_admin_deputy, is_active FROM users WHERE id = ?", (uid("ippan_b1"),)).fetchone() == (1, 1))
    database.execute("UPDATE users SET is_admin_deputy = 0, is_admin_deputy_lead = 0 WHERE id = ?", (uid("hosa01"),))
    _, _, page_html = save_deputy("ippan_b1", is_admin_deputy_lead="1")
    check("最後の 1 人の補佐は、開発担当にすることもできない", "開発担当のほかに補佐が 1 人もいなくなる" in page_html and one("SELECT is_admin_deputy_lead FROM users WHERE id = ?", uid("ippan_b1")) == 0)
    database.execute("UPDATE users SET is_admin_deputy = 1, is_admin_deputy_lead = 1 WHERE id = ?", (uid("hosa01"),))
    _, _, bulk_html = client_of("admin01").get("/admin/org.php?tab=bulk")
    csv_text = "ログイン ID,表示名,所属の課,ライン,役割,担当のライン,項目管理者,有効\r\nippan_b1,%s,B課,,,,,0\r\n" % names["ippan_b1"]
    status_code, _, preview_html = client_of("admin01").post_file("/admin/org.php?tab=bulk", {"action": "bulk_preview", "csrf_token": csrf(bulk_html), "request_key": os.urandom(16).hex()},
                                                                 "org_csv", "org.csv", csv_text.encode("utf-8"))
    if status_code in (302, 303):
        _, _, preview_html = client_of("admin01").get("/admin/org.php?tab=bulk")
    check("CSV でも、最後の 1 人の補佐は無効にできない", "開発担当のほかに補佐が 1 人もいなくなるため、反映できません" in preview_html, status_code)
    check("補佐がいる間は、補佐がいない知らせは出ない", "開発担当のほかに、システム管理者補佐がいません" not in client_of("admin01").get("/index.php")[2])
    save_deputy("leader_a")
    save_deputy("ippan_b1", is_admin_deputy="")
    check("先にほかの人を補佐にすれば、外せる", one("SELECT is_admin_deputy FROM users WHERE id = ?", uid("ippan_b1")) == 0 and one("SELECT is_admin_deputy FROM users WHERE id = ?", uid("leader_a")) == 1)
    _, _, page_html = post(client_of("admin01"), edit_path("admin01"), {"action": "save", "display_name": names["admin01"], "role_title": "一般", "department_id": "", "is_active": "1"})
    check("最後の 1 人の管理者も外せない", one("SELECT is_admin FROM users WHERE id = ?", uid("admin01")) == 1)
    save_deputy("hosa01", is_admin_deputy="")
    check("開発担当は、ほかに補佐がいれば外せる", one("SELECT is_admin_deputy FROM users WHERE id = ?", uid("hosa01")) == 0)
    database.execute("UPDATE users SET is_admin_deputy = 1, is_admin_deputy_lead = 1 WHERE id = ?", (uid("hosa01"),))
    database.execute("UPDATE users SET is_admin_deputy = 0 WHERE id = ?", (uid("leader_a"),))
    check("開発担当だけで、ほかの補佐が 1 人もいなければ、管理者のホームに知らせる", "開発担当のほかに、システム管理者補佐がいません" in client_of("admin01").get("/index.php")[2])
    section("G-14", "管理者は補佐（開発担当）のアカウントに入れない・2 人での復旧")
    database.execute("DELETE FROM account_freezes")
    database.execute("UPDATE users SET must_change_password = 0, is_credential_reset = 0, frozen_at = NULL, failed_count = 0, locked_until = NULL")
    database.execute("UPDATE users SET is_admin = 0, is_admin_deputy = 0, is_admin_deputy_lead = 0 WHERE id IN (?, ?, ?)", (uid("ippan_b1"), uid("leader_a"), uid("kacho_b")))
    database.execute("UPDATE users SET is_admin_deputy = 1 WHERE id = ?", (uid("ippan_b1"),))
    database.execute("UPDATE users SET is_admin_deputy = 1, is_admin_deputy_lead = 1, is_active = 1 WHERE id = ?", (uid("hosa01"),))
    lead_hash = one("SELECT password_hash FROM users WHERE id = ?", uid("hosa01"))

    def lead_state():
        return database.execute("SELECT password_hash, totp_secret_enc, must_change_password FROM users WHERE id = ?", (uid("hosa01"),)).fetchone()

    def direct_operations(label):
        before = lead_state()
        for action in ("temp_password", "reset_2fa", "view_backup_codes", "recovery_issue"):
            status_code, _, _ = security_post("admin01", action, "hosa01")
            check("%s: 管理者は %s ができない（403・DB は変わらない）" % (label, action), status_code == 403 and lead_state() == before
                  and one("SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ?", uid("hosa01")) == 0, status_code)
        page_html = client_of("admin01").get(edit_path("hosa01"))[2]
        check("%s: 管理者の画面に、その欄がない" % label, all('value="%s"' % action not in page_html for action in ("temp_password", "reset_2fa", "view_backup_codes", "recovery_issue")) and 'value="unlock"' in page_html)

    direct_operations("開発担当")
    save_deputy("hosa01", is_admin_deputy_lead="")
    check("管理者が開発担当の印を外せる（交代のため）", one("SELECT is_admin_deputy_lead FROM users WHERE id = ?", uid("hosa01")) == 0)
    direct_operations("開発担当の印を外したあと")
    save_deputy("hosa01", is_admin_deputy="")
    check("管理者が補佐から外せる", one("SELECT is_admin_deputy FROM users WHERE id = ?", uid("hosa01")) == 0)
    direct_operations("補佐からも外したあと")
    save_deputy("hosa01", is_admin_deputy="", is_active="")
    save_deputy("hosa01", is_admin_deputy="")
    direct_operations("無効にして戻したあと")
    save_deputy("hosa01", is_admin_deputy_lead="1")
    for login_id in ("ippan_b1", "kacho_b"):
        clients.pop(login_id, None)
    check("ほかの人（補佐でも開発担当でもない人）は、今までどおり初期化できる", post(client_of("admin01"), edit_path("ippan_a2"), {"action": "reset_2fa"})[0] in (302, 303))
    section("G-14", "補佐（開発担当）の凍結と 2 人での復旧")
    database.execute("UPDATE users SET frozen_at = '2026-01-01 00:00:00' WHERE id = ?", (uid("hosa01"),))
    database.execute("INSERT INTO account_freezes (user_id, notice_id, operator_id, created_at) VALUES (?, ?, ?, datetime('now', 'localtime'))", (uid("hosa01"), any_notice, uid("ippan_a1")))
    check("管理者は、凍結した開発担当を 1 人で再初期化できない（403）", reinit("admin01", "hosa01")[0] == 403 and one("SELECT frozen_at FROM users WHERE id = ?", uid("hosa01")) is not None)

    def lead_post(operator, action, **extra):
        values = {"action": action, "reason": "紛失", "operator_password": password_of(operator), "user_id": str(uid("hosa01"))}
        pending = one("SELECT MAX(id) FROM lead_recovery_requests")
        if pending:
            values["request_id"] = str(pending)
        values.update(extra)
        return post(client_of(operator), "/admin/security.php", values, "/index.php")

    def half(operator, path="/admin/security.php"):
        match = re.search(r'仮のパスワードの(前半|後半).*?<p class="secret">([^<]+)</p>', client_of(operator).get(path)[2], re.S)
        return match.groups() if match else (None, None)

    check("補佐・課長は申請できない（403）", lead_post("ippan_b1", "lead_recovery_request")[0] == 403 and lead_post("kacho_b", "lead_recovery_request")[0] == 403)
    check("守るアカウントでない人は対象にできない（403）", lead_post("admin01", "lead_recovery_request", user_id=str(uid("ippan_a1")))[0] == 403)
    _, _, page_html = lead_post("admin01", "lead_recovery_request", operator_password="wrong-password")
    check("操作者のパスワードが違うと申請できない", "あなたのパスワードが違います" in page_html and one("SELECT COUNT(*) FROM lead_recovery_requests") == 0)
    lead_post("admin01", "lead_recovery_request")
    label, first_half = half("admin01")
    check("管理者の申請で、前半だけが 1 度だけ出て、まだ再初期化しない", label == "前半" and half("admin01") == (None, None) and one("SELECT frozen_at FROM users WHERE id = ?", uid("hosa01")) is not None)
    check("DB に前半の平文がない", first_half not in one("SELECT first_half_enc FROM lead_recovery_requests ORDER BY id DESC LIMIT 1"))
    check("申請の知らせが、別の補佐のホームに出る", "補佐（開発担当）の復旧の申請があります" in client_of("ippan_b1").get("/index.php")[2] and "補佐（開発担当）の復旧の申請があります" not in client_of("kacho_b").get("/index.php")[2])
    check("申請した管理者・課長は承認できない（403）", lead_post("admin01", "lead_recovery_approve")[0] == 403 and lead_post("kacho_b", "lead_recovery_approve")[0] == 403)
    database.execute("UPDATE users SET is_admin = 1, is_admin_deputy = 0 WHERE id = ?", (uid("leader_a"),))
    clients.pop("leader_a", None)
    check("別の管理者でも承認できない（403）", lead_post("leader_a", "lead_recovery_approve")[0] == 403 and lead_state()[0] == lead_hash)
    database.execute("UPDATE users SET is_admin = 0 WHERE id = ?", (uid("leader_a"),))
    database.execute("UPDATE lead_recovery_requests SET created_at = ?", ((datetime.datetime.now() - datetime.timedelta(hours=24.1)).strftime("%Y-%m-%d %H:%M:%S"),))
    check("24 時間を過ぎた申請は承認できない（404・403）", lead_post("ippan_b1", "lead_recovery_approve")[0] in (403, 404) and lead_state()[0] == lead_hash)
    lead_post("admin01", "lead_recovery_request")
    first_half = half("admin01")[1]
    lead_post("ippan_b1", "lead_recovery_approve", operator_password="wrong-password")
    check("承認する補佐のパスワードが違うと再初期化しない", lead_state()[0] == lead_hash)
    # 管理者が補佐になりすまして承認する道（直前 7 日に触られた補佐は承認できない）
    for operation in ("temp_password", "reset_2fa", "recovery_issue", "view_backup_codes", "freeze_reinit", "deputy_on", "create"):
        for days, refused in ((6.9, True), (7.1, False)):
            database.execute("INSERT INTO access_logs (user_id, action, target, created_at) VALUES (?, 'admin', ?, ?)",
                             (uid("admin01"), "user:%d:%s" % (uid("ippan_b1"), operation), (datetime.datetime.now() - datetime.timedelta(days=days)).strftime("%Y-%m-%d %H:%M:%S")))
            page_html = client_of("ippan_b1").get("/admin/security.php")[2]
            check("%s から %s 日の補佐には、承認の欄を%s" % (operation, days, "出さない" if refused else "出す"), ('value="lead_recovery_approve"' in page_html) != refused)
            if refused:
                check("%s から %s 日の補佐は承認できない（403）" % (operation, days), lead_post("ippan_b1", "lead_recovery_approve")[0] == 403 and lead_state()[0] == lead_hash)
            database.execute("DELETE FROM access_logs WHERE target = ?", ("user:%d:%s" % (uid("ippan_b1"), operation),))
    lead_post("ippan_b1", "lead_recovery_approve")
    label, second_half = half("ippan_b1", "/admin/security.php?half=1")
    check("補佐の承認で再初期化し、補佐には後半だけが出る", label == "後半" and second_half != first_half and lead_state()[0] != lead_hash and one("SELECT frozen_at FROM users WHERE id = ?", uid("hosa01")) is None)
    check("前半は DB から消える", one("SELECT COUNT(*) FROM lead_recovery_requests WHERE first_half_enc IS NOT NULL") == 0)
    for label, partial in (("前半", first_half), ("後半", second_half), ("逆の順", second_half + first_half)):
        check("%sだけではログインできない" % label, "/account.php" not in password_step("hosa01", partial)[1])
    database.execute("UPDATE users SET failed_count = 0, locked_until = NULL")
    client, location, _ = password_step("hosa01", first_half + second_half)
    check("前半と後半をつなぐと、本人が 2 段階認証の初期設定からやり直せる", "/account.php?tab=totp" in location, location)
    set_up_second_factor(client)
    check("本人のホームに 2 人の名前と役割が出る（パスワードの変更のあと）", "/index.php" in (change_password(client, "brand-new-hosa-01")[1] or "")
          and "%s さん（%s）と %s さん（システム管理者補佐）が、あなたのアカウントを再初期化しました" % (names["admin01"], roles["admin01"], names["ippan_b1"]) in client.get("/index.php")[2])
    lead_post("admin01", "lead_recovery_request")
    half("admin01")
    users["hosa01"]["password"] = "brand-new-hosa-01"
    clients["hosa01"] = client
    lead_post("hosa01", "lead_recovery_cancel")
    check("本人も、心当たりのない復旧の申請を取り消せる", one("SELECT COUNT(*) FROM lead_recovery_requests WHERE status = 'pending'") == 0)
    check("取り消した申請は承認できない", lead_post("ippan_b1", "lead_recovery_approve")[0] in (403, 404) and one("SELECT must_change_password FROM users WHERE id = ?", uid("hosa01")) == 0)

    return summary()


if __name__ == "__main__":
    sys.exit(main())
