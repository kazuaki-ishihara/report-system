# .htaccess 対策案（公開範囲の制限）

| 項目 | 内容 |
|---|---|
| 作成日 | 2026-11-12 |
| 対象 | `https://toyama-mai.sakura.ne.jp/report-system/` |
| 根拠 | 監査スクリプト `tools/audit/Invoke-ReportAudit.ps1` の HTTP監査結果（2026-11-12 実施） |

## 1. 背景（監査結果）

| ID | 重要度 | 内容 |
|---|---|---|
| H-04 | 高 | `report-system/data/reports.sqlite` を誰でもダウンロードできる（ユーザー名・パスワードハッシュ・レポート本文を含む） |
| H-04b | 中 | `config/database.php`、`lib/auth.php` が Web から実行できる（`public/` ではなくアプリのルートが公開されている） |
| H-03 | 中 | `http://`（平文）でもアクセスでき、HTTPS へ転送されない |
| H-02a / H-02b | 中 / 低 | X-Frame-Options、X-Content-Type-Options が返らない |

## 2. 追加するファイル

| ファイル | 内容 |
|---|---|
| `data/.htaccess` | ディレクトリへのアクセスをすべて拒否（**H-04 の本命の対策**） |
| `config/.htaccess` | 同上 |
| `lib/.htaccess` | 同上 |
| `.htaccess`（ルート） | HTTPS への転送、内部ディレクトリ・DB・ドキュメント類の拒否、セキュリティヘッダー |

`data/` などのディレクトリ個別の `.htaccess` は mod_rewrite を使わないので、ルートの `.htaccess` がうまく動かない場合でも DB は保護されます。

## 3. 反映手順

1. **先に `data/.htaccess` だけをアップロード**します（最優先）。
2. ブラウザで `https://toyama-mai.sakura.ne.jp/report-system/data/reports.sqlite` を開き、**403** になることを確認します。
3. `config/.htaccess`、`lib/.htaccess` をアップロードします。
4. ルートの `.htaccess` をアップロードし、次を確認します。
   - `https://…/report-system/public/login.php` が開き、ログインできる
   - `http://…/report-system/public/login.php` が `https://` に転送される（**リダイレクトがループしない**こと）
5. 監査スクリプトを再実行し、H-04 / H-04b / H-03 / H-02a / H-02b が解消したことを確認します。

```powershell
.\tools\audit\Invoke-ReportAudit.ps1 -SkipStatic -SkipDb
```

万一サイトが表示されなくなった場合は、ルートの `.htaccess` を削除すれば元に戻ります。ディレクトリ個別の `.htaccess` はアプリの動作に影響しません。

## 4. 注意点

- **HTTPS 転送の判定**: さくらのレンタルサーバは SSL をプロキシで終端するため、`%{HTTPS}` ではなく `X-Sakura-Forwarded-For` ヘッダーの有無で判定しています。ほかのサーバーに移す場合は条件の変更が必要です。手順 4 で、ループしないことを必ず確認してください。
- **HSTS** はコメントアウトしています。一度配信するとブラウザ側で取り消せないため、HTTPS 転送の動作確認が済んでから有効にしてください。
- **パスワードの変更**: DB はすでに誰でも取得できる状態だったため、塞いだあとでテストユーザーと管理者のパスワードを変更することを推奨します。
- **アップロード対象**: `docs/`、`tools/`、`*.md` はサーバーに置く必要はありません（置いた場合でもルートの `.htaccess` で拒否します）。
- **抜本的な対策**: ドキュメントルートを `public/` に設定できれば、`public/` 以外は Web から一切見えなくなります。さくらのコントロールパネルで公開フォルダを変更できるか確認してください。

## 5. この案で対応しないもの（PHP の修正が必要）

| ID | 内容 | 対応箇所 |
|---|---|---|
| H-02e | セッション Cookie に HttpOnly / Secure / SameSite がない | `lib/auth.php`（`session_set_cookie_params()`） |
| H-06b / S-01 | 未ログイン時に存在しない URL へ転送される | `lib/auth.php` の `requireLogin()` |
| S-02 | 外部キー制約が無効 | `config/database.php` |
| S-03 | CSRF 対策がない | レポート・コメントの各処理 |
