<#
.SYNOPSIS
  PHP がインストール済みか、report-system を動かせる状態かを確認する（読み取りのみ）。

.DESCRIPTION
  1. PATH 上の php.exe
  2. よくあるインストール先（C:\php, C:\xampp\php, Program Files, winget の配置先 など）
  を探し、見つかった php.exe ごとに次を確認する。
  - バージョン（必要: 7.3 以上）
  - 拡張モジュール pdo_sqlite（必須）、sqlite3 / mbstring / openssl（推奨）
  - 使用中の php.ini

  何もインストール・変更しない。終了コード: 使える PHP があれば 0、なければ 1。

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\check_php.ps1
#>
[CmdletBinding()]
param(
    [version]$MinVersion = '7.3'
)

$ErrorActionPreference = 'Stop'

$requiredExt    = @('pdo_sqlite')
$recommendedExt = @('sqlite3', 'mbstring', 'openssl')

# ==== 候補の収集 ====
$candidates = New-Object System.Collections.Generic.List[string]

function Add-Candidate([string]$path) {
    if ([string]::IsNullOrWhiteSpace($path)) { return }
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { return }
    $full = (Resolve-Path -LiteralPath $path).Path
    if (-not ($candidates -contains $full)) { $candidates.Add($full) }
}

# PATH 上
$inPath = @(Get-Command php -CommandType Application -All -ErrorAction SilentlyContinue)
foreach ($c in $inPath) { Add-Candidate $c.Source }

# よくあるインストール先（再帰検索はしない）
$fixedPaths = @(
    'C:\php\php.exe',
    'C:\xampp\php\php.exe',
    'C:\tools\php\php.exe',
    (Join-Path $env:ProgramFiles 'PHP\php.exe')
)
foreach ($p in $fixedPaths) { Add-Candidate $p }

# Program Files\PHP\v8.x など、1 階層下まで
$phpRoots = @((Join-Path $env:ProgramFiles 'PHP'), 'C:\php')
foreach ($root in $phpRoots) {
    if (Test-Path -LiteralPath $root) {
        Get-ChildItem -LiteralPath $root -Directory -ErrorAction SilentlyContinue |
            ForEach-Object { Add-Candidate (Join-Path $_.FullName 'php.exe') }
    }
}

# winget の配置先（%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.*）
$wingetPkgs = Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages'
if (Test-Path -LiteralPath $wingetPkgs) {
    Get-ChildItem -LiteralPath $wingetPkgs -Directory -Filter 'PHP.PHP*' -ErrorAction SilentlyContinue |
        ForEach-Object { Add-Candidate (Join-Path $_.FullName 'php.exe') }
}

# ==== 各 php.exe の確認 ====
Write-Host '==== PHP インストール確認 ===='
Write-Host ("必要なバージョン: {0} 以上 / 必須拡張: {1}" -f $MinVersion, ($requiredExt -join ', '))
Write-Host ''

if ($candidates.Count -eq 0) {
    Write-Host '[NG] PHP は見つかりませんでした。' -ForegroundColor Red
    Write-Host '     インストール例: winget install PHP.PHP.8.3'
    exit 1
}

$usable = 0
foreach ($exe in $candidates) {
    $inPathMark = if ($inPath | Where-Object { $_.Source -eq $exe }) { '（PATH 上）' } else { '（PATH 外）' }
    Write-Host ("● {0} {1}" -f $exe, $inPathMark)

    try {
        $verText = (& $exe -r 'echo PHP_VERSION;' 2>$null | Out-String).Trim()
        $mods    = @(& $exe -m 2>$null | ForEach-Object { $_.Trim().ToLower() } | Where-Object { $_ -and $_ -notmatch '^\[' })
        $ini     = (& $exe -r 'echo php_ini_loaded_file() ?: "(なし)";' 2>$null | Out-String).Trim()
    } catch {
        Write-Host ("  [NG] 実行できません: {0}" -f $_.Exception.Message) -ForegroundColor Red
        continue
    }

    $ok = $true
    $ver = $null
    if (-not [version]::TryParse(($verText -replace '[^0-9.].*$', ''), [ref]$ver)) {
        Write-Host ("  [NG] バージョンを取得できません: {0}" -f $verText) -ForegroundColor Red
        $ok = $false
    } elseif ($ver -lt $MinVersion) {
        Write-Host ("  [NG] バージョン {0}（{1} 以上が必要）" -f $verText, $MinVersion) -ForegroundColor Red
        $ok = $false
    } else {
        Write-Host ("  [OK] バージョン {0}" -f $verText) -ForegroundColor Green
    }

    foreach ($e in $requiredExt) {
        if ($mods -contains $e) {
            Write-Host ("  [OK] {0}" -f $e) -ForegroundColor Green
        } else {
            Write-Host ("  [NG] {0} が無効（php.ini で extension={0} を有効にする）" -f $e) -ForegroundColor Red
            $ok = $false
        }
    }
    foreach ($e in $recommendedExt) {
        if ($mods -contains $e) {
            Write-Host ("  [OK] {0}" -f $e) -ForegroundColor Green
        } else {
            Write-Host ("  [--] {0} が無効（推奨）" -f $e) -ForegroundColor Yellow
        }
    }
    Write-Host ("  php.ini: {0}" -f $ini)
    if ($ini -eq '(なし)') {
        Write-Host '  [--] php.ini が読み込まれていません。php.ini-development をコピーして php.ini を作ると拡張を有効にできます。' -ForegroundColor Yellow
    }

    if ($ok) { $usable++ }
    Write-Host ''
}

# ==== まとめ ====
if ($usable -gt 0) {
    Write-Host ("結果: 使える PHP が {0} 件あります。" -f $usable) -ForegroundColor Green
    if ($inPath.Count -eq 0) {
        Write-Host '      ただし PATH に通っていないため、フルパスで実行するか PATH に追加してください。' -ForegroundColor Yellow
    }
    exit 0
} else {
    Write-Host '結果: 条件を満たす PHP がありません。' -ForegroundColor Red
    exit 1
}
