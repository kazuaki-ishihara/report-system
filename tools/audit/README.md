# 監査スクリプト（audit.py）

レポート管理システムを次の3つの観点で監査し、結果を画面と HTML レポートに出力します。

| 監査 | 内容 | 必要なもの |
|---|---|---|
| 1. 静的監査 | リポジトリの PHP ソースから既知の問題パターンを検出（CSRF、リダイレクト、外部キー、項目定義の重複 など） | リポジトリ |
| 2. HTTP監査 | 稼働サイトで、未ログイン時の制御、公開ディレクトリ外ファイルの到達可否、セキュリティヘッダー、ユーザーごとの閲覧・編集権限を確認 | ネットワーク接続、テストユーザーのパスワード |
| 3. DB監査 | SQLite ファイルを読み取り専用で開き、破損・孤立データ・上司の循環・管理者数・パスワード形式などを確認 | `data/reports.sqlite` |

---

## はじめて使うとき（Ubuntu）

### 1. Python 3 があるか確認する

端末（Ctrl + Alt + T）を開いて、次を実行します。

```sh
python3 --version
```

`Python 3.8` 以上が表示されれば準備完了です（Ubuntu 20.04 以降は標準で入っています）。
追加のインストール（`pip install` など）は不要です。

### 2. 監査スクリプトを取得する

**リポジトリをまだ取得していない場合**

```sh
cd ~
git clone https://github.com/kazuaki-ishihara/report-system.git
cd report-system
git checkout dev_check_report
```

**すでに取得済みの場合**

```sh
cd ~/report-system          # 取得した場所に読み替えてください
git fetch
git checkout dev_check_report
git pull
```

### 3. 実行する

```sh
cd tools/audit
./run_audit.sh
```

1. 静的監査 → DB監査 → HTTP監査の順に進みます。
2. HTTP監査の途中で次のように聞かれたら、**テストユーザー共通のパスワード**を入力して Enter を押します（入力中の文字は表示されません）。

   ```
   テストユーザー共通パスワード（空欄でログイン後の監査を省略）:
   ```

   空欄のまま Enter を押すと、ログインが必要な権限チェックだけを省略します。

3. 最後に結果の一覧と、レポートの保存場所が表示されます。

   ```
   ==== 結果 ====
   [高] HTTP H-04   DB ファイルを Web からダウンロードできる: data/reports.sqlite
   [高] 静的 S-01   未ログイン時のリダイレクト先が相対パス
   ...
   高 5 件 / 中 11 件 / 低 7 件 / 確認済み 24 項目
   レポート: /home/…/report-system/tools/audit/reports/audit-report_20261112_110205.html
   ```

所要時間はおよそ 1 分です（サーバーへの負荷を抑えるため、リクエストの間隔を空けています）。

### 4. レポートを見る

表示されたレポートのパスをブラウザで開きます。

```sh
xdg-open reports/audit-report_*.html
```

最初から `./run_audit.sh --open` で実行すると、終了後に自動でブラウザが開きます。

レポートの見方:

- 上部のカードに、重要度ごとの件数が出ます。
- 「1. 静的監査」「2. HTTP監査」「3. DB監査」の各章に、**指摘・該当箇所・対応案**の表があります。
- 各章の「問題がなかった確認項目」は、チェックして問題がなかった項目です。
- HTTP監査の章には、テストユーザーごとの「閲覧できるレポート数・編集できるレポート数」の表があります。上司・部下の設定どおりになっているかの確認に使えます。

### 5. 対策後にもう一度確認する

`.htaccess` の反映などの対策をしたあとは、同じコマンドで再実行して、指摘が消えたことを確認します。
サーバーの確認だけなら次で十分です。

```sh
./run_audit.sh --skip-static --skip-db
```

---

## よくあるトラブル

| 症状 | 対処 |
|---|---|
| `python3: command not found` | `sudo apt install python3` を実行する |
| `./run_audit.sh: Permission denied` | `python3 audit.py` で直接実行する。または `chmod +x run_audit.sh` |
| `HTTP監査でエラー: ... CERTIFICATE_VERIFY_FAILED` | 証明書ストアが古い。`sudo apt install ca-certificates` を実行する |
| `ログイン画面に接続できない` | ネットワークと URL（`audit_config.json` の `base_url`）を確認する |
| `テストユーザーでログインできない: ○○` | パスワードの入力ミス、またはそのユーザーのパスワード変更・削除。他のユーザーの結果は有効 |
| 表示が文字化けする | 端末の文字コードを UTF-8 にする（Ubuntu 標準の端末なら UTF-8） |

---

## 詳しい使い方

### オプション

```sh
./run_audit.sh --skip-http                                    # サーバーにアクセスしない（静的監査 + DB監査）
./run_audit.sh --skip-http --skip-static --db-path ~/work/reports.sqlite   # 別の DB を監査する
./run_audit.sh --help                                         # オプション一覧
```

| オプション | 説明 |
|---|---|
| `--skip-static` / `--skip-http` / `--skip-db` | 指定した監査を省略 |
| `--base-url URL` | 監査する URL（`.../public/`）。設定ファイルより優先 |
| `--db-path FILE` | 監査する DB ファイル（既定: `<リポジトリ>/data/reports.sqlite`） |
| `--password PW` | テストユーザー共通パスワード。省略時は環境変数 `AUDIT_PASSWORD`、それもなければ実行中に入力 |
| `--output-dir DIR` | レポートの出力先（既定: `tools/audit/reports/`） |
| `--open` | 終了後にレポートをブラウザで開く |
| `--config FILE` | 設定ファイル（既定: `tools/audit/audit_config.json`） |

パスワードを `--password` で渡すとシェルの履歴に残ります。実行中の入力か、環境変数を使ってください。

終了コードは、重要度「高」の指摘があれば 1、なければ 0 です（cron や CI での判定に使えます）。

### 設定ファイル（audit_config.json）

| 設定 | 説明 |
|---|---|
| `base_url` | 稼働サイトの `public/` の URL |
| `users` | 権限確認に使うテストユーザー（パスワードは保存しません） |
| `request_delay_ms` | HTTP リクエストの間隔（ミリ秒） |
| `max_probe_per_user` | ユーザーごとに確認する「閲覧範囲外」レポートの最大件数 |

テストユーザーを追加・削除したときは `users` を編集してください。

### 動作環境

- **Python 3.8 以上**（標準ライブラリのみ）。Windows / macOS でも Python があれば `python3 audit.py` で動きます。

---

## サーバーへの影響

- HTTP監査で送信する **POST はログインのみ**です。それ以外はすべて GET です。
- レポート・コメント・ユーザーの登録・更新・削除は一切行いません。
- `public/setup.php`（アクセスするとテーブル作成処理が動く）にはアクセスしません。
- DB ファイルが Web から取得できるかの確認では、先頭 16 バイトだけを読みます（中身はダウンロードしません）。
- 実行時には、テストユーザーごとにログインとログアウトが記録されます。
- レポートに出力するセッション Cookie の値は伏せ字にしています。

## DB監査について

- DB は読み取り専用（`mode=ro`）で開くので、ファイルは変更しません。
- 既定ではリポジトリに同梱された `data/reports.sqlite` を監査します。サーバーの DB とは内容が異なる場合があります。
- サーバーの DB を監査したいときは、FTP 等でコピーを取得して `--db-path` で指定してください。

## 指摘 ID と重要度

| 重要度 | 意味 |
|---|---|
| 高 | 不具合・情報漏えい・権限回避につながる |
| 中 | 整合性・保守性・防御の不足 |
| 低 | 軽微な改善 |
| 情報 | 確認事項 |

ID の先頭は監査の種類を表します（S = 静的、H = HTTP、D = DB）。各指摘の背景は次の文書を参照してください。

- `docs/basic_design.md` の「10. 現状の課題」
- `docs/detailed_design.md` の「8. 課題一覧」
- `docs/htaccess_proposal.md`（H-04 などの対策案）

## 注意

- 静的監査はパターン検出です。「要確認」と書かれた指摘は、該当箇所を目で見て判断してください。
- スクリプトは UTF-8・改行 LF で保存してください（`.gitattributes` で LF に固定しています）。
- `reports/` に出力されたレポートは Git の管理対象外です（`.gitignore`）。
