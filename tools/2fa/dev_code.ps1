<#
.SYNOPSIS
ローカル検証環境のテスト用利用者のログイン情報と、今の 2 段階認証のコード（6 桁）を表示する。

.DESCRIPTION
tools\test\dev_code.py の PowerShell 版（Python がない Windows の端末で使う）。
tools\test\dev_seed.php で作ったテスト用の利用者だけ（tools\test\output_dev_users.json を読む。本番の利用者には使えない）。
Windows PowerShell 5.1 と PowerShell 7 で動く。

.EXAMPLE
powershell -ExecutionPolicy Bypass -File tools\2fa\dev_code.ps1
全員を表示する。

.EXAMPLE
powershell -ExecutionPolicy Bypass -File tools\2fa\dev_code.ps1 kacho_a ippan_a1
指定した人だけ表示する。

.EXAMPLE
powershell -ExecutionPolicy Bypass -File tools\2fa\dev_code.ps1 -Watch
1 秒ごとに表示し直す（Ctrl + C で終わる）。

.EXAMPLE
powershell -ExecutionPolicy Bypass -File tools\2fa\dev_code.ps1 -Watch -Interval 30
30 秒ごと（コードが切り替わる時刻）に表示し直す。tools\2fa\all_ps.bat はこの形で呼ぶ。
#>
param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$LoginIdList = @(),
    [switch]$Watch,
    [ValidateRange(1, 3600)]
    [int]$Interval = 1
)

$ErrorActionPreference = 'Stop'
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch { }

$RoleByLoginId = [ordered]@{
    'admin01'  = '管理者'
    'kacho_a'  = 'A課 課長'
    'dairi_a'  = 'A課 確認者（課長代理）'
    'leader_a' = 'A課 確認者（リーダー）'
    'ippan_a1' = 'A課 部下'
    'ippan_a2' = 'A課 部下'
    'kacho_b'  = 'B課 課長'
    'ippan_b1' = 'B課 部下'
    'hosa01'   = 'システム管理者補佐'
}

function ConvertFrom-Base32Text([string]$Base32Text) {
    $alphabetText = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
    $cleanText = $Base32Text.Replace(' ', '').TrimEnd('=').ToUpperInvariant()
    $byteList = New-Object System.Collections.Generic.List[byte]
    $bitBuffer = 0
    $bitCount = 0
    foreach ($character in $cleanText.ToCharArray()) {
        $characterValue = $alphabetText.IndexOf($character)
        if ($characterValue -lt 0) { throw "2 段階認証の鍵に使えない文字があります: $character" }
        $bitBuffer = (($bitBuffer -shl 5) -bor $characterValue) -band 0xFFFF
        $bitCount += 5
        if ($bitCount -ge 8) {
            $bitCount -= 8
            $byteList.Add([byte](($bitBuffer -shr $bitCount) -band 0xFF))
        }
    }
    return ,$byteList.ToArray()
}

function Get-TotpCode([string]$SecretBase32, [long]$UnixSeconds) {
    # RFC 6238（30 秒ごと・HMAC-SHA1・6 桁）。アプリと tools\test\lib_http.py の totp() と同じ計算
    $keyBytes = ConvertFrom-Base32Text $SecretBase32
    $stepNumber = [long][Math]::Floor($UnixSeconds / 30)
    $counterBytes = [BitConverter]::GetBytes($stepNumber)
    if ([BitConverter]::IsLittleEndian) { [Array]::Reverse($counterBytes) }
    $hmacSha1 = New-Object System.Security.Cryptography.HMACSHA1 -ArgumentList (, $keyBytes)
    try { $hashBytes = $hmacSha1.ComputeHash($counterBytes) } finally { $hmacSha1.Dispose() }
    $offsetNumber = $hashBytes[19] -band 0x0F
    $codeNumber = (([int]$hashBytes[$offsetNumber] -band 0x7F) -shl 24) -bor
                  ([int]$hashBytes[$offsetNumber + 1] -shl 16) -bor
                  ([int]$hashBytes[$offsetNumber + 2] -shl 8) -bor
                  [int]$hashBytes[$offsetNumber + 3]
    return '{0:D6}' -f ($codeNumber % 1000000)
}

$usersJsonPath = Join-Path $PSScriptRoot '..\test\output_dev_users.json'
if (-not (Test-Path $usersJsonPath)) {
    Write-Host 'tools\test\output_dev_users.json がありません（tools\test\dev_seed.php でテストデータを作ると出来ます）'
    exit 1
}
$usersObject = (Get-Content $usersJsonPath -Raw -Encoding UTF8 | ConvertFrom-Json).users
$allLoginIdList = @($usersObject.PSObject.Properties | ForEach-Object { $_.Name })
if ($LoginIdList.Count -eq 0) { $shownLoginIdList = $allLoginIdList } else { $shownLoginIdList = $LoginIdList }

function Show-CodeTable {
    $unixSeconds = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
    $secondsLeft = 30 - ($unixSeconds % 30)
    Write-Host ('2 段階認証のコード（あと {0} 秒有効）' -f $secondsLeft)
    Write-Host ('{0,-10} {1,-24} {2,-24} {3}' -f 'ログインID', '役割', 'パスワード', 'コード')
    foreach ($loginId in $shownLoginIdList) {
        $userInfo = $usersObject.$loginId
        if ($null -eq $userInfo) {
            Write-Host ('{0,-10} （テスト用の利用者にいません）' -f $loginId)
            continue
        }
        Write-Host ('{0,-10} {1,-24} {2,-24} {3}' -f $loginId, $RoleByLoginId[$loginId], $userInfo.password, (Get-TotpCode $userInfo.totp $unixSeconds))
    }
}

if ($Watch) {
    while ($true) {
        Clear-Host
        Show-CodeTable
        Write-Host ''
        Write-Host ('{0} 秒ごとに更新しています（Ctrl + C で終わる）' -f $Interval)
        # 時計の区切り（Interval 秒の倍数）まで待つ。30 秒ならコードが切り替わった直後に表示し直す
        $nowMilliseconds = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
        $waitMilliseconds = ($Interval * 1000) - ($nowMilliseconds % ($Interval * 1000)) + 50
        Start-Sleep -Milliseconds $waitMilliseconds
    }
} else {
    Show-CodeTable
}
