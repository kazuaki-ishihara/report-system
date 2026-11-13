#!/usr/bin/env python3
"""便利機能 A〜J の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

A 更新のお知らせ / B 返信の定型文 / C 重複をまとめる / D 対応の予定 / E 要望の CSV /
F メンテナンス中 / G 組織の改訂の予約 / H バックアップの状態 / I 画面ごとの使い方 / J よくある質問

    php tools/test/dev_seed.php --yes
    python3 tools/test/smoke_conveniences.py --base http://127.0.0.1:8000 --db app/data/dev.sqlite
"""
import argparse
import datetime
import json
import os
import re
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, csrf, login, summary  # noqa: E402


def main():
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--base", default="http://127.0.0.1:8000")
    argument_parser.add_argument("--db", required=True)
    arguments = argument_parser.parse_args()
    test_dir = os.path.dirname(os.path.abspath(__file__))
    seed_info = json.load(open(os.path.join(test_dir, "output_dev_users.json"), encoding="utf-8"))
    user_info_by_login_id = seed_info["users"]
    database = sqlite3.connect(arguments.db, timeout=10, isolation_level=None)
    today = datetime.date.today()

    def post_form(client, path, form_values):
        _status_code, _location, page_html = client.get(path)
        return client.post(path, dict(form_values, csrf_token=csrf(page_html), request_key=os.urandom(16).hex()))

    def create_request(client, title_text, kind="improvement"):
        _status_code, location, _page_html = post_form(client, "/requests/index.php", {
            "action": "create", "kind": kind, "title": title_text, "body": title_text + " の内容", "screen_code": "system", "urgency": "normal"})
        found = re.search(r"id=(\d+)", location or "")
        return int(found.group(1)) if found else 0

    admin_client = login(arguments.base, "admin01", user_info_by_login_id["admin01"])
    member_client = login(arguments.base, "ippan_a1", user_info_by_login_id["ippan_a1"])
    kacho_client = login(arguments.base, "kacho_a", user_info_by_login_id["kacho_a"])
    leader_client = login(arguments.base, "leader_a", user_info_by_login_id["leader_a"])

    # ---------------- A 更新のお知らせ ----------------
    check("A: 部下は更新のお知らせの管理を開けない", member_client.get("/admin/notices.php")[0] == 403)
    status_code, _location, _page_html = post_form(admin_client, "/admin/notices.php", {
        "action": "save", "id": "", "source_request_id": "", "title": "提出状況の札を色分けしました", "body": "提出済みは緑、未作成は灰色です。",
        "show_from": today.isoformat(), "show_until": (today + datetime.timedelta(days=14)).isoformat()})
    check("A: 管理者が更新のお知らせを書ける", status_code in (302, 303), status_code)
    post_form(admin_client, "/admin/notices.php", {
        "action": "save", "id": "", "source_request_id": "", "title": "終わったお知らせ", "body": "",
        "show_from": (today - datetime.timedelta(days=30)).isoformat(), "show_until": (today - datetime.timedelta(days=1)).isoformat()})
    _status_code, _location, member_home_html = member_client.get("/index.php")
    check("A: 全員のホームに、出す期間のお知らせが出る", "提出状況の札を色分けしました" in member_home_html)
    check("A: 出す期間を過ぎたお知らせは出ない", "終わったお知らせ" not in member_home_html)
    status_code, _location, error_html = post_form(admin_client, "/admin/notices.php", {
        "action": "save", "id": "", "source_request_id": "", "title": "逆の期間", "body": "",
        "show_from": today.isoformat(), "show_until": (today - datetime.timedelta(days=1)).isoformat()})
    check("A: 出す最後の日が出す日より前なら保存しない", status_code == 200 and "出す最後の日は" in error_html, status_code)

    # ---------------- I 画面ごとの使い方 ----------------
    check("I: ホームに「この画面の使い方」", "この画面の使い方" in member_home_html)
    _status_code, _location, edit_html = member_client.get("/reports/edit.php?period=" + today.strftime("%Y-%m"))
    check("I: 報告の作成にも使い方（下書き保存・必須の説明）", "この画面の使い方" in edit_html and "下書き保存" in edit_html)

    # ---------------- J よくある質問 ----------------
    status_code, _location, _page_html = post_form(admin_client, "/faq.php", {
        "action": "save", "id": "", "source_request_id": "", "question": "締め日を過ぎても直せますか", "answer": "当月と過去 3 か月の報告は直せます。"})
    check("J: 管理者がよくある質問を載せられる", status_code in (302, 303), status_code)
    status_code, _location, faq_html = member_client.get("/faq.php")
    check("J: 部下もよくある質問を見られる", status_code == 200 and "締め日を過ぎても直せますか" in faq_html and "当月と過去 3 か月" in faq_html, status_code)
    check("J: 部下には直す・消すを出さない", 'value="delete"' not in faq_html)
    status_code, _location, _page_html = member_client.post("/faq.php", {"csrf_token": csrf(faq_html), "request_key": os.urandom(16).hex(), "action": "save", "question": "x", "answer": "y"})
    check("J: 部下は載せられない", status_code == 403, status_code)
    check("J: メニューに「よくある質問」", "/faq.php" in member_home_html)

    # ---------------- B 返信の定型文 / D 対応の予定 / C 重複をまとめる / E CSV ----------------
    status_code, _location, _page_html = post_form(admin_client, "/requests/index.php", {"action": "template_add", "template_kind": "", "template_body": "ユーザーを追加しました。仮のパスワードは別にお伝えします。"})
    post_form(admin_client, "/requests/index.php", {"action": "template_add", "template_kind": "bug", "template_body": "不具合を直しました。もう一度お試しください。"})
    check("B: 管理者が返信の定型文を種類ごとに足せる", database.execute("SELECT COUNT(*) FROM support_reply_templates").fetchone()[0] == 2, status_code)
    kacho_request_id = create_request(kacho_client, "提出状況を色分けしてほしい")
    leader_request_id = create_request(leader_client, "提出状況の色を分けてほしい")
    _status_code, _location, admin_view_html = admin_client.get("/requests/view.php?id=%d" % kacho_request_id)
    check("B: 返信の欄で定型文を選べる（すべての種類の定型文）", "support-reply-template-select" in admin_view_html and "ユーザーを追加しました" in admin_view_html)
    check("B: 要望の種類と違う種類の定型文は出さない（改善要望に不具合の定型文）", "不具合を直しました" not in admin_view_html)

    status_code, _location, _page_html = post_form(admin_client, "/requests/view.php?id=%d" % kacho_request_id, {"action": "plan_save", "plan_text": "2026年12月にリリース予定"})
    _status_code, _location, kacho_view_html = kacho_client.get("/requests/view.php?id=%d" % kacho_request_id)
    check("D: 対応の予定を出した人に見せる", "2026年12月にリリース予定" in kacho_view_html, status_code)
    _status_code, _location, kacho_list_html = kacho_client.get("/requests/index.php?status=all")
    check("D: 一覧にも対応の予定", "2026年12月にリリース予定" in kacho_list_html)

    status_code, _location, _page_html = post_form(admin_client, "/requests/view.php?id=%d" % leader_request_id, {"action": "merge", "merge_into": str(kacho_request_id)})
    merged_row = database.execute("SELECT status, merged_into_id FROM support_requests WHERE id = ?", (leader_request_id,)).fetchone()
    check("C: 同じ内容の要望にまとめて閉じる", tuple(merged_row) == ("done", kacho_request_id), merged_row)
    status_code, _location, error_html = post_form(admin_client, "/requests/view.php?id=%d" % kacho_request_id, {"action": "merge", "merge_into": str(kacho_request_id)})
    check("C: 自分自身にはまとめられない", status_code == 200 and "まとめる先の番号が正しくありません" in error_html, status_code)
    leader_client.get("/requests/view.php?id=%d" % leader_request_id)   # まとめた知らせは見たことにする
    post_form(admin_client, "/requests/view.php?id=%d" % kacho_request_id, {"action": "staff_reply", "body": "提出状況の札を色分けしました。", "status": "released"})
    _status_code, _location, leader_home_html = leader_client.get("/index.php")
    check("C: まとめた先の返信が、まとめた要望の人にも届く（ホームに回答あり）", "要望・依頼にシステム管理者から回答があります" in leader_home_html)
    _status_code, _location, leader_view_html = leader_client.get("/requests/view.php?id=%d" % leader_request_id)
    check("C: まとめた要望に、まとめた先の返信の中身が入る", "提出状況の札を色分けしました。" in leader_view_html and "同じ内容の要望 #%d での返信" % kacho_request_id in leader_view_html)
    check("C: 出した人には、まとめた先の要望の中身は開けない", leader_client.get("/requests/view.php?id=%d" % kacho_request_id)[0] == 403)
    _status_code, _location, admin_view_html = admin_client.get("/requests/view.php?id=%d" % kacho_request_id)
    check("A: リリース済みの要望から、お知らせを書く画面へ移れる", "/admin/notices.php?request=%d" % kacho_request_id in admin_view_html)

    status_code, _location, _csv_text = admin_client.get("/requests/index.php?status=all&csv=1")
    csv_text = admin_client.last_raw.decode("utf-8-sig")
    check("E: 管理者が要望・依頼の一覧を CSV に出せる（件名と状態）", status_code == 200 and "要望・依頼の一覧" in csv_text and "提出状況を色分けしてほしい" in csv_text and "の内容" not in csv_text, status_code)
    status_code, _location, _csv_text = admin_client.get("/requests/index.php?status=all&csv=1&body=1")
    check("E: 内容も含めて出せる", "提出状況を色分けしてほしい の内容" in admin_client.last_raw.decode("utf-8-sig"))
    check("E: 出力を記録する", database.execute("SELECT COUNT(*) FROM access_logs WHERE action = 'export' AND target LIKE 'export:support_requests%'").fetchone()[0] == 2)
    check("E: 課長は CSV に出せない", kacho_client.get("/requests/index.php?status=all&csv=1")[0] == 403)

    # ---------------- G 組織の改訂の予約 ----------------
    def preview_csv(csv_body_text):
        _status_code, _location, bulk_html = admin_client.get("/admin/org.php?tab=bulk")
        csv_bytes = ("ログイン ID,表示名,所属の課,班,役割,担当の班,項目管理者,有効\r\n" + csv_body_text).encode("utf-8")
        _status_code, _location, preview_html = admin_client.post_file("/admin/org.php?tab=bulk", {"csrf_token": csrf(bulk_html), "action": "bulk_preview"}, "org_csv", "org.csv", csv_bytes)
        token_match = re.search(r'name="plan_token" value="([0-9a-f]+)"', preview_html)
        return token_match.group(1) if token_match else "", preview_html

    plan_token, preview_html = preview_csv("ippan_a2,A一般2,B課,,,,,1\r\n")
    check("G: 取り込んだあと「すぐに反映」と「予約」を選べる", "すぐに反映する" in preview_html and 'name="apply_on"' in preview_html)
    tomorrow_text = (today + datetime.timedelta(days=1)).isoformat()
    status_code, _location, _page_html = admin_client.post("/admin/org.php?tab=bulk", {
        "csrf_token": csrf(preview_html), "request_key": os.urandom(16).hex(), "action": "bulk_schedule", "plan_token": plan_token, "apply_on": tomorrow_text})
    schedule_row = database.execute("SELECT id, status, apply_on FROM org_schedules ORDER BY id DESC LIMIT 1").fetchone()
    check("G: 明日に反映するよう予約できる", schedule_row is not None and schedule_row[1] == "scheduled" and schedule_row[2] == tomorrow_text, (status_code, schedule_row))
    check("G: 予約しただけでは変わらない", database.execute("SELECT d.name FROM users u JOIN departments d ON d.id = u.department_id WHERE u.login_id = 'ippan_a2'").fetchone()[0] == "A課")
    database.execute("UPDATE org_schedules SET apply_on = ? WHERE id = ?", (today.isoformat(), schedule_row[0]))   # 日が来たことにする
    member_client.get("/index.php")
    check("G: 予約した日が来ると、画面の表示で反映される", database.execute("SELECT d.name FROM users u JOIN departments d ON d.id = u.department_id WHERE u.login_id = 'ippan_a2'").fetchone()[0] == "B課"
          and database.execute("SELECT status FROM org_schedules WHERE id = ?", (schedule_row[0],)).fetchone()[0] == "applied")
    database.execute("INSERT INTO org_schedules (apply_on, csv_text, change_count, status, created_by, created_at) VALUES (?, ?, 1, 'scheduled', ?, datetime('now'))",
                     (today.isoformat(), "ログイン ID,表示名,所属の課,班,役割,担当の班,項目管理者,有効\r\nippan_a2,A一般2,無い課,,,,,1\r\n", user_info_by_login_id["admin01"]["id"]))
    member_client.get("/index.php")
    check("G: その時点で誤りがあれば反映せず「失敗」にする", database.execute("SELECT status FROM org_schedules ORDER BY id DESC LIMIT 1").fetchone()[0] == "failed")
    _status_code, _location, admin_top_html = admin_client.get("/admin/index.php")
    check("G: 管理のトップに、反映できなかった予約を知らせる", "反映できなかった予約" in admin_top_html)
    plan_token, preview_html = preview_csv("ippan_a1,A一般1,B課,,,,,1\r\n")
    admin_client.post("/admin/org.php?tab=bulk", {"csrf_token": csrf(preview_html), "request_key": os.urandom(16).hex(), "action": "bulk_schedule", "plan_token": plan_token, "apply_on": tomorrow_text})
    cancel_schedule_id = database.execute("SELECT id FROM org_schedules WHERE status = 'scheduled' ORDER BY id DESC LIMIT 1").fetchone()[0]
    post_form(admin_client, "/admin/org.php?tab=bulk", {"action": "schedule_cancel", "schedule_id": str(cancel_schedule_id)})
    check("G: 予約を取り消せる", database.execute("SELECT status FROM org_schedules WHERE id = ?", (cancel_schedule_id,)).fetchone()[0] == "cancelled")

    # ---------------- H バックアップの状態 ----------------
    check("H: 管理のトップに DB の大きさと最後のバックアップ", "DB の大きさ" in admin_top_html and "最後のバックアップ" in admin_top_html)
    _status_code, _location, kacho_top_html = kacho_client.get("/admin/index.php")
    check("H: 課長の管理のトップには出さない", "DB の大きさ" not in kacho_top_html)

    # ---------------- F メンテナンス中 ----------------
    until_text = (datetime.datetime.now() + datetime.timedelta(hours=1)).strftime("%Y-%m-%dT%H:%M")
    status_code, _location, _page_html = post_form(admin_client, "/admin/index.php", {"action": "maintenance_start", "until": until_text, "message": "新しい版に入れ替えています"})
    check("F: 管理者がメンテナンス中にできる", database.execute("SELECT COUNT(*) FROM app_state WHERE name = 'maintenance'").fetchone()[0] == 1, status_code)
    _status_code, _location, member_home_html = member_client.get("/index.php")
    check("F: 部下の画面に知らせを出す（一言も）", "メンテナンス中です" in member_home_html and "新しい版に入れ替えています" in member_home_html and 'data-maintenance="1"' in member_home_html)
    status_code, _location, _page_html = post_form(member_client, "/account.php?tab=theme", {"action": "theme_save", "ui_theme": "dark"})
    check("F: 部下の保存などの操作は受け付けない（503）", status_code == 503
          and database.execute("SELECT ui_theme FROM users WHERE login_id = 'ippan_a1'").fetchone()[0] != "dark", status_code)
    _status_code, _location, admin_home_html = admin_client.get("/index.php")
    check("F: 管理者の画面は止めない（知らせだけ）", 'data-maintenance="1"' not in admin_home_html and "メンテナンス中です（管理者・補佐の画面）" in admin_home_html)
    status_code, _location, _page_html = post_form(admin_client, "/admin/index.php", {"action": "maintenance_end"})
    check("F: メンテナンスを終えられる", database.execute("SELECT COUNT(*) FROM app_state WHERE name = 'maintenance'").fetchone()[0] == 0, status_code)
    status_code, _location, _page_html = post_form(member_client, "/account.php?tab=theme", {"action": "theme_save", "ui_theme": "dark"})
    check("F: 終えたあとは部下も保存できる", database.execute("SELECT ui_theme FROM users WHERE login_id = 'ippan_a1'").fetchone()[0] == "dark", status_code)
    database.execute("INSERT INTO app_state (name, value) VALUES ('maintenance', ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value",
                     (json.dumps({"until": "2000-01-01 00:00:00", "message": "", "started_by": 1, "started_at": "2000-01-01 00:00:00"}),))
    status_code, _location, _page_html = post_form(member_client, "/account.php?tab=theme", {"action": "theme_save", "ui_theme": "light"})
    check("F: 終わる日時を過ぎたら自動で終わる", database.execute("SELECT ui_theme FROM users WHERE login_id = 'ippan_a1'").fetchone()[0] == "light", status_code)
    return summary()


if __name__ == "__main__":
    sys.exit(main())
