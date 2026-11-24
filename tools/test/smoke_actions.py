#!/usr/bin/env python3
"""検証に入っていなかった操作の確認（ローカル検証環境専用。dev_seed.php の直後に実行する）。

確認完了の取り消し・回答待ちに戻す、案件の再開、臨時の調査の締め切り・複製・ひな形・質問の並べ替えと削除、
削除データ（コメント・調査）を戻す、注意する言葉の追加・削除、返信の定型文の削除、並べ替え（下へ）、自動保存（HTTP）。
どの操作も DB の値と、権限のない人が断られることまで見る。

    python3 tools/test/smoke_actions.py --base http://127.0.0.1:8000 --db app/data/verify.sqlite
"""
import argparse
import datetime
import json
import os
import re
import secrets
import sqlite3
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib_http import check, csrf, login, section, summary  # noqa: E402


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8000")
    ap.add_argument("--db", required=True)
    a = ap.parse_args()
    here = os.path.dirname(os.path.abspath(__file__))
    seed = json.load(open(os.path.join(here, "output_dev_users.json"), encoding="utf-8"))
    users = seed["users"]
    db = sqlite3.connect(a.db, timeout=10, isolation_level=None)
    now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    today = datetime.date.today()
    period = today.strftime("%Y-%m")
    due = (today + datetime.timedelta(days=5)).isoformat()

    def one(sql, *values):
        row = db.execute(sql, values).fetchone()
        return row[0] if row else None

    def column(sql, *values):
        return [row[0] for row in db.execute(sql, values)]

    clients = {name: login(a.base, name, users[name]) for name in ("admin01", "kacho_a", "kacho_b", "ippan_a1", "ippan_a2", "hosa01")}

    def post(name, path, action, **values):
        client = clients[name]
        _, _, body = client.get("/account.php?tab=password")
        data = {"csrf_token": csrf(body), "request_key": secrets.token_hex(16), "action": action}
        data.update(values)
        return client.post(path, data)

    # ---- 報告とコメント ----
    owner_id = users["ippan_a1"]["id"]
    db.execute("UPDATE report_records SET deleted_at = ? WHERE user_id = ? AND period = ? AND deleted_at IS NULL", (now, owner_id, period))
    report_id = db.execute("INSERT INTO report_records (user_id, department_id, period, status, is_legacy, submitted_at, created_at, updated_at)"
                           " VALUES (?, ?, ?, 'submitted', 0, ?, ?, ?)", (owner_id, seed["dept_a"], period, now, now, now)).lastrowid

    section("G-05", "確認完了の取り消し・回答待ちに戻す")

    def report_status():
        return one("SELECT status FROM report_records WHERE id = ?", report_id)

    act = "/reports/act.php"
    post("kacho_a", act, "unconfirm", report_id=report_id)
    check("確認完了でない報告は取り消せない", report_status() == "submitted", report_status())
    post("kacho_a", act, "confirm", report_id=report_id)
    check("課長が確認を完了にできる", report_status() == "confirmed", report_status())
    status, _, _ = post("ippan_a1", act, "unconfirm", report_id=report_id)
    check("本人は確認完了を取り消せない", status == 403 and report_status() == "confirmed", status)
    status, _, _ = post("kacho_b", act, "unconfirm", report_id=report_id)
    check("別の課の課長は取り消せない", status in (403, 404) and report_status() == "confirmed", status)
    post("kacho_a", act, "unconfirm", report_id=report_id)
    row = db.execute("SELECT status, confirmed_by, confirmed_at FROM report_records WHERE id = ?", (report_id,)).fetchone()
    check("課長が取り消すと提出済みに戻り、確認した人と日時が消える", row == ("submitted", None, None), row)
    check("取り消しが状態の履歴に残る", one("SELECT COUNT(*) FROM report_status_history WHERE report_id = ? AND from_status = 'confirmed' AND to_status = 'submitted'", report_id) == 1)

    post("kacho_a", act, "comment", report_id=report_id, comment_type="request", comment="理由を教えてください", field_id="", report_project_id="0")
    comment_id = one("SELECT MAX(id) FROM report_field_comments WHERE report_id = ?", report_id)
    check("準備: 課長のコメントができる", comment_id is not None)
    if comment_id is not None:
        def thread():
            return db.execute("SELECT thread_status, resolved_by FROM report_field_comments WHERE id = ?", (comment_id,)).fetchone()
        post("kacho_a", act, "resolve", report_id=report_id, parent_id=comment_id)
        check("解決にできる", thread() == ("resolved", users["kacho_a"]["id"]), thread())
        status, _, _ = post("ippan_a1", act, "reopen", report_id=report_id, parent_id=comment_id)
        check("書いた人・課長でなければ、回答待ちに戻せない", status == 403 and thread()[0] == "resolved", status)
        post("kacho_a", act, "reopen", report_id=report_id, parent_id=comment_id)
        check("回答待ちに戻すと、解決した人が消える", thread() == ("open", None), thread())

        section("G-15", "削除したコメント・調査を戻す")
        post("kacho_a", act, "comment_delete", report_id=report_id, comment_id=comment_id)
        check("準備: コメントを削除した", one("SELECT deleted_at FROM report_field_comments WHERE id = ?", comment_id) is not None)
        status, _, _ = post("hosa01", "/admin/trash.php", "restore_comment", id=comment_id)
        check("補佐は戻せない", status == 403 and one("SELECT deleted_at FROM report_field_comments WHERE id = ?", comment_id) is not None, status)
        post("admin01", "/admin/trash.php", "restore_comment", id=comment_id)
        check("管理者がコメントを戻せる", one("SELECT deleted_at FROM report_field_comments WHERE id = ?", comment_id) is None)
        _, _, body = clients["ippan_a1"].get("/reports/view.php?id=%d" % report_id)
        check("戻したコメントの本文が読める", "理由を教えてください" in body)
        old = (datetime.datetime.now() - datetime.timedelta(days=31)).strftime("%Y-%m-%d %H:%M:%S")
        db.execute("UPDATE report_field_comments SET deleted_at = ? WHERE id = ?", (old, comment_id))
        post("admin01", "/admin/trash.php", "restore_comment", id=comment_id)
        check("30 日を過ぎたコメントは戻せない", one("SELECT deleted_at FROM report_field_comments WHERE id = ?", comment_id) == old)
        db.execute("UPDATE report_field_comments SET deleted_at = NULL WHERE id = ?", (comment_id,))

    # ---- 臨時の調査 ----
    section("G-11", "質問の並べ替え・削除、締め切り、複製、ひな形")
    _, location, _ = post("kacho_a", "/surveys/survey_edit.php", "save_meta", department_id=seed["dept_a"], title="操作の確かめ", requester="", purpose="",
                          due_at=due, result_visibility="detail", target_type="all")
    match = re.search(r"id=(\d+)", location or "")
    check("準備: 調査を作れる", match is not None, location)
    survey_id = int(match.group(1)) if match else 0
    edit = "/surveys/survey_edit.php?id=%d" % survey_id
    for label in ("質問1", "質問2", "質問3"):
        post("kacho_a", edit, "add_custom", q_label=label, q_type="radio", q_options="はい\nいいえ", q_conf="internal", q_help="")

    def labels(target_id):
        return column("SELECT label FROM adhoc_request_fields WHERE request_id = ? ORDER BY sort_order, id", target_id)

    def question(label):
        return one("SELECT id FROM adhoc_request_fields WHERE request_id = ? AND label = ?", survey_id, label)

    check("準備: 質問が 3 つ", labels(survey_id) == ["質問1", "質問2", "質問3"], labels(survey_id))
    post("kacho_a", edit, "q_down", q_id=question("質問1"))
    check("質問を下へ動かせる", labels(survey_id) == ["質問2", "質問1", "質問3"], labels(survey_id))
    post("kacho_a", edit, "q_up", q_id=question("質問3"))
    check("質問を上へ動かせる", labels(survey_id) == ["質問2", "質問3", "質問1"], labels(survey_id))
    post("kacho_a", edit, "q_up", q_id=question("質問2"))
    check("先頭の質問を上へ動かしても変わらない", labels(survey_id) == ["質問2", "質問3", "質問1"], labels(survey_id))
    post("kacho_a", edit, "q_remove", q_id=question("質問3"))
    check("質問を削除できる", labels(survey_id) == ["質問2", "質問1"], labels(survey_id))
    status, _, _ = post("kacho_b", edit, "q_remove", q_id=question("質問1"))
    check("別の課の課長は質問を削除できない", status == 403 and len(labels(survey_id)) == 2, status)

    before = set(column("SELECT id FROM adhoc_requests"))
    post("kacho_a", edit, "copy")
    new_ids = set(column("SELECT id FROM adhoc_requests")) - before
    check("複製すると、同じ質問の下書きが 1 つできる", len(new_ids) == 1 and labels(min(new_ids)) == ["質問2", "質問1"]
          and db.execute("SELECT status, is_template FROM adhoc_requests WHERE id = ?", (min(new_ids),)).fetchone() == ("draft", 0), new_ids)
    before = set(column("SELECT id FROM adhoc_requests"))
    post("kacho_a", edit, "save_template")
    new_ids = set(column("SELECT id FROM adhoc_requests")) - before
    template_id = min(new_ids) if new_ids else 0
    check("ひな形として保存できる", len(new_ids) == 1 and one("SELECT is_template FROM adhoc_requests WHERE id = ?", template_id) == 1
          and labels(template_id) == ["質問2", "質問1"], new_ids)
    post("kacho_a", "/surveys/survey_edit.php?id=%d" % template_id, "publish")
    check("ひな形は公開できない", one("SELECT status FROM adhoc_requests WHERE id = ?", template_id) == "draft")

    status, _, _ = post("kacho_a", edit, "close")
    check("公開していない調査は締め切れない", status == 400 and one("SELECT status FROM adhoc_requests WHERE id = ?", survey_id) == "draft", status)
    post("kacho_a", edit, "publish")
    check("準備: 公開できる", one("SELECT status FROM adhoc_requests WHERE id = ?", survey_id) == "open")
    status, _, _ = post("kacho_a", edit, "q_remove", q_id=question("質問1"))
    check("公開後は質問を削除できない", status == 400 and len(labels(survey_id)) == 2, status)
    status, _, _ = post("kacho_b", edit, "close")
    check("別の課の課長は締め切れない", status == 403 and one("SELECT status FROM adhoc_requests WHERE id = ?", survey_id) == "open", status)
    post("kacho_a", edit, "close")
    row = db.execute("SELECT status, closed_at IS NOT NULL FROM adhoc_requests WHERE id = ?", (survey_id,)).fetchone()
    check("締め切れる", row == ("closed", 1), row)
    post("ippan_a1", "/surveys/answer.php?id=%d" % survey_id, "", id=survey_id, **{"q[%d]" % question("質問1"): "o1"})
    check("締め切った調査には回答できない", one("SELECT COUNT(*) FROM adhoc_responses WHERE request_id = ? AND submitted_at IS NOT NULL", survey_id) == 0)

    section("G-15", "削除したコメント・調査を戻す")
    post("kacho_a", edit, "delete")
    check("準備: 調査を削除した", one("SELECT deleted_at FROM adhoc_requests WHERE id = ?", survey_id) is not None)
    status, _, _ = post("kacho_a", "/admin/trash.php", "restore_survey", id=survey_id)
    check("課長は戻せない", status == 403 and one("SELECT deleted_at FROM adhoc_requests WHERE id = ?", survey_id) is not None, status)
    post("admin01", "/admin/trash.php", "restore_survey", id=survey_id)
    check("管理者が調査を戻せる", one("SELECT deleted_at FROM adhoc_requests WHERE id = ?", survey_id) is None)
    status, _, body = clients["kacho_a"].get(edit)
    check("戻した調査を課長が開ける", status == 200 and "操作の確かめ" in body, status)

    # ---- 案件 ----
    section("G-13", "案件の終了と再開")
    project_id = one("SELECT id FROM projects WHERE code = 'P-0001'")
    post("admin01", "/admin/projects.php", "end", id=project_id)
    check("準備: 終了にした", one("SELECT is_active FROM projects WHERE id = ?", project_id) == 0)
    status, _, _ = post("ippan_a1", "/admin/projects.php", "reopen", id=project_id)
    check("一般の人は再開できない", status == 403 and one("SELECT is_active FROM projects WHERE id = ?", project_id) == 0, status)
    post("admin01", "/admin/projects.php", "reopen", id=project_id)
    row = db.execute("SELECT is_active, ended_on FROM projects WHERE id = ?", (project_id,)).fetchone()
    check("再開すると有効になり、終了日が消える", row == (1, None), row)
    _, _, body = clients["ippan_a2"].get("/reports/edit.php?period=" + period)
    check("再開した案件を報告で選べる", "P-0001" in body)

    # ---- 管理 ----
    section("G-14", "注意する言葉の追加・削除")
    post("admin01", "/admin/org.php?tab=words", "word_add", word="検証の言葉")
    word_id = one("SELECT id FROM warning_words WHERE word = '検証の言葉'")
    check("言葉を追加できる", word_id is not None)
    _, _, body = clients["ippan_a2"].get("/reports/edit.php?period=" + period)
    check("追加した言葉が、報告の画面の注意に使われる", "検証の言葉" in body)
    post("admin01", "/admin/org.php?tab=words", "word_add", word="検証の言葉")
    check("同じ言葉は 2 つにならない", one("SELECT COUNT(*) FROM warning_words WHERE word = '検証の言葉'") == 1)
    status, _, _ = post("ippan_a1", "/admin/org.php?tab=words", "word_delete", id=word_id or 1)
    check("一般の人は言葉を削除できない", status == 403 and one("SELECT COUNT(*) FROM warning_words WHERE word = '検証の言葉'") == 1, status)
    post("admin01", "/admin/org.php?tab=words", "word_delete", id=word_id or 1)
    check("言葉を削除できる", one("SELECT COUNT(*) FROM warning_words WHERE word = '検証の言葉'") == 0)

    section("G-22", "返信の定型文の削除")
    post("admin01", "/requests/index.php", "template_add", template_body="検証の定型文")
    template_row_id = one("SELECT MAX(id) FROM support_reply_templates")
    check("準備: 定型文を足せる", template_row_id is not None)
    if template_row_id is not None:
        status, _, _ = post("kacho_a", "/requests/index.php", "template_delete", template_id=template_row_id)
        check("課長は定型文を消せない", status == 403 and one("SELECT COUNT(*) FROM support_reply_templates WHERE id = ?", template_row_id) == 1, status)
        post("admin01", "/requests/index.php", "template_delete", template_id=template_row_id)
        check("管理者が定型文を消せる", one("SELECT COUNT(*) FROM support_reply_templates WHERE id = ?", template_row_id) == 0)

    section("G-25", "並びを下へ・上へ動かす")
    for question_text in ("並べ替えの確かめ1", "並べ替えの確かめ2"):
        post("admin01", "/faq.php", "save", id="", category="login", question=question_text, answer="答え")
    ordered = "SELECT question FROM faq_entries WHERE question LIKE '並べ替えの確かめ%' ORDER BY sort_order, id"
    check("準備: 質問が 2 つ", column(ordered) == ["並べ替えの確かめ1", "並べ替えの確かめ2"], column(ordered))
    post("admin01", "/faq.php", "move_down", id=one("SELECT id FROM faq_entries WHERE question = '並べ替えの確かめ1'") or 1)
    check("よくある質問を下へ動かせる", column(ordered) == ["並べ替えの確かめ2", "並べ替えの確かめ1"], column(ordered))
    post("admin01", "/faq.php", "move_up", id=one("SELECT id FROM faq_entries WHERE question = '並べ替えの確かめ1'") or 1)
    check("よくある質問を上へ動かせる", column(ordered) == ["並べ替えの確かめ1", "並べ替えの確かめ2"], column(ordered))

    section("G-12", "項目を下へ動かす")
    field_order = "SELECT code FROM report_fields WHERE field_scope = 'project' AND department_id IS NULL ORDER BY sort_order, id"
    codes = column(field_order)
    post("admin01", "/admin/fields.php", "move_down", field_id=one("SELECT id FROM report_fields WHERE code = ?", codes[1]), id=one("SELECT id FROM report_fields WHERE code = ?", codes[1]))
    check("項目を下へ動かせる", column(field_order)[1:3] == [codes[2], codes[1]], column(field_order)[:4])

    # ---- 自動保存（HTTP。画面からの送信は ui_session.py） ----
    section("G-04", "自動保存の応答（autosave）")
    duties = one("SELECT id FROM report_fields WHERE code = 'duties'")
    user_id = users["ippan_a2"]["id"]
    db.execute("UPDATE report_records SET deleted_at = ? WHERE user_id = ? AND period = ? AND deleted_at IS NULL", (now, user_id, period))
    values = {"period": period, "b[0][project]": project_id, "b[0][f][%d]" % duties: "自動保存の値"}
    status, _, body = post("ippan_a2", "/reports/save.php", "autosave", **values)
    check("新しい報告: ok と保存の時刻を返す", status == 200 and json.loads(body).get("ok") is True, body[:100])
    row = db.execute("SELECT id, status FROM report_records WHERE user_id = ? AND period = ? AND deleted_at IS NULL", (user_id, period)).fetchone()
    check("下書きができ、案件と値が入る", row is not None and row[1] == "draft" and
          one("SELECT COUNT(*) FROM report_field_values WHERE report_id = ? AND field_id = ?", row[0], duties) == 1, row)
    values["b[0][f][%d]" % duties] = "x" * 1001
    _, _, body = post("ippan_a2", "/reports/save.php", "autosave", **values)
    check("長すぎる入力: invalid を返す", json.loads(body).get("reason") == "invalid", body[:100])
    db.execute("UPDATE report_records SET status = 'submitted' WHERE id = ?", (row[0],))
    values["b[0][f][%d]" % duties] = "提出後の値"
    _, _, body = post("ippan_a2", "/reports/save.php", "autosave", **values)
    check("提出済み: not_draft を返し、値を変えない", json.loads(body).get("reason") == "not_draft", body[:100])
    db.close()
    return summary()


if __name__ == "__main__":
    sys.exit(main())
