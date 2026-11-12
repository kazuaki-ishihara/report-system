# 作業再開ポイント（dev_ono_check）

| 項目 | 内容 |
|---|---|
| 最終更新 | 2026-11-12 |
| ブランチ | `dev_ono_check`（`main` の `9ffe3e5` から派生） |
| 内容 | 月次報告システムの見直し版（設計書・実装・検証） |

> Claude Code で再開する場合は「dev_ono_check の docs/work_resume.md を読んで作業を再開して」と伝える。

---

## 1. 守るルール

- **`main` は参照のみ。絶対に変更しない**（編集・コミット・マージ・push・main 向けのプルリクエストをしない）。
- 作業・コミットは `dev_ono_check` だけ。**push は指示があったときだけ**行う。
- コミットメッセージと文書に、会話の内容や経緯（「○日決定」など）を書かない。細かいコミットは push の前にまとめる。
- パスワード・秘密情報・実データの DB・暗号化の鍵をリポジトリに入れない（`.gitignore` で除外済み）。
- ソフトウェアのインストールは上長の了解を得てから。
- 依頼元の課長の開発ブランチ（`feature/dynamic-report-fields`）は参照のみ。統合は課長が行う。

## 2. 作業端末のフォルダ構成

```text
C:\ReportSystem\
├── dev\     リポジトリ本体（dev_ono_check）。作業はここ
├── main\    origin/main の参照用（git worktree、読み取り専用）
├── check\   旧版のローカル検証環境（作り直し版では使わない）
└── 依頼メモ・送り状（Git 管理外）
```

## 3. 文書

| 文書 | 内容 |
|---|---|
| `docs/redesign_plan.md` | 見直し計画（背景・原則・検討事項・未決事項） |
| `docs/basic_design.md` | 基本設計書 |
| `docs/system_architecture.md` | システム構成図 |
| `docs/detailed_design/` | 詳細設計書（共通編・DB 編・画面 G-01〜G-16） |

## 4. 実装の状況

詳細設計書の画面 G-01〜G-16 と、既存データの移行・保管期限による削除をすべて実装済み。

| 場所 | 内容 |
|---|---|
| `app/lib/` | 共通の処理（DB、セッション、CSRF、送信の番号、暗号化、権限、様式の仕組み、臨時の調査、出力、保管期限） |
| `app/pages/`・`app/views/` | 画面ごとの処理とテンプレート |
| `public/` | 公開側の入り口（本番では公開ディレクトリ。`app/` は公開ディレクトリの外に置く） |
| `app/bin/` | `migrate.php`（テーブルと鍵の作成）、`seed_template.php`（初期の様式）、`create_user.php`（利用者の作成）、`migrate_legacy.php`（既存データの移行） |
| `tools/test/` | 検証の道具（Python 3 標準ライブラリ） |

### 詳細設計書と違うところ（設計書は未更新）

- 出力は `export/index.php` 1 つ（GET で条件の画面、POST で印刷用画面・xlsx・CSV）。
- 移行前の 6 項目は、すべて無効の共通項目として登録（移行データの表示だけに使う）。
- 列・表の追加: `adhoc_requests.target_type`、`users.totp_last_step`、`login_throttle`（存在しない ID のログイン失敗を IP アドレスごとに数える）、`app_state`。
- 項目マスタの並べ替えは ↑↓ のボタン。表の列と表示の条件は文字で入力する。

## 5. ローカルで動かす

PHP 8.3 は作業端末に導入済み（`%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.3_...\php.exe`）。`app/config/config.local.php`（リポジトリ外）にローカル用の設定がある。

```powershell
cd C:\ReportSystem\dev
$php = (Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.3_*\php.exe").FullName
& $php tools\test\dev_seed.php --yes        # テストデータを作り直す
& $php -S 127.0.0.1:8000 -t public          # サーバー（止めるときは Ctrl + C）
python tools\test\dev_code.py               # テスト用の利用者と 2 段階認証のコード
```

ブラウザーで `http://127.0.0.1:8000/` を開く。

`config.local.php` がない端末では、次の内容で作る（リポジトリに入れない）。

```php
<?php
return ['base_path' => '', 'https' => false, 'allow_dev_seed' => true, 'db_path' => dirname(__DIR__) . '/data/dev.sqlite'];
```

## 6. 検証

```powershell
python tools\test\verify_all.py          # 自動検証（構文・PHP 7.4 の書き方・秘密の確認・全シナリオ・保管期限）
python tools\test\verify_all.py --ui     # 画面の自動操作検証も行う（Edge / Chrome をプログラムから操作）
```

- 結果は `tools/test/output/検証記録/<日時>/結果.md`、一覧は `tools/test/output/検証記録/一覧.csv`（リポジトリ外）。
- 作業端末の Claude Code の実行環境では、Python から起動したブラウザーが応答しない。その場合は Edge を先に起動し、`--browser-port` でつなぐ。

  ```powershell
  Start-Process "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe" -ArgumentList "--headless=new","--remote-debugging-port=29777","--user-data-dir=$env:TEMP\mr_ui_edge","--no-first-run","about:blank"
  python tools\test\verify_all.py --ui --browser-port 29777
  ```

- 直近の結果: すべて成功（項目マスタの検証を含む）。

## 7. 未完了・確認待ち

| 状態 | 内容 |
|---|---|
| 未検証 | PHP 7.4 での実際の動作（書き方の確認のみ）。本番の PHP のバージョンは未確認 |
| 未検証 | さくらのレンタルサーバーへの配置（`app/` を公開ディレクトリの外に置けるか、`.htaccess`、zip 機能の有無） |
| 未検証 | 通常の端末で `--ui` が自分でブラウザーを起動できるか |
| 未対応 | 詳細設計書を実装に合わせて更新する（§4 の違い） |
| 未決 | 会社として判断が必要な事項（記録してよい範囲、閲覧の範囲、保管期限）、締め日、課と所属、リーダーの閲覧範囲、お知らせの方法、案件コードの付け方（`docs/redesign_plan.md` の未決事項） |
| push | `dev_ono_check` の手元のコミットは GitHub に未反映（GitHub 上の版と分かれているため、push するときは強制 push） |
| 移行 | 本番では移行後に `legacy_reports`・`legacy_comments`（平文）を確認して削除する |
