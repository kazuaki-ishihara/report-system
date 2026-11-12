# 作業再開ポイント（dev_check_report）

| 項目 | 内容 |
|---|---|
| 最終更新 | 2026-11-12 |
| 作業ブランチ | `dev_check_report`（`main` の `9ffe3e5` から派生） |
| 依頼元 | 石原課長 |
| 依頼内容 | 「項目を管理する機能」を追加したい。main から作業ブランチを作って試してほしい |

> 別の端末（自宅など）で作業を再開するときは、まずこのファイルを読む。
> Claude Code で再開する場合は「docs/work_resume.md を読んで作業を再開して」と伝える。

---

## 1. 守るルール

- **`main` には触らない**（チェックアウト後の編集・コミット・マージ・push をしない）。作業は `dev_check_report` だけで行う。
- **パスワード・秘密情報をリポジトリに入れない**。テストユーザーのパスワードは石原課長の依頼メモ（リポジトリ外）にある。
- **石原課長の端末は Ubuntu**。課長に渡すツールは `python3` の標準ライブラリだけで作り、**Python 3.8 互換**にする（pip 依存、`sqlite3` コマンド、`curl` を前提にしない）。
- 課長は **GitHub Copilot** で改修している。仕様・規約を変えたら `.github/copilot-instructions.md` も更新する。
- 参考資料用の別フォルダ（作業端末の `C:\dev2`）は**読むだけ**。実行・編集はしない。このフォルダはリポジトリに含まれない。

---

## 2. 環境の準備

### リポジトリの取得

```sh
git clone https://github.com/kazuaki-ishihara/report-system.git
cd report-system
git checkout dev_check_report
```

すでに取得済みなら `git fetch && git checkout dev_check_report && git pull`。

- push には `kazuaki-ishihara/report-system` のコラボレーター権限が必要（招待を承認済みの GitHub アカウントでログインする）。
- `git checkout -b dev_check_report origin/main` のように **main を追跡する設定にしない**。誤って main へ push しないため、追跡先は `origin/dev_check_report` にする。

### 作業端末のフォルダ構成（2026-11-12 から）

```text
C:\ReportSystem\
├── dev\     リポジトリ（dev_check_report）。作業・コミットはここ
├── main\    origin/main の参照用（git worktree、detached。編集・コミットしない）
├── check\   ローカル検証環境（tools/verify/setup_devenv.bat が作成。DB はコピー）
└── 石原さん依頼.txt / 依頼書.txt   Git 管理外
```

- `main\` の更新: `git -C C:\ReportSystem\dev fetch` のあと `git -C C:\ReportSystem\main checkout --detach origin/main`
- 検証サーバー: `C:\ReportSystem\check\start_server.bat` → `http://localhost:8000/public/login.php`

### 必要なもの

| 用途 | 必要なもの |
|---|---|
| 設計書・コードを読む | なし |
| 監査ツール | Python 3.8 以上（標準ライブラリのみ） |
| PHP の動作確認 | PHP 8.3（`tools/verify/setup_devenv.bat` で導入。作業端末は導入済み） |

### リポジトリ外にあるファイル（必要なら手で持ってくる）

| ファイル | 内容 | 備考 |
|---|---|---|
| `石原さん依頼.txt` | 課長からの依頼メモ（サーバーURL、テストユーザー一覧、**共通パスワード**、リポジトリURL） | **パスワードを含むため、コミットしない** |
| `依頼書.txt` | 課長への送り状（調査報告・至急対応のお願い・確認事項） | 社内連絡文のため、コミットしない |

どちらも `.git/info/exclude` で Git の管理対象から外していた。新しい端末で同じ場所に置く場合は、`.git/info/exclude` に次を追記する。

```text
石原さん依頼.txt
依頼書.txt
.claude/
```

---

## 3. ここまでにやったこと

| コミット | 内容 |
|---|---|
| `84827f7` | 監査プログラム（初版は PowerShell）と `.htaccess` 対策案を追加 |
| `f3279f6` | 基本設計書・詳細設計書を追加（ソースコードから現状を分析） |
| `34e62cc` | 監査プログラムを Python 3 標準ライブラリ版に置き換え（Ubuntu 対応）、PowerShell 版を削除 |
| `8d039d7` | 監査ツールの README に Ubuntu での手順を追記 |
| `429f6bc` | GitHub Copilot 向けの指示書を追加 |

### 成果物

| ファイル | 内容 |
|---|---|
| `docs/basic_design.md` | 基本設計書（概要、機能・画面一覧、画面遷移、ER 図、権限マトリクス、課題） |
| `docs/detailed_design.md` | 詳細設計書（テーブル定義、画面・処理ごとの検証と SQL、メッセージ、課題一覧 15 件） |
| `docs/htaccess_proposal.md` | DB 公開などへの `.htaccess` 対策案と反映手順 |
| `.htaccess`、`data/.htaccess`、`config/.htaccess`、`lib/.htaccess` | 上記の案の本体（**未反映・Apache 上では未検証**） |
| `tools/audit/` | 監査ツール（`audit.py`、`run_audit.sh`、`audit_config.json`、`README.md`） |
| `.github/copilot-instructions.md` | Copilot 向けの指示書 |

### 監査結果（2026-11-12 時点）

高 5 / 中 11 / 低 7（静的監査と HTTP監査で同じ問題を重複して数えているものを含む）。

- **【高・至急】** `https://toyama-mai.sakura.ne.jp/report-system/data/reports.sqlite` がログインなしで取得できる（ドキュメントルートが `public/` ではない）
- 【高】未ログイン時、`reports/` 等のサブディレクトリから存在しない `login.php` へ転送されて 404（`lib/auth.php` の `requireLogin()`）
- 【高】`PRAGMA foreign_keys` 未設定で、レポート削除時にコメントが残る
- 【高】レポート・コメントの POST に CSRF 対策がない
- 【中】HTTP でアクセスでき、HTTPS に転送されない／Cookie 属性・セキュリティヘッダーがない
- 権限（閲覧範囲・編集権限・管理画面）は、テストユーザー 7 名で確認して**正しく効いていた**

---

## 4. 未完了・確認待ち

| 状態 | 内容 |
|---|---|
| 課長の対応待ち | `data/.htaccess` のサーバー反映（DB 公開の対策）と、反映後のパスワード変更 |
| 課長の回答待ち | 「項目」は、レポートの入力項目を画面から管理する（追加・名称変更・並び順・必須など）という理解で良いか |
| 課長の回答待ち | リモートの `feature/dynamic-report-fields` ブランチ（`de41a98`）は本件と関係があるか、参考にしてよいか（**まだ中身は見ていない**） |
| 送付状況未確認 | `依頼書.txt`（送り状）を課長に送ったかどうか |
| 未検証 | 監査ツールを Ubuntu 実機で動かしていない（Windows の Python 3.14 で動作確認、3.8 の文法チェックは済み） |
| 未検証 | `.htaccess` を Apache 上で動かしていない。特に HTTPS 転送の条件（さくらの `X-Sakura-Forwarded-For`）は要確認 |

---

## 5. 次にやること

1. 課長の回答を確認する（上の「確認待ち」）。
2. `.htaccess` が反映されたら、監査ツールで解消を確認する。

   ```sh
   python3 tools/audit/audit.py --skip-static --skip-db
   ```

3. 了承が取れたら `feature/dynamic-report-fields` の差分を確認する。

   ```sh
   git fetch
   git log --oneline origin/main..origin/feature/dynamic-report-fields
   git diff origin/main...origin/feature/dynamic-report-fields --stat
   ```

4. 「項目を管理する機能」の設計に入る。前提となる現状は次のとおり。
   - 項目はいま `reports` テーブルの固定カラム（report_date, name, subject, content, issues, remarks）。
   - 項目の一覧が 11 ファイルに直書きされている（一覧は `.github/copilot-instructions.md` の「レポートの『項目』について」）。
   - まず項目定義を1か所（項目マスタテーブルなど）に集約する必要がある。既存データと `comments.field_name` の移行方法も設計に含める。
5. 画面・処理を変えたら、`docs/` の設計書、`.github/copilot-instructions.md`、監査ツールの許可リスト（`audit.py` の `ALLOWED_FIELDS`）も更新する。

---

## 6. メモ

- サーバー: `https://toyama-mai.sakura.ne.jp/report-system/public/login.php`（**本番環境になる予定**。いまは検証にも使っているが、改修の動作確認はローカルの検証環境で行い、本番になってからは HTTP監査も実施のタイミングを課長と相談する）
- 本番化の前に、テストユーザー（共通パスワード）の削除またはパスワード変更、`data/` 等の `.htaccess` 反映、HTTPS 転送が必要
- テストユーザー: 7 名（ユーザー名は `tools/audit/audit_config.json` を参照。パスワードは依頼メモを参照）
- サーバー上のレポートは 8 件、管理者は 1 名（2026-11-12 時点）
- リポジトリ同梱の `data/reports.sqlite` は、サーバーの DB とは内容が異なる（同梱はレポート 9 件）
