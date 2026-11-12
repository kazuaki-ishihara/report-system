#Requires -Version 5.1
<#
.SYNOPSIS
    レポート管理システム 監査スクリプト

.DESCRIPTION
    次の3種類の監査を行い、結果を画面とHTMLレポートに出力する。
      1. 静的監査   : リポジトリ内の PHP ソースを読み、既知の問題パターンを検出する
      2. HTTP監査   : 稼働サイトへアクセスし、認証・権限・公開範囲・セキュリティヘッダーを確認する
      3. DB監査     : SQLite ファイルを読み取り専用で解析し、データ整合性を確認する

    追加ソフト（PHP / SQLite / Python / 外部モジュール）は不要。
    Windows PowerShell 5.1 の標準機能だけで動作する。

    HTTP監査でサーバーへ送る POST はログインのみ。その他はすべて GET で、
    データを登録・更新・削除する処理は呼び出さない。

.PARAMETER RepoRoot
    リポジトリのルート。省略時はこのスクリプトの2階層上。

.PARAMETER ConfigPath
    設定ファイル (psd1)。省略時は同じフォルダの audit-config.psd1。

.PARAMETER BaseUrl
    稼働サイトの public/ の URL。設定ファイルより優先。

.PARAMETER DbPath
    監査する SQLite ファイル。省略時は <RepoRoot>\data\reports.sqlite。

.PARAMETER Password
    テストユーザー共通パスワード。省略時は実行時に入力を求める（空欄ならログイン後の監査を省略）。

.PARAMETER OutputDir
    HTMLレポートの出力先。省略時は同じフォルダの reports\。

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File .\Invoke-ReportAudit.ps1

.EXAMPLE
    .\Invoke-ReportAudit.ps1 -SkipHttp
#>
[CmdletBinding()]
param(
    [string]$RepoRoot,
    [string]$ConfigPath,
    [string]$BaseUrl,
    [string]$DbPath,
    [string]$Password,
    [string]$OutputDir,
    [switch]$SkipStatic,
    [switch]$SkipHttp,
    [switch]$SkipDb,
    [switch]$NoOpen
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Off

# ============================================================
# 設定
# ============================================================

if (-not $RepoRoot) {
    $RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
}
$RepoRoot = $RepoRoot.TrimEnd('\')

if (-not $ConfigPath) {
    $ConfigPath = Join-Path $PSScriptRoot 'audit-config.psd1'
}

$config = @{}
if (Test-Path -LiteralPath $ConfigPath) {
    $config = Import-PowerShellDataFile -LiteralPath $ConfigPath
}

if (-not $BaseUrl) { $BaseUrl = [string]$config.BaseUrl }
if ($BaseUrl -and -not $BaseUrl.EndsWith('/')) { $BaseUrl += '/' }

if (-not $DbPath) { $DbPath = Join-Path $RepoRoot 'data\reports.sqlite' }
if (-not $OutputDir) { $OutputDir = Join-Path $PSScriptRoot 'reports' }

$script:DelayMs = 300
if ($config.RequestDelayMs) { $script:DelayMs = [int]$config.RequestDelayMs }

$script:MaxProbePerUser = 5
if ($config.MaxProbePerUser) { $script:MaxProbePerUser = [int]$config.MaxProbePerUser }

$auditUsers = @()
if ($config.Users) { $auditUsers = @($config.Users) }

# レポートの項目（コメント対象 field_name の許可値）。詳細設計書 4.1 と同じ。
$script:AllowedFields = @('report_date', 'name', 'subject', 'content', 'issues', 'remarks')
# 役職。DB の CHECK 制約と同じ。
$script:AllowedRoles = @('一般', '主任', '課長代理', '課長', '部長')

# ============================================================
# 結果の収集
# ============================================================

$script:Findings = New-Object 'System.Collections.Generic.List[object]'
$script:Passes = New-Object 'System.Collections.Generic.List[object]'
$script:Notes = New-Object 'System.Collections.Generic.List[object]'   # 実行状況（スキップ等）
$script:HttpMatrix = $null
$script:DbInfo = $null
$script:DbExpectedVisible = $null

$SeverityOrder = @{ '高' = 0; '中' = 1; '低' = 2; '情報' = 3 }

function Add-Finding {
    param(
        [string]$Category,
        [string]$Id,
        [ValidateSet('高', '中', '低', '情報')][string]$Severity,
        [string]$Title,
        [string[]]$Location,
        [string]$Detail,
        [string]$Recommendation
    )
    $script:Findings.Add([pscustomobject]@{
            Category       = $Category
            Id             = $Id
            Severity       = $Severity
            Title          = $Title
            Location       = @($Location | Where-Object { $_ })
            Detail         = $Detail
            Recommendation = $Recommendation
        })
}

function Add-Pass {
    param([string]$Category, [string]$Id, [string]$Title, [string]$Detail)
    $script:Passes.Add([pscustomobject]@{
            Category = $Category; Id = $Id; Title = $Title; Detail = $Detail
        })
}

function Add-Note {
    param([string]$Category, [string]$Text)
    $script:Notes.Add([pscustomobject]@{ Category = $Category; Text = $Text })
}

function Write-Step {
    param([string]$Text)
    Write-Host ('  - ' + $Text)
}

# ============================================================
# 1. 静的監査
# ============================================================

function Find-Lines {
    param([string[]]$Lines, [string]$Pattern)
    $hits = @()
    for ($i = 0; $i -lt $Lines.Count; $i++) {
        if ($Lines[$i] -match $Pattern) {
            $hits += [pscustomobject]@{ Line = $i + 1; Text = $Lines[$i].Trim() }
        }
    }
    return $hits
}

function Invoke-StaticAudit {
    $cat = '静的'
    Write-Host '[1/3] 静的監査（ソースコード）' -ForegroundColor Cyan

    $files = Get-ChildItem -LiteralPath $RepoRoot -Recurse -File -Filter '*.php' |
        Where-Object { $_.FullName -notmatch '\\(\.git|tools|vendor|node_modules)\\' }

    if (-not $files) {
        Add-Note $cat "PHP ファイルが見つかりません（RepoRoot: $RepoRoot）。静的監査を省略しました。"
        return
    }

    $src = @{}    # 相対パス -> 行配列
    $text = @{}   # 相対パス -> 全文
    foreach ($f in $files) {
        $rel = $f.FullName.Substring($RepoRoot.Length).TrimStart('\') -replace '\\', '/'
        $lines = [IO.File]::ReadAllLines($f.FullName, [Text.Encoding]::UTF8)
        $src[$rel] = $lines
        $text[$rel] = $lines -join "`n"
    }
    $relPaths = @($src.Keys | Sort-Object)
    Write-Step ("対象 PHP ファイル: {0} 件" -f $relPaths.Count)

    # ---- S-01 requireLogin() の相対パスリダイレクト ----
    if ($src.ContainsKey('lib/auth.php')) {
        $hits = Find-Lines $src['lib/auth.php'] "Location:\s*login\.php"
        $callers = @($relPaths | Where-Object {
                $_ -match '^public/[^/]+/' -and $text[$_] -match 'require(Login|Admin)\s*\('
            })
        if ($hits -and $callers) {
            Add-Finding $cat 'S-01' '高' '未ログイン時のリダイレクト先が相対パス' `
                -Location (@("lib/auth.php:$($hits[0].Line)") + $callers) `
                -Detail ("requireLogin() が 'Location: login.php' を返す。サブディレクトリ配下の {0} ファイル（reports/・comments/・users/）から呼ばれると、ブラウザは存在しない URL（例: reports/login.php）へ遷移する。" -f $callers.Count) `
                -Recommendation 'ログイン画面の URL をアプリのルート基準で組み立てる（例: 呼び出し元の階層に依存しない絶対パス）。'
        }
        else {
            Add-Pass $cat 'S-01' 'requireLogin() のリダイレクト先' '相対パスの問題は検出されませんでした。'
        }
    }

    # ---- S-02 外部キー制約が無効 ----
    $allText = ($text.Values -join "`n")
    if ($allText -notmatch 'foreign_keys') {
        $loc = @()
        if ($src.ContainsKey('config/database.php')) { $loc += 'config/database.php' }
        Add-Finding $cat 'S-02' '高' 'SQLite の外部キー制約が有効化されていない' `
            -Location $loc `
            -Detail 'PRAGMA foreign_keys = ON を実行している箇所がない。SQLite は既定で外部キーを無視するため、comments の ON DELETE CASCADE 等が動作せず、レポート削除時にコメントが孤立して残る。' `
            -Recommendation 'PDO 接続直後に $pdo->exec(''PRAGMA foreign_keys = ON'') を実行する。あわせて既存の孤立データを確認する（DB監査 D-02）。'
    }
    else {
        Add-Pass $cat 'S-02' '外部キー制約の有効化' 'foreign_keys の設定が見つかりました。'
    }

    # ---- S-03 CSRF 対策のない POST 処理 ----
    $postPattern = "REQUEST_METHOD'\]\s*[!=]==\s*'POST'|INPUT_POST|\`$_POST\["
    $noCsrfHigh = @()
    $noCsrfLow = @()
    $withCsrf = @()
    foreach ($p in $relPaths) {
        if ($text[$p] -notmatch $postPattern) { continue }
        if ($text[$p] -match 'hash_equals' -and $text[$p] -match 'csrf') {
            $withCsrf += $p
            continue
        }
        if ($p -match '(^|/)(login|setup_admin)\.php$') { $noCsrfLow += $p } else { $noCsrfHigh += $p }
    }
    if ($noCsrfHigh) {
        Add-Finding $cat 'S-03' '高' 'CSRF 対策のない更新処理' -Location $noCsrfHigh `
            -Detail ("POST を受け付けるがトークン検証（hash_equals + csrf_token）がない処理が {0} 件ある。ログイン中の利用者が罠ページを開くと、本人の意図しない登録・更新・削除が実行される可能性がある。" -f $noCsrfHigh.Count) `
            -Recommendation 'ユーザー管理（users/）と同じトークン方式を共通関数化し、すべての POST 処理と送信元フォームに適用する。'
    }
    if ($noCsrfLow) {
        Add-Finding $cat 'S-03b' '低' 'ログイン・初期設定フォームに CSRF 対策なし' -Location $noCsrfLow `
            -Detail 'ログイン CSRF（攻撃者のアカウントでログインさせられる）等のリスクがある。影響は限定的。' `
            -Recommendation '必要に応じてログインフォームにもトークンを付与する。'
    }
    if ($withCsrf) {
        Add-Pass $cat 'S-03' 'CSRF トークン検証あり' ($withCsrf -join ', ')
    }

    # ---- S-04 GET でのログアウト ----
    if ($src.ContainsKey('public/logout.php') -and $text['public/logout.php'] -notmatch 'REQUEST_METHOD') {
        Add-Finding $cat 'S-04' '低' 'GET リクエストでログアウトできる' -Location @('public/logout.php') `
            -Detail '画像タグ等の埋め込みで利用者を強制的にログアウトさせられる。' `
            -Recommendation 'POST + CSRF トークンでのログアウトに変更する。'
    }

    # ---- S-05 項目定義の重複 ----
    $fieldFiles = @($relPaths | Where-Object {
            $text[$_] -match "'report_date'" -and $text[$_] -match "'remarks'"
        })
    if ($fieldFiles.Count -gt 1) {
        Add-Finding $cat 'S-05' '中' 'レポート項目の定義が複数ファイルに重複' -Location $fieldFiles `
            -Detail ("項目（report_date〜remarks）の一覧・許可リストが {0} ファイルに個別に記述されている。項目の追加・変更時に修正漏れが起きやすい。" -f $fieldFiles.Count) `
            -Recommendation '項目定義を1か所（共通定義ファイルまたは項目マスタテーブル）に集約し、各画面・処理から参照する。'
    }

    # ---- S-06 閲覧範囲 SQL の重複・循環リスク ----
    $cteFiles = @($relPaths | Where-Object { $text[$_] -match 'WITH\s+RECURSIVE' })
    if ($cteFiles.Count -gt 1) {
        Add-Finding $cat 'S-06' '中' '再帰 SQL（閲覧範囲・配下判定）が重複' -Location $cteFiles `
            -Detail ("WITH RECURSIVE による配下ユーザー取得が {0} ファイルに個別実装されている。権限ルール変更時に一部だけ修正される恐れがある。" -f $cteFiles.Count) `
            -Recommendation '閲覧可能ユーザー ID の取得を共通関数にまとめる。'
    }
    $unionAll = @($cteFiles | Where-Object { $text[$_] -match 'UNION\s+ALL' })
    if ($unionAll) {
        Add-Finding $cat 'S-06b' '中' '再帰 SQL で UNION ALL を使用（循環データで無限再帰）' -Location $unionAll `
            -Detail '上司設定に循環がある場合、UNION ALL の再帰 CTE は終了しない。他のファイルは UNION（重複除去）を使っており、実装が統一されていない。' `
            -Recommendation 'UNION に統一する。'
    }

    # ---- S-07 エスケープされていない出力（要確認） ----
    $rawOut = @()
    foreach ($p in $relPaths) {
        foreach ($h in (Find-Lines $src[$p] '<\?=\s*(?!h\(|\(int\)|count\(|date\(|htmlspecialchars\()')) {
            $rawOut += ("{0}:{1}  {2}" -f $p, $h.Line, $h.Text)
        }
    }
    if ($rawOut) {
        Add-Finding $cat 'S-07' '情報' 'h() を通さない出力（要目視確認）' -Location ($rawOut | Select-Object -First 30) `
            -Detail ("<?= ?> で h() / (int) を通さずに出力している箇所が {0} 件ある。数値など安全な値の可能性が高いが、利用者の入力値でないことを確認する。" -f $rawOut.Count) `
            -Recommendation '利用者入力やDB値を出力する場合は必ず h() を使う。'
    }
    else {
        Add-Pass $cat 'S-07' 'HTML 出力のエスケープ' 'h() を通さない <?= 出力は検出されませんでした。'
    }

    # ---- S-08 例外メッセージの画面出力 ----
    $leak = @()
    foreach ($p in $relPaths) {
        foreach ($h in (Find-Lines $src[$p] 'getMessage\(\)')) {
            if ($h.Text -notmatch 'error_log') { $leak += ("{0}:{1}" -f $p, $h.Line) }
        }
    }
    if ($leak) {
        Add-Finding $cat 'S-08' '中' '例外メッセージを画面に出力' -Location $leak `
            -Detail '例外の詳細（ファイルパス等）が利用者に表示される。' `
            -Recommendation '画面には汎用メッセージのみを出し、詳細は error_log に記録する。'
    }

    # ---- S-09 公開ディレクトリのセットアップスクリプト ----
    if ($src.ContainsKey('public/setup.php')) {
        $t = $text['public/setup.php']
        if ($t -notmatch 'require(Login|Admin)\s*\(') {
            $detail = '認証なしで実行できるセットアップ処理が公開ディレクトリにある。'
            if ($t -match 'CREATE\s+TABLE' -and $t -notmatch 'user_id') {
                $detail += ' 中の CREATE TABLE は user_id 列を持たない旧スキーマで、現行 DB と一致しない。'
            }
            Add-Finding $cat 'S-09' '中' '認証なしのセットアップスクリプトが公開されている' -Location @('public/setup.php') `
                -Detail $detail -Recommendation '削除する。必要ならスキーマ定義は公開ディレクトリ外の SQL ファイルで管理する。'
        }
    }
    if (Test-Path -LiteralPath (Join-Path $RepoRoot 'setup_admin.php')) {
        Add-Finding $cat 'S-10' '低' '初期管理者登録スクリプトが残っている' -Location @('setup_admin.php') `
            -Detail 'ファイル内で「直ちに削除してください」と案内しているが残存している。ユーザーが存在する間は実行不可だが、不要なファイルは攻撃面になる。HTTP監査 H-05 で Web から到達できるかを確認する。' `
            -Recommendation '本番環境から削除する（リポジトリには残してもよいが、デプロイ対象から外す）。'
    }

    # ---- S-11 DB ファイルのリポジトリ同梱 ----
    $dbFiles = @(Get-ChildItem -LiteralPath $RepoRoot -Recurse -File -Include '*.sqlite', '*.sqlite3', '*.db', '*.sqlite.backup' -ErrorAction SilentlyContinue |
            Where-Object { $_.FullName -notmatch '\\(\.git|tools)\\' })
    if ($dbFiles) {
        $ignored = $false
        $gi = Join-Path $RepoRoot '.gitignore'
        if (Test-Path -LiteralPath $gi) {
            $ignored = ([IO.File]::ReadAllText($gi) -match 'sqlite')
        }
        if (-not $ignored) {
            $names = $dbFiles | ForEach-Object { $_.FullName.Substring($RepoRoot.Length).TrimStart('\') -replace '\\', '/' }
            Add-Finding $cat 'S-11' '中' 'DB ファイルがリポジトリに含まれている' -Location $names `
                -Detail 'パスワードハッシュを含む DB がバージョン管理されている。リポジトリの閲覧者全員がデータを取得でき、デプロイ時に本番 DB を上書きする事故も起こり得る。' `
                -Recommendation '.gitignore で除外し、スキーマは SQL ファイルとして管理する。'
        }
    }

    # ---- S-12 デバッグ出力の残存 ----
    $debug = @()
    foreach ($p in $relPaths) {
        foreach ($h in (Find-Lines $src[$p] '\b(var_dump|print_r|phpinfo|var_export)\s*\(|display_errors')) {
            $debug += ("{0}:{1}  {2}" -f $p, $h.Line, $h.Text)
        }
    }
    if ($debug) {
        Add-Finding $cat 'S-12' '低' 'デバッグ用の出力が残っている' -Location $debug `
            -Detail '内部情報が表示される恐れがある。' -Recommendation '削除する。'
    }
    else {
        Add-Pass $cat 'S-12' 'デバッグ出力' 'var_dump / print_r / phpinfo は検出されませんでした。'
    }

    # ---- S-13 SQL への変数埋め込み（要確認） ----
    $interp = @()
    foreach ($p in $relPaths) {
        foreach ($h in (Find-Lines $src[$p] '"[^"]*\b(SELECT|INSERT|UPDATE|DELETE|WHERE|FROM)\b[^"]*\$[A-Za-z_]')) {
            $interp += ("{0}:{1}  {2}" -f $p, $h.Line, $h.Text)
        }
    }
    if ($interp) {
        Add-Finding $cat 'S-13' '中' 'SQL 文字列への変数埋め込み（要確認）' -Location $interp `
            -Detail 'ダブルクォート文字列内の SQL に変数を展開している。利用者入力が含まれると SQL インジェクションになる。' `
            -Recommendation 'プレースホルダとバインドを使う。'
    }
    $prepareCount = @($relPaths | Where-Object { $text[$_] -match '->prepare\(' }).Count
    Add-Pass $cat 'S-13' 'プリペアドステートメントの使用' ("{0} ファイルで prepare() を使用。" -f $prepareCount)

    # ---- S-14 登録と更新で入力チェックが不一致 ----
    $store = 'public/reports/store.php'
    $update = 'public/reports/update.php'
    if ($src.ContainsKey($store) -and $src.ContainsKey($update)) {
        if ($text[$update] -match 'strlen\(' -and $text[$store] -notmatch 'strlen\(') {
            Add-Finding $cat 'S-14' '中' 'レポート登録時に長さチェックがない' -Location @($store, $update) `
                -Detail '更新処理（update.php）は氏名 300 バイト・件名 600 バイトの上限を検証するが、登録処理（store.php）には上限チェックがない。HTML の maxlength はブラウザ側の制限で回避できる。' `
                -Recommendation '入力チェックを共通関数にし、登録と更新で同じ検証を行う。'
        }
    }

    # ---- S-15 役職リストの重複 ----
    $roleFiles = @($relPaths | Where-Object { $text[$_] -match "'課長代理'" })
    if ($roleFiles.Count -gt 1) {
        Add-Finding $cat 'S-15' '低' '役職リストが複数ファイルに重複' -Location $roleFiles `
            -Detail ("役職の一覧が {0} ファイルと DB の CHECK 制約に個別に定義されている。" -f $roleFiles.Count) `
            -Recommendation '共通定義に集約する。'
    }

    # ---- S-16 ログイン試行回数の制限 ----
    if ($src.ContainsKey('public/login.php')) {
        if ($text['public/login.php'] -notmatch '(?i)attempt|lock|throttle|sleep\(|usleep\(') {
            Add-Finding $cat 'S-16' '低' 'ログイン試行回数の制限がない' -Location @('public/login.php') `
                -Detail 'パスワードの総当たり攻撃を抑止する仕組みがない。' `
                -Recommendation '失敗回数に応じた待機・一時ロック等を検討する。'
        }
    }

    # ---- S-17 コメント操作ボタンの表示制御 ----
    $detail = 'public/reports/detail.php'
    if ($src.ContainsKey($detail)) {
        if ($text[$detail] -match 'comment-edit-button' -and $text[$detail] -notmatch "comment\['user_id'\]") {
            Add-Finding $cat 'S-17' '低' '権限のないコメントにも編集・削除ボタンが表示される' -Location @($detail) `
                -Detail 'ボタン表示時に投稿者を判定していない（サーバー側では 403 で拒否される）。投稿者名も表示されない。' `
                -Recommendation '投稿者本人または管理者のときだけボタンを表示し、投稿者名を表示する。'
        }
    }

    # ---- 良好な点 ----
    if ($allText -match 'password_hash\(' -and $allText -match 'password_verify\(') {
        Add-Pass $cat 'P-01' 'パスワードのハッシュ化' 'password_hash / password_verify を使用。'
    }
    if ($allText -match 'session_regenerate_id\(\s*true\s*\)') {
        Add-Pass $cat 'P-02' 'セッション固定攻撃対策' 'ログイン時に session_regenerate_id(true) を実行。'
    }
    if ($src.ContainsKey('config/database.php') -and $text['config/database.php'] -match "/\.\./data/") {
        Add-Pass $cat 'P-03' 'DB ファイルの配置' 'DB は public/ の外（data/）に配置。ただし実際に Web から取得できないかは HTTP監査 H-04 で確認。'
    }
}

# ============================================================
# 2. HTTP 監査
# ============================================================

function Invoke-AuditRequest {
    param(
        [string]$Url,
        [string]$Method = 'GET',
        [string]$Body,
        [System.Net.CookieContainer]$Cookies,
        [int]$MaxBytes = 524288
    )
    Start-Sleep -Milliseconds $script:DelayMs

    $req = [System.Net.HttpWebRequest]::Create($Url)
    $req.Method = $Method
    $req.AllowAutoRedirect = $false
    $req.Timeout = 30000
    $req.UserAgent = 'ReportSystemAudit/1.0 (WindowsPowerShell)'
    if ($Cookies) { $req.CookieContainer = $Cookies } else { $req.CookieContainer = New-Object System.Net.CookieContainer }

    if ($Body) {
        $data = [Text.Encoding]::UTF8.GetBytes($Body)
        $req.ContentType = 'application/x-www-form-urlencoded'
        $req.ContentLength = $data.Length
        $rs = $req.GetRequestStream()
        $rs.Write($data, 0, $data.Length)
        $rs.Close()
    }

    $resp = $null
    $err = $null
    try {
        $resp = $req.GetResponse()
    }
    catch [System.Net.WebException] {
        $resp = $_.Exception.Response
        $err = $_.Exception.Message
    }

    if (-not $resp) {
        return [pscustomobject]@{
            Url = $Url; Status = 0; Error = $err; Headers = $null
            Location = $null; LocationAbs = $null; Body = ''; Bytes = (New-Object byte[] 0)
        }
    }

    try {
        $status = [int]$resp.StatusCode
        $stream = $resp.GetResponseStream()
        $ms = New-Object IO.MemoryStream
        $buf = New-Object byte[] 8192
        $total = 0
        while ($total -lt $MaxBytes) {
            $n = $stream.Read($buf, 0, [Math]::Min($buf.Length, $MaxBytes - $total))
            if ($n -le 0) { break }
            $ms.Write($buf, 0, $n)
            $total += $n
        }
        $bytes = $ms.ToArray()
        $loc = $resp.Headers['Location']
        $abs = $null
        if ($loc) { $abs = (New-Object Uri((New-Object Uri $Url), $loc)).AbsoluteUri }

        return [pscustomobject]@{
            Url = $Url; Status = $status; Error = $null; Headers = $resp.Headers
            Location = $loc; LocationAbs = $abs
            Body = [Text.Encoding]::UTF8.GetString($bytes); Bytes = $bytes
        }
    }
    finally {
        $resp.Close()
    }
}

function Test-IsRedirect([int]$Status) {
    return @(301, 302, 303, 307, 308) -contains $Status
}

function Get-IdsFromHtml([string]$Html, [string]$Pattern) {
    $ids = @()
    foreach ($m in [regex]::Matches($Html, $Pattern)) { $ids += [int]$m.Groups[1].Value }
    return @($ids | Sort-Object -Unique)
}

function Invoke-HttpAudit {
    $cat = 'HTTP'
    Write-Host '[2/3] HTTP監査（稼働サイト）' -ForegroundColor Cyan

    if (-not $BaseUrl) {
        Add-Note $cat 'BaseUrl が未設定のため HTTP監査を省略しました（audit-config.psd1 または -BaseUrl で指定）。'
        return
    }
    Write-Step "対象: $BaseUrl"

    # PowerShell 5.1 は既定で TLS1.2 が無効な場合がある
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12

    # ---- H-01 疎通 ----
    $login = Invoke-AuditRequest ($BaseUrl + 'login.php')
    if ($login.Status -ne 200) {
        Add-Finding $cat 'H-01' '高' 'ログイン画面に接続できない' -Location @($BaseUrl + 'login.php') `
            -Detail ("HTTP {0} {1}" -f $login.Status, $login.Error) `
            -Recommendation 'URL・ネットワーク・サーバー状態を確認する。以降の HTTP監査は省略しました。'
        return
    }
    Add-Pass $cat 'H-01' 'ログイン画面への接続' 'HTTP 200'

    # ---- H-02 セキュリティヘッダー / Cookie 属性 ----
    Write-Step 'セキュリティヘッダーと Cookie 属性'
    $h = $login.Headers
    $isHttps = $BaseUrl.StartsWith('https://')
    $xfo = $h['X-Frame-Options']
    $csp = $h['Content-Security-Policy']
    if (-not $xfo -and -not ($csp -and $csp -match 'frame-ancestors')) {
        Add-Finding $cat 'H-02a' '中' 'クリックジャッキング対策ヘッダーがない' -Location @('login.php') `
            -Detail 'X-Frame-Options / CSP frame-ancestors が設定されていない。他サイトの iframe に埋め込まれ、ボタンを誤クリックさせられる恐れがある。' `
            -Recommendation 'X-Frame-Options: DENY（または SAMEORIGIN）を返す。'
    }
    else { Add-Pass $cat 'H-02a' 'クリックジャッキング対策' ("X-Frame-Options: {0} / CSP: {1}" -f $xfo, $csp) }

    if (-not $h['X-Content-Type-Options']) {
        Add-Finding $cat 'H-02b' '低' 'X-Content-Type-Options ヘッダーがない' -Location @('login.php') `
            -Detail 'MIME スニッフィング対策が未設定。' -Recommendation 'X-Content-Type-Options: nosniff を返す。'
    }
    if ($isHttps -and -not $h['Strict-Transport-Security']) {
        Add-Finding $cat 'H-02c' '低' 'HSTS ヘッダーがない' -Location @('login.php') `
            -Detail 'Strict-Transport-Security が未設定。初回アクセス時に HTTP へ誘導される余地がある。' `
            -Recommendation '運用上問題がなければ Strict-Transport-Security を設定する。'
    }
    if ($h['X-Powered-By']) {
        Add-Finding $cat 'H-02d' '低' 'X-Powered-By で PHP バージョンを公開' -Location @('login.php') `
            -Detail ("X-Powered-By: {0}" -f $h['X-Powered-By']) -Recommendation 'expose_php = Off にする。'
    }

    $setCookie = $h['Set-Cookie']
    if ($setCookie) {
        # レポートにセッション ID を残さないよう値を伏せる
        $setCookie = [regex]::Replace($setCookie, '(PHPSESSID=)[^;,\s]+', '$1********')
        $missing = @()
        if ($setCookie -notmatch '(?i)httponly') { $missing += 'HttpOnly' }
        if ($isHttps -and $setCookie -notmatch '(?i);\s*secure') { $missing += 'Secure' }
        if ($setCookie -notmatch '(?i)samesite') { $missing += 'SameSite' }
        if ($missing) {
            $sev = '低'
            if ($missing -contains 'HttpOnly' -or $missing -contains 'Secure') { $sev = '中' }
            Add-Finding $cat 'H-02e' $sev ('セッション Cookie に属性がない: ' + ($missing -join ', ')) -Location @('login.php') `
                -Detail ("Set-Cookie: {0}" -f $setCookie) `
                -Recommendation 'session_set_cookie_params() または php.ini で httponly / secure / samesite=Lax を設定する。'
        }
        else { Add-Pass $cat 'H-02e' 'セッション Cookie 属性' $setCookie }
    }
    else {
        Add-Note $cat 'ログイン画面で Set-Cookie が返らなかったため Cookie 属性は確認できませんでした。'
    }

    # ---- H-03 HTTP から HTTPS への誘導 ----
    if ($isHttps) {
        $plain = 'http://' + $BaseUrl.Substring(8) + 'login.php'
        $r = Invoke-AuditRequest $plain
        if ($r.Status -eq 200) {
            Add-Finding $cat 'H-03' '中' 'HTTP（平文）でもアクセスできる' -Location @($plain) `
                -Detail 'HTTPS へリダイレクトされず、パスワードが平文で送信され得る。' `
                -Recommendation '.htaccess 等で HTTPS へ 301 リダイレクトする。'
        }
        elseif ((Test-IsRedirect $r.Status) -and $r.LocationAbs -and $r.LocationAbs.StartsWith('https://')) {
            Add-Pass $cat 'H-03' 'HTTPS への誘導' ("HTTP {0} → {1}" -f $r.Status, $r.LocationAbs)
        }
        else {
            Add-Pass $cat 'H-03' 'HTTP アクセス' ("HTTP {0} {1}" -f $r.Status, $r.Error)
        }
    }

    # ---- H-04 / H-05 公開ディレクトリ外のファイル ----
    Write-Step '公開ディレクトリ外のファイルへの到達可否'
    if ($BaseUrl -match '^(.*/)public/$') {
        $appRoot = $Matches[1]
        $probes = @(
            @{ Path = 'data/reports.sqlite'; Kind = 'sqlite' },
            @{ Path = 'data/reports.sqlite.backup'; Kind = 'sqlite' },
            @{ Path = '.git/HEAD'; Kind = 'git' },
            @{ Path = 'setup_admin.php'; Kind = 'setup' },
            @{ Path = 'config/database.php'; Kind = 'php' },
            @{ Path = 'lib/auth.php'; Kind = 'php' }
        )
        $phpReachable = @()
        foreach ($p in $probes) {
            $url = $appRoot + $p.Path
            # DB は先頭 16 バイトだけ読む（中身はダウンロードしない）
            $max = 4096
            if ($p.Kind -eq 'sqlite') { $max = 16 }
            $r = Invoke-AuditRequest $url -MaxBytes $max

            if ($r.Status -ne 200) {
                Add-Pass $cat 'H-04' ("到達不可: " + $p.Path) ("HTTP {0}" -f $r.Status)
                continue
            }
            switch ($p.Kind) {
                'sqlite' {
                    $head = [Text.Encoding]::ASCII.GetString($r.Bytes)
                    if ($head.StartsWith('SQLite format 3')) {
                        Add-Finding $cat 'H-04' '高' ('DB ファイルを Web からダウンロードできる: ' + $p.Path) -Location @($url) `
                            -Detail 'ユーザー名・パスワードハッシュ・レポート内容を含む DB を誰でも取得できる（本監査は先頭 16 バイトのみ取得）。' `
                            -Recommendation 'ドキュメントルートを public/ にする、または data/ への HTTP アクセスを .htaccess（Require all denied）で拒否する。'
                    }
                    else {
                        Add-Finding $cat 'H-04' '中' ('DB パスが HTTP 200 を返す（要確認）: ' + $p.Path) -Location @($url) `
                            -Detail 'SQLite の署名ではないため独自エラーページの可能性があるが、ブラウザで確認すること。' `
                            -Recommendation 'data/ への HTTP アクセスを拒否する。'
                    }
                }
                'git' {
                    if ($r.Body -match '^ref:') {
                        Add-Finding $cat 'H-04' '高' '.git ディレクトリが公開されている' -Location @($url) `
                            -Detail 'ソースコード・履歴（DB を含む）を復元される恐れがある。' `
                            -Recommendation '.git を公開領域から外す、またはアクセスを拒否する。'
                    }
                }
                'setup' {
                    Add-Finding $cat 'H-05' '高' '初期管理者登録スクリプトが Web から実行できる' -Location @($url) `
                        -Detail 'setup_admin.php が公開されている。ユーザーが 0 件になった場合、誰でも管理者を作成できる。' `
                        -Recommendation 'サーバーから削除する。'
                }
                'php' { $phpReachable += $url }
            }
        }
        if ($phpReachable) {
            Add-Finding $cat 'H-04b' '中' '公開ディレクトリ外の PHP が Web から実行できる' -Location $phpReachable `
                -Detail 'ドキュメントルートが public/ ではなく、アプリのルートが公開されている。public/ 外に置いたファイルの保護が効いていない。' `
                -Recommendation 'ドキュメントルートを public/ に変更する、またはルート直下に .htaccess を置き public/ 以外を拒否する。'
        }
    }
    else {
        Add-Note $cat 'BaseUrl が .../public/ 形式でないため、公開ディレクトリ外ファイルの確認（H-04/H-05）を省略しました。'
    }

    # ---- H-06 未ログイン時のアクセス制御 ----
    Write-Step '未ログイン時のアクセス制御'
    $protected = @(
        'index.php',
        'reports/create.php',
        'reports/detail.php?id=1',
        'reports/edit.php?id=1',
        'comments/comment_edit.php?id=1',
        'users/users.php'
    )
    $broken = @()
    $exposed = @()
    $okList = @()
    foreach ($p in $protected) {
        $r = Invoke-AuditRequest ($BaseUrl + $p)
        if (Test-IsRedirect $r.Status) {
            $f = Invoke-AuditRequest $r.LocationAbs
            if ($f.Status -eq 200 -and $f.Body -match 'name="password"') {
                $okList += $p
            }
            else {
                $broken += ("{0} → Location: {1} → {2} [HTTP {3}]" -f $p, $r.Location, $r.LocationAbs, $f.Status)
            }
        }
        elseif ($r.Status -eq 200) {
            $exposed += $p
        }
        else {
            $okList += ("{0} (HTTP {1})" -f $p, $r.Status)
        }
    }
    if ($exposed) {
        Add-Finding $cat 'H-06' '高' '未ログインで閲覧できる保護ページ' -Location $exposed `
            -Detail 'ログインせずに HTTP 200 が返った。' -Recommendation '該当ページで requireLogin() を呼ぶ。'
    }
    if ($broken) {
        Add-Finding $cat 'H-06b' '高' '未ログイン時の転送先が存在しない（ログイン画面に戻れない）' -Location $broken `
            -Detail 'サブディレクトリのページで未ログイン時に存在しない URL へ転送される（静的監査 S-01 の実害）。セッション切れの利用者がエラー画面になる。' `
            -Recommendation 'requireLogin() のリダイレクト先をアプリのルート基準の URL にする。'
    }
    if ($okList) { Add-Pass $cat 'H-06' '未ログイン時にログイン画面へ誘導' ($okList -join ', ') }

    # ---- H-07 ログイン後の権限 ----
    if (-not $auditUsers) {
        Add-Note $cat 'audit-config.psd1 に Users が未設定のため、ログイン後の権限監査を省略しました。'
        return
    }
    if (-not $Password) {
        Write-Host ''
        $sec = Read-Host -AsSecureString 'テストユーザー共通パスワード（空欄でログイン後の監査を省略）'
        $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec)
        try { $Password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) }
        finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
    }
    if (-not $Password) {
        Add-Note $cat 'パスワード未入力のため、ログイン後の権限監査を省略しました。'
        return
    }

    Write-Step ("テストユーザー {0} 名でログインして権限を確認" -f $auditUsers.Count)
    $sessions = @()
    foreach ($u in $auditUsers) {
        $jar = New-Object System.Net.CookieContainer
        $body = 'username=' + [Uri]::EscapeDataString($u) + '&password=' + [Uri]::EscapeDataString($Password)
        $r = Invoke-AuditRequest ($BaseUrl + 'login.php') -Method 'POST' -Body $body -Cookies $jar
        if (-not ((Test-IsRedirect $r.Status) -and $r.Location -match 'index\.php')) {
            $sessions += [pscustomobject]@{
                User = $u; LoggedIn = $false; IsAdmin = $false; Visible = @(); Editable = @()
                UsersStatus = $null; Jar = $null; Problems = @('ログイン失敗')
            }
            Add-Finding $cat 'H-07' '情報' ('テストユーザーでログインできない: ' + $u) -Location @('login.php') `
                -Detail ("HTTP {0}。パスワード変更・削除済みの可能性。" -f $r.Status) -Recommendation 'テストユーザーの状態を確認する。'
            continue
        }
        $idx = Invoke-AuditRequest ($BaseUrl + 'index.php') -Cookies $jar
        $isAdmin = $idx.Body -match 'users/users\.php'
        $visible = Get-IdsFromHtml $idx.Body 'reports/detail\.php\?id=(\d+)'
        $editable = Get-IdsFromHtml $idx.Body 'reports/edit\.php\?id=(\d+)'
        $up = Invoke-AuditRequest ($BaseUrl + 'users/users.php') -Cookies $jar
        $sessions += [pscustomobject]@{
            User = $u; LoggedIn = $true; IsAdmin = $isAdmin; Visible = $visible; Editable = $editable
            UsersStatus = $up.Status; Jar = $jar; Problems = @()
        }
    }

    $loggedIn = @($sessions | Where-Object { $_.LoggedIn })
    $admins = @($loggedIn | Where-Object { $_.IsAdmin })
    $source = $admins
    if (-not $admins) {
        $source = $loggedIn
        Add-Note $cat '管理者のテストユーザーがいないため、全レポートの一覧は各ユーザーの閲覧結果の和集合で代用しました。'
    }
    $allIds = @($source | ForEach-Object { $_.Visible } | Sort-Object -Unique)

    $leakDetail = @()
    $leakEdit = @()
    $leakAdmin = @()
    foreach ($s in $loggedIn) {
        if ($s.IsAdmin) {
            if ($s.UsersStatus -ne 200) { $s.Problems += ("管理者なのにユーザー管理が HTTP {0}" -f $s.UsersStatus) }
            continue
        }
        # 管理画面
        if ($s.UsersStatus -eq 200) {
            $leakAdmin += $s.User
            $s.Problems += 'ユーザー管理画面を開ける'
        }
        # 一覧に出ないレポートの詳細画面
        $hidden = @($allIds | Where-Object { $s.Visible -notcontains $_ } | Select-Object -First $script:MaxProbePerUser)
        foreach ($id in $hidden) {
            $r = Invoke-AuditRequest ($BaseUrl + "reports/detail.php?id=$id") -Cookies $s.Jar
            if ($r.Status -eq 200) {
                $leakDetail += ("{0} → reports/detail.php?id={1}" -f $s.User, $id)
                $s.Problems += "閲覧範囲外の詳細 id=$id"
            }
        }
        # 閲覧できるが編集できないレポートの編集画面
        $notEditable = @($s.Visible | Where-Object { $s.Editable -notcontains $_ } | Select-Object -First $script:MaxProbePerUser)
        foreach ($id in $notEditable) {
            $r = Invoke-AuditRequest ($BaseUrl + "reports/edit.php?id=$id") -Cookies $s.Jar
            if ($r.Status -eq 200) {
                $leakEdit += ("{0} → reports/edit.php?id={1}" -f $s.User, $id)
                $s.Problems += "他人のレポート編集画面 id=$id"
            }
        }
    }
    if ($leakAdmin) {
        Add-Finding $cat 'H-07a' '高' '管理者以外がユーザー管理画面を開ける' -Location $leakAdmin `
            -Detail 'users/users.php が HTTP 200 を返した。' -Recommendation 'requireAdmin() を確認する。'
    }
    else { Add-Pass $cat 'H-07a' '管理画面の保護' '管理者以外は users/users.php にアクセスできない。' }
    if ($leakDetail) {
        Add-Finding $cat 'H-07b' '高' '閲覧範囲外のレポートを URL 直打ちで参照できる' -Location $leakDetail `
            -Detail '一覧に表示されないレポートの詳細画面が HTTP 200 を返した。' -Recommendation 'detail.php の権限判定を確認する。'
    }
    else { Add-Pass $cat 'H-07b' '閲覧範囲の保護' ("各ユーザー最大 {0} 件の範囲外レポートで 403 を確認。" -f $script:MaxProbePerUser) }
    if ($leakEdit) {
        Add-Finding $cat 'H-07c' '高' '他人のレポートの編集画面を開ける' -Location $leakEdit `
            -Detail '編集ボタンが表示されないレポートの編集画面が HTTP 200 を返した。' -Recommendation 'edit.php / update.php の権限判定を確認する。'
    }
    else { Add-Pass $cat 'H-07c' '編集権限の保護' ("各ユーザー最大 {0} 件の編集不可レポートで 403 を確認。" -f $script:MaxProbePerUser) }

    # ---- H-08 不正パラメータ ----
    if ($loggedIn) {
        $jar = $loggedIn[0].Jar
        $r1 = Invoke-AuditRequest ($BaseUrl + 'reports/detail.php?id=999999999') -Cookies $jar
        $r2 = Invoke-AuditRequest ($BaseUrl + 'reports/detail.php?id=abc') -Cookies $jar
        if ($r1.Status -eq 404 -and $r2.Status -eq 400) {
            Add-Pass $cat 'H-08' '不正なレポートID の扱い' '存在しない ID は 404、不正な ID は 400。'
        }
        else {
            Add-Finding $cat 'H-08' '低' '不正なレポートID への応答が想定と異なる' -Location @('reports/detail.php') `
                -Detail ("id=999999999 → HTTP {0}（想定 404）、id=abc → HTTP {1}（想定 400）" -f $r1.Status, $r2.Status) `
                -Recommendation '入力チェックを確認する。'
        }
    }

    # ログアウト
    foreach ($s in $loggedIn) { [void](Invoke-AuditRequest ($BaseUrl + 'logout.php') -Cookies $s.Jar) }

    $script:HttpMatrix = $sessions
}

# ============================================================
# 3. DB 監査（SQLite ファイル形式を直接解析）
# ============================================================

function Get-BE16([byte[]]$b, [int]$o) {
    return ([int]$b[$o] -shl 8) -bor [int]$b[$o + 1]
}

function Get-BE32([byte[]]$b, [int]$o) {
    return ([long]$b[$o] -shl 24) -bor ([long]$b[$o + 1] -shl 16) -bor ([long]$b[$o + 2] -shl 8) -bor [long]$b[$o + 3]
}

function Read-Varint([byte[]]$b, [int]$o) {
    [long]$v = 0
    for ($i = 0; $i -lt 8; $i++) {
        $x = [int]$b[$o + $i]
        $v = ($v -shl 7) -bor ($x -band 0x7F)
        if (($x -band 0x80) -eq 0) { return [long[]]@($v, ($i + 1)) }
    }
    $v = ($v -shl 8) -bor [int]$b[$o + 8]
    return [long[]]@($v, 9)
}

function Read-BEInt([byte[]]$b, [int]$o, [int]$n) {
    [long]$v = 0
    for ($i = 0; $i -lt $n; $i++) { $v = ($v -shl 8) -bor [long]$b[$o + $i] }
    if ($n -lt 8 -and ($b[$o] -band 0x80)) { $v -= ([long]1 -shl (8 * $n)) }
    return $v
}

function Open-SqliteFile([string]$Path) {
    $b = [IO.File]::ReadAllBytes($Path)
    if ($b.Length -lt 100 -or [Text.Encoding]::ASCII.GetString($b, 0, 15) -ne 'SQLite format 3') {
        throw 'SQLite 形式のファイルではありません。'
    }
    $ps = Get-BE16 $b 16
    if ($ps -eq 1) { $ps = 65536 }
    $enc = [int](Get-BE32 $b 56)
    $decoder = [Text.Encoding]::UTF8
    if ($enc -eq 2) { $decoder = [Text.Encoding]::Unicode }
    if ($enc -eq 3) { $decoder = [Text.Encoding]::BigEndianUnicode }
    return [pscustomobject]@{
        Bytes     = $b
        PageSize  = $ps
        Usable    = $ps - [int]$b[20]
        PageCount = [int](Get-BE32 $b 28)
        Decoder   = $decoder
    }
}

function Get-CellPayload($db, [int]$cellOff) {
    $b = $db.Bytes
    # 注意: PowerShell の変数名は大文字小文字を区別しないため、
    #       ペイロード長と読み取り位置には別の名前を使う
    $r = Read-Varint $b $cellOff
    $payloadLen = [int]$r[0]
    $pos = $cellOff + [int]$r[1]
    $r = Read-Varint $b $pos
    $rowid = $r[0]
    $pos += [int]$r[1]

    $usable = $db.Usable
    $maxLocal = $usable - 35
    if ($payloadLen -le $maxLocal) {
        $local = $payloadLen
    }
    else {
        $minLocal = [int][Math]::Floor(($usable - 12) * 32 / 255) - 23
        $k = $minLocal + (($payloadLen - $minLocal) % ($usable - 4))
        if ($k -le $maxLocal) { $local = $k } else { $local = $minLocal }
    }

    $payload = New-Object byte[] $payloadLen
    [Array]::Copy($b, $pos, $payload, 0, $local)
    if ($local -lt $payloadLen) {
        $ovf = Get-BE32 $b ($pos + $local)
        $copied = $local
        while ($ovf -ne 0 -and $copied -lt $payloadLen) {
            $po = [int](($ovf - 1) * $db.PageSize)
            $next = Get-BE32 $b $po
            $n = [Math]::Min($usable - 4, $payloadLen - $copied)
            [Array]::Copy($b, $po + 4, $payload, $copied, $n)
            $copied += $n
            $ovf = $next
        }
    }
    return [pscustomobject]@{ RowId = $rowid; Payload = $payload }
}

function ConvertFrom-SqliteRecord($db, [byte[]]$p) {
    $r = Read-Varint $p 0
    $hdrLen = [int]$r[0]
    $h = [int]$r[1]
    $types = New-Object 'System.Collections.Generic.List[long]'
    while ($h -lt $hdrLen) {
        $r = Read-Varint $p $h
        $types.Add($r[0])
        $h += [int]$r[1]
    }
    $sizes = @(0, 1, 2, 3, 4, 6, 8)
    $o = $hdrLen
    $vals = New-Object 'System.Collections.Generic.List[object]'
    foreach ($t in $types) {
        if ($t -eq 0) { $vals.Add($null) }
        elseif ($t -ge 1 -and $t -le 6) {
            $n = $sizes[$t]
            $vals.Add((Read-BEInt $p $o $n))
            $o += $n
        }
        elseif ($t -eq 7) {
            $tmp = New-Object byte[] 8
            [Array]::Copy($p, $o, $tmp, 0, 8)
            [Array]::Reverse($tmp)
            $vals.Add([BitConverter]::ToDouble($tmp, 0))
            $o += 8
        }
        elseif ($t -eq 8) { $vals.Add([long]0) }
        elseif ($t -eq 9) { $vals.Add([long]1) }
        elseif ($t -ge 12 -and ($t % 2) -eq 0) {
            $n = [int](($t - 12) / 2)
            $tmp = New-Object byte[] $n
            [Array]::Copy($p, $o, $tmp, 0, $n)
            $vals.Add($tmp)
            $o += $n
        }
        elseif ($t -ge 13) {
            $n = [int](($t - 13) / 2)
            $vals.Add($db.Decoder.GetString($p, $o, $n))
            $o += $n
        }
        else { $vals.Add($null) }
    }
    return , $vals.ToArray()
}

function Get-TableRecords($db, [int]$RootPage) {
    $b = $db.Bytes
    $out = New-Object 'System.Collections.Generic.List[object]'
    $stack = New-Object 'System.Collections.Generic.Stack[int]'
    $stack.Push($RootPage)
    $visited = @{}
    while ($stack.Count -gt 0) {
        $pg = $stack.Pop()
        if ($visited.ContainsKey($pg)) { continue }
        $visited[$pg] = $true
        $start = ($pg - 1) * $db.PageSize
        $hdr = $start
        if ($pg -eq 1) { $hdr = 100 }
        $type = [int]$b[$hdr]
        $n = Get-BE16 $b ($hdr + 3)
        if ($type -eq 13) {
            for ($i = 0; $i -lt $n; $i++) {
                $ptr = Get-BE16 $b ($hdr + 8 + 2 * $i)
                $cell = Get-CellPayload $db ($start + $ptr)
                $vals = ConvertFrom-SqliteRecord $db $cell.Payload
                $out.Add([pscustomobject]@{ RowId = $cell.RowId; Values = $vals })
            }
        }
        elseif ($type -eq 5) {
            $children = New-Object 'System.Collections.Generic.List[int]'
            for ($i = 0; $i -lt $n; $i++) {
                $ptr = Get-BE16 $b ($hdr + 12 + 2 * $i)
                $children.Add([int](Get-BE32 $b ($start + $ptr)))
            }
            $children.Add([int](Get-BE32 $b ($hdr + 8)))
            for ($i = $children.Count - 1; $i -ge 0; $i--) { $stack.Push($children[$i]) }
        }
        else {
            throw ("想定外のページ種別 {0}（ページ {1}）" -f $type, $pg)
        }
    }
    return , $out.ToArray()
}

function Get-ColumnDefs([string]$Sql) {
    $s = $Sql.IndexOf('(')
    $e = $Sql.LastIndexOf(')')
    $body = $Sql.Substring($s + 1, $e - $s - 1)
    $parts = New-Object 'System.Collections.Generic.List[string]'
    $sb = New-Object Text.StringBuilder
    $depth = 0
    $quote = $null
    foreach ($ch in $body.ToCharArray()) {
        $c = [string]$ch
        if ($quote) {
            [void]$sb.Append($c)
            if ($c -eq $quote) { $quote = $null }
            continue
        }
        if ($c -eq "'" -or $c -eq '"' -or $c -eq '`') {
            $quote = $c
            [void]$sb.Append($c)
            continue
        }
        if ($c -eq '(') { $depth++ }
        elseif ($c -eq ')') { $depth-- }
        if ($c -eq ',' -and $depth -eq 0) {
            $parts.Add($sb.ToString())
            [void]$sb.Clear()
            continue
        }
        [void]$sb.Append($c)
    }
    $parts.Add($sb.ToString())

    $cols = @()
    foreach ($p in $parts) {
        $t = $p.Trim()
        if (-not $t) { continue }
        $first = ($t -split '\s+')[0]
        if (@('CONSTRAINT', 'PRIMARY', 'FOREIGN', 'UNIQUE', 'CHECK') -contains $first.ToUpper()) { continue }
        $cols += [pscustomobject]@{
            Name    = $first.Trim('"', '`', '[', ']', "'")
            IsRowid = ($t -match '^\S+\s+INTEGER\s+PRIMARY\s+KEY')
        }
    }
    return , $cols
}

function Get-SqliteTable($db, $Master, [string]$Name) {
    $m = $Master | Where-Object { $_.Type -eq 'table' -and $_.Name -eq $Name } | Select-Object -First 1
    if (-not $m) { return $null }
    $cols = Get-ColumnDefs $m.Sql
    $rows = @()
    foreach ($rec in (Get-TableRecords $db $m.RootPage)) {
        $o = [ordered]@{}
        for ($i = 0; $i -lt $cols.Count; $i++) {
            $v = $null
            if ($cols[$i].IsRowid) { $v = $rec.RowId }
            elseif ($i -lt $rec.Values.Count) { $v = $rec.Values[$i] }
            $o[$cols[$i].Name] = $v
        }
        $rows += [pscustomobject]$o
    }
    return , $rows
}

function Get-Descendants($UsersById, $ChildrenMap, [long]$Id) {
    $seen = @{}
    $queue = New-Object 'System.Collections.Generic.Queue[long]'
    $queue.Enqueue($Id)
    while ($queue.Count -gt 0) {
        $cur = $queue.Dequeue()
        if ($ChildrenMap.ContainsKey($cur)) {
            foreach ($c in $ChildrenMap[$cur]) {
                if (-not $seen.ContainsKey($c) -and $c -ne $Id) {
                    $seen[$c] = $true
                    $queue.Enqueue($c)
                }
            }
        }
    }
    return @($seen.Keys)
}

function Invoke-DbAudit {
    $cat = 'DB'
    Write-Host '[3/3] DB監査（SQLite）' -ForegroundColor Cyan

    if (-not (Test-Path -LiteralPath $DbPath)) {
        Add-Note $cat "DB ファイルが見つかりません（$DbPath）。DB監査を省略しました。"
        return
    }
    Write-Step "対象: $DbPath"

    $fi = Get-Item -LiteralPath $DbPath
    foreach ($suffix in @('-wal', '-journal')) {
        $side = $DbPath + $suffix
        if ((Test-Path -LiteralPath $side) -and (Get-Item -LiteralPath $side).Length -gt 0) {
            Add-Finding $cat 'D-00' '中' ("{0} ファイルがある" -f $suffix) -Location @($side) `
                -Detail '本監査は本体ファイルのみを読むため、ジャーナル／WAL に残る未反映の変更は結果に含まれない。' `
                -Recommendation 'アプリ停止後（ジャーナルが消えた状態）で再監査する。'
        }
    }

    try {
        $db = Open-SqliteFile $DbPath
    }
    catch {
        Add-Finding $cat 'D-01' '高' 'DB ファイルを解析できない' -Location @($DbPath) -Detail $_.Exception.Message -Recommendation 'ファイルが破損していないか確認する。'
        return
    }
    if ($db.PageCount * $db.PageSize -ne $fi.Length) {
        Add-Note $cat ("ヘッダーのページ数（{0}×{1}）とファイルサイズ（{2}）が一致しません。" -f $db.PageCount, $db.PageSize, $fi.Length)
    }

    $master = @()
    foreach ($rec in (Get-TableRecords $db 1)) {
        $v = $rec.Values
        $master += [pscustomobject]@{ Type = $v[0]; Name = $v[1]; RootPage = [int]$v[3]; Sql = $v[4] }
    }

    $users = Get-SqliteTable $db $master 'users'
    $reports = Get-SqliteTable $db $master 'reports'
    $comments = Get-SqliteTable $db $master 'comments'
    if ($null -eq $users -or $null -eq $reports -or $null -eq $comments) {
        Add-Finding $cat 'D-01' '高' '必要なテーブルがない' -Location @($DbPath) `
            -Detail ('存在するテーブル: ' + (($master | Where-Object { $_.Type -eq 'table' } | ForEach-Object { $_.Name }) -join ', ')) `
            -Recommendation 'users / reports / comments が揃っているか確認する。'
        return
    }
    $users = @($users); $reports = @($reports); $comments = @($comments)
    Write-Step ("users {0} 件 / reports {1} 件 / comments {2} 件" -f $users.Count, $reports.Count, $comments.Count)

    $userById = @{}; foreach ($u in $users) { $userById[[long]$u.id] = $u }
    $reportById = @{}; foreach ($r in $reports) { $reportById[[long]$r.id] = $r }

    # ---- D-02 孤立コメント ----
    $orphan = @($comments | Where-Object { -not $reportById.ContainsKey([long]$_.report_id) })
    if ($orphan) {
        Add-Finding $cat 'D-02' '高' '削除済みレポートに紐づくコメント（孤立データ）' `
            -Location ($orphan | ForEach-Object { "comments.id={0} (report_id={1}, field={2})" -f $_.id, $_.report_id, $_.field_name }) `
            -Detail ("{0} 件。外部キー制約が無効なため、レポート削除時にコメントが残っている（静的監査 S-02）。" -f $orphan.Count) `
            -Recommendation '外部キーを有効化したうえで、孤立コメントを削除する。'
    }
    else { Add-Pass $cat 'D-02' '孤立コメント' 'なし' }

    # ---- D-03 存在しないユーザーへの参照 ----
    $badRefs = @()
    foreach ($r in $reports) { if (-not $userById.ContainsKey([long]$r.user_id)) { $badRefs += ("reports.id={0} user_id={1}" -f $r.id, $r.user_id) } }
    foreach ($c in $comments) { if (-not $userById.ContainsKey([long]$c.user_id)) { $badRefs += ("comments.id={0} user_id={1}" -f $c.id, $c.user_id) } }
    foreach ($u in $users) {
        if ($null -ne $u.manager_id -and -not $userById.ContainsKey([long]$u.manager_id)) { $badRefs += ("users.id={0} manager_id={1}" -f $u.id, $u.manager_id) }
    }
    if ($badRefs) {
        Add-Finding $cat 'D-03' '中' '存在しないユーザーを参照しているデータ' -Location $badRefs `
            -Detail ("{0} 件。" -f $badRefs.Count) -Recommendation '参照先を修正するか、データを整理する。'
    }
    else { Add-Pass $cat 'D-03' 'ユーザー参照の整合性' 'reports / comments / users.manager_id の参照先はすべて存在。' }

    # ---- D-04 上司設定の循環 ----
    $cycles = @()
    foreach ($u in $users) {
        $seen = @{}
        $cur = $u
        while ($null -ne $cur -and $null -ne $cur.manager_id) {
            $mid = [long]$cur.manager_id
            if ($mid -eq [long]$u.id) { $cycles += $u.username; break }
            if ($seen.ContainsKey($mid)) { break }
            $seen[$mid] = $true
            $cur = $userById[$mid]
        }
    }
    if ($cycles) {
        Add-Finding $cat 'D-04' '高' '上司・部下の設定が循環している' -Location $cycles `
            -Detail '閲覧範囲の再帰 SQL が想定外の結果になる（UNION ALL の箇所は無限再帰）。' `
            -Recommendation 'ユーザー管理画面で上司設定を修正する。'
    }
    else { Add-Pass $cat 'D-04' '上司設定の循環' 'なし' }

    # ---- D-05 管理者数 ----
    $adminUsers = @($users | Where-Object { [long]$_.is_admin -eq 1 })
    if ($adminUsers.Count -eq 0) {
        Add-Finding $cat 'D-05' '高' '管理者が 0 人' -Location @('users') -Detail 'ユーザー管理ができない。' -Recommendation 'DB を直接修正して管理者を設定する。'
    }
    elseif ($adminUsers.Count -eq 1) {
        Add-Finding $cat 'D-05' '情報' '管理者が 1 人のみ' -Location ($adminUsers | ForEach-Object { $_.username }) `
            -Detail '退職・パスワード紛失時に管理できなくなる。' -Recommendation '必要に応じて予備の管理者を設ける。'
    }
    else { Add-Pass $cat 'D-05' '管理者数' ("{0} 人" -f $adminUsers.Count) }

    # ---- D-06 コメント対象項目 ----
    $badField = @($comments | Where-Object { $script:AllowedFields -notcontains $_.field_name })
    if ($badField) {
        Add-Finding $cat 'D-06' '中' '定義外の項目に対するコメント' `
            -Location ($badField | ForEach-Object { "comments.id={0} field_name={1}" -f $_.id, $_.field_name }) `
            -Detail '画面に表示されず、編集・削除もできない（400 エラー）。' -Recommendation '項目定義と照合してデータを整理する。'
    }
    else { Add-Pass $cat 'D-06' 'コメント対象項目' ('すべて許可値（' + ($script:AllowedFields -join ', ') + '）。') }

    # ---- D-07 役職 ----
    $badRole = @($users | Where-Object { $script:AllowedRoles -notcontains $_.role_title })
    if ($badRole) {
        Add-Finding $cat 'D-07' '中' '定義外の役職' -Location ($badRole | ForEach-Object { "{0}: {1}" -f $_.username, $_.role_title }) `
            -Detail '編集画面で役職が選択されない状態になる。' -Recommendation '役職を修正する。'
    }
    else { Add-Pass $cat 'D-07' '役職' 'すべて許可値。' }

    # ---- D-08 パスワードハッシュ形式 ----
    $weak = @($users | Where-Object { [string]$_.password_hash -notmatch '^\$(2[aby]\$\d\d\$|argon2)' })
    if ($weak) {
        Add-Finding $cat 'D-08' '高' 'password_hash() 形式でないパスワード' -Location ($weak | ForEach-Object { $_.username }) `
            -Detail '平文または弱いハッシュの可能性がある（ハッシュ値そのものはレポートに出力しない）。' -Recommendation 'パスワードを再設定する。'
    }
    else { Add-Pass $cat 'D-08' 'パスワードハッシュ形式' 'すべて bcrypt / argon2 形式。' }

    # ---- D-09 日付・日時の形式 ----
    $badDate = @()
    foreach ($r in $reports) {
        $d = [datetime]::MinValue
        if (-not [datetime]::TryParseExact([string]$r.report_date, 'yyyy-MM-dd', [Globalization.CultureInfo]::InvariantCulture, 'None', [ref]$d)) {
            $badDate += ("reports.id={0} report_date={1}" -f $r.id, $r.report_date)
        }
    }
    $dtPattern = '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$'
    foreach ($set in @(@{ N = 'users'; R = $users; C = @('created_at') }, @{ N = 'reports'; R = $reports; C = @('created_at', 'updated_at') }, @{ N = 'comments'; R = $comments; C = @('created_at', 'updated_at') })) {
        foreach ($row in $set.R) {
            foreach ($col in $set.C) {
                if ([string]$row.$col -notmatch $dtPattern) { $badDate += ("{0}.id={1} {2}={3}" -f $set.N, $row.id, $col, $row.$col) }
            }
            if ($set.C -contains 'updated_at' -and [string]$row.updated_at -lt [string]$row.created_at) {
                $badDate += ("{0}.id={1} updated_at が created_at より前" -f $set.N, $row.id)
            }
        }
    }
    if ($badDate) {
        Add-Finding $cat 'D-09' '低' '日付・日時の形式不正' -Location $badDate -Detail ("{0} 件。" -f $badDate.Count) -Recommendation 'データを修正する。'
    }
    else { Add-Pass $cat 'D-09' '日付・日時の形式' 'すべて正常。' }

    # ---- D-10 必須項目の空白のみ ----
    $blank = @()
    foreach ($r in $reports) {
        foreach ($col in @('name', 'subject')) {
            if (([string]$r.$col).Trim() -eq '') { $blank += ("reports.id={0} {1}" -f $r.id, $col) }
        }
        foreach ($col in @('name', 'subject')) {
            $v = [string]$r.$col
            if ($v -ne $v.Trim()) { $blank += ("reports.id={0} {1} に前後の空白" -f $r.id, $col) }
        }
    }
    foreach ($u in $users) {
        if ([string]$u.username -ne ([string]$u.username).Trim()) { $blank += ("users.id={0} username に前後の空白" -f $u.id) }
    }
    if ($blank) {
        Add-Finding $cat 'D-10' '低' '必須項目の空白・前後の空白' -Location $blank `
            -Detail '登録処理が trim 前の値を保存しているために発生し得る（詳細設計書 3.2）。' -Recommendation 'データを修正し、保存前に trim する。'
    }
    else { Add-Pass $cat 'D-10' '必須項目の空白' 'なし' }

    # ---- 統計情報 ----
    $children = @{}
    foreach ($u in $users) {
        if ($null -ne $u.manager_id) {
            $k = [long]$u.manager_id
            if (-not $children.ContainsKey($k)) { $children[$k] = @() }
            $children[$k] += [long]$u.id
        }
    }
    $reportsByUser = @{}
    foreach ($r in $reports) {
        $k = [long]$r.user_id
        if (-not $reportsByUser.ContainsKey($k)) { $reportsByUser[$k] = 0 }
        $reportsByUser[$k]++
    }

    $userStats = @()
    $expected = @{}
    foreach ($u in ($users | Sort-Object { [long]$_.id })) {
        $desc = Get-Descendants $userById $children ([long]$u.id)
        $ids = @([long]$u.id) + @($desc)
        $vis = 0
        if ([long]$u.is_admin -eq 1) { $vis = $reports.Count }
        else { foreach ($i in $ids) { if ($reportsByUser.ContainsKey([long]$i)) { $vis += $reportsByUser[[long]$i] } } }
        $expected[[string]$u.username] = $vis
        $mgr = ''
        if ($null -ne $u.manager_id -and $userById.ContainsKey([long]$u.manager_id)) { $mgr = $userById[[long]$u.manager_id].username }
        $own = 0; if ($reportsByUser.ContainsKey([long]$u.id)) { $own = $reportsByUser[[long]$u.id] }
        $userStats += [pscustomobject]@{
            Id = $u.id; Username = $u.username; Role = $u.role_title; Manager = $mgr
            Admin = ([long]$u.is_admin -eq 1); Own = $own; Subordinates = $desc.Count; Visible = $vis
        }
    }
    $script:DbExpectedVisible = $expected

    $fieldStats = [ordered]@{}
    foreach ($f in $script:AllowedFields) { $fieldStats[$f] = 0 }
    foreach ($c in $comments) {
        $k = [string]$c.field_name
        if (-not $fieldStats.Contains($k)) { $fieldStats[$k] = 0 }
        $fieldStats[$k]++
    }

    $fkCount = 0
    foreach ($m in $master) { if ($m.Sql) { $fkCount += ([regex]::Matches($m.Sql, 'REFERENCES')).Count } }

    $script:DbInfo = [pscustomobject]@{
        Path = $DbPath; Size = $fi.Length; LastWrite = $fi.LastWriteTime
        PageSize = $db.PageSize; PageCount = $db.PageCount
        Tables = @($master | Where-Object { $_.Type -eq 'table' } | ForEach-Object { $_.Name })
        Indexes = @($master | Where-Object { $_.Type -eq 'index' } | ForEach-Object { $_.Name })
        Counts = [ordered]@{ users = $users.Count; reports = $reports.Count; comments = $comments.Count }
        UserStats = $userStats; FieldStats = $fieldStats; FkCount = $fkCount
    }
}

# ============================================================
# レポート出力
# ============================================================

function Get-HtmlText([object]$Value) {
    return [System.Net.WebUtility]::HtmlEncode([string]$Value)
}

function New-HtmlReport([string]$Path) {
    $sb = New-Object Text.StringBuilder
    $now = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'

    $counts = [ordered]@{ '高' = 0; '中' = 0; '低' = 0; '情報' = 0 }
    foreach ($f in $script:Findings) { $counts[$f.Severity]++ }

    [void]$sb.AppendLine(@'
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>監査レポート | レポート管理システム</title>
<style>
:root { --text:#1f2933; --muted:#5f6c7b; --line:#d9e2ec; --bg:#f5f7fa; --surface:#fff;
        --high:#c62828; --mid:#e08600; --low:#2e6db4; --info:#6b7785; --ok:#2e7d32; }
* { box-sizing:border-box; }
body { margin:0; background:var(--bg); color:var(--text); line-height:1.6;
       font-family:"Segoe UI","Meiryo","Yu Gothic UI",sans-serif; }
.page { max-width:1200px; margin:0 auto; padding:24px 16px 48px; }
h1 { margin:0 0 4px; font-size:1.6rem; }
h2 { margin:32px 0 12px; font-size:1.25rem; border-bottom:2px solid var(--line); padding-bottom:6px; }
h3 { margin:20px 0 8px; font-size:1.05rem; }
.meta { color:var(--muted); font-size:.9rem; }
.cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin:20px 0; }
.card { background:var(--surface); border:1px solid var(--line); border-radius:8px; padding:14px; }
.card .n { font-size:2rem; font-weight:700; line-height:1.1; font-variant-numeric:tabular-nums; }
.card .l { color:var(--muted); font-size:.85rem; }
.card.high .n { color:var(--high); } .card.mid .n { color:var(--mid); }
.card.low .n { color:var(--low); } .card.info .n { color:var(--info); } .card.ok .n { color:var(--ok); }
table { width:100%; border-collapse:collapse; background:var(--surface); font-size:.9rem; margin:8px 0 16px; }
th, td { border:1px solid var(--line); padding:8px 10px; text-align:left; vertical-align:top; }
th { background:#eef2f6; white-space:nowrap; }
td.num { text-align:right; font-variant-numeric:tabular-nums; }
.badge { display:inline-block; min-width:2.6em; padding:1px 8px; border-radius:10px; color:#fff;
         font-size:.8rem; font-weight:700; text-align:center; white-space:nowrap; }
.sev-高 { background:var(--high); } .sev-中 { background:var(--mid); }
.sev-低 { background:var(--low); } .sev-情報 { background:var(--info); } .sev-OK { background:var(--ok); }
.loc { font-family:Consolas,"BIZ UDGothic",monospace; font-size:.8rem; color:#334e68; word-break:break-all; }
.rec { color:#1b5e20; }
.note { background:#fff8e1; border-left:4px solid #f0b400; padding:10px 14px; margin:8px 0; }
.small { color:var(--muted); font-size:.85rem; }
.table-wrap { overflow-x:auto; }
@media print { body { background:#fff; } .card, table { break-inside:avoid; } }
</style>
</head>
<body>
<div class="page">
<h1>監査レポート：レポート管理システム</h1>
'@)
    [void]$sb.AppendLine(('<p class="meta">実行日時 {0} ／ リポジトリ {1} ／ 対象URL {2} ／ DB {3}</p>' -f `
            (Get-HtmlText $now), (Get-HtmlText $RepoRoot), (Get-HtmlText $BaseUrl), (Get-HtmlText $DbPath)))

    # サマリー
    [void]$sb.AppendLine('<div class="cards">')
    [void]$sb.AppendLine(('<div class="card high"><div class="n">{0}</div><div class="l">高</div></div>' -f $counts['高']))
    [void]$sb.AppendLine(('<div class="card mid"><div class="n">{0}</div><div class="l">中</div></div>' -f $counts['中']))
    [void]$sb.AppendLine(('<div class="card low"><div class="n">{0}</div><div class="l">低</div></div>' -f $counts['低']))
    [void]$sb.AppendLine(('<div class="card info"><div class="n">{0}</div><div class="l">情報</div></div>' -f $counts['情報']))
    [void]$sb.AppendLine(('<div class="card ok"><div class="n">{0}</div><div class="l">問題なし（確認済み）</div></div>' -f $script:Passes.Count))
    [void]$sb.AppendLine('</div>')

    [void]$sb.AppendLine('<p class="small">重要度　高: 不具合・情報漏えい・権限回避につながる ／ 中: 整合性・保守性・防御の不足 ／ 低: 軽微な改善 ／ 情報: 確認事項</p>')

    foreach ($n in $script:Notes) {
        [void]$sb.AppendLine(('<div class="note">[{0}] {1}</div>' -f (Get-HtmlText $n.Category), (Get-HtmlText $n.Text)))
    }

    $catNames = [ordered]@{ '静的' = '1. 静的監査（ソースコード）'; 'HTTP' = '2. HTTP監査（稼働サイト）'; 'DB' = '3. DB監査（データ整合性）' }
    foreach ($cat in $catNames.Keys) {
        [void]$sb.AppendLine(('<h2>{0}</h2>' -f $catNames[$cat]))
        $fs = @($script:Findings | Where-Object { $_.Category -eq $cat } | Sort-Object { $SeverityOrder[$_.Severity] }, Id)
        if ($fs) {
            [void]$sb.AppendLine('<div class="table-wrap"><table><thead><tr><th>重要度</th><th>ID</th><th>指摘</th><th>該当箇所</th><th>対応案</th></tr></thead><tbody>')
            foreach ($f in $fs) {
                $locHtml = ($f.Location | ForEach-Object { Get-HtmlText $_ }) -join '<br>'
                [void]$sb.AppendLine(('<tr><td><span class="badge sev-{0}">{0}</span></td><td>{1}</td><td><strong>{2}</strong><br>{3}</td><td class="loc">{4}</td><td class="rec">{5}</td></tr>' -f `
                        $f.Severity, (Get-HtmlText $f.Id), (Get-HtmlText $f.Title), (Get-HtmlText $f.Detail), $locHtml, (Get-HtmlText $f.Recommendation)))
            }
            [void]$sb.AppendLine('</tbody></table></div>')
        }
        else {
            [void]$sb.AppendLine('<p class="small">指摘事項はありません。</p>')
        }

        $ps = @($script:Passes | Where-Object { $_.Category -eq $cat })
        if ($ps) {
            [void]$sb.AppendLine('<h3>問題がなかった確認項目</h3><div class="table-wrap"><table><thead><tr><th>結果</th><th>ID</th><th>確認項目</th><th>内容</th></tr></thead><tbody>')
            foreach ($p in $ps) {
                [void]$sb.AppendLine(('<tr><td><span class="badge sev-OK">OK</span></td><td>{0}</td><td>{1}</td><td class="loc">{2}</td></tr>' -f `
                        (Get-HtmlText $p.Id), (Get-HtmlText $p.Title), (Get-HtmlText $p.Detail)))
            }
            [void]$sb.AppendLine('</tbody></table></div>')
        }

        if ($cat -eq 'HTTP' -and $script:HttpMatrix) {
            [void]$sb.AppendLine('<h3>ユーザー別の閲覧・編集範囲（一覧画面の表示件数）</h3>')
            [void]$sb.AppendLine('<div class="table-wrap"><table><thead><tr><th>ユーザー</th><th>ログイン</th><th>管理者</th><th>閲覧可能</th><th>編集可能</th><th>ユーザー管理</th><th>同梱DBから算出した閲覧可能件数</th><th>問題</th></tr></thead><tbody>')
            foreach ($s in $script:HttpMatrix) {
                $exp = ''
                if ($script:DbExpectedVisible -and $script:DbExpectedVisible.ContainsKey($s.User)) { $exp = $script:DbExpectedVisible[$s.User] }
                $login = '×'; if ($s.LoggedIn) { $login = '○' }
                $adm = ''; if ($s.IsAdmin) { $adm = '○' }
                [void]$sb.AppendLine(('<tr><td>{0}</td><td>{1}</td><td>{2}</td><td class="num">{3}</td><td class="num">{4}</td><td class="num">{5}</td><td class="num">{6}</td><td>{7}</td></tr>' -f `
                        (Get-HtmlText $s.User), $login, $adm, @($s.Visible).Count, @($s.Editable).Count, (Get-HtmlText $s.UsersStatus), (Get-HtmlText $exp), (Get-HtmlText ($s.Problems -join ' / '))))
            }
            [void]$sb.AppendLine('</tbody></table></div>')
            [void]$sb.AppendLine('<p class="small">「同梱DBから算出」はリポジトリ内の DB の上司設定から計算した期待値。サーバーの DB と同梱 DB が同じ内容の場合のみ比較の意味がある。</p>')
        }

        if ($cat -eq 'DB' -and $script:DbInfo) {
            $i = $script:DbInfo
            [void]$sb.AppendLine('<h3>DB 概要</h3><div class="table-wrap"><table><tbody>')
            [void]$sb.AppendLine(('<tr><th>ファイル</th><td class="loc">{0}</td></tr>' -f (Get-HtmlText $i.Path)))
            [void]$sb.AppendLine(('<tr><th>サイズ / 更新日時</th><td>{0:N0} バイト / {1}</td></tr>' -f $i.Size, $i.LastWrite))
            [void]$sb.AppendLine(('<tr><th>ページ</th><td>{0} バイト × {1} ページ</td></tr>' -f $i.PageSize, $i.PageCount))
            [void]$sb.AppendLine(('<tr><th>テーブル</th><td>{0}</td></tr>' -f (Get-HtmlText ($i.Tables -join ', '))))
            [void]$sb.AppendLine(('<tr><th>インデックス</th><td>{0}</td></tr>' -f (Get-HtmlText ($i.Indexes -join ', '))))
            [void]$sb.AppendLine(('<tr><th>件数</th><td>users {0} / reports {1} / comments {2}</td></tr>' -f $i.Counts.users, $i.Counts.reports, $i.Counts.comments))
            [void]$sb.AppendLine(('<tr><th>外部キー定義</th><td>{0} 件（PRAGMA foreign_keys はファイルに保存されない設定のため、有効かどうかは静的監査 S-02 を参照）</td></tr>' -f $i.FkCount))
            [void]$sb.AppendLine('</tbody></table></div>')

            [void]$sb.AppendLine('<h3>ユーザーと閲覧範囲（同梱DBから算出）</h3><div class="table-wrap"><table><thead><tr><th>ID</th><th>ユーザー名</th><th>役職</th><th>上司</th><th>管理者</th><th>自分のレポート</th><th>配下の人数</th><th>閲覧可能なレポート</th></tr></thead><tbody>')
            foreach ($u in $i.UserStats) {
                $adm = ''; if ($u.Admin) { $adm = '○' }
                [void]$sb.AppendLine(('<tr><td class="num">{0}</td><td>{1}</td><td>{2}</td><td>{3}</td><td>{4}</td><td class="num">{5}</td><td class="num">{6}</td><td class="num">{7}</td></tr>' -f `
                        $u.Id, (Get-HtmlText $u.Username), (Get-HtmlText $u.Role), (Get-HtmlText $u.Manager), $adm, $u.Own, $u.Subordinates, $u.Visible))
            }
            [void]$sb.AppendLine('</tbody></table></div>')

            [void]$sb.AppendLine('<h3>項目別コメント件数</h3><div class="table-wrap"><table><thead><tr><th>field_name</th><th>件数</th></tr></thead><tbody>')
            foreach ($k in $i.FieldStats.Keys) {
                [void]$sb.AppendLine(('<tr><td>{0}</td><td class="num">{1}</td></tr>' -f (Get-HtmlText $k), $i.FieldStats[$k]))
            }
            [void]$sb.AppendLine('</tbody></table></div>')
        }
    }

    [void]$sb.AppendLine('<p class="small">本レポートは Invoke-ReportAudit.ps1 により自動生成。HTTP監査で送信した POST はログインのみで、データの登録・更新・削除は行っていない。</p>')
    [void]$sb.AppendLine('</div></body></html>')

    [IO.File]::WriteAllText($Path, $sb.ToString(), (New-Object Text.UTF8Encoding $true))
}

# ============================================================
# メイン
# ============================================================

Write-Host ''
Write-Host '==== レポート管理システム 監査 ====' -ForegroundColor White
Write-Host ("リポジトリ: {0}" -f $RepoRoot)
Write-Host ''

if (-not $SkipStatic) {
    try { Invoke-StaticAudit } catch { Add-Note '静的' ('静的監査でエラー: ' + $_.Exception.Message); Write-Warning $_ }
}
else { Add-Note '静的' '-SkipStatic により省略しました。' }

if (-not $SkipDb) {
    try { Invoke-DbAudit } catch { Add-Note 'DB' ('DB監査でエラー: ' + $_.Exception.Message); Write-Warning $_ }
}
else { Add-Note 'DB' '-SkipDb により省略しました。' }

if (-not $SkipHttp) {
    try { Invoke-HttpAudit } catch { Add-Note 'HTTP' ('HTTP監査でエラー: ' + $_.Exception.Message); Write-Warning $_ }
}
else { Add-Note 'HTTP' '-SkipHttp により省略しました。' }

# 画面出力
Write-Host ''
Write-Host '==== 結果 ====' -ForegroundColor White
$colors = @{ '高' = 'Red'; '中' = 'Yellow'; '低' = 'Cyan'; '情報' = 'Gray' }
foreach ($f in ($script:Findings | Sort-Object { $SeverityOrder[$_.Severity] }, Category, Id)) {
    Write-Host ("[{0}] {1,-4} {2,-6} {3}" -f $f.Severity, $f.Category, $f.Id, $f.Title) -ForegroundColor $colors[$f.Severity]
}
foreach ($n in $script:Notes) { Write-Host ("[注記] {0}: {1}" -f $n.Category, $n.Text) -ForegroundColor DarkGray }

$high = @($script:Findings | Where-Object { $_.Severity -eq '高' }).Count
$mid = @($script:Findings | Where-Object { $_.Severity -eq '中' }).Count
$low = @($script:Findings | Where-Object { $_.Severity -eq '低' }).Count
Write-Host ''
Write-Host ("高 {0} 件 / 中 {1} 件 / 低 {2} 件 / 確認済み {3} 項目" -f $high, $mid, $low, $script:Passes.Count)

if (-not (Test-Path -LiteralPath $OutputDir)) { New-Item -ItemType Directory -Path $OutputDir | Out-Null }
$reportPath = Join-Path $OutputDir ('audit-report_{0}.html' -f (Get-Date -Format 'yyyyMMdd_HHmmss'))
New-HtmlReport $reportPath
Write-Host ("レポート: {0}" -f $reportPath) -ForegroundColor Green

if (-not $NoOpen) { Start-Process $reportPath }

if ($high -gt 0) { exit 1 } else { exit 0 }
