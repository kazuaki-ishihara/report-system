<#
.SYNOPSIS
  report-system のローカル検証環境（PHP 組み込みサーバー）を作る。

.DESCRIPTION
  次の順に処理する。-DryRun を付けると、何も変更せずに予定だけを表示する。

  1. PHP を探す。なければ winget でインストールする（実行前に確認する）
  2. php.ini を用意し、pdo_sqlite / sqlite3 / mbstring / openssl と date.timezone を設定する
     （既存の php.ini は php.ini.bak_日時 に退避してから変更）
  3. リポジトリを検証用フォルダ（既定: C:\ReportSystem-verify）にコピーする
     - .git、依頼メモ、監査レポートはコピーしない
     - 検証用 DB（data/reports.sqlite）は初回だけコピーし、2 回目以降は残す（-ResetDb で作り直し）
     - リポジトリの DB は変更しない
  4. 検証用フォルダに start_server.ps1 を作る
  5. check_php.ps1 で結果を確認する

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
#>
[CmdletBinding()]
param(
    [string]$VerifyRoot = 'C:\ReportSystem-verify',
    [string]$PhpPackage = 'PHP.PHP.8.3',
    [int]$Port = 8000,
    [switch]$DryRun,
    [switch]$Yes,        # 確認を省略する
    [switch]$SkipPhp,    # PHP の導入・設定をしない（ソースのコピーだけ）
    [switch]$ResetDb     # 検証用 DB をリポジトリの DB で作り直す
)

$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot  = (Resolve-Path (Join-Path $scriptDir '..\..')).Path
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
Write-Info "リポジトリ  : $repoFull"
Write-Info "検証フォルダ: $verifyFull"
if ($DryRun) { Write-Info '（-DryRun: 何も変更しません）' }

if ($verifyFull -ieq $repoFull -or $verifyFull.StartsWith($repoFull + '\', [StringComparison]::OrdinalIgnoreCase) -or
    $repoFull.StartsWith($verifyFull + '\', [StringComparison]::OrdinalIgnoreCase)) {
    throw '検証フォルダはリポジトリの外（かつリポジトリを含まない場所）にしてください。'
}
if ($verifyFull -match '^[A-Za-z]:\\dev2(\\|$)') {
    throw 'C:\dev2 は参照専用のため、検証フォルダにできません。'
}
if ((Test-Path -LiteralPath $verifyFull) -and -not (Test-Path -LiteralPath (Join-Path $verifyFull $marker))) {
    $hasFiles = @(Get-ChildItem -LiteralPath $verifyFull -Force -ErrorAction SilentlyContinue).Count -gt 0
    if ($hasFiles) { throw "既存のフォルダ $verifyFull はこのスクリプトが作ったものではないため、上書きしません。別の -VerifyRoot を指定してください。" }
}
Write-Ok '検証フォルダの場所'

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
        Write-Plan "winget install --id $PhpPackage -e でインストール"
    } else {
        if (-not (Get-Command winget -ErrorAction SilentlyContinue)) { throw 'winget がありません。PHP を手動でインストールしてください。' }
        if (-not (Confirm-Action "PHP（$PhpPackage）を winget でインストールします。よろしいですか？")) {
            Write-Host '中止しました。' -ForegroundColor Red; exit 1
        }
        & winget install --id $PhpPackage -e --accept-package-agreements --accept-source-agreements --disable-interactivity
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
Write-Step '3. 検証用フォルダへコピー'
$excludeDirs  = @('.git', '.claude', (Join-Path $repoFull 'tools\audit\reports'))
$excludeFiles = @('reports.sqlite', 'reports.sqlite-journal', 'reports.sqlite-wal', 'reports.sqlite-shm',
                  '石原さん依頼.txt', '依頼書.txt', $marker)
$dbSrc = Join-Path $repoFull 'data\reports.sqlite'
$dbDst = Join-Path $verifyFull 'data\reports.sqlite'

if ($DryRun) {
    Write-Plan "robocopy /MIR $repoFull -> $verifyFull（.git・依頼メモ・DB を除く）"
    if ($ResetDb -or -not (Test-Path -LiteralPath $dbDst)) { Write-Plan "DB をコピー: $dbSrc -> $dbDst" }
    else { Write-Plan "検証用 DB は残す: $dbDst" }
} else {
    New-Item -ItemType Directory -Force -Path $verifyFull | Out-Null
    $rcArgs = @($repoFull, $verifyFull, '/MIR', '/NFL', '/NDL', '/NJH', '/NJS', '/NP', '/R:1', '/W:1', '/XD') + $excludeDirs + @('/XF') + $excludeFiles
    & robocopy @rcArgs | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy が失敗しました（終了コード $LASTEXITCODE）。" }
    Set-Content -LiteralPath (Join-Path $verifyFull $marker) -Value "created by tools/verify/setup_devenv.ps1`r`nsource: $repoFull" -Encoding UTF8
    Write-Ok 'ソースをコピーしました'

    if ($ResetDb -or -not (Test-Path -LiteralPath $dbDst)) {
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
Write-Step '4. 起動スクリプト'
$startPath = Join-Path $verifyFull 'start_server.ps1'
$phpForStart = if ($phpExe) { $phpExe } else { 'php' }
$startScript = @"
# report-system 検証用サーバー（setup_devenv.ps1 が生成）
# 停止: Ctrl + C
`$php = '$phpForStart'
if (-not (Test-Path -LiteralPath `$php)) { `$php = 'php' }
Write-Host 'http://localhost:$Port/public/login.php を開いてください（停止: Ctrl + C）'
Write-Host '注意: PHP 組み込みサーバーは .htaccess を読まないため、data/ などは本番と同じく見えます。'
& `$php -S 127.0.0.1:$Port -t '$verifyFull'
"@
if ($DryRun) {
    Write-Plan "$startPath を作成（php -S 127.0.0.1:$Port -t $verifyFull）"
} else {
    [IO.File]::WriteAllText($startPath, $startScript, (New-Object Text.UTF8Encoding($true)))
    Write-Ok "作成しました: $startPath"
}

# ==== 5. 確認 ====
Write-Step '5. PHP の確認'
$checker = Join-Path $scriptDir 'check_php.ps1'
if ($DryRun) {
    Write-Plan "$checker を実行"
} else {
    & powershell -NoProfile -ExecutionPolicy Bypass -File $checker
}

Write-Step '完了'
if ($DryRun) {
    Write-Info '-DryRun のため何も変更していません。-DryRun を外すと実行します。'
} else {
    Write-Info "起動: powershell -ExecutionPolicy Bypass -File `"$startPath`""
    Write-Info "監査: python tools\audit\audit.py --base-url http://localhost:$Port/public/ --db-path `"$dbDst`""
}
