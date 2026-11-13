# 詳細設計書

月次報告システム（見直し版）

| 項目 | 内容 |
|---|---|
| 版 | 0.1（たたき台） |
| 作成日 | 2026-11-12 |
| 前提の文書 | [基本設計書](../basic_design.md)、[システム構成図](../system_architecture.md) |

基本設計書で決めた機能と画面を、作れる粒度まで具体化する。画面ごとに 1 ファイルとし、全画面に共通の決まりは共通編にまとめる。

## 文書の一覧

| ファイル | 内容 |
|---|---|
| [00_common.md](00_common.md) | 共通編: ファイル構成、URL、共通処理、セッション、CSRF、送信の番号、暗号化、入力の確認、権限の判定、メッセージ、画面の共通部品 |
| [01_database.md](01_database.md) | DB 編: テーブル定義、制約、索引、暗号化する列、保管期限 |
| [G01_login.md](G01_login.md) | G-01 ログイン |
| [G02_password_reset.md](G02_password_reset.md) | G-02 パスワードの再設定 |
| [G03_home.md](G03_home.md) | G-03 ホーム |
| [G04_report_edit.md](G04_report_edit.md) | G-04 報告の作成・編集 |
| [G05_report_detail.md](G05_report_detail.md) | G-05 報告の詳細 |
| [G06_my_reports.md](G06_my_reports.md) | G-06 自分の報告一覧 |
| [G07_survey_answer.md](G07_survey_answer.md) | G-07 臨時の調査への回答 |
| [G08_status.md](G08_status.md) | G-08 提出状況 |
| [G09_compare.md](G09_compare.md) | G-09 横並び表示・状況のまとめ |
| [G10_export.md](G10_export.md) | G-10 出力 |
| [G11_survey_manage.md](G11_survey_manage.md) | G-11 臨時の調査の管理 |
| [G12_fields.md](G12_fields.md) | G-12 項目マスタ |
| [G13_projects.md](G13_projects.md) | G-13 案件の管理 |
| [G14_org_users.md](G14_org_users.md) | G-14 組織・ユーザーの管理 |
| [G15_logs.md](G15_logs.md) | G-15 記録の閲覧・削除データ |
| [G16_account.md](G16_account.md) | G-16 アカウント設定（2 段階認証・端末・パスワード） |
| [G17_messages.md](G17_messages.md) | G-17 メッセージ（課長が画面の文言を変える） |
| [G18_comments.md](G18_comments.md) | G-18 コメントの一覧（報告をまたいだコメントのやり取り） |
| [G19_comment_templates.md](G19_comment_templates.md) | G-19 コメントの定型文（課長が用意する） |
| [G20_search.md](G20_search.md) | G-20 検索（対象月・課・選択式の値・キーワード） |
| [G21_admin_home.md](G21_admin_home.md) | G-21 管理のトップと管理画面の道しるべ |
| [G22_support_requests.md](G22_support_requests.md) | G-22 要望・依頼（課長・確認者 → システム管理者の返信） |
| [G24_update_notices.md](G24_update_notices.md) | G-24 更新のお知らせ（便利機能 A） |
| [G25_faq.md](G25_faq.md) | G-25 よくある質問（便利機能 J） |
| [G23_department_manage.md](G23_department_manage.md) | G-23 課の管理（課長: 締め日・管理の仕方・確認者・チェックする人・項目管理者・班） |

G-16 は、基本設計書の F-02（2 段階認証・端末の管理）を行う画面として追加した。G-15 には、F-21（削除の取り消し）を含めた。

## 画面ごとの文書の構成

| 章 | 内容 |
|---|---|
| 概要 | URL、ファイル、利用者、主な端末、段階、関連する機能 |
| 1. 目的 | この画面で何をするか |
| 2. 表示の条件と権限 | 誰が開けるか、何が見えるか |
| 3. 画面の配置 | スマートフォン幅での配置（PC 向けの画面は PC 幅） |
| 4. 表示項目 | 出す内容と出し方 |
| 5. 入力項目と確認 | 入力欄と、サーバーでの確認 |
| 6. 操作と処理 | ボタンごとの処理の流れ、使うテーブル |
| 7. メッセージ | 画面に出す文言 |
| 8. 記録 | 閲覧・出力・管理操作の記録 |
| 9. 検証の観点 | 自動の検証で確かめること |
