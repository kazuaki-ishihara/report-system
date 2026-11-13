#!/usr/bin/env python3
"""DB に秘密の値・平文の自由記述が残っていないかを確かめる（読み取り専用）。

    python3 tools/test/check_db_secrets.py app/data/dev.sqlite
"""
import re
import sqlite3
import sys


def main():
    path = sys.argv[1]
    con = sqlite3.connect("file:%s?mode=ro" % path, uri=True)
    ng = []

    # 2 段階認証の秘密の値は暗号化されている
    for uid, v in con.execute("SELECT id, totp_secret_enc FROM users WHERE totp_secret_enc IS NOT NULL"):
        if not v.startswith("v1:") or re.fullmatch(r"[A-Z2-7]{32}", v):
            ng.append("users.totp_secret_enc が暗号化されていない: id=%s" % uid)

    # 予備のコードはハッシュだけ
    for cid, v in con.execute("SELECT id, code_hash FROM user_backup_codes"):
        if not v.startswith("$2y$") and not v.startswith("$argon2"):
            ng.append("user_backup_codes.code_hash がハッシュでない: id=%s" % cid)

    # 端末の記憶はハッシュだけ（SHA-256 の 16 進）
    for did, v in con.execute("SELECT id, token_hash FROM remembered_devices"):
        if not re.fullmatch(r"[0-9a-f]{64}", v):
            ng.append("remembered_devices.token_hash が想定外: id=%s" % did)

    # 自由記述の値は暗号化されている
    q = """SELECT v.report_id, v.field_id, v.value_text, v.is_encrypted, f.field_type, f.confidentiality
             FROM report_field_values v JOIN report_record_fields f ON f.report_id = v.report_id AND f.field_id = v.field_id"""
    for rid, fid, val, isenc, ftype, conf in con.execute(q):
        need = ftype in ("single_text", "multi_text", "table") or conf == "restricted"
        if need and val != "" and (isenc != 1 or not val.startswith("v1:")):
            ng.append("自由記述・限定の値が平文: report=%s field=%s" % (rid, fid))

    # コメントの本文は暗号化されている
    for cid, v in con.execute("SELECT id, comment_enc FROM report_field_comments"):
        if v != "" and not v.startswith("v1:"):
            ng.append("コメントが平文: id=%s" % cid)

    # 要望・依頼（G-22）の件名・本文・返信は暗号化されている
    has_support_tables = con.execute("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'support_requests'").fetchone() is not None
    if has_support_tables:
        for request_id, title_enc, body_enc in con.execute("SELECT id, title_enc, body_enc FROM support_requests"):
            if not str(title_enc).startswith("v1:") or not str(body_enc).startswith("v1:"):
                ng.append("要望・依頼の件名か本文が平文: id=%s" % request_id)
        for message_id, message_body_enc in con.execute("SELECT id, body_enc FROM support_request_messages"):
            if message_body_enc != "" and not str(message_body_enc).startswith("v1:"):
                ng.append("要望・依頼の返信が平文: id=%s" % message_id)

    # 記録にパスワードやコードらしいものが入っていない
    for lid, t in con.execute("SELECT id, target FROM access_logs"):
        if re.search(r"password=|code=|secret", t):
            ng.append("記録に秘密らしい値: id=%s" % lid)

    for line in ng:
        print("NG  " + line)
    print("確認: %s / 問題 %d 件" % (path, len(ng)))
    return 1 if ng else 0


if __name__ == "__main__":
    sys.exit(main())
