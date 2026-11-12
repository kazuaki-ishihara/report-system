<#
.SYNOPSIS
  report-system のローカル検証環境（PHP 組み込みサーバー）を作る。

.DESCRIPTION
  次の順に処理する。-DryRun を付けると、何も変更せずに予定だけを表示する。

  1. PHP を探す。なければ winget でインストールする（実行前に確認する）
  2. php.ini を用意し、pdo_sqlite / sqlite3 / mbstring / openssl と date.timezone を設定する
     （既存の php.ini は php.ini.bak_日時 に退避してから変更）
  3. リポジトリを検証用フォルダ（既定: C:\ReportSystem\check）にコピーする
     - .git、依頼メモ、監査レポートはコピーしない
     - 検証用 DB（data/reports.sqlite）は初回だけコピーし、2 回目以降は残す（-ResetDb で作り直し）
     - リポジトリの DB は変更しない
  4. 検証用フォルダに start_server.ps1 を作る
  5. check_php.ps1 で結果を確認する

  リポジトリが見つからない端末（リポジトリ管理外で作業する端末）では、
  3 を行わず、PHP の導入と設定（1・2・5）だけを行う。
  -AppDir でアプリのフォルダ（config\, lib\, public\, data\ があるフォルダ）を指定すると、
  そのフォルダを直接動かす起動スクリプトも作る（このとき DB はそのフォルダのものが変更される）。

.PARAMETER VerifyRoot
  検証用フォルダ。リポジトリの外に置く。

.EXAMPLE
  # 予定だけ表示（何も変更しない）
  powershell -ExecutionPolicy Bypass -File .\setup_devenv.ps1 -DryRun

.EXAMPLE
  # 実行（インストール前に確認あり）
  powershell -ExecutionPolicy Bypass -File .\setup_devenv.ps1

.EXAMPLE
  # ソースだけ最新にし、DB は初期状態に戻す
  powershell -ExecutionPolicy Bypass -File .\setup_devenv.ps1 -SkipPhp -ResetDb

.EXAMPLE
  # リポジトリ管理外の端末: アプリのフォルダを直接動かす
  powershell -ExecutionPolicy Bypass -File .\setup_devenv.ps1 -AppDir D:\work\report-system
#>
[CmdletBinding()]
param(
    [string]$RepoRoot = '',   # 省略時はこのスクリプトの 2 階層上（<リポジトリ>\tools\verify\）
    [string]$AppDir = '',     # リポジトリ管理外の端末で、直接動かすアプリのフォルダ
    [string]$VerifyRoot = 'C:\ReportSystem\check',
    [string]$PhpPackage = 'PHP.PHP.8.3',
    [int]$Port = 8000,
    [switch]$DryRun,
    [switch]$Yes,        # 確認を省略する
    [switch]$SkipPhp,    # PHP の導入・設定をしない（ソースのコピーだけ）
    [switch]$ResetDb     # 検証用 DB をリポジトリの DB で作り直す
)

$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
if ([string]::IsNullOrWhiteSpace($RepoRoot)) { $RepoRoot = Join-Path $scriptDir '..\..' }
$repoRoot  = [IO.Path]::GetFullPath($RepoRoot)
$marker    = '.verify-root'   # 検証用フォルダの目印（これがないフォルダは上書きしない）
$extensions = @('pdo_sqlite', 'sqlite3', 'mbstring', 'openssl')

function Write-Step([string]$msg) { Write-Host ''; Write-Host "==== $msg ====" -ForegroundColor Cyan }
function Write-Plan([string]$msg) { Write-Host "  [予定] $msg" -ForegroundColor Yellow }
function Write-Ok([string]$msg)   { Write-Host "  [OK] $msg" -ForegroundColor Green }
function Write-Info([string]$msg) { Write-Host "  $msg" }

function Confirm-Action([string]$question) {
    if ($Yes) { return $true }
    $ans = Read-Host "$question [y/N]"
    return ($ans -match '^(y|yes)$')
}

function Find-Php {
    $cmd = Get-Command php -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($cmd) { return $cmd.Source }
    $places = @('C:\php\php.exe', (Join-Path $env:ProgramFiles 'PHP\php.exe'))
    $pkgs = Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages'
    if (Test-Path -LiteralPath $pkgs) {
        $places += @(Get-ChildItem -LiteralPath $pkgs -Directory -Filter 'PHP.PHP*' -ErrorAction SilentlyContinue |
                     ForEach-Object { Join-Path $_.FullName 'php.exe' })
    }
    foreach ($p in $places) { if (Test-Path -LiteralPath $p -PathType Leaf) { return $p } }
    return $null
}

# ==== 事前チェック ====
Write-Step '事前チェック'
$verifyFull = [IO.Path]::GetFullPath($VerifyRoot).TrimEnd('\')
$repoFull   = $repoRoot.TrimEnd('\')
if ($DryRun) { Write-Info '（-DryRun: 何も変更しません）' }

# report-system のアプリのフォルダか（主要ファイルがあるか）
$appMarkers = @('config\database.php', 'lib\auth.php', 'public\login.php')
function Get-MissingAppFiles([string]$dir) {
    return @($appMarkers | Where-Object { -not (Test-Path -LiteralPath (Join-Path $dir $_)) })
}

function Test-Overlap([string]$a, [string]$b) {
    return ($a -ieq $b -or $a.StartsWith($b + '\', [StringComparison]::OrdinalIgnoreCase) -or
            $b.StartsWith($a + '\', [StringComparison]::OrdinalIgnoreCase))
}

# モード: repo = リポジトリをコピーして動かす / app = 指定フォルダを直接動かす / phponly = PHP の導入・設定のみ
$appFull = $null
if (-not [string]::IsNullOrWhiteSpace($AppDir)) {
    $appFull = [IO.Path]::GetFullPath($AppDir).TrimEnd('\')
    $m = Get-MissingAppFiles $appFull
    if ($m.Count -gt 0) {
        Write-Host "  [NG] -AppDir $appFull は report-system のフォルダではありません（見つからない: $($m -join ', ')）" -ForegroundColor Red
        exit 1
    }
    if ($appFull -match '^[A-Za-z]:\\dev2(\\|$)') { throw 'C:\dev2 は参照専用のため、-AppDir にできません。' }
    $mode = 'app'
} elseif ((Get-MissingAppFiles $repoFull).Count -eq 0) {
    $mode = 'repo'
} else {
    $mode = 'phponly'
}

switch ($mode) {
    'repo' {
        Write-Info "リポジトリ  : $repoFull"
        Write-Info "検証フォルダ: $verifyFull"
        Write-Ok 'リポジトリの確認'
    }
    'app' {
        Write-Info "アプリ      : $appFull（直接動かす。DB はこのフォルダのものが変更される）"
        Write-Info "起動用フォルダ: $verifyFull"
        Write-Ok 'アプリのフォルダの確認'
    }
    'phponly' {
        Write-Host "  [--] リポジトリが見つかりません（$repoFull）。" -ForegroundColor Yellow
        Write-Host '       PHP の導入と設定だけを行います（ソースのコピーと起動スクリプトの作成は省略）。' -ForegroundColor Yellow
        Write-Host '       アプリを動かすときは、あとで -AppDir <アプリのフォルダ> を付けて再実行してください。' -ForegroundColor Yellow
        if ($SkipPhp) { Write-Host '  -SkipPhp が指定されているため、何もすることがありません。'; exit 0 }
        if (-not $DryRun -and -not (Confirm-Action 'PHP の導入と設定だけを行います。続けますか？')) {
            Write-Host '中止しました。' -ForegroundColor Red; exit 1
        }
    }
}

if ($mode -eq 'repo' -and (Test-Overlap $verifyFull $repoFull)) {
    throw '検証フォルダはリポジトリの外（かつリポジトリを含まない場所）にしてください。'
}
if ($mode -eq 'app' -and (Test-Overlap $verifyFull $appFull)) {
    throw '起動用フォルダ（-VerifyRoot）は -AppDir の外にしてください。'
}
if ($mode -ne 'phponly') {
    if ($verifyFull -match '^[A-Za-z]:$') {
        throw 'ドライブの直下は検証フォルダにできません。'
    }
    if ($verifyFull -match '^[A-Za-z]:\\dev2(\\|$)') {
        throw 'C:\dev2 は参照専用のため、検証フォルダにできません。'
    }
    if ((Test-Path -LiteralPath $verifyFull) -and -not (Test-Path -LiteralPath (Join-Path $verifyFull $marker))) {
        $hasFiles = @(Get-ChildItem -LiteralPath $verifyFull -Force -ErrorAction SilentlyContinue).Count -gt 0
        if ($hasFiles) { throw "既存のフォルダ $verifyFull はこのスクリプトが作ったものではないため、上書きしません。別の -VerifyRoot を指定してください。" }
    }
    Write-Ok '検証フォルダの場所'
}

# ==== 1. PHP ====
$phpExe = $null
if ($SkipPhp) {
    Write-Step '1. PHP（-SkipPhp のため省略）'
    $phpExe = Find-Php
} else {
    Write-Step '1. PHP'
    $phpExe = Find-Php
    if ($phpExe) {
        Write-Ok "見つかりました: $phpExe"
    } elseif ($DryRun) {
        $arch = if ([Environment]::Is64BitOperatingSystem) { ' --architecture x64' } else { '' }
        Write-Plan "winget install --id $PhpPackage -e --source winget$arch でインストール"
    } else {
        if (-not (Get-Command winget -ErrorAction SilentlyContinue)) { throw 'winget がありません。PHP を手動でインストールしてください。' }
        if (-not (Confirm-Action "PHP（$PhpPackage）を winget でインストールします。よろしいですか？")) {
            Write-Host '中止しました。' -ForegroundColor Red; exit 1
        }
        # 64 ビット Windows では x64 版を明示する（指定しないと x86 版が選ばれる端末がある）
        $wgArgs = @('install', '--id', $PhpPackage, '-e', '--source', 'winget',
                    '--accept-package-agreements', '--accept-source-agreements', '--disable-interactivity')
        if ([Environment]::Is64BitOperatingSystem) { $wgArgs += @('--architecture', 'x64') }
        & winget @wgArgs
        if ($LASTEXITCODE -ne 0) { throw "winget install が失敗しました（終了コード $LASTEXITCODE）。" }
        $phpExe = Find-Php
        if (-not $phpExe) { throw 'インストール後に php.exe が見つかりません。新しい PowerShell で再実行してください。' }
        Write-Ok "インストールしました: $phpExe"
    }
}

# ==== 2. php.ini ====
if (-not $SkipPhp) {
    Write-Step '2. php.ini'
    if (-not $phpExe) {
        Write-Plan 'PHP のインストール後に php.ini を作成し、拡張と date.timezone を設定'
    } else {
        $phpDir  = Split-Path -Parent $phpExe
        $iniPath = Join-Path $phpDir 'php.ini'
        $devIni  = Join-Path $phpDir 'php.ini-development'
        $extDir  = Join-Path $phpDir 'ext'

        if (Test-Path -LiteralPath $iniPath) {
            $lines = [IO.File]::ReadAllLines($iniPath)
            $isNew = $false
        } elseif (Test-Path -LiteralPath $devIni) {
            $lines = [IO.File]::ReadAllLines($devIni)
            $isNew = $true
        } else {
            $lines = @()
            $isNew = $true
        }

        $changes = New-Object System.Collections.Generic.List[string]
        $out = New-Object System.Collections.Generic.List[string]
        $seenExtDir = $false; $seenTz = $false
        $seenExt = @{}
        foreach ($l in $lines) {
            $line = $l
            if ($line -match '^\s*;?\s*extension_dir\s*=' -and -not $seenExtDir -and ($line -match '"ext"' -or $line -notmatch '^\s*;')) {
                $new = "extension_dir = `"$extDir`""
                if ($line -ne $new) { $changes.Add("extension_dir = $extDir"); $line = $new }
                $seenExtDir = $true
            } elseif ($line -match '^\s*;?\s*date\.timezone\s*=' -and -not $seenTz) {
                if ($line -notmatch '^\s*date\.timezone\s*=\s*"?Asia/Tokyo"?\s*$') { $changes.Add('date.timezone = Asia/Tokyo'); $line = 'date.timezone = Asia/Tokyo' }
                $seenTz = $true
            } else {
                foreach ($e in $extensions) {
                    if ($line -match "^\s*;?\s*extension\s*=\s*(php_)?$e(\.dll)?\s*$") {
                        if ($line -match '^\s*;') { $changes.Add("extension=$e を有効化"); $line = "extension=$e" }
                        $seenExt[$e] = $true
                    }
                }
            }
            $out.Add($line)
        }
        if (-not $seenExtDir) { $out.Add("extension_dir = `"$extDir`""); $changes.Add("extension_dir = $extDir") }
        foreach ($e in $extensions) { if (-not $seenExt[$e]) { $out.Add("extension=$e"); $changes.Add("extension=$e を追加") } }
        if (-not $seenTz) { $out.Add('date.timezone = Asia/Tokyo'); $changes.Add('date.timezone = Asia/Tokyo') }

        if ($changes.Count -eq 0 -and -not $isNew) {
            Write-Ok "変更不要: $iniPath"
        } elseif ($DryRun) {
            if ($isNew) { Write-Plan "php.ini-development から $iniPath を作成" }
            foreach ($c in $changes) { Write-Plan $c }
        } else {
            if (-not $isNew) {
                $bak = "$iniPath.bak_$(Get-Date -Format yyyyMMdd_HHmmss)"
                Copy-Item -LiteralPath $iniPath -Destination $bak
                Write-Info "退避: $bak"
            }
            [IO.File]::WriteAllLines($iniPath, $out, (New-Object Text.UTF8Encoding($false)))
            foreach ($c in $changes) { Write-Ok $c }
        }
    }
}

# ==== 3. ソースのコピー ====
$excludeDirs  = @('.git', '.claude', (Join-Path $repoFull 'tools\audit\reports'))
$excludeFiles = @('reports.sqlite', 'reports.sqlite-journal', 'reports.sqlite-wal', 'reports.sqlite-shm',
                  '石原さん依頼.txt', '依頼書.txt', $marker)
$dbSrc = Join-Path $repoFull 'data\reports.sqlite'
$dbDst = if ($mode -eq 'app') { Join-Path $appFull 'data\reports.sqlite' } else { Join-Path $verifyFull 'data\reports.sqlite' }

if ($mode -ne 'repo') {
    Write-Step '3. 検証用フォルダへコピー（リポジトリがないため省略）'
} elseif ($DryRun) {
    Write-Step '3. 検証用フォルダへコピー'
    Write-Plan "robocopy /MIR $repoFull -> $verifyFull（.git・依頼メモ・DB を除く）"
    if ($ResetDb -or -not (Test-Path -LiteralPath $dbDst)) { Write-Plan "DB をコピー: $dbSrc -> $dbDst" }
    else { Write-Plan "検証用 DB は残す: $dbDst" }
} else {
    Write-Step '3. 検証用フォルダへコピー'
    New-Item -ItemType Directory -Force -Path $verifyFull | Out-Null
    $rcArgs = @($repoFull, $verifyFull, '/MIR', '/NFL', '/NDL', '/NJH', '/NJS', '/NP', '/R:1', '/W:1', '/XD') + $excludeDirs + @('/XF') + $excludeFiles
    & robocopy @rcArgs | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy が失敗しました（終了コード $LASTEXITCODE）。" }
    Set-Content -LiteralPath (Join-Path $verifyFull $marker) -Value "created by tools/verify/setup_devenv.ps1`r`nsource: $repoFull" -Encoding UTF8
    Write-Ok 'ソースをコピーしました'

    if (-not (Test-Path -LiteralPath $dbSrc)) {
        Write-Host "  [--] リポジトリに DB がありません（$dbSrc）。DB のコピーを省略しました。" -ForegroundColor Yellow
    } elseif ($ResetDb -or -not (Test-Path -LiteralPath $dbDst)) {
        foreach ($sfx in @('-journal', '-wal', '-shm')) {
            $f = "$dbDst$sfx"; if (Test-Path -LiteralPath $f) { Remove-Item -LiteralPath $f -Force }
        }
        Copy-Item -LiteralPath $dbSrc -Destination $dbDst -Force
        Write-Ok "DB をコピーしました: $dbDst"
    } else {
        Write-Ok "検証用 DB はそのまま残しました（作り直すときは -ResetDb）"
    }
}

# ==== 4. 起動スクリプト ====
$serveRoot = if ($mode -eq 'app') { $appFull } else { $verifyFull }
$startPath = Join-Path $verifyFull 'start_server.ps1'
$phpForStart = if ($phpExe) { $phpExe } else { 'php' }
$startScript = @"
# report-system 検証用サーバー（setup_devenv.ps1 が生成）
# 停止: Ctrl + C
`$php = '$phpForStart'
if (-not (Test-Path -LiteralPath `$php)) { `$php = 'php' }
Write-Host 'http://localhost:$Port/public/login.php を開いてください（停止: Ctrl + C）'
Write-Host '注意: PHP 組み込みサーバーは .htaccess を読まないため、data/ などは本番と同じく見えます。'
& `$php -S 127.0.0.1:$Port -t '$serveRoot'
"@
$startBat = Join-Path $verifyFull 'start_server.bat'
$startBatText = "@echo off`r`nrem report-system local server (double-click to run, Ctrl+C to stop)`r`npowershell -NoProfile -ExecutionPolicy Bypass -File `"%~dp0start_server.ps1`"`r`npause`r`n"
if ($mode -eq 'phponly') {
    Write-Step '4. 起動スクリプト（アプリのフォルダがないため省略）'
} elseif ($DryRun) {
    Write-Step '4. 起動スクリプト'
    Write-Plan "$startPath を作成（php -S 127.0.0.1:$Port -t $serveRoot）"
    Write-Plan "$startBat を作成（ダブルクリック用）"
} else {
    Write-Step '4. 起動スクリプト'
    if ($mode -eq 'app') {
        New-Item -ItemType Directory -Force -Path $verifyFull | Out-Null
        Set-Content -LiteralPath (Join-Path $verifyFull $marker) -Value "created by tools/verify/setup_devenv.ps1`r`napp: $appFull" -Encoding UTF8
    }
    [IO.File]::WriteAllText($startPath, $startScript, (New-Object Text.UTF8Encoding($true)))
    [IO.File]::WriteAllText($startBat, $startBatText, (New-Object Text.ASCIIEncoding))
    Write-Ok "作成しました: $startPath"
    Write-Ok "作成しました: $startBat（ダブルクリックで起動）"
}

# ==== 5. 確認 ====
Write-Step '5. PHP の確認'
$checker = Join-Path $scriptDir 'check_php.ps1'
if (-not (Test-Path -LiteralPath $checker)) {
    Write-Info "check_php.ps1 が同じフォルダにないため省略（$checker）"
} elseif ($DryRun) {
    Write-Plan "$checker を実行"
} else {
    & powershell -NoProfile -ExecutionPolicy Bypass -File $checker
}

Write-Step '完了'
if ($DryRun) {
    Write-Info '-DryRun のため何も変更していません。-DryRun を外すと実行します。'
} elseif ($mode -eq 'phponly') {
    Write-Info 'PHP の導入と設定が終わりました。'
    Write-Info 'アプリを動かすときは: setup_devenv.bat -SkipPhp -AppDir <アプリのフォルダ>'
} else {
    Write-Info "起動: $startBat をダブルクリック"
    Write-Info "監査: python tools\audit\audit.py --base-url http://localhost:$Port/public/ --db-path `"$dbDst`""
}
